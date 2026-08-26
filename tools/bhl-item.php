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
 * character positions should not depend on which. A word broken over two
 * lines is put back together, whether BHL marked the break with a not sign or
 * left an ordinary hyphen. Nothing else is inserted
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
    // BHL writes a word broken across two lines with a not sign where the
    // hyphen was: 'DoÂ¬ \nliocarpus'. Joining the halves puts the word back,
    // and with it any name the line break had cut in two. Only where a line
    // actually ends - a Â¬ in the middle of one is left alone.
    $text = preg_replace('/\xc2\xac[ \t]*\n[ \t]*/', '', $text);
    // And sometimes with an ordinary hyphen: 'Thala- \n\n\n\nmum'. That one is
    // ambiguous, because a word may be hyphenated in its own right and happen
    // to break there, and joining turns 'yellow-green' into 'yellowgreen'.
    // Joined anyway: over the five items here, 239 breaks are real compounds
    // and not one of them spells a name when run together, so joining cannot
    // invent one. Leaving them apart can - the halves are what put 'macro',
    // 'hexa' and 'mon' into a list of epithets.
    $text = preg_replace('/([a-z])-[ \t]*\n[ \t\n]*(?=[a-z])/', '$1', $text);
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
