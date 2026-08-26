<?php
/**
 * Carries the genus of a section heading down to the bare epithets beneath it.
 *
 * A key, or a run of numbered entries under a genus, prints the epithet on its
 * own and leaves the genus to the heading above:
 *
 *   Key to Species of Criotettix.
 *   ...
 *   10.— Griotettix spinilobus , sp. nov.
 *   ... obliquely forward. spinilobus , sp. nov.
 *
 * The parser sees no name on that last line at all, so the 'sp. nov.' has
 * nothing to attach to and the act is lost. This finds those and reports
 * 'Criotettix spinilobus'.
 *
 * Two things keep it honest.
 *
 * The genus comes from the heading - 'Genus X', 'Subgenus X', 'Key to Species
 * of X' - and not from whichever genus happens to be nearest. Keys describe
 * their species by comparing them with others, so the nearest genus is often
 * one merely mentioned in passing: in the volume this was written for, the
 * nearest genus to 'logani , sp. nov.' is Scelimena and to one 'acutus , sp.
 * nov.' is Lamellitettix, and both belong to Cladonotus. Anchoring on
 * proximity would have invented two species that do not exist.
 *
 * And an epithet only counts when a nomenclatural act follows it. Descriptions
 * are full of Latin adjectives that are also epithets - robusta, elongata,
 * truncate - so without that signal this would attach a genus to half the
 * prose in the book. 'sp. nov.' after the word means the line is an entry.
 */

namespace Taxonfinder;

class KeyGenus
{
    /** Words that introduce the genus a section is about. */
    private static $headings = array('genus', 'subgenus');

    /**
     * Names the parser missed because only the epithet was printed.
     *
     * @param string       $text
     * @param Dictionaries $dictionaries
     * @param array        $covered  byte ranges already carrying a name, as
     *                               array(array(start, end), ...)
     * @return array list of array('name' => ..., 'offsets' => array(start, end))
     */
    public static function find($text, Dictionaries $dictionaries, array $covered)
    {
        $text = (string) $text;
        $words = self::realWords($text);
        $found = array();

        $sectionGenus = null;
        $window = array();
        $run = array();
        $previous = null;

        foreach ($words as $entry) {
            $clean = Utility::clean($entry['word']);
            $lower = Utility::lower($clean);

            if (self::opensSection($text, $previous, $entry, $window)) {
                // A section we cannot read the genus of still ends the one
                // before it. Keeping the old genus would hand this section's
                // epithets to the previous section's genus.
                $sectionGenus = self::isUsableGenus($clean, $lower, $dictionaries)
                    ? $clean : null;
                $run = array();
                $previous = $entry;
                self::remember($window, $lower);
                continue;
            }

            // An epithet is lowercase and in the species dictionary. Consecutive
            // ones run together, so 'atypicalis ceylonus' is read as one.
            if (self::isEpithet($clean, $lower, $dictionaries)) {
                $run[] = $entry;
            } else {
                $run = array();
            }
            self::remember($window, $lower);
            $previous = $entry;

            if (!$run || $sectionGenus === null) {
                continue;
            }

            $last = $run[count($run) - 1];
            $end = $last['offset'] + strlen($last['word']);
            if (!self::newnessFollows($text, $end)) {
                continue;
            }
            $start = $run[0]['offset'];
            if (self::overlaps($covered, $start, $end)) {
                $run = array();
                continue;
            }
            $epithets = array();
            foreach ($run as $word) {
                $epithets[] = Utility::lower(Utility::clean($word['word']));
            }
            $found[] = array(
                'name' => $sectionGenus . ' ' . implode(' ', $epithets),
                'offsets' => array($start, $end),
            );
            $run = array();
        }
        return $found;
    }

    /** The words, without the punctuation and whitespace explodeText keeps. */
    private static function realWords($text)
    {
        $words = array();
        foreach (Utility::explodeText($text) as $entry) {
            if (!isset($entry['word']) || $entry['word'] === null) {
                continue;
            }
            if (Utility::clean($entry['word']) === '') {
                continue;
            }
            $words[] = $entry;
        }
        return $words;
    }

    /**
     * Is this word where a section announces the genus it is about?
     *
     *   Genus Acanthalobus, Hanc.
     *   Key to Species of Acanthalobus.
     *
     * 'genus' is also an ordinary word, and one that often ends a sentence -
     * '... belonging to an undescribed genus. In the following ...' - so the
     * two have to be part of the same sentence. Without that test the word
     * opening the next sentence is read as the heading's genus, and In, This
     * and their like all sit in the genus dictionary.
     */
    private static function opensSection($text, $previous, array $entry, array $window)
    {
        if ($previous === null) {
            return false;
        }
        $clean = Utility::clean($entry['word']);
        if (!preg_match('/^[A-Z][a-z]+$/D', $clean)) {
            return false;
        }
        $before = Utility::lower(Utility::clean($previous['word']));
        $isHeading = in_array($before, self::$headings, true)
            // 'Key to Species of X' - 'of' alone is far too common to trust.
            || ($before === 'of' && in_array('key', $window, true));
        if (!$isHeading) {
            return false;
        }
        $gap = substr($text, $previous['offset'] + strlen($previous['word']),
            $entry['offset'] - $previous['offset'] - strlen($previous['word']));
        return strpos($gap, '.') === false;
    }

    /**
     * A genus we are willing to hand to an epithet that never names one. It
     * has to be in the dictionary, so a heading the OCR has damaged is passed
     * over rather than guessed at, and it must not be an everyday word.
     */
    private static function isUsableGenus($clean, $lower, Dictionaries $dictionaries)
    {
        if (!$dictionaries->has('genera', $lower) && !$dictionaries->has('genera_new', $lower)) {
            return false;
        }
        return !$dictionaries->has('overlap_new', $lower)
            && !$dictionaries->has('dict_ambig', $lower);
    }

    /**
     * Is what follows an act announcing something new - 'sp. nov.', 'form.
     * nov.', 'n. sp.'?
     *
     * A bare act will not do here. 'species', 'genus' and 'variety' are all
     * act words that stand alone, and all ordinary English besides, so 'a
     * remarkably constant species' and 'an undescribed genus' would otherwise
     * be read as entries in a key. Naming something new is the part that only
     * happens in a formal entry.
     */
    private static function newnessFollows($text, $offset)
    {
        $nomenclature = Nomenclature::detect($text, $offset);
        if ($nomenclature === null) {
            return false;
        }
        foreach ($nomenclature['acts'] as $act) {
            if (substr($act, -4) === 'nov.') {
                return true;
            }
        }
        return false;
    }

    private static function isEpithet($clean, $lower, Dictionaries $dictionaries)
    {
        if (!preg_match('/^[a-z][a-z\-]+$/D', $clean)) {
            return false;
        }
        if ($dictionaries->has('species_bad', $lower)) {
            return false;
        }
        return $dictionaries->has('species', $lower) || $dictionaries->has('species_new', $lower);
    }

    /** A short memory of the words just gone, for reading a heading. */
    private static function remember(array &$window, $lower)
    {
        $window[] = $lower;
        if (count($window) > 6) {
            array_shift($window);
        }
    }

    private static function overlaps(array $covered, $start, $end)
    {
        foreach ($covered as $range) {
            if ($start < $range[1] && $end > $range[0]) {
                return true;
            }
        }
        return false;
    }
}
