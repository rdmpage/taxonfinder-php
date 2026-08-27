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

    /** @var bool has dictionaries/annotations.txt been read yet */
    private static $loaded = false;

    /**
     * Words meaning 'new'. The single letter 'n' must be lowercase, so that an
     * author's initial in 'Amanita sp. N. Smith' is not read as an act.
     */
    private static $newMarkers = array();

    /**
     * Act words: lowercase word => array(canonical form, may it stand
     * without a 'new' marker).
     */
    private static $acts = array();

    /**
     * Lowercase words allowed inside an author citation between a name and
     * its annotation, alongside capitalised surnames and numbers.
     */
    private static $citationWords = array();

    /** @var array words that may stand between two acts: 'gen. et sp. nov.' */
    private static $joinWords = array();

    /**
     * @var array open nomenclature qualifiers - 'cf.', 'indet.', 'stet.' -
     * which say how sure an identification is, not that anything is new
     */
    private static $qualifiers = array();

    /**
     * @var array canonical forms that are a judgment about a name rather than
     * the publication of one: 'syn.', 'stat.'
     */
    private static $judgmentForms = array();

    /**
     * Acts that cannot be new, so a 'nov.' shared across a run never reaches
     * them. These say how sure the identification is, not what is being
     * published, and 'cf. nov.' is not a thing.
     */
    private static $neverNew = array('cf.' => true, 'aff.' => true, 'ined.' => true);

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
        self::load();
        $scan = self::tokens($text, $offset);
        $tokens = $scan['tokens'];
        if (!$tokens) {
            return null;
        }

        $acts = array();
        $qualifiers = array();
        $judgments = array();
        $used = array();
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $word = strtolower($tokens[$i]['word']);
            $next = isset($tokens[$i + 1]) ? $tokens[$i + 1] : null;

            if (isset(self::$acts[$word])) {
                list($canonical, $mayStandAlone) = self::$acts[$word];
                if ($next !== null && self::isNewMarker($next['word'])
                    && !isset(self::$neverNew[$canonical])) {
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

            if (isset(self::$qualifiers[$word])) {
                $qualifiers[] = self::$qualifiers[$word];
                $used[] = $i;
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

        if (!$acts && !$qualifiers && !$judgments) {
            return null;
        }
        // Sharing first: in 'gen. et sp. nov.' the 'gen.' stands alone until
        // the marker at the end of the run reaches it.
        $acts = self::shareNewness($acts);

        // Then the three ways an annotation can go.
        //
        //   acts        something is published - 'sp. nov.', 'comb. nov.'
        //   judgments   a view taken of names already published - 'syn. nov.'
        //               sinks one name into another, 'stat. nov.' moves one to
        //               another rank. Neither publishes a name.
        //   qualifiers  how sure the identification was - 'Nucula sp.'
        $announced = array();
        foreach ($acts as $act) {
            $base = substr($act, -5) === ' nov.' ? substr($act, 0, -5) : $act;
            if (isset(self::$judgmentForms[$base])) {
                $judgments[] = $act;
            } elseif (substr($act, -4) === 'nov.') {
                $announced[] = $act;
            } else {
                $qualifiers[] = $act;
            }
        }
        $acts = $announced;

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
        // A qualifier belongs against its name. Reached across an author
        // citation it is a word in a title, not a statement about a specimen.
        if ($scan['skippedCitation']) {
            $qualifiers = array();
        }
        if ($scan['skippedCitation']) {
            // Reached across a citation, it has to be announcing something.
            // A bare qualifier belongs against its name - 'Amanita sp.' - and
            // one found beyond an author citation is almost always a title
            // being read as an act: 'Fabr. Sp. Ins., 1781' is Species
            // Insectorum, 'Steudel, Nom. Bot.' the Nomenclator.
            if (!self::announcesSomethingNew($acts) && !$judgments) {
                return null;
            }
            if (!self::endsTheLine($text, $end) && !self::opensAStatement($text, $end)) {
                return null;
            }
        }

        return array(
            'verbatim' => substr($text, $start, $end - $start),
            'acts' => $acts,
            'judgments' => $judgments,
            'qualifiers' => $qualifiers,
            'start' => $start,
            'end' => $end,
        );
    }

    /**
     * Does a nomenclatural act finish just before $offset?
     *
     * Asked by Identifiers, so that a bare registry number can be trusted
     * where it follows one. Mycotaxon prints them that way in the body -
     * '... sp. nov. FIGS 2-4 MB 833932' - and the prefix alone is no evidence
     * at all, 'IF' being an English word and 'MB' a museum accession.
     *
     * Read off the text rather than off the annotations, because the two do
     * not agree: a paper erecting new fungi names them with epithets no
     * dictionary can hold yet, so most of these acts are in the text and not
     * in our findings.
     *
     * @param string $text
     * @param int    $offset  where the identifier starts
     * @param int    $within  how far back to look
     */
    public static function actEndsBefore($text, $offset, $within = 40)
    {
        self::load();
        $from = max(0, $offset - $within);
        $window = (string) substr($text, $from, $offset - $from);
        if (!preg_match_all('/[A-Za-z]+/', $window, $matches)) {
            return false;
        }
        $words = $matches[0];
        for ($i = 0, $n = count($words) - 1; $i < $n; $i++) {
            $here = strtolower($words[$i]);
            $next = $words[$i + 1];
            // 'sp. nov.' and 'n. sp.' alike
            if (isset(self::$acts[$here]) && self::isNewMarker($next)) {
                return true;
            }
            if (self::isNewMarker($words[$i]) && isset(self::$acts[strtolower($next)])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read the shipped vocabulary, and dictionaries/local/annotations.txt if
     * it is there. Called automatically; only needed directly if you want the
     * vocabulary before the first detect().
     */
    public static function load()
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dictionaries';
        self::readFile($directory . DIRECTORY_SEPARATOR . 'annotations.txt');
        $local = $directory . DIRECTORY_SEPARATOR . 'local'
            . DIRECTORY_SEPARATOR . 'annotations.txt';
        if (is_file($local)) {
            self::readFile($local);
        }
    }

    /**
     * Merge another vocabulary file on top. See dictionaries/annotations.txt
     * for the format.
     */
    public static function addFile($file)
    {
        self::load();
        self::readFile($file);
    }

    /**
     * Add one entry at runtime.
     *
     *   Nomenclature::add('act', 'nudum', 'nom. nud.', true);
     *   Nomenclature::add('new', 'novissima');
     *   Nomenclature::add('cite', 'apud');
     *
     * @param string      $type      'new', 'act', 'cite', 'join', 'qualifier'
     *                               or 'judgment'
     * @param string      $word
     * @param string|null $canonical how an act is reported; required for acts
     * @param bool        $mayStandAlone may an act appear without a 'new' word
     */
    public static function add($type, $word, $canonical = null, $mayStandAlone = false)
    {
        self::load();
        $word = strtolower(trim($word));
        if ($word === '') {
            return;
        }
        switch (strtolower($type)) {
            case 'new':
                self::$newMarkers[$word] = true;
                break;
            case 'act':
                if ($canonical === null) {
                    throw new \InvalidArgumentException("An act needs a canonical form: $word");
                }
                self::$acts[$word] = array($canonical, (bool) $mayStandAlone);
                break;
            case 'cite':
                self::$citationWords[$word] = true;
                break;
            case 'join':
                self::$joinWords[$word] = true;
                break;
            case 'qualifier':
                if ($canonical === null) {
                    throw new \InvalidArgumentException("A qualifier needs a canonical form: $word");
                }
                self::$qualifiers[$word] = $canonical;
                break;
            case 'judgment':
                if ($canonical === null) {
                    throw new \InvalidArgumentException("A judgment needs a canonical form: $word");
                }
                // Read exactly as an act is, so it may take a "new" word and
                // share one along a run; told apart only when reporting.
                self::$acts[$word] = array($canonical, (bool) $mayStandAlone);
                self::$judgmentForms[$canonical] = true;
                break;
            default:
                throw new \InvalidArgumentException("Unknown annotation type: $type");
        }
    }

    /** Forget everything, so the next use reloads from disk. For tests. */
    public static function reset()
    {
        self::$loaded = false;
        self::$newMarkers = array();
        self::$acts = array();
        self::$citationWords = array();
        self::$joinWords = array();
        self::$qualifiers = array();
        self::$judgmentForms = array();
    }

    private static function readFile($file)
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException('Could not read annotation vocabulary: ' . $file);
        }
        if (substr($contents, 0, 3) === "\xEF\xBB\xBF") {
            $contents = substr($contents, 3);
        }
        foreach (explode("\n", $contents) as $number => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $columns = preg_split('/\s+/', $line);
            $type = strtolower($columns[0]);
            if (!isset($columns[1])) {
                throw new \RuntimeException(sprintf(
                    '%s line %d: "%s" needs a word', $file, $number + 1, $type));
            }
            if ($type === 'act') {
                if (!isset($columns[2])) {
                    throw new \RuntimeException(sprintf(
                        '%s line %d: act "%s" needs a canonical form',
                        $file, $number + 1, $columns[1]));
                }
                $bare = isset($columns[3]) && strtolower($columns[3]) === 'bare';
                self::add('act', $columns[1], $columns[2], $bare);
            } elseif ($type === 'judgment') {
                if (!isset($columns[2])) {
                    throw new \RuntimeException(sprintf(
                        '%s line %d: judgment "%s" needs a canonical form',
                        $file, $number + 1, $columns[1]));
                }
                $bare = isset($columns[3]) && strtolower($columns[3]) === 'bare';
                self::add('judgment', $columns[1], $columns[2], $bare);
            } elseif ($type === 'qualifier') {
                if (!isset($columns[2])) {
                    throw new \RuntimeException(sprintf(
                        '%s line %d: qualifier "%s" needs a canonical form',
                        $file, $number + 1, $columns[1]));
                }
                self::add('qualifier', $columns[1], $columns[2]);
            } else {
                self::add($type, $columns[1]);
            }
        }
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
        $firstAct = null;
        $count = count($matches[0]);
        for ($index = 0; $index < $count; $index++) {
            list($word, $position) = $matches[0][$index];
            // Only punctuation and space between the words.
            $gap = substr($window, $cursor, $position - $cursor);
            if (!preg_match('/^[^0-9A-Za-z]*$/D', $gap)) {
                break;
            }
            $lower = strtolower($word);
            if ((isset(self::$acts[$lower]) && self::actNotAnInitial($matches[0], $index))
                || isset(self::$qualifiers[$lower])
                || self::isNewMarker($word)) {
                if ($firstAct === null) {
                    $firstAct = $position;
                }
                $tokens[] = array('word' => $word, 'offset' => $offset + $position);
                $cursor = $position + strlen($word);
                continue;
            }
            // A word joining two acts - 'gen. et sp. nov.' - and only where
            // an act really does follow it, so 'sp. nov. and Felis leo' still
            // ends here.
            if ($tokens && isset(self::$joinWords[$lower])
                && self::actFollows($matches[0], $index + 1)) {
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
        if ($citationWords > 0) {
            // A citation needs a surname; a bare year is not enough.
            if ($citationSurnames === 0) {
                return $empty;
            }
            // And nothing but a line wrap separates it from the act. A
            // citation regularly runs on to the next line -
            // 'Cladocolea alternifolia (Eichler) Kuijt,' then 'comb. nov.' -
            // so a single break has to be allowed or most combinations in a
            // botanical journal are lost. A blank line is different: it is
            // where a paragraph or a page ends, and without stopping there
            // the '201' of a page number and the heading after it read as a
            // citation, and that heading's 'gen. n.' is taken by the last
            // name on the page before.
            if (preg_match('/\n[^\S\n]*\n/', substr($window, 0, $firstAct))) {
                return $empty;
            }
        }
        return array('tokens' => $tokens, 'skippedCitation' => $citationWords > 0);
    }

    /**
     * One "new" word can carry a whole run of acts.
     *
     *   Ptilototheca soutpansbergensis gen. et sp. nov.
     *   Hcemocystidium simondi, n. g. et sp.
     *
     * Both announce a genus and a species, and the marker is written once -
     * before the run in the second, after it in the first. Read literally
     * only the act beside the marker is new and the other comes back bare,
     * which says the genus was merely mentioned when the paper is erecting
     * it.
     *
     * Only where the run holds exactly one marker. 'gen. nov., sp. nov.'
     * writes its own and is left alone, and a lone bare act - 'Amanita sp.' -
     * has no marker to share.
     *
     * @param string[] $acts
     * @return string[]
     */
    private static function shareNewness(array $acts)
    {
        if (count($acts) < 2) {
            return $acts;
        }
        $new = 0;
        foreach ($acts as $act) {
            if (substr($act, -4) === 'nov.') {
                $new++;
            }
        }
        if ($new !== 1) {
            return $acts;
        }
        foreach ($acts as $index => $act) {
            if (substr($act, -4) === 'nov.' || isset(self::$neverNew[$act])) {
                continue;
            }
            $acts[$index] = $act . ' nov.';
        }
        return $acts;
    }

    /**
     * A one letter act, or somebody's initial?
     *
     * 'g' is an act because a new genus is written 'n. g.', and 'f' because a
     * new form is written 'f. nov.'. They are also how half the botanists in
     * the literature abbreviate their names: 'T.G.Gao', 'R.F. Castaneda',
     * 'G.Watt'. Read as an act, an initial ends the scan of the citation and
     * takes the real act behind it down with it - two combinations lost in
     * one PhytoKeys paper, and two registry numbers in Mycotaxon 136(1).
     *
     * A letter only counts as an act with a "new" word beside it, before it
     * as in 'n. g.' or after it as in 'g. n.'. An initial never has one.
     * Longer acts are unaffected: nobody is abbreviated 'sp'.
     */
    private static function actNotAnInitial(array $matches, $index)
    {
        if (strlen($matches[$index][0]) > 1) {
            return true;
        }
        foreach (array($index - 1, $index + 1) as $beside) {
            if (isset($matches[$beside]) && self::isNewMarker($matches[$beside][0])) {
                return true;
            }
        }
        return false;
    }

    /** Is the next word an act, or the marker that makes one? */
    private static function actFollows(array $matches, $index)
    {
        if (!isset($matches[$index])) {
            return false;
        }
        $word = $matches[$index][0];
        return isset(self::$acts[strtolower($word)])
            || isset(self::$qualifiers[strtolower($word)])
            || self::isNewMarker($word);
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

    /**
     * Acts that need more than a uninomial to be an act at all.
     *
     * A new species is published as a binomen and a new subspecies as a
     * trinomen; a new genus or family is published as one word, so those are
     * not here.
     */
    private static $needsBinomen = array(
        'sp. nov.' => true,
        'subsp. nov.' => true,
        'var. nov.' => true,
        'forma nov.' => true,
    );

    /**
     * Re-read an annotation against the name it was found on.
     *
     * 'sp. nov.' after a binomen publishes a name. After a genus on its own
     * it cannot: there is no name there to publish. Sigovini et al. 2016 read
     * that second form as open nomenclature - a way of pointing at a new
     * species not yet named, as in 'Pristiophorus sp. nov. A' - and say
     * plainly that it is no nomenclatural act. So it moves to the qualifiers.
     *
     * It also catches our own failures, which look the same from here: where
     * an epithet was unreadable and the act attached to the bare genus, that
     * row was never an act either.
     *
     * @param array  $nomenclature as detect() returned it
     * @param string $name         the interpreted name it was found on
     */
    public static function readAgainstName(array $nomenclature, $name)
    {
        if (strpos(trim($name), ' ') !== false) {
            return $nomenclature;
        }
        $acts = array();
        foreach ($nomenclature['acts'] as $act) {
            if (isset(self::$needsBinomen[$act])) {
                $nomenclature['qualifiers'][] = $act;
            } else {
                $acts[] = $act;
            }
        }
        $nomenclature['acts'] = $acts;
        return $nomenclature;
    }

    /** Did any of these acts come with a word meaning "new"? */
    private static function announcesSomethingNew(array $acts)
    {
        foreach ($acts as $act) {
            if (substr($act, -4) === 'nov.') {
                return true;
            }
        }
        return false;
    }

    /**
     * Does a new statement start where the annotation left off?
     *
     * A combination in a botanical journal is followed by its basionym on the
     * same line - 'Cladocolea alternifolia (Eichler) Kuijt, comb. nov.
     * Basionym: ...' - so finishing the line cannot be the only way an
     * annotation reached across a citation is allowed to stand. What the
     * guard is really for is an act swallowed out of the sentence following a
     * name, and there the act runs on in lower case: '... Onconotellus. New
     * species of the genus are described' continues with 'of'. A capital
     * starts something new, and the act belongs to what came before it.
     */
    private static function opensAStatement($text, $end)
    {
        $rest = (string) substr($text, $end, 40);
        return (bool) preg_match('/^[^0-9A-Za-z]*[A-Z]/', $rest);
    }

    /** Is there nothing but punctuation between $end and the end of its line? */
    private static function endsTheLine($text, $end)
    {
        $lineEnd = strpos($text, "\n", $end);
        $rest = $lineEnd === false ? substr($text, $end) : substr($text, $end, $lineEnd - $end);
        return !preg_match('/[0-9A-Za-z]/', (string) $rest);
    }
}
