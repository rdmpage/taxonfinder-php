<?php
/**
 * Takes in a capitalised specific epithet that the parser stopped short of.
 *
 * Botanical writing before the middle of the last century capitalises an
 * epithet built on a person's name:
 *
 *   Boscia Plantefolii Hadj Moust. sp. nov.
 *   Cleome Perrieri Hadj Moust. sp. nov.
 *
 * Parser::scoreSpecies() refuses a candidate holding a capital, which is what
 * stops 'Amanita MUSCARIA' being read the wrong way round, and it takes these
 * with it. The act still attaches, but to the genus on its own, so the species
 * being described is lost and the act is filed against the wrong name.
 *
 * The rule cannot simply be relaxed. Across the documents here, 575 places
 * read as a genus followed by a capitalised known epithet without being
 * anything of the kind: 'Costa Rica' 79 times, 'South America' 66, 'South
 * Africa' 58, 'Sierra Madre', 'Santa Cruz', 'Index Fungorum' - Costa is a
 * genus and rica an epithet, and so on down the list. Letting them through
 * would cost far more than it returned.
 *
 * Two things separate the ten real ones from the rest.
 *
 * An act follows. Nobody writes 'sp. nov.' after a country, and the whole
 * reason for wanting these is that an act was filed against a bare genus.
 * Only twelve places in the corpus have one, against 575 that do not.
 *
 * And the epithet is a patronym. Every real one here ends in -i or -ii, which
 * is what an epithet made from a name looks like; the convention being
 * relaxed was for personal names in the first place. 'America' is not one, and
 * that is what parts the last false case from the ten true ones.
 */

namespace Taxonfinder;

class CapitalEpithet
{
    /** Endings that mark an epithet made out of somebody's name. */
    const PATRONYM = '/(?:ii|i|ae|iana|ianum|ianus)$/D';

    /**
     * Widen any annotation that stopped at the genus, in place.
     *
     * @param string        $text
     * @param Dictionaries  $dictionaries
     * @param array         $annotations  modified in place
     * @return int how many were widened
     */
    public static function extend($text, Dictionaries $dictionaries, array &$annotations)
    {
        $widened = 0;
        foreach ($annotations as $index => $annotation) {
            // Only a bare genus, and only where an act was filed against it.
            if (!isset($annotation['nomenclature'])) {
                continue;
            }
            if (strpos($annotation['body']['value'], ' ') !== false) {
                continue;
            }
            $position = $annotation['target']['selector'][1];
            $epithet = self::epithetAfter($text, $position['end'], $dictionaries);
            if ($epithet === null) {
                continue;
            }
            list($word, $end) = $epithet;
            $annotations[$index]['body']['value'] .= ' ' . Utility::lower($word);
            $annotations[$index]['target']['selector'][1]['end'] = $end;
            $annotations[$index]['target']['selector'][0]['exact'] =
                substr($text, $position['start'], $end - $position['start']);
            $widened++;
        }
        return $widened;
    }

    /**
     * The capitalised epithet standing just after $offset, if there is one.
     *
     * @return array|null array($word, $end)
     */
    private static function epithetAfter($text, $offset, Dictionaries $dictionaries)
    {
        if (!preg_match('/^[ \t]*([A-Z][a-z]{3,})\b/',
                (string) substr($text, $offset, 40), $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $word = $match[1][0];
        $lower = Utility::lower($word);
        if (!preg_match(self::PATRONYM, $lower)) {
            return null;
        }
        // A known epithet, and not a genus - two genera side by side are a
        // list, not a name.
        if (!$dictionaries->has('species', $lower) && !$dictionaries->has('species_new', $lower)) {
            return null;
        }
        if ($dictionaries->has('genera', $lower) || $dictionaries->has('genera_new', $lower)) {
            return null;
        }
        if ($dictionaries->has('species_bad', $lower)) {
            return null;
        }
        return array($word, $offset + $match[1][1] + strlen($word));
    }
}
