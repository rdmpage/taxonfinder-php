<?php
/**
 * Run the PHP parser over a corpus and print one JSON result per line.
 * Used by tools/compare.sh; see tools/dump.js for the JavaScript counterpart.
 *
 *   php tools/dump.php /tmp/corpus.txt [--html]
 */

require __DIR__ . '/../autoload.php';

$file = isset($argv[1]) ? $argv[1] : 'php://stdin';
$isHtml = in_array('--html', $argv, true);
$finder = new Taxonfinder\Finder();

$handle = fopen($file, 'r');
while (($line = fgets($handle)) !== false) {
    $document = str_replace('\n', "\n", rtrim($line, "\r\n"));
    $results = $finder->find($document, $isHtml);
    $simplified = array();
    foreach ($results as $result) {
        $simplified[] = array(
            $result['name'],
            $result['offsets'][0],
            $result['offsets'][1],
            isset($result['original']) ? $result['original'] : null,
        );
    }
    echo json_encode($simplified), "\n";
}
fclose($handle);
