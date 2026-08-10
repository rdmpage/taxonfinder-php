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
    const WINDOW = 48;

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
        'combination' => array('comb.', false),
        'synonym'     => array('syn.', false),
        'status'      => array('stat.', false),
        'name'        => array('nom.', false),
        'subspecies'  => array('subsp.', false),
        'variety'     => array('var.', false),
        'family'      => array('fam.', false),
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
        $tokens = self::tokens($text, $offset);
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
     * The run of words after $offset that could form an annotation. Stops at
     * the first word that is not annotation vocabulary, and at anything other
     * than punctuation and space between words - so a digit or another word
     * ends the run.
     *
     * @return array list of array('word' => string, 'offset' => int)
     */
    private static function tokens($text, $offset)
    {
        $window = substr($text, $offset, self::WINDOW);
        if ($window === false || $window === '') {
            return array();
        }
        if (!preg_match_all('/[A-Za-z]+/', $window, $matches, PREG_OFFSET_CAPTURE)) {
            return array();
        }
        $tokens = array();
        $cursor = 0;
        foreach ($matches[0] as $match) {
            list($word, $position) = $match;
            // Punctuation and space only between the words. A digit or any
            // other word ends the run.
            $gap = substr($window, $cursor, $position - $cursor);
            if (!preg_match('/^[^0-9A-Za-z]*$/D', $gap)) {
                break;
            }
            $lower = strtolower($word);
            if (!isset(self::$acts[$lower]) && !self::isNewMarker($word)) {
                break;
            }
            $tokens[] = array('word' => $word, 'offset' => $offset + $position);
            $cursor = $position + strlen($word);
        }
        return $tokens;
    }
}
