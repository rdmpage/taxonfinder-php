<?php
/**
 * Detects the nomenclatural annotation that follows a name.
 *
 *   Lygus buxtoni, sp. n.                 -> sp. nov.
 *   Pseudoneoborus samoanus, gen. n., sp. n. -> gen. nov., sp. nov.
 *   Amanita muscaria comb. nov.           -> comb. nov.
 *   Amanita sp.                           -> sp.        (indeterminate)
 *
 * The parser strips these from the name itself, so this reads the text
 * starting where the name ended.
 *
 * Acts are reported canonically. 'sp. nov.' means the new-species marker was
 * present; a bare 'sp.' means it was not, either because the name really is
 * indeterminate or because OCR destroyed the marker - 'gen. ., sp. 0.' is a
 * real example, and reports 'gen.' and 'sp.'. The verbatim text is always
 * included so you can see which it was.
 */

namespace Taxonfinder;

class Nomenclature
{
    /** How far past the end of a name to look, in bytes. */
    const WINDOW = 80;

    /**
     * Words meaning 'new'. The single letter 'n' must be lowercase, so that an
     * author's initial in 'Amanita sp. N. Smith' is not read as an act.
     */
    private static $newMarkers = array(
        'nov' => true, 'nova' => true, 'novum' => true, 'novus' => true, 'n' => true,
        'new' => true,
    );

    /**
     * Act words, as canonical form => may it stand without a 'new' marker.
     * 'f' only counts next to a 'new' marker, because a bare 'f.' after a name
     * is as likely to be a figure or a female symbol as a forma.
     */
    private static $acts = array(
        'sp'      => array('sp.', true),
        'spec'    => array('sp.', true),
        'species' => array('sp.', true),
        'gen'     => array('gen.', true),
        'genus'   => array('gen.', true),
        'comb'    => array('comb.', true),
        'syn'     => array('syn.', true),
        'stat'    => array('stat.', true),
        'nom'     => array('nom.', true),
        'subsp'   => array('subsp.', true),
        'ssp'     => array('subsp.', true),
        'var'     => array('var.', true),
        'fam'     => array('fam.', true),
        'subgen'  => array('subgen.', true),
        'subfam'  => array('subfam.', true),
        'forma'   => array('forma', true),
        'f'       => array('f.', false),
        'cf'      => array('cf.', true),
        'aff'     => array('aff.', true),
        'ined'    => array('ined.', true),
        'emend'   => array('emend.', true),
        // Spelled out, as in 'Hypogastrura (s. str.) simsi NEW SPECIES'. Only
        // counted next to 'new', since these words are common in prose.
        'combination'  => array('comb.', false),
        'combinations' => array('comb.', false),
        'synonym'      => array('syn.', false),
        'synonyms'     => array('syn.', false),
        'synonymy'     => array('syn.', false),
        'synonymies'   => array('syn.', false),
        'status'       => array('stat.', false),
        'name'         => array('nom.', false),
        'names'        => array('nom.', false),
        'subspecies'   => array('subsp.', false),
        'variety'      => array('var.', false),
        'varieties'    => array('var.', false),
        'family'       => array('fam.', false),
        'genera'       => array('gen.', false),
    );

    /**
     * Lowercase words allowed inside an author citation between a name and its
     * annotation, alongside capitalised surnames and numbers.
     */
    private static $citationWords = array(
        'and' => true, 'et' => true, 'al' => true, 'in' => true, 'ex' => true,
        'von' => true, 'van' => true, 'de' => true, 'del' => true, 'della' => true,
        'da' => true, 'du' => true, 'la' => true, 'le' => true, 'den' => true,
        'ter' => true, 'sensu' => true, 'auct' => true, 'non' => true, 'nec' => true,
        'pp' => true, 'p' => true,
    );

    /**
     * Look for an annotation starting at $offset (the end of a name).
     *
     * @param string $text
     * @param int    $offset
     * @return array|null array('verbatim' => string, 'acts' => string[],
     *                          'start' => int, 'end' => int)
     */
    public static function detect($text, $offset)
    {
        $scan = self::tokens($text, $offset);
        $tokens = $scan['tokens'];
        if (!$tokens) {
            return null;
        }

        $acts = array();
        $used = array();
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $word = strtolower($tokens[$i]['word']);
            $next = isset($tokens[$i + 1]) ? $tokens[$i + 1] : null;

            if (isset(self::$acts[$word])) {
                list($canonical, $mayStandAlone) = self::$acts[$word];
                if ($next !== null && self::isNewMarker($next['word'])) {
                    $acts[] = $canonical . ' nov.';
                    $used[] = $i;
                    $used[] = $i + 1;
                    $i++;
                } elseif ($mayStandAlone) {
                    $acts[] = $canonical;
                    $used[] = $i;
                }
                continue;
            }

            // 'n. sp.' as well as 'sp. n.'
            if (self::isNewMarker($tokens[$i]['word']) && $next !== null) {
                $nextWord = strtolower($next['word']);
                if (isset(self::$acts[$nextWord])) {
                    $acts[] = self::$acts[$nextWord][0] . ' nov.';
                    $used[] = $i;
                    $used[] = $i + 1;
                    $i++;
                }
            }
        }

        if (!$acts) {
            return null;
        }

        $first = $tokens[$used[0]];
        $last = $tokens[$used[count($used) - 1]];
        $start = $first['offset'];
        $end = $last['offset'] + strlen($last['word']);
        // Take a full stop belonging to the last abbreviation with it.
        if (isset($text[$end]) && $text[$end] === '.') {
            $end++;
        }

        // An annotation reached across an author citation has to finish the
        // line, as 'Alabameubria starki Brown, 1980:188. NEW SYNONYMY' does.
        // Without that, the 'New species' opening a following sentence would
        // attach itself to whatever name the previous one ended with.
        if ($scan['skippedCitation'] && !self::endsTheLine($text, $end)) {
            return null;
        }

        return array(
            'verbatim' => substr($text, $start, $end - $start),
            'acts' => $acts,
            'start' => $start,
            'end' => $end,
        );
    }

    /** Is this the 'new' marker? A bare 'n' has to be lowercase. */
    private static function isNewMarker($word)
    {
        if ($word === 'n') {
            return true;
        }
        if (strlen($word) === 1) {
            return false;
        }
        return isset(self::$newMarkers[strtolower($word)]);
    }

    /**
     * The run of words after $offset that could form an annotation.
     *
     * Before the annotation there may be an author citation - 'Brown, 1980:188.'
     * in 'Alabameubria starki Brown, 1980:188. NEW SYNONYMY' - so capitalised
     * surnames, numbers and a few connecting words are stepped over. The
     * citation must contain at least one surname, so a bare year does not open
     * the door, and ordinary prose ends it: 'Brown, by original designation.'
     * is rejected at 'by'.
     *
     * @return array array('tokens' => list of array('word', 'offset'),
     *                     'skippedCitation' => bool)
     */
    private static function tokens($text, $offset)
    {
        $empty = array('tokens' => array(), 'skippedCitation' => false);
        $window = substr($text, $offset, self::WINDOW);
        if ($window === false || $window === '') {
            return $empty;
        }
        if (!preg_match_all('/[A-Za-z]+|[0-9]+/', $window, $matches, PREG_OFFSET_CAPTURE)) {
            return $empty;
        }

        $tokens = array();
        $cursor = 0;
        $citationWords = 0;
        $citationSurnames = 0;
        foreach ($matches[0] as $match) {
            list($word, $position) = $match;
            // Only punctuation and space between the words.
            $gap = substr($window, $cursor, $position - $cursor);
            if (!preg_match('/^[^0-9A-Za-z]*$/D', $gap)) {
                break;
            }
            $lower = strtolower($word);
            if (isset(self::$acts[$lower]) || self::isNewMarker($word)) {
                $tokens[] = array('word' => $word, 'offset' => $offset + $position);
                $cursor = $position + strlen($word);
                continue;
            }
            // Not vocabulary. Part of a citation before the annotation?
            if ($tokens || !self::isCitationWord($word)) {
                break;
            }
            if (preg_match('/^[A-Z]/', $word)) {
                $citationSurnames++;
            }
            $citationWords++;
            $cursor = $position + strlen($word);
        }

        if (!$tokens) {
            return $empty;
        }
        if ($citationWords > 0 && $citationSurnames === 0) {
            return $empty;
        }
        return array('tokens' => $tokens, 'skippedCitation' => $citationWords > 0);
    }

    /** A surname, a number, or one of the words that join them. */
    private static function isCitationWord($word)
    {
        if (preg_match('/^[0-9]+$/D', $word)) {
            return true;
        }
        if (preg_match('/^[A-Z][A-Za-z]*$/D', $word)) {
            return true;
        }
        return isset(self::$citationWords[strtolower($word)]);
    }

    /** Is there nothing but punctuation between $end and the end of its line? */
    private static function endsTheLine($text, $end)
    {
        $lineEnd = strpos($text, "\n", $end);
        $rest = $lineEnd === false ? substr($text, $end) : substr($text, $end, $lineEnd - $end);
        return !preg_match('/[0-9A-Za-z]/', (string) $rest);
    }
}
