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
        if ($nomenclature !== null) {
            $annotation['nomenclature'] = $nomenclature;
        }
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
