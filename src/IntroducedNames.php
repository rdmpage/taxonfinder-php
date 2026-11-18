<?php
/**
 * The genera and epithets a document is itself publishing.
 *
 * A paper describing a new species uses an epithet that no dictionary built
 * before it was published can hold, and a paper erecting a genus does the
 * same with the genus. So the one place a name is most worth finding - where
 * it is published - is the one place the dictionaries are sure to miss it:
 *
 *   Ptilototheca gen. nov.
 *   Ptilototheca soutpansbergensis gen. et sp. nov.
 *   Sheldonia nonsmaripi Herbert, sp. nov.
 *
 * But the act says what the words are. 'gen. nov.' after a capitalised word
 * names it a genus, and 'sp. nov.' after a genus and a lowercase word names
 * that word an epithet. This reads those places, and the parser then runs
 * with the words in its dictionaries for this document only - so every other
 * mention, 'P. soutpansbergensis' and 'Ptilototheca' on its own, is found
 * too.
 *
 * What it takes:
 *
 *   A capitalised word, not already a genus, family or everyday word, that a
 *   new genus or subgenus is announced on.
 *
 *   One or two lowercase words after a genus (known, abbreviated, or the
 *   capitalised word before them) that a new species-group name or a new
 *   combination is announced on. A new combination publishes no new
 *   epithet, but it does say the word is one.
 *
 * What keeps the rest out, each rule here because of something it stopped
 * across the test documents - mostly author surnames and the words of an
 * address, which stand right before an act just as a name does:
 *
 *   the act carries a word meaning new ('sp.' on its own is an indeterminate
 *   species, not a name);
 *   it is on the word's own line and near it, with nothing but an author
 *   citation between (see announces());
 *   a new genus opens its line or clause (see headsAName());
 *   an unknown lowercase word ends the way Latin epithets do, and in a
 *   trinomen only the last word may be new.
 *
 * A word learned wrongly costs more than one missed, because the parser then
 * expands every matching abbreviation in the document to it.
 */

namespace Taxonfinder;

class IntroducedNames
{
    /** Terminations a Latin epithet takes; a broader net than CapitalEpithet's. */
    const LATIN = '/(?:[aeiouy]|[aeiou]s|um|ns|x|[eo]r)$/D';

    /** A word meaning new, roughly: a cheap test before the real one. */
    const NEW_WORD = '/\b(?:nov|nova|novum|novus|new|n)\b/i';

    /** How far an act may stand from the word it is about, in bytes. */
    const REACH = 70;

    /** Acts that publish a genus or subgenus. */
    private static $genusActs = array('gen. nov.' => true, 'subgen. nov.' => true);

    /** Acts that say the words before them are a species-group name. */
    private static $speciesActs = array(
        'sp. nov.' => true,
        'subsp. nov.' => true,
        'var. nov.' => true,
        'forma nov.' => true,
        'nom. nov.' => true,
        'comb. nov.' => true,
    );

    /**
     * @param string       $text
     * @param Dictionaries $dictionaries
     * @param bool         $isHtml
     * @return array<string, string[]> dictionary => lowercase terms, empty
     *                                 when the document introduces nothing
     */
    public static function find($text, Dictionaries $dictionaries, $isHtml = false)
    {
        $text = (string) $text;
        if ($isHtml) {
            // Only the words are wanted, not where they are, so the tags can
            // simply go: '<i>Ptilototheca</i> gen. nov.' reads as one.
            $text = strip_tags($text);
        }
        // Nothing announced as new, nothing to do - which is most documents.
        if (!preg_match(self::NEW_WORD, $text)) {
            return array();
        }

        $genera = array();
        $epithets = array();

        // A genus published on its own.
        if (preg_match_all('/\b([A-Z][a-z]{3,}|[A-Z]{4,})\b(?![\-\'])/', $text, $matches,
                PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                list($word, $offset) = $match;
                if (!self::newNear($text, $offset + strlen($word))) {
                    continue;
                }
                $lower = Utility::lower($word);
                if (isset($genera[$lower]) || !self::couldBeNewGenus($dictionaries, $lower)
                    || !self::headsAName($text, $offset)) {
                    continue;
                }
                if (self::announces($text, $offset + strlen($word), self::$genusActs)) {
                    $genera[$lower] = true;
                }
            }
        }

        // A species-group name, with its genus if that is new as well.
        $space = '(?:[ \t]+\n?[ \t]*|\n[ \t]*)';
        $pattern = '/(?<![A-Za-z\-])([A-Z][a-z]+|[A-Z]{4,}|[A-Z]\.)' . $space
            . '([a-z][a-z\-]*[a-z])(?:' . $space . '([a-z][a-z\-]*[a-z]))?(?![A-Za-z\-\.])/';
        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                // The binomen first, then the trinomen: the act after
                // 'Gulella puzeyi minor subsp. nov.' follows the third word,
                // but the one in 'Gulella puzeyi Connolly, sp. nov.' follows
                // the second.
                $words = array();
                foreach (array(2, 3) as $group) {
                    if (!isset($match[$group]) || $match[$group][1] < 0) {
                        break;
                    }
                    $words[] = $match[$group][0];
                    $end = $match[$group][1] + strlen($match[$group][0]);
                    if (self::readSpeciesGroup($text, $dictionaries, $match[1][0], $match[1][1],
                            $words, $end, $genera, $epithets)) {
                        break;
                    }
                }
            }
        }

        $found = array();
        if ($genera) {
            $found['genera_new'] = array_keys($genera);
        }
        if ($epithets) {
            $found['species_new'] = array_keys($epithets);
        }
        return $found;
    }

    /**
     * Is the act after $end announcing a species-group name on these words?
     * If it is, record whatever in them the dictionaries do not hold.
     */
    private static function readSpeciesGroup($text, Dictionaries $dictionaries, $genusWord,
        $genusOffset, array $words, $end, array &$genera, array &$epithets)
    {
        if (!self::newNear($text, $end)) {
            return false;
        }
        // An initial is not an abbreviated genus: 'E. L. bridges & S. L.
        // Orzell, sp. nov.' is an author, lowercased by the OCR.
        if (substr($genusWord, -1) === '.'
            && preg_match('/\b[A-Z]\.\s*$/', substr($text, max(0, $genusOffset - 8), min(8, $genusOffset)))) {
            return false;
        }
        $unknown = array();
        foreach ($words as $position => $word) {
            $lower = Utility::lower($word);
            if (Nomenclature::isVocabulary($lower) || $dictionaries->has('ranks', $lower)) {
                return false;                                   // 'Gulella sp. nov.'
            }
            if ($dictionaries->has('species_bad', $lower)
                || $dictionaries->has('overlap_new', $lower)) {
                return false;
            }
            if (!$dictionaries->has('species', $lower) && !$dictionaries->has('species_new', $lower)) {
                if (strlen($lower) < 4 || !preg_match(self::LATIN, $lower)) {
                    return false;                               // 'from', 'with'
                }
                // A new subspecies is put under a species already named, so
                // in a trinomen only the last word is new. 'Nolo pleura
                // vargasiana C. M. Taylor, sp.' is Notopleura split by the OCR.
                if ($position < count($words) - 1) {
                    return false;
                }
                $unknown[] = $lower;
            }
        }

        $genus = Utility::lower($genusWord);
        $newGenus = false;
        if (substr($genusWord, -1) !== '.' && !isset($genera[$genus])
            && !self::isGenus($dictionaries, $genus)) {
            if (strlen($genus) < 4 || !self::couldBeNewGenus($dictionaries, $genus)
                || !self::headsAName($text, $genusOffset)) {
                return false;
            }
            // A new genus is published with a binomen under it, never a
            // trinomen: 'Nolo pleura vargasiana' is Notopleura in two.
            if (count($words) > 1) {
                return false;
            }
            $newGenus = true;
        }
        if (!$unknown && !$newGenus) {
            return false;                                       // nothing to learn
        }
        if (!self::announces($text, $end, self::$speciesActs)) {
            return false;
        }
        if ($newGenus) {
            $genera[$genus] = true;
        }
        foreach ($unknown as $lower) {
            $epithets[$lower] = true;
        }
        return true;
    }

    /**
     * Does an act from $wanted, carrying a word meaning new, follow $offset?
     *
     * Held closer than Nomenclature::detect() holds it. That reads across an
     * author citation and onto the next line, which is right for an act on a
     * name already found and wrong for deciding what a word is: 'Museum of
     * Natural History, Washington, D.C.' followed by 'Laneocera, new genus'
     * would make 'Natural' a genus. And a genus learned wrongly does more
     * harm than going unfound, since every 'E.' in the paper is then
     * expanded to it. So here the act has to be on the word's own line, near
     * it, and an act spelled out in English has to be set off from the word
     * by punctuation - 'Drake, new species' but not 'indicates new
     * combination'.
     */
    private static function announces($text, $offset, array $wanted)
    {
        $found = Nomenclature::detect($text, $offset);
        if ($found === null) {
            return false;
        }
        $gap = (string) substr($text, $offset, $found['start'] - $offset);
        if (strpos($gap, "\n") !== false || strlen($gap) > self::REACH) {
            return false;
        }
        // Whatever stands between is an author citation or nothing. Not a
        // sentence ending - 'Ridley, H. N. 1909. Tricalistra, new genus' -
        // nor the colon of a title or a page reference. An initial, 'X.G.
        // Zhang', is one letter before its stop, and allowed.
        if (preg_match('/[A-Za-z0-9]{2}\.\s+[A-Z]|:/', $gap)) {
            return false;
        }
        if (preg_match('/^[a-z]{2,}/i', $found['verbatim'], $first)
            && substr($text, $found['start'] + strlen($first[0]), 1) !== '.'
            && !preg_match('/\S/', $gap)) {
            return false;                                   // 'indicates new combination'
        }
        foreach ($found['acts'] as $act) {
            if (isset($wanted[$act])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does the word at $offset start a name, rather than finish an author
     * citation?
     *
     * The act comes straight after the author, so on its own it would make
     * a genus of the surname in 'Distobactrodesmium rahmii Jian & Zhang,
     * gen. et sp. nov.' A genus being published opens its heading or its
     * clause - the line, or after punctuation, as in 'Three new genera,
     * Blastophragmia gen. nov., ...' - and does not follow another word.
     */
    private static function headsAName($text, $offset)
    {
        $lineStart = self::lineStart($text, $offset);
        $before = rtrim(substr($text, $lineStart, $offset - $lineStart));
        if ($before === '') {
            // The start of a line, unless it is a citation running on from
            // the one above: 'Jian Ma, L.G. Ma, X.G. Zhang &' then 'R.F.
            // Castafieda, sp. nov.', or 'M. T.' then 'Strong, comb. nov.'
            if ($lineStart === 0) {
                return true;
            }
            $aboveStart = self::lineStart($text, $lineStart - 1);
            $above = rtrim(substr($text, $aboveStart, $lineStart - 1 - $aboveStart));
            return !preg_match('/(?:[&,]|\b(?:and|et)|\b[A-Z]\.)$/', $above);
        }
        // After an initial is a surname: 'M. T. Strong, gen. nov.'
        if (preg_match('/\b[A-Z]\.$/', $before)) {
            return false;
        }
        return (bool) preg_match('/(?:[.,:;(\[]|\xE2\x80[\x93\x94]|\bgen(?:us)?\.?)$/i', $before);
    }

    /**
     * Is there a word meaning new soon after $offset? Most words have none
     * near them, and this is far cheaper than the dictionaries or reading
     * the act to find that out.
     */
    private static function newNear($text, $offset)
    {
        return (bool) preg_match(self::NEW_WORD, (string) substr($text, $offset, self::REACH + 30));
    }

    /**
     * Where the line holding $offset starts. Searched backwards in place:
     * copying the text before every candidate makes a book quadratic.
     */
    private static function lineStart($text, $offset)
    {
        if ($offset <= 0) {
            return 0;
        }
        $newline = strrpos($text, "\n", $offset - 1 - strlen($text));
        return $newline === false ? 0 : $newline + 1;
    }

    /** What Parser::scoreGenus() calls a genus, rather than a family or above. */
    private static function isGenus(Dictionaries $dictionaries, $lower)
    {
        return ($dictionaries->has('genera', $lower) || $dictionaries->has('genera_new', $lower))
            && !$dictionaries->has('genera_family', $lower);
    }

    /**
     * A capitalised word the dictionaries have no other reading of - not a
     * genus already, nor a family, nor a word they have been told is never a
     * name.
     */
    private static function couldBeNewGenus(Dictionaries $dictionaries, $lower)
    {
        return !self::isGenus($dictionaries, $lower)
            && !$dictionaries->has('family', $lower)
            && !$dictionaries->has('family_new', $lower)
            && !$dictionaries->has('genera_family', $lower)
            && !$dictionaries->has('overlap_new', $lower)
            && !$dictionaries->has('dict_ambig', $lower)
            && !Nomenclature::isVocabulary($lower);
    }
}
