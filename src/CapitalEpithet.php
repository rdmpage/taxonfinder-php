<?php
/**
 * Takes in a capitalised specific epithet that the parser stopped short of.
 *
 * Botanical writing before the middle of the last century capitalises an
 * epithet built on a person's name, and often one built on an older generic
 * or vernacular name as well:
 *
 *   Ornithocephalus Lankesteri Ames, Sched. Orch. 3: 24. 1923.
 *   Lockhartia Integra A. & S. Sched. Orch. 10: 108. 1930.
 *   Pinus Roxburghii Sarg.
 *   Cedrus Deodara Loud.
 *
 * Parser::scoreSpecies() refuses a candidate holding a capital, which is what
 * stops 'Amanita MUSCARIA' being read the wrong way round, and it takes these
 * with it. The genus is reported on its own and the species being described
 * is lost - along with any nomenclatural act, which is then filed against the
 * genus.
 *
 * The rule cannot simply be relaxed. Across the documents here, 293 places
 * read as a genus followed by a capitalised known epithet with no act behind
 * them, and only 84 of those are a name: 'Nusa Tenggara' 36 times, 'Costa
 * Rica', 'South America', 'Sierra Madre', and, far more often than any place
 * name, a genus quoted with its own author - 'Scopodes Erichson',
 * 'Carpoapseudes Lang', 'Gulella Pfeiffer'. Letting them through would cost
 * more than it returned.
 *
 * Four things part the names from the rest.
 *
 * The first word is a genus. Not a family or an order: 'Xenophoridae
 * Philippi' and 'Gastropoda Pulmonata' are a family with its author and a
 * heading, and a family is never the first half of a binomen anyway. This is
 * the parser's own test, because family.txt holds Pinus and Malus and taking
 * mere membership for the rank would lose every Pinus here.
 *
 * The epithet ends like a Latin word. Author surnames mostly do not -
 * Erichson, Lang, Baker, Hooker, Pfeiffer, Meigen, Dejean, Schrank, Walker -
 * and this one test cuts the 122 strings that are not names down to 29 while
 * keeping every one of the 52 that are.
 *
 * The genus is not being introduced as a genus. 'A new species of the genus
 * Pogonus Nicolai' is a genus and its author, and the word before says so.
 *
 * And something confirms it: either a nomenclatural act, which nobody writes
 * after a country or an author, or an author citation of the epithet's own -
 * 'Ames, Sched.', 'Sarg.', '(Stev.) Spach', 'L. Wms.,'. What follows a genus
 * quoted with its author is a year, a rank, or prose, and none of those pass
 * for a citation.
 *
 * Confirmation is then carried across the document: a paper that writes
 * 'Pinus Wallichiana A. B. Jack' once is writing the same name at 'à Pinus
 * Wallichiana on peut avancer', where nothing local would vouch for it.
 *
 * A place name that gets past all four belongs in dict_bad.txt, which is
 * where 'Nusa tenggara' now is.
 *
 * Together they widen 82 places across these documents, against 10 before,
 * and 77 of the 82 are right. The five that are not are three strings, every
 * one of them a line in a bibliography: 'Flora Agaricina Neerlandica 1',
 * 'Plantae Vasculares Orientis Extremi Sovietiei' and 'Serropeltis Poppius,
 * Ann. Mus. Nat. Hung.'
 */

namespace Taxonfinder;

class CapitalEpithet
{
    /** Terminations a Latin epithet takes, and a surname generally does not. */
    const LATIN = '/(?:ae|ii|ia|is|um|us|[aio])$/D';

    /** The word before a genus that says it is being named as a genus. */
    const RANK_BEFORE = '/\b(?:gen(?:us|era|re|ero)?|g\xc3\xa9nero|subgenus|sous-genre|
                            section|sect|family|famille|familia|subfamily|tribe|tribu)
                         [\.\s]*$/ix';

    /** Lowercase words that may stand inside an author citation. */
    private static $connecting = array(
        'ex' => true, 'et' => true, 'in' => true, 'and' => true, 'und' => true,
        'von' => true, 'van' => true, 'de' => true, 'del' => true, 'der' => true,
        'da' => true, 'dos' => true, 'f' => true, 'emend' => true, 'non' => true,
        'nec' => true, 'apud' => true,
    );

    /**
     * Widen any annotation that stopped at the genus, in place.
     *
     * @param string        $text
     * @param Dictionaries  $dictionaries
     * @param array         $annotations  modified in place
     * @return array the indexes widened, so the caller can rebuild them
     */
    public static function extend($text, Dictionaries $dictionaries, array &$annotations)
    {
        $candidates = array();
        $confirmed = array();
        foreach ($annotations as $index => $annotation) {
            $candidate = self::candidateAt($text, $dictionaries, $annotation);
            if ($candidate === null) {
                continue;
            }
            $candidates[$index] = $candidate;
            if ($candidate['confirmed']) {
                $confirmed[$candidate['name']] = true;
            }
        }

        $widened = array();
        foreach ($candidates as $index => $candidate) {
            // Either this place vouches for the name, or another place in the
            // same document already did.
            if (!$candidate['confirmed'] && !isset($confirmed[$candidate['name']])) {
                continue;
            }
            $start = $annotations[$index]['target']['selector'][1]['start'];
            $annotations[$index]['body']['value'] = $candidate['name'];
            $annotations[$index]['target']['selector'][1]['end'] = $candidate['end'];
            $annotations[$index]['target']['selector'][0]['exact'] =
                substr($text, $start, $candidate['end'] - $start);
            $widened[] = $index;
        }
        return $widened;
    }

    /**
     * The name this annotation could be widened to, and whether anything here
     * vouches for it.
     *
     * @return array|null array('name', 'end', 'confirmed')
     */
    private static function candidateAt($text, Dictionaries $dictionaries, array $annotation)
    {
        $genus = $annotation['body']['value'];
        if (strpos($genus, ' ') !== false) {
            return null;
        }
        if (!self::isGenus($dictionaries, $genus)) {
            return null;
        }
        $position = $annotation['target']['selector'][1];
        if (preg_match(self::RANK_BEFORE, substr($text, max(0, $position['start'] - 24),
                min(24, $position['start'])))) {
            return null;                                  // 'the genus Pogonus Nicolai'
        }
        $after = (string) substr($text, $position['end'], 90);
        if (!preg_match('/^[ \t]*([A-Z][a-z]{3,})\b/', $after, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $word = $match[1][0];
        $epithet = Utility::lower($word);
        if (!preg_match(self::LATIN, $epithet)) {
            return null;
        }
        if (!self::isEpithet($dictionaries, $epithet)) {
            return null;
        }
        $name = $genus . ' ' . $epithet;
        if ($dictionaries->has('dict_bad', Utility::lower($name))) {
            return null;
        }
        $beyond = substr($after, $match[1][1] + strlen($word));
        if (preg_match('/^[^0-9A-Za-z]{0,3}1[6-9][0-9]{2}\b/', $beyond)) {
            return null;                                  // 'Pipizella Rondani, 1856'
        }
        return array(
            'name' => $name,
            'end' => $position['end'] + $match[1][1] + strlen($word),
            'confirmed' => isset($annotation['nomenclature'])
                || self::authorFollows($beyond),
        );
    }

    /** What Parser::scoreGenus() calls a genus, rather than a family or above. */
    private static function isGenus(Dictionaries $dictionaries, $word)
    {
        $lower = Utility::lower($word);
        return ($dictionaries->has('genera', $lower) || $dictionaries->has('genera_new', $lower))
            && !$dictionaries->has('genera_family', $lower);
    }

    /** A known epithet, and nothing else a capitalised word could be. */
    private static function isEpithet(Dictionaries $dictionaries, $lower)
    {
        if (!$dictionaries->has('species', $lower) && !$dictionaries->has('species_new', $lower)) {
            return false;
        }
        // Two genera side by side are a list, not a name.
        if ($dictionaries->has('genera', $lower) || $dictionaries->has('genera_new', $lower)) {
            return false;
        }
        return !$dictionaries->has('species_bad', $lower)
            && !$dictionaries->has('overlap_new', $lower)
            && !$dictionaries->has('family', $lower)
            && !$dictionaries->has('family_new', $lower);
    }

    /**
     * Does an author citation stand right after the epithet?
     *
     * 'Sarg.', 'Ames, Sched.', '(Stev.) Spach', 'L. Wms.,', 'Kiefer, 1938'.
     * An abbreviation settles it at once; a surname spelled out in full does
     * not, so at most three of them are read before giving up, and prose or a
     * bare year ends the scan. The citation has to start on the name's own
     * line, which is what stops the last word of a heading reaching into the
     * paragraph below it.
     */
    private static function authorFollows($beyond)
    {
        preg_match('/^([^\n]{0,70})/', $beyond, $line);
        $line = $line[1];
        preg_match('/^[ \t]*,?[ \t]*/', $line, $lead);
        $offset = strlen($lead[0]);
        $length = strlen($line);
        $surnames = 0;
        while ($offset < $length && $surnames < 3) {
            $rest = substr($line, $offset);
            if ($rest[0] === '(') {
                // '(L.)', '(Stev.)', '(P. Danguy)' - but not '(Coleoptera:
                // Carabidae)', '(ca. 75 spp.)' or the '(US)' of a table.
                return (bool) preg_match(
                    '/^\((?:(?:[A-Z]\.|[A-Z][a-z][A-Za-z\x80-\xFF\'\-]*\.?)[ ,]*)+\)/', $rest);
            }
            if (preg_match('/^1[6-9][0-9]{2}\b/', $rest)) {
                return $surnames > 0;                     // 'Kiefer, 1938'
            }
            if (!preg_match('/^([A-Za-z][A-Za-z\x80-\xFF\'\-]*)(\.?)/', $rest, $token)) {
                return false;
            }
            $offset += strlen($token[0]);
            if (preg_match('/^[a-z]/', $token[1])) {
                if (!isset(self::$connecting[strtolower($token[1])])) {
                    return false;                         // prose ends it
                }
            } elseif ($token[2] === '.') {
                return true;                              // 'Sarg.', 'L.', 'Sched.'
            } else {
                $surnames++;
            }
            preg_match('/^[ \t]*[,&]?[ \t]*/', substr($line, $offset), $gap);
            $offset += strlen($gap[0]);
        }
        return false;
    }
}
