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
 * Names are classified one at a time, because a single name can differ in more
 * than one of the expected ways at once. The known, deliberate differences:
 *
 *   byte offsets      JavaScript offsets count UTF-16 code units, PHP offsets
 *                     count bytes. Checked by converting ours to UTF-16 and
 *                     comparing exactly.
 *   NaN end offset    For a name running to the end of the text, JavaScript
 *                     reads the end offset off a sentinel that has none and
 *                     returns NaN. We return the real offset.
 *   leading punct     JavaScript skips one punctuation character before the
 *                     name, which is not enough for 'Upolu :-Vailima' and
 *                     lands mid-character on a multi-byte one. We skip the
 *                     whole run, so the span brackets the name exactly.
 *                     Verified against the document, not against node.
 *   trailing rank     JavaScript strips at most one trailing rank, and only
 *                     when the name ends in exactly 'rank' or 'rank.', so
 *                     'gen. nov.' and OCR debris like 'gen. .' stay attached.
 *                     We strip them all.
 *
 * Exits non-zero if anything else differs.
 */

require __DIR__ . '/../autoload.php';

$dictionaries = taxonfinder()->dictionaries();
$corpus = file($argv[1], FILE_IGNORE_NEW_LINES);
$php = file($argv[2], FILE_IGNORE_NEW_LINES);
$node = file($argv[3], FILE_IGNORE_NEW_LINES);

$documents = 0;
$identicalDocuments = 0;
$badDocuments = 0;
$names = 0;
$tally = array('identical' => 0, 'byte offsets' => 0, 'NaN end offset' => 0,
               'leading punct' => 0, 'trailing rank' => 0, 'DIFFERENT' => 0);
$shown = 0;

foreach ($php as $i => $line) {
    $documents++;
    $nodeLine = isset($node[$i]) ? $node[$i] : '';
    if ($line === $nodeLine) {
        $identicalDocuments++;
        $ours = json_decode($line, true);
        $names += is_array($ours) ? count($ours) : 0;
        $tally['identical'] += is_array($ours) ? count($ours) : 0;
        continue;
    }

    $document = str_replace('\n', "\n", $corpus[$i]);
    $ours = json_decode($line, true);
    $theirs = json_decode($nodeLine, true);
    $problems = array();

    if (!is_array($ours) || !is_array($theirs) || count($ours) !== count($theirs)) {
        $problems[] = array(null, 'different number of names: '
            . (is_array($ours) ? count($ours) : '?') . ' vs '
            . (is_array($theirs) ? count($theirs) : '?'));
        $tally['DIFFERENT']++;
    } else {
        foreach ($ours as $n => $result) {
            $names++;
            $verdict = classify($result, $theirs[$n], $document, $dictionaries);
            $tally[$verdict]++;
            if ($verdict === 'DIFFERENT') {
                $problems[] = array($n, json_encode($result) . '  vs node ' . json_encode($theirs[$n]));
            }
        }
    }

    if ($problems) {
        $badDocuments++;
        if ($shown < 25) {
            $shown++;
            $preview = strlen($corpus[$i]) > 100 ? substr($corpus[$i], 0, 100) . '...' : $corpus[$i];
            echo '--- line ', $i + 1, ': ', $preview, "\n";
            foreach (array_slice($problems, 0, 8) as $problem) {
                echo '  ', $problem[0] === null ? '' : '[' . $problem[0] . '] ', $problem[1], "\n";
            }
        }
    }
}

echo "\n";
printf("documents           : %d (%d byte-identical to node)\n", $documents, $identicalDocuments);
printf("names               : %d\n", $names);
foreach ($tally as $label => $count) {
    if ($label === 'DIFFERENT') continue;
    printf("  %-18s: %d\n", $label, $count);
}
printf("  %-18s: %d\n", 'UNEXPECTED', $tally['DIFFERENT']);
exit($tally['DIFFERENT'] === 0 ? 0 : 1);

/**
 * Decide how one name from this port relates to the matching name from the
 * JavaScript original.
 *
 * @return string one of the keys of $tally
 */
function classify($ours, $theirs, $document, $dictionaries)
{
    if ($ours === $theirs) {
        return 'identical';
    }
    if ($ours[3] !== $theirs[3]) {
        return 'DIFFERENT';
    }

    // The JavaScript kept a trailing rank that we stripped.
    if ($ours[0] !== $theirs[0]) {
        if (strpos($theirs[0], $ours[0]) !== 0) {
            return 'DIFFERENT';
        }
        $tail = substr($theirs[0], strlen($ours[0]));
        if (!preg_match('/^[\s.,;]*([A-Za-z]+[\s.,;]*)+$/D', $tail)) {
            return 'DIFFERENT';
        }
        preg_match_all('/[A-Za-z]+/', $tail, $words);
        foreach ($words[0] as $word) {
            if (!$dictionaries->contains('ranks', $word)) {
                return 'DIFFERENT';
            }
        }
        return 'trailing rank';
    }

    // Same name. Do the offsets line up once ours are converted to UTF-16?
    $start = utf16Offset($document, $ours[1]);
    if ($start === $theirs[1]) {
        if ($theirs[2] === null) {
            return 'NaN end offset';
        }
        return utf16Offset($document, $ours[2]) === $theirs[2] ? 'byte offsets' : 'DIFFERENT';
    }

    // Offsets differ because we skipped more leading punctuation. Rather than
    // trusting node here, check our own span really does hold the name.
    if ($start > $theirs[1] && spanHoldsName($document, $ours)) {
        return 'leading punct';
    }
    return 'DIFFERENT';
}

/** Does our reported span contain exactly the name we reported? */
function spanHoldsName($document, $result)
{
    $span = substr($document, $result[1], $result[2] - $result[1]);
    $normalise = function ($string) {
        return strtolower(preg_replace('/[^0-9A-Za-z]+/', '', $string));
    };
    return $normalise($span) === $normalise($result[0]);
}

/** Convert a byte offset into $text to a UTF-16 code unit offset. */
function utf16Offset($text, $byteOffset)
{
    $prefix = substr($text, 0, $byteOffset);
    // One unit per character, and one extra for each character outside the
    // basic multilingual plane (4 byte UTF-8, encoded as a surrogate pair).
    return mb_strlen($prefix, 'UTF-8') + preg_match_all('/[\xF0-\xF4]/', $prefix);
}
