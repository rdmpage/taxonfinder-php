#!/usr/bin/env php
<?php
/**
 * Annotate an assembled BHL item, anchoring each name to the page it sits on.
 *
 *   php tools/bhl-annotate.php 273748.txt --pages=273748.pages.tsv > 273748.json
 *
 * A genus in a section heading is carried down to the bare epithets beneath
 * it, which is how keys are set - --no-carry-over turns that off.
 *
 * --statements=FILE also writes the names something is said about, as a TSV of
 * PageID, name, statement and identifier.
 *
 * The names are found in the item as a whole, not page by page, because that
 * is what lets an abbreviated genus reach back to where it was spelled out -
 * 'P. penicilliger' becomes 'Platygrapsus penicilliger' from a genus given
 * pages earlier. Only when a record is written is it moved onto its page.
 *
 * So the target gains a source, which is the thing the offsets are into:
 *
 *   "target": {
 *     "type": "SpecificResource",
 *     "source": 59021864,                     <- BHL PageID
 *     "selector": [
 *       { "type": "TextQuoteSelector", ... },
 *       { "type": "TextPositionSelector", "start": 1123, "end": 1138 }
 *     ]
 *   }
 *
 * The offsets are relative to that page, not to the assembled item: the item
 * text is this pipeline's own artefact, built with its own separator, while a
 * page is something BHL publishes and anyone can go and look at. That makes a
 * record mean something on its own.
 *
 * source is the bare PageID for now. It wants to be an IRI to satisfy the
 * Web Annotation model, and can become one without disturbing anything else
 * here once we have settled which BHL URL serves the text these offsets index.
 */

// A book-length text needs more than PHP's default 128 MB: the parser holds
// a token per word, and they cost far more than the bytes they came from.
if (memoryLimitBytes() < 1024 * 1024 * 1024) {
    ini_set('memory_limit', '1G');
}

require __DIR__ . '/../autoload.php';

/** The current memory_limit in bytes; PHP_INT_MAX when there is none. */
function memoryLimitBytes()
{
    $limit = trim((string) ini_get('memory_limit'));
    if ($limit === '' || $limit === '-1') {
        return PHP_INT_MAX;
    }
    $units = array('k' => 1024, 'm' => 1048576, 'g' => 1073741824);
    $suffix = strtolower(substr($limit, -1));
    return isset($units[$suffix])
        ? (int) substr($limit, 0, -1) * $units[$suffix]
        : (int) $limit;
}

$file = null;
$pagesFile = null;
$actsFile = null;
$context = 32;
$carryOver = true;
$publishedOnly = false;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--pages=(.+)$/', $argument, $match)) {
        $pagesFile = $match[1];
    } elseif (preg_match('/^--statements=(.+)$/', $argument, $match)) {
        $actsFile = $match[1];
    } elseif ($argument === '--published-only') {
        $publishedOnly = true;
    } elseif (preg_match('/^--context=(\d+)$/', $argument, $match)) {
        $context = (int) $match[1];
    } elseif ($argument === '--no-carry-over') {
        $carryOver = false;
    } elseif ($file === null) {
        $file = $argument;
    }
}
if ($file === null || $pagesFile === null) {
    fwrite(STDERR, "Usage: bhl-annotate.php <item text> --pages=FILE [--statements=FILE]\n"
        . "                        [--published-only] [--context=N] [--no-carry-over]\n");
    exit(1);
}

$text = file_get_contents($file);
$pages = readPages($pagesFile);
if ($text === false || !$pages) {
    fwrite(STDERR, "Could not read $file or $pagesFile\n");
    exit(1);
}

$finder = new Taxonfinder\Finder(null, $context);
// Keys are everywhere in this literature, and a key entry prints the epithet
// alone. See KeyGenus; --no-carry-over turns it off.
$finder->setCarryOverKeyGenus($carryOver);
$annotations = array();
$unplaced = 0;
$overrun = 0;
$strayed = array();

// Byte offsets: the page table this places names into is written by
// bhl-item.php, which measures the item text with strlen().
foreach ($finder->findWithByteOffsets($text) as $annotation) {
    $selectors = $annotation['target']['selector'];
    $position = $selectors[1];
    $page = pageAt($pages, $position['start']);
    if ($page === null) {
        $unplaced++;
        continue;
    }
    // A name that runs off the foot of a page keeps its true length, so the
    // end can sit past what the page holds. Rare, and better than a silently
    // shortened name.
    if ($position['end'] > $page['end']) {
        $overrun++;
    }
    $selectors[1] = array(
        'type' => 'TextPositionSelector',
        'start' => $position['start'] - $page['start'],
        'end' => $position['end'] - $page['start'],
    );
    $annotation['target'] = array(
        'type' => 'SpecificResource',
        'source' => $page['pageid'],
        'selector' => $selectors,
    );
    if (isset($annotation['statements'])) {
        // Kept against the same page as the name it belongs to, so both
        // spans are read in one coordinate system. An act that reaches onto
        // the next page would give an offset the page cannot answer, so say
        // so rather than write a span that quietly resolves to nothing. It
        // has so far always meant the act was misread off the running header
        // at the top of the following page.
        if ($annotation['statements']['end'] > $page['end']) {
            $strayed[] = $page['pageid'] . ' ' . $annotation['body']['value']
                . ' (' . $annotation['statements']['verbatim'] . ')';
            unset($annotation['statements']);
        } else {
            $annotation['statements']['start'] -= $page['start'];
            $annotation['statements']['end'] -= $page['start'];
        }
    }
    $annotations[] = $annotation;
}

if ($actsFile !== null) {
    writeStatements($actsFile, $annotations, $publishedOnly);
}

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
    $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
}
echo json_encode($annotations, $flags), "\n";

fprintf(STDERR, "%d annotations over %d pages\n", count($annotations), count($pages));
if ($unplaced) {
    fprintf(STDERR, "%d fell outside every page and were dropped\n", $unplaced);
}
if ($overrun) {
    fprintf(STDERR, "%d run past the end of their page\n", $overrun);
}
if ($strayed) {
    fprintf(STDERR, "%d nomenclatural act(s) reached onto the next page and were dropped:\n",
        count($strayed));
    foreach ($strayed as $one) {
        fprintf(STDERR, "  %s\n", $one);
    }
}

/**
 * The forms that publish a name, for --published-only.
 *
 * Not the default. Every statement is worth a row, because the table is a
 * list of pages that say something about a name and a synonymy or a
 * restoration says as much as a description does. This list is here for the
 * narrower question - which pages publish a name for the first time - and is
 * written out rather than left implicit in the parser so that it can be
 * argued with.
 */
function publishingForms()
{
    return array(
        'sp. nov.' => true, 'gen. nov.' => true, 'fam. nov.' => true,
        'subgen. nov.' => true, 'subfam. nov.' => true, 'subsp. nov.' => true,
        'var. nov.' => true, 'forma nov.' => true, 'comb. nov.' => true,
        'nom. nov.' => true,
    );
}

/**
 * The registry identifier printed under a statement, where the paper gives
 * one. An act LSID is preferred: it names the act itself, which is what a row
 * of this table is.
 */
function identifierFor(array $annotation)
{
    if (!isset($annotation['identifiers'])) {
        return '';
    }
    foreach ($annotation['identifiers'] as $identifier) {
        if ($identifier['type'] === 'act') {
            return $identifier['value'];
        }
    }
    return $annotation['identifiers'][0]['value'];
}

/**
 * The names something is said about, as PageID, name, statement and the
 * registry identifier printed under it.
 *
 * Every statement, one row each, so the column holds a single value and can
 * be grouped on: a genus and its type species published together carry
 * 'gen. nov.' and 'sp. nov.' and take a row apiece. A synonymy, a rank
 * change, a name declared unavailable - all of them make a page worth
 * finding again, which is what this table is for. --published-only narrows it
 * to the statements that put a name into the world.
 */
function writeStatements($path, array $annotations, $publishedOnly)
{
    $handle = fopen($path, 'w');
    if ($handle === false) {
        fwrite(STDERR, "Could not write $path\n");
        exit(1);
    }
    fwrite($handle, "PageID\tname\tstatement\tidentifier\n");
    $publishing = publishingForms();
    $rows = 0;
    foreach ($annotations as $annotation) {
        if (!isset($annotation['statements'])) {
            continue;
        }
        foreach ($annotation['statements']['terms'] as $term) {
            if ($publishedOnly && !isset($publishing[$term])) {
                continue;
            }
            fwrite($handle, implode("\t", array(
                $annotation['target']['source'],
                $annotation['body']['value'],
                $term,
                identifierFor($annotation),
            )) . "\n");
            $rows++;
        }
    }
    fclose($handle);
    fprintf(STDERR, "%d statement%s -> %s\n", $rows, $rows === 1 ? '' : 's', $path);
}

/** start, end, PageID, sequence - one line per page, as bhl-item.php writes it. */
function readPages($path)
{
    $pages = array();
    foreach ((array) file($path) as $line) {
        $field = explode("\t", rtrim($line, "\r\n"));
        if (count($field) < 4) {
            continue;
        }
        $pages[] = array(
            'start' => (int) $field[0],
            'end' => (int) $field[1],
            'pageid' => (int) $field[2],
            'sequence' => (int) $field[3],
        );
    }
    return $pages;
}

/**
 * The page an offset falls on. The pages are in order and do not overlap, so
 * this walks them; a name starting in the separator between two pages cannot
 * happen, since a name starts with a letter.
 */
function pageAt(array $pages, $offset)
{
    $low = 0;
    $high = count($pages) - 1;
    while ($low <= $high) {
        $mid = intdiv($low + $high, 2);
        if ($offset < $pages[$mid]['start']) {
            $high = $mid - 1;
        } elseif ($offset >= $pages[$mid]['end']) {
            $low = $mid + 1;
        } else {
            return $pages[$mid];
        }
    }
    return null;
}
