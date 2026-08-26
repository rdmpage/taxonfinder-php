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
        $acts = self::shareNewness($acts);

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
        if ($scan['skippedCitation']) {
            // Reached across a citation, it has to be announcing something.
            // A bare qualifier belongs against its name - 'Amanita sp.' - and
            // one found beyond an author citation is almost always a title
            // being read as an act: 'Fabr. Sp. Ins., 1781' is Species
            // Insectorum, 'Steudel, Nom. Bot.' the Nomenclator.
            if (!self::announcesSomethingNew($acts)) {
                return null;
            }
            if (!self::endsTheLine($text, $end) && !self::opensAStatement($text, $end)) {
                return null;
            }
        }

        return array(
            'verbatim' => substr($text, $start, $end - $start),
            'acts' => $acts,
            'start' => $start,
            'end' => $end,
        );
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
     * @param string      $type      'new', 'act', 'cite' or 'join'
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
            if (isset(self::$acts[$lower]) || self::isNewMarker($word)) {
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

    /** Is the next word an act, or the marker that makes one? */
    private static function actFollows(array $matches, $index)
    {
        if (!isset($matches[$index])) {
            return false;
        }
        $word = $matches[$index][0];
        return isset(self::$acts[strtolower($word)]) || self::isNewMarker($word);
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
