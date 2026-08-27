<?php
/**
 * Turns the parser's findings into annotation records, loosely modelled on the
 * W3C Web Annotation Data Model.
 *
 *   {
 *     "type": "Annotation",
 *     "body": { "type": "TextualBody", "purpose": "identifying",
 *               "value": "Pomatomus saltator" },
 *     "target": { "selector": [
 *       { "type": "TextQuoteSelector",
 *         "prefix": "Pomatomus; ", "exact": "P. saltator", "suffix": "" },
 *       { "type": "TextPositionSelector", "start": 11, "end": 22 }
 *     ]},
 *     "nomenclature": { "verbatim": "sp. n.", "acts": ["sp. nov."],
 *                       "start": 24, "end": 30 }
 *   }
 *
 * body.value is the interpreted name, with abbreviated genera expanded and
 * capitalisation normalised. The TextQuoteSelector's exact is the original
 * string, exactly as it appears in the source, with enough surrounding text to
 * find it again if the offsets go stale. nomenclature is only present when an
 * annotation follows the name, and covers its own separate span.
 */

namespace Taxonfinder;

class Annotator
{
    /** @var Parser */
    private $parser;

    /** @var int bytes of context either side in the TextQuoteSelector */
    private $contextLength;

    /** @var bool carry a section heading's genus down to bare epithets */
    private $carryOverKeyGenus = false;

    public function __construct(?Parser $parser = null, $contextLength = 32)
    {
        $this->parser = $parser === null ? new Parser() : $parser;
        $this->setContextLength($contextLength);
    }

    /** How much context to put either side of the quote. */
    public function setContextLength($contextLength)
    {
        $this->contextLength = max(0, (int) $contextLength);
        return $this;
    }

    public function contextLength()
    {
        return $this->contextLength;
    }

    /**
     * Whether to carry the genus of a section heading down to the bare
     * epithets beneath it. See KeyGenus. Off by default: it reports names
     * that are not written out anywhere in the text, which is a bigger claim
     * than the rest of the parser makes, and it only fires where a
     * nomenclatural act says a line is an entry in a key.
     */
    public function setCarryOverKeyGenus($carryOver)
    {
        $this->carryOverKeyGenus = (bool) $carryOver;
        return $this;
    }

    public function carryOverKeyGenus()
    {
        return $this->carryOverKeyGenus;
    }

    /**
     * @param string $text
     * @param bool   $isHtml
     * @return array list of annotation records
     */
    public function annotate($text, $isHtml = false)
    {
        $text = (string) $text;
        $length = strlen($text);
        $annotations = array();

        $covered = array();
        foreach ($this->parser->findNamesAndOffsets($text, $isHtml) as $result) {
            list($start, $end) = $result['offsets'];
            // Names in comma separated lists can be reported with an end
            // offset past the end of the text; keep the span inside it.
            $start = max(0, min($start, $length));
            $end = max($start, min($end, $length));
            $covered[] = array($start, $end);
            $annotations[] = $this->record($text, $result['name'], $start, $end);
        }

        if ($this->carryOverKeyGenus) {
            $carried = KeyGenus::find($text, $this->parser->dictionaries(), $covered);
            foreach ($carried as $result) {
                list($start, $end) = $result['offsets'];
                $annotations[] = $this->record($text, $result['name'], $start, $end);
            }
            if ($carried) {
                usort($annotations, function ($a, $b) {
                    return $a['target']['selector'][1]['start']
                         - $b['target']['selector'][1]['start'];
                });
            }
        }

        // Before the identifiers, so one lands on the whole name rather than
        // the genus the parser stopped at.
        CapitalEpithet::extend($text, $this->parser->dictionaries(), $annotations);

        // Last, so that it reads the finished name. 'sp. nov.' on a genus
        // alone publishes nothing, but the passes above are what turn a bare
        // genus into the binomen the act really sits on - do this before them
        // and every name they complete is judged on what it used to be.
        foreach ($annotations as $index => $annotation) {
            if (isset($annotation['nomenclature'])) {
                $annotations[$index]['nomenclature'] = Nomenclature::readAgainstName(
                    $annotation['nomenclature'], $annotation['body']['value']);
            }
        }

        // Last of all, once the passes above have finished the names.
        foreach ($annotations as $index => $annotation) {
            $annotations[$index] = $this->settleName($text, $annotation);
        }

        self::attachIdentifiers($text, $annotations);
        return $annotations;
    }

    /**
     * Hand each registry identifier to the annotation it sits under.
     *
     * Worked from the identifier back to the name rather than from each name
     * forward, because a name is repeated all through a paper - the one in
     * this test appears eight times - and searching forward would give every
     * mention the same LSID. There is one identifier and it belongs to the
     * act it is printed beneath, which is the nearest name before it.
     */
    private static function attachIdentifiers($text, array &$annotations)
    {
        $identifiers = Identifiers::find($text);
        if (!$identifiers || !$annotations) {
            return;
        }
        foreach ($identifiers as $identifier) {
            $best = null;
            $nearest = null;
            foreach ($annotations as $index => $annotation) {
                // An act sits between the name and its identifier, so the
                // reach is measured from whichever of them ends later.
                $end = $annotation['target']['selector'][1]['end'];
                if (isset($annotation['nomenclature'])) {
                    $end = max($end, $annotation['nomenclature']['end']);
                }
                if ($end > $identifier['start']) {
                    continue;
                }
                $distance = $identifier['start'] - $end;
                if ($distance <= Identifiers::REACH
                    && ($nearest === null || $distance < $nearest)) {
                    $nearest = $distance;
                    $best = $index;
                }
            }
            if ($best !== null) {
                $annotations[$best]['identifiers'][] = $identifier;
            }
        }
    }

    /** One annotation record for a name found between $start and $end. */
    private function record($text, $name, $start, $end)
    {
        $annotation = array(
            'type' => 'Annotation',
            'body' => array(
                'type' => 'TextualBody',
                'purpose' => 'identifying',
                'value' => $name,
            ),
            'target' => array(
                'selector' => array(
                    array(
                        'type' => 'TextQuoteSelector',
                        'prefix' => $this->prefix($text, $start),
                        'exact' => substr($text, $start, $end - $start),
                        'suffix' => $this->suffix($text, $end),
                    ),
                    array(
                        'type' => 'TextPositionSelector',
                        'start' => $start,
                        'end' => $end,
                    ),
                ),
            ),
        );
        $nomenclature = Nomenclature::detect($text, $end);

        // A qualifier read through to reach the epithet - 'Odontostilbe cf.
        // stenodon' - is inside the name rather than after it, so it is
        // recovered from the span instead of from the text beyond it.
        $inside = Nomenclature::readInterposed(substr($text, $start, $end - $start));
        if ($inside !== null) {
            if ($nomenclature === null) {
                $nomenclature = array(
                    'verbatim' => substr($text, $start + $inside['offset'], $inside['length']),
                    'acts' => array(),
                    'judgments' => array(),
                    'qualifiers' => array(),
                    'start' => $start + $inside['offset'],
                    'end' => $start + $inside['offset'] + $inside['length'],
                );
            }
            // kept apart from the qualifiers that follow the name: this one
            // stands between the genus and the epithet and belongs there
            $nomenclature['interposed'] = $inside['canonical'];
        }

        if ($nomenclature !== null) {
            $annotation['nomenclature'] = $nomenclature;
        }
        return $annotation;
    }

    /**
     * Settle what the name is, and whether it is a formal one.
     *
     * A qualifier of identification stands in for a part of the name, or
     * hedges one - 'Amanita sp.', 'Odontostilbe cf. stenodon',
     * 'Pristiophorus sp. nov.' Each of those says something about the
     * specimen in hand rather than about a name, and none of them denotes a
     * formally published taxon. So the qualifier is kept in the name where
     * the author wrote it, and the name is marked informal.
     *
     * What is left over is a statement about a name that is already whole:
     * that it is new, a synonym, a combination, invalid. Those go in a list
     * of their own.
     *
     * 'formal' says the name is well formed and unhedged. It does not say the
     * text was naming a taxon - 'Discus carnosus' out of a Latin description
     * parses perfectly and is no name at all.
     */
    private function settleName($text, array $annotation)
    {
        $annotation['formal'] = true;
        if (!isset($annotation['nomenclature'])) {
            return $annotation;
        }
        $nomenclature = $annotation['nomenclature'];
        $statements = array_merge($nomenclature['acts'], $nomenclature['judgments']);

        // One standing between the genus and the epithet goes back where the
        // author put it.
        if (isset($nomenclature['interposed'])) {
            $annotation['formal'] = false;
            $words = explode(' ', $annotation['body']['value']);
            array_splice($words, 1, 0, array($nomenclature['interposed']));
            $annotation['body']['value'] = implode(' ', $words);
        }

        // One following the name is taken in, and the span reaches over it -
        // but only where it really follows the name. A blank line between
        // them is a paragraph or a page ending, and what stands after it
        // belongs to whatever comes next: 'ASPIDURA DRUMMONDHAYI.' at the
        // foot of a page took the 'SP' of the running header overleaf.
        $position = $annotation['target']['selector'][1];
        if ($nomenclature['qualifiers']
            && !preg_match('/\n[^\S\n]*\n/',
                substr($text, $position['end'], $nomenclature['start'] - $position['end']))) {
            $annotation['formal'] = false;
            $annotation['body']['value'] .= ' ' . implode(' ', $nomenclature['qualifiers']);
            $annotation['target']['selector'][1]['end'] = $nomenclature['end'];
            $annotation['target']['selector'][0]['exact'] =
                substr($text, $position['start'], $nomenclature['end'] - $position['start']);
            $annotation['target']['selector'][0]['suffix'] = $this->suffix($text, $nomenclature['end']);
        }

        if ($statements) {
            $annotation['statements'] = array(
                'verbatim' => $nomenclature['verbatim'],
                'terms' => $statements,
                'start' => $nomenclature['start'],
                'end' => $nomenclature['end'],
            );
        }
        unset($annotation['nomenclature']);
        return $annotation;
    }

    private function prefix($text, $start)
    {
        $from = max(0, $start - $this->contextLength);
        return self::dropPartialCharacterAtStart(substr($text, $from, $start - $from));
    }

    private function suffix($text, $end)
    {
        return self::dropPartialCharacterAtEnd((string) substr($text, $end, $this->contextLength));
    }

    /**
     * Cutting a fixed number of bytes can land inside a multi-byte character,
     * which would make the JSON invalid. Drop the broken piece.
     */
    private static function dropPartialCharacterAtStart($string)
    {
        for ($i = 0; $i < 3 && $string !== ''; $i++) {
            $byte = ord($string[0]);
            if ($byte < 0x80 || $byte >= 0xC0) {
                break;
            }
            $string = substr($string, 1);
        }
        return $string;
    }

    private static function dropPartialCharacterAtEnd($string)
    {
        if ($string === '' || !function_exists('mb_check_encoding')) {
            return $string;
        }
        for ($i = 0; $i < 3 && $string !== '' && !mb_check_encoding($string, 'UTF-8'); $i++) {
            $string = substr($string, 0, -1);
        }
        return $string;
    }
}
