#!/usr/bin/env php
<?php
/**
 * Assemble the OCR pages of one BHL item into a single text.
 *
 *   php tools/bhl-item.php pages/ > 273748.txt
 *   php tools/bhl-item.php pages/ --pages=273748.tsv > 273748.txt
 *   php bin/taxonfinder --mark 273748.txt > 273748.html
 *
 * tools/bhl-archive.py puts the pages of an item in a directory, named
 * item-<ItemID>-<PageID>-<sequence>.txt. They do not come out of the archive
 * in reading order, so they are sorted on the sequence number here.
 *
 * Line endings are normalised to \n - the OCR mixes CRLF and bare CR, and
 * character positions should not depend on which. Nothing else is inserted
 * between the pages beyond the blank line that separates them, so the text
 * stays as it was scanned. That leaves the question of which
 * page a name was found on, which --pages answers with a sidecar of
 * start/end/PageID/sequence, one page per line. An annotation's
 * TextPositionSelector falls inside exactly one of those ranges:
 *
 *   https://www.biodiversitylibrary.org/page/<PageID>
 */

require __DIR__ . '/../autoload.php';

$directory = null;
$pagesFile = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--pages=(.+)$/', $argument, $match)) {
        $pagesFile = $match[1];
    } elseif ($directory === null) {
        $directory = $argument;
    }
}
if ($directory === null) {
    fwrite(STDERR, "Usage: bhl-item.php <directory of pages> [--pages=FILE]\n");
    exit(1);
}

$pages = pages($directory);
if (!$pages) {
    fwrite(STDERR, "No OCR pages in $directory\n");
    exit(1);
}

$sidecar = $pagesFile === null ? null : fopen($pagesFile, 'w');
$offset = 0;
$first = true;
foreach ($pages as $page) {
    $text = file_get_contents($page['path']);
    if ($text === false) {
        fwrite(STDERR, "Could not read {$page['path']}\n");
        exit(1);
    }
    // BHL's OCR is full of CRLF, and some of it bare CR. Normalise before
    // anything measures the text, so an offset means the same thing wherever
    // the file came from and whatever reads it next.
    $text = preg_replace('/\r\n|\r/', "\n", $text);
    if (!$first) {
        echo "\n";
        $offset += 1;
    }
    $first = false;
    echo $text;
    if ($sidecar !== null) {
        fwrite($sidecar, implode("\t", array(
            $offset, $offset + strlen($text), $page['pageid'], $page['sequence'],
        )) . "\n");
    }
    $offset += strlen($text);
}
if ($sidecar !== null) {
    fclose($sidecar);
}

/**
 * The pages in reading order. The sequence number is the last field of the
 * name, and is what the archive orders them by - not the PageID, which climbs
 * with when the page was scanned rather than where it sits in the book.
 */
function pages($directory)
{
    $pages = array();
    foreach ((array) glob(rtrim($directory, '/') . '/*.txt') as $path) {
        // item-273748-40298468-0001.txt, and part-... the same shape
        if (!preg_match('/-(\d+)-(\d+)\.txt$/', basename($path), $match)) {
            continue;
        }
        $pages[] = array(
            'path' => $path,
            'pageid' => ltrim($match[1], '0'),
            'sequence' => (int) $match[2],
        );
    }
    usort($pages, function ($a, $b) {
        return $a['sequence'] - $b['sequence'];
    });
    return $pages;
}
