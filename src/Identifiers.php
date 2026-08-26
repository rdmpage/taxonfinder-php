<?php
/**
 * Finds the registry identifiers printed beside a nomenclatural act.
 *
 * A modern taxonomic paper registers its acts and prints the identifier under
 * the heading of the new taxon:
 *
 *   Gulella hadroglossa sp. nov.
 *   urn:lsid:zoobank.org:act:DDCAA18B-CC50-4EC1-B63B-28ABAE6904C2
 *
 * That identifier is worth more than the name it sits under: it is the act's
 * key in a registry, so an annotation carrying one can be checked rather than
 * believed.
 *
 * Two kinds are read. An LSID of any authority, which covers ZooBank for
 * animals and IPNI for plants; and the prefixed number the three fungal
 * registries use instead - MycoBank, Index Fungorum and Fungal Names.
 *
 * The OCR fights back. In one paper 'urn:lsid:zoobank.org:act:' appears also
 * as 'urn: lsid:zoobank.org :' and 'urn:lsid:zoobank.org: act:', so space is
 * allowed around every colon, and a line break with it - a UUID is 36
 * characters and does not always fit. Within a UUID the scanner reads l and I
 * for 1 and O for 0, which is safe to put right because the shape is fixed
 * and the alphabet is hex: 'CC50-4ECl' can only have been 'CC50-4EC1'. The
 * repair is only made when it turns something invalid into something valid,
 * and the source text is always recoverable from the offsets.
 */

namespace Taxonfinder;

class Identifiers
{
    /** How far after a name an identifier may sit and still belong to it. */
    const REACH = 160;

    /** Length of a UUID written out: 8-4-4-4-12. */
    const UUID = 36;

    /**
     * Every identifier in $text, in order.
     *
     * @return array list of array('scheme', 'type', 'value', 'verbatim',
     *                             'start', 'end')
     */
    public static function find($text)
    {
        $found = array();
        foreach (self::lsids($text) as $identifier) {
            $found[] = $identifier;
        }
        foreach (self::fungalRegistries($text) as $identifier) {
            $found[] = $identifier;
        }
        usort($found, function ($a, $b) {
            return $a['start'] - $b['start'];
        });
        return $found;
    }

    /**
     * urn:lsid:<authority>:<namespace>:<object>[:<revision>]
     *
     * Space and line breaks are tolerated around the colons because the OCR
     * puts them there.
     */
    private static function lsids($text)
    {
        $gap = '[ \t\r\n]*';
        $pattern = '/urn' . $gap . ':' . $gap . 'lsid' . $gap . ':' . $gap
            . '([A-Za-z0-9][A-Za-z0-9.\-]*)' . $gap . ':' . $gap
            . '([A-Za-z0-9][A-Za-z0-9_\-]*)' . $gap . ':' . $gap
            . '([A-Za-z0-9][A-Za-z0-9.\-]*)'
            . '(?:' . $gap . ':' . $gap . '([0-9]+))?/i';

        $found = array();
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $found;
        }
        foreach ($matches as $match) {
            $authority = strtolower(self::flatten($match[1][0]));
            $namespace = strtolower(self::flatten($match[2][0]));
            $end = $match[0][1] + strlen($match[0][0]);

            // The object is usually a UUID, and the scanner breaks it apart:
            // '0C09EE45-6198-482E-85 7A-EF690C2 AO 16F' is one identifier in
            // four pieces. Gather the pieces back while they still spell a
            // UUID, which is what says where to stop.
            $object = self::flatten($match[3][0]);
            if (self::isUuidPrefix(self::repairChars($object)) && strlen($object) < self::UUID) {
                list($object, $end) = self::gatherUuid($text, $object, $end);
            }
            $object = self::repairUuid($object);

            $value = 'urn:lsid:' . $authority . ':' . $namespace . ':' . $object;
            if (isset($match[4]) && $match[4][0] !== '') {
                $value .= ':' . $match[4][0];
            }
            $found[] = array(
                'scheme' => self::scheme($authority),
                'type' => $namespace,
                'value' => $value,
                'verbatim' => substr($text, $match[0][1], $end - $match[0][1]),
                'start' => $match[0][1],
                'end' => $end,
            );
        }
        return $found;
    }

    /**
     * The three registries a new fungal name may be published in: MycoBank,
     * Index Fungorum and Fungal Names. All are cited as a prefix and a
     * number, and Mycotaxon brackets them in its index of new taxa -
     * 'Blastophragmia Jian Ma, ... & R.F. Castaneda [IF 557506], p. 168'.
     *
     * The prefix alone will not do. 'IF' is an English word, 'MB' is a museum
     * accession as often as a MycoBank number, and 'FN' is anyone's guess. So
     * a number is only read where the brackets say it is a citation, or the
     * registry is spelled out beside it.
     */
    private static function fungalRegistries($text)
    {
        $registries = array(
            'mb' => 'mycobank',
            'if' => 'indexfungorum',
            'fn' => 'fungalnames',
        );
        $spelled = 'MycoBank|Index[ \t\r\n]*Fungorum|Fungal[ \t\r\n]*Names';
        $pattern = '/(\[)?[ \t]*(MB|IF|FN)[ \t\r\n#]*([0-9]{5,7})\b[ \t]*(\])?/i';

        $found = array();
        if (!preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return $found;
        }
        foreach ($matches as $match) {
            $prefix = $match[2][0];
            $digits = $match[3][0];
            $key = strtolower($prefix);
            if (!isset($registries[$key])) {
                continue;
            }
            // The prefix alone means nothing. Something has to say this is a
            // registry citation: the brackets an index puts round it, the
            // registry spelled out in front, or the act it belongs to.
            $bracketed = $match[1][0] === '[' && isset($match[4]) && $match[4][0] === ']';
            $named = (bool) preg_match('/(?:' . $spelled . ')[ \t\r\n]*(?:no\.?)?[ \t\r\n]*$/i',
                (string) substr($text, max(0, $match[0][1] - 30), min(30, $match[0][1])));
            if (!$bracketed && !$named
                && !Nomenclature::actEndsBefore($text, $match[0][1])) {
                continue;
            }
            $found[] = array(
                'scheme' => $registries[$key],
                'type' => 'name',
                'value' => strtoupper($prefix) . $digits,
                'verbatim' => $match[0][0],
                'start' => $match[0][1],
                'end' => $match[0][1] + strlen($match[0][0]),
            );
        }
        return $found;
    }

    /**
     * Take in the pieces of a UUID the scanner has broken apart, stopping the
     * moment it is whole. Nothing else is read: every piece has to keep the
     * thing a UUID, so a following word cannot be swallowed.
     *
     * @return array array($object, $end)
     */
    private static function gatherUuid($text, $object, $end)
    {
        for ($piece = 0; $piece < 8 && strlen($object) < self::UUID; $piece++) {
            if (!preg_match('/^[ \t\r\n]*([0-9A-Za-z\-]+)/',
                    (string) substr($text, $end, 80), $match)) {
                break;
            }
            $longer = $object . $match[1];
            if (strlen($longer) > self::UUID
                || !self::isUuidPrefix(self::repairChars($longer))) {
                break;
            }
            $object = $longer;
            $end += strlen($match[0]);
        }
        return array($object, $end);
    }

    /** Hex where a UUID wants hex, a hyphen where it wants one, so far. */
    private static function isUuidPrefix($string)
    {
        $length = strlen($string);
        if ($length > self::UUID) {
            return false;
        }
        for ($i = 0; $i < $length; $i++) {
            $hyphen = ($i === 8 || $i === 13 || $i === 18 || $i === 23);
            if ($hyphen ? $string[$i] !== '-' : !ctype_xdigit($string[$i])) {
                return false;
            }
        }
        return true;
    }

    /** l and I read for 1, O for 0. */
    private static function repairChars($string)
    {
        return strtr($string, array('l' => '1', 'I' => '1', 'O' => '0', 'o' => '0'));
    }

    /** The registry behind an LSID authority, where we know it. */
    private static function scheme($authority)
    {
        $known = array(
            'zoobank.org' => 'zoobank',
            'ipni.org' => 'ipni',
            'indexfungorum.org' => 'indexfungorum',
            'organismnames.com' => 'ion',
            'marinespecies.org' => 'worms',
        );
        return isset($known[$authority]) ? $known[$authority] : $authority;
    }

    /** Take out the space and line breaks the OCR put inside a token. */
    private static function flatten($string)
    {
        return preg_replace('/[ \t\r\n]+/', '', $string);
    }

    /**
     * 8-4-4-4-12 hex. The scanner reads l and I for 1 and O for 0; putting
     * those back is safe because the alphabet is hex and cannot hold them.
     * Anything that is not a UUID, or does not become a valid one, is left
     * exactly as it was found.
     */
    private static function repairUuid($object)
    {
        $shape = '/^[0-9A-Za-z]{8}-[0-9A-Za-z]{4}-[0-9A-Za-z]{4}-[0-9A-Za-z]{4}-[0-9A-Za-z]{12}$/D';
        if (!preg_match($shape, $object)) {
            return $object;
        }
        if (preg_match('/^[0-9A-Fa-f\-]+$/D', $object)) {
            return $object;
        }
        $repaired = self::repairChars($object);
        return preg_match('/^[0-9A-Fa-f\-]+$/D', $repaired) ? $repaired : $object;
    }
}
