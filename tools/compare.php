<?php
/**
 * Compare the output of tools/dump.php with the output of tools/dump.js and
 * report where the PHP port and the JavaScript original disagree.
 *
 *   php tools/make-corpus.php > /tmp/corpus.txt
 *   php  tools/dump.php /tmp/corpus.txt > /tmp/php.out
 *   node tools/dump.js  /tmp/corpus.txt > /tmp/node.out
 *   php  tools/compare.php /tmp/corpus.txt /tmp/php.out /tmp/node.out
 *
 * Two differences are expected, and are counted separately rather than being
 * reported as failures:
 *
 *  - When a name is still being built as the text runs out, the JavaScript
 *    reads the end offset off a sentinel that has no offset and returns NaN
 *    (null once through JSON). The PHP port returns the real end offset.
 *  - JavaScript offsets count UTF-16 code units, PHP offsets count bytes, so
 *    they drift apart after a non-ASCII character. Those are checked by
 *    converting the PHP offset to a UTF-16 offset and comparing exactly.
 */

$corpus = file($argv[1], FILE_IGNORE_NEW_LINES);
$php = file($argv[2], FILE_IGNORE_NEW_LINES);
$node = file($argv[3], FILE_IGNORE_NEW_LINES);

$identical = 0;
$nanOnly = 0;
$byteOffsets = 0;
$different = 0;
$shown = 0;

foreach ($php as $i => $line) {
    $nodeLine = isset($node[$i]) ? $node[$i] : '';
    if ($line === $nodeLine) {
        $identical++;
        continue;
    }
    $document = str_replace('\n', "\n", $corpus[$i]);
    $ours = json_decode($line, true);
    $theirs = json_decode($nodeLine, true);
    if (isNanEndOffsetOnly($ours, $theirs)) {
        $nanOnly++;
        continue;
    }
    if (isByteVersusUtf16Offsets($ours, $theirs, $document)) {
        $byteOffsets++;
        continue;
    }
    $different++;
    if ($shown < 25) {
        $shown++;
        echo "--- line ", $i + 1, ": ", $corpus[$i], "\n";
        echo "  php : $line\n";
        echo "  node: $nodeLine\n";
    }
}

echo "\n";
echo "documents      : ", count($php), "\n";
echo "identical      : $identical\n";
echo "NaN end offset : $nanOnly (JavaScript returns NaN, PHP returns the real offset)\n";
echo "byte offsets   : $byteOffsets (same names; PHP counts bytes, JavaScript UTF-16 units)\n";
echo "different      : $different\n";
exit($different === 0 ? 0 : 1);

/**
 * True if the names match and every PHP byte offset converts exactly to the
 * offset JavaScript reported, i.e. the only difference is how offsets count.
 */
function isByteVersusUtf16Offsets($ours, $theirs, $document)
{
    if (!is_array($ours) || !is_array($theirs) || count($ours) !== count($theirs)) {
        return false;
    }
    foreach ($ours as $index => $result) {
        $other = $theirs[$index];
        if ($result[0] !== $other[0] || $result[3] !== $other[3]) {
            return false;
        }
        if (utf16Offset($document, $result[1]) !== $other[1]) {
            return false;
        }
        if ($other[2] !== null && utf16Offset($document, $result[2]) !== $other[2]) {
            return false;
        }
    }
    return true;
}

/** Convert a byte offset into $text to a UTF-16 code unit offset. */
function utf16Offset($text, $byteOffset)
{
    $prefix = substr($text, 0, $byteOffset);
    // One unit per character, and one extra for each character outside the
    // basic multilingual plane (4 byte UTF-8, encoded as a surrogate pair).
    return mb_strlen($prefix, 'UTF-8') + preg_match_all('/[\xF0-\xF4]/', $prefix);
}

/** True if the only differences are end offsets that JavaScript reports as null. */
function isNanEndOffsetOnly($ours, $theirs)
{
    if (!is_array($ours) || !is_array($theirs) || count($ours) !== count($theirs)) {
        return false;
    }
    foreach ($ours as $index => $result) {
        $other = $theirs[$index];
        if ($result[0] !== $other[0] || $result[1] !== $other[1] || $result[3] !== $other[3]) {
            return false;
        }
        if ($result[2] !== $other[2] && $other[2] !== null) {
            return false;
        }
    }
    return true;
}
