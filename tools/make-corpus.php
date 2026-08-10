<?php
/**
 * Build a corpus of test documents for tools/compare.sh.
 *
 * Writes one document per line (newlines inside documents are escaped as \n),
 * mixing hand written edge cases with sentences generated from the
 * dictionaries themselves, so that the comparison against the JavaScript
 * original exercises the odd corners of the parser.
 *
 *   php tools/make-corpus.php > /tmp/corpus.txt
 */

require __DIR__ . '/../autoload.php';

mt_srand(20260809); // deterministic

$handwritten = array(
    'Felis leo',
    'Wow, Felis leo rocks',
    'Pomatomus; P. saltator',
    'Pomatomus, P. saltator',
    'Felis leo leo',
    'Felis leo leo leo',
    'Felis leo leo leo leo',
    'Felis leo leo leo leo leo',
    'Felis leo, chaus, catus',
    'Felis (Felis) leo',
    'Pomatomus (Ignoreme) saltatrix',
    'Pomatomus; P. (Pomatomus) saltatrix',
    'Some Animalia Felis leo more text',
    'Amanita muscaria',
    'P. Pomatomus more words',
    'P. Animalia more words',
    'Text <e this would break HTML parsing Amanita muscaria',
    'The quick brown Animalia Vulpes vulpes (Canidae; Carnivora; Animalia) jumped over the lazy Canis lupis familiaris',
    'AMANITA MUSCARIA',
    'AMANITA (AMANITA) MUSCARIA MUSCARIA',
    'Amanita sp.',
    'Amanita sp. nov.',
    'Felis leo var. persicus',
    'Felis leo var persicus',
    'Stella marina',
    'Homo sapiens Linnaeus, 1758',
    'Homo sapiens; Homo erectus; Homo habilis.',
    'A study of Escherichia coli and Bacillus subtilis.',
    'see Fig. 3 for Quercus robur',
    'Quercus robur L.',
    "Line one Felis\nleo line two",
    "Tab\tseparated Felis\tleo",
    '(Felis leo)',
    '[Felis leo]',
    'Felis leo.',
    'Felis leo,',
    'Felis leo;',
    '...Felis leo...',
    'Nothing to see here at all',
    '',
    '   ',
    '123 456',
    'Felis 0 leo',
    '<p>Felis leo</p>',
    '<p class="x">Amanita muscaria</p>',
    '<i>Felis</i> <i>leo</i>',
    '<b>Felis leo</b> and <span>Amanita muscaria</span>',
    'Felis<br/>leo',
    '<table><tr><td>Felis</td><td>leo</td></tr></table>',
    '&nbsp;Felis leo&nbsp;',
    'M. musculus and R. norvegicus',
    'Mus musculus, M. musculus, M. spretus',
    'Drosophila melanogaster (Diptera: Drosophilidae)',
    'the genus Goliath is not a beetle here',
    'Sorghum bicolor',
    'Tuberosa something',
    'Aa',
    'A. B. C.',
    'Felis leo Felis leo',
    'Animalia Animalia',
    'Canis lupus familiaris subsp. dingo',
    'x Felis leo',
    'Felis-leo',
    'FELIS LEO',
    'felis leo',
    'Felis LEO',
    'Vulpes vulpes (Linnaeus, 1758) is the red fox.',
    '<p>Felis leo<p>Amanita muscaria',
    '<ul><li>Felis leo</li><li>Amanita muscaria</li></ul>',
    '<td>Felis</td><td>leo</td>',
    '<hr>Felis leo<hr/>',
    '<P CLASS="X">Felis leo</P>',
    '<img src="a.png">Felis leo',
    '<!-- Felis leo -->',
    '<!DOCTYPE html><html><body>Felis leo</body></html>',
    'a < b and Felis leo',
    'a > b and Felis leo',
    '<span>Felis</span> leo',
    'Felis <span>leo</span>',
    '<a href="http://example.com/a;b.c">Felis leo</a>',
    '<div\nclass="x">Felis leo</div>',
    'unclosed <div Felis leo',
    '<p>Felis</p>leo',
    '<em>Amanita</em> <em>muscaria</em> <em>muscaria</em>',
    '&amp; Felis leo &lt;',
    '<name found="x">Felis leo</name>',
    // Nomenclatural annotations, including the shapes OCR leaves behind
    'Amanita sp.',
    'Amanita muscaria gen. nov.',
    'Amanita muscaria sp. nov.',
    'Amanita muscaria sp. n.',
    'Amanita muscaria gen. n., sp. n.',
    '15. Pseudoneoborus samoanus, gen. ., sp. 0. x',
    '32. Paurolugus scutatus, gen. n.,,sp.n. . x',
    'Felis leo gen. ,',
    'Felis leo var.',
    'Felis leo var. persicus gen. nov.',
    'Amanita muscaria ssp. nov. and more text',
);

foreach ($handwritten as $document) {
    echo str_replace("\n", '\n', $document), "\n";
}

// Sentences built from real dictionary terms
$genera = pick(__DIR__ . '/../dictionaries/genera.txt', 900);
$species = pick(__DIR__ . '/../dictionaries/species.txt', 900);
$families = pick(__DIR__ . '/../dictionaries/family.txt', 300);
$ranks = pick(__DIR__ . '/../dictionaries/ranks.txt', 60);
$filler = array('the', 'of', 'and', 'in', 'we', 'collected', 'specimens', 'from',
    'a', 'site', 'near', 'the', 'river', 'during', '1987', 'Figure', 'Table',
    'see', 'also', 'et', 'al', 'new', 'record', 'for', 'this', 'area');
$punctuation = array('', '', '', '', ',', '.', ';', ':', ')', '(', '],', '.)', ',');

$templates = array(
    '%G %s',
    '%G %s %s',
    '%G %s %r %s',
    '%G (%G) %s',
    '%G %s, %s, %s',
    '%F',
    '%G %s (%F)',
    '%w %w %G %s %w %w',
    '%w %F %w %G %s%p %w',
    '%G; %A %s',
    '%A %s and %A %s',
    '%w %w %w %w',
    '%G%p %G %s%p %F%p',
    '<p>%w %G %s%p</p>',
    '<i>%G %s</i> %w %w',
    '%w <b>%G</b> %s %w',
    '%G %s %s %s %s',
    '%g %s',
    '%w, %G %s (%w, 1899) %w',
    '<p>%G %s</p><p>%G %s</p>',
    '<td>%G</td><td>%s</td>',
    '<li>%G %s%p</li> <li>%F</li>',
    '<span class="taxon">%G</span> <span>%s</span> %w',
    '<a href="/a;b.c?d=1">%G %s</a> %w %w',
    '%w <hr/> %G %s <br> %F',
    '<i>%G</i> <i>%s</i> %r <i>%s</i>',
    '%w <div %G %s',
    '%G%p</p>%s %w',
    '%d. %G %s, gen. ., sp. 0. x',
    '%d. %G %s, %r. n., %r. n.',
    '%G %s %r. nov.',
    '%G %r.',
    '%w %G %s %r. %w %w',
);

for ($i = 0; $i < 4000; $i++) {
    $template = $templates[array_rand($templates)];
    $document = preg_replace_callback('/%[GgFsrwpAd]/', function ($match) use (
        $genera, $species, $families, $ranks, $filler, $punctuation
    ) {
        switch ($match[0]) {
            case '%G': return $genera[array_rand($genera)];
            case '%g': return strtolower($genera[array_rand($genera)]);
            case '%A': return strtoupper(substr($genera[array_rand($genera)], 0, 1)) . '.';
            case '%F': return $families[array_rand($families)];
            case '%s': return $species[array_rand($species)];
            case '%r': return $ranks[array_rand($ranks)];
            case '%w': return $filler[array_rand($filler)];
            case '%p': return $punctuation[array_rand($punctuation)];
            case '%d': return (string) mt_rand(1, 99);
        }
        return '';
    }, $template);
    echo str_replace("\n", '\n', $document), "\n";
}

/** Sample $count random lines from a dictionary file, capitalised as written. */
function pick($file, $count)
{
    $handle = fopen($file, 'r');
    $reservoir = array();
    $seen = 0;
    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '' || !preg_match('/^[A-Za-z][a-z]+$/', $line)) {
            continue;
        }
        $seen++;
        if (count($reservoir) < $count) {
            $reservoir[] = $line;
        } elseif (mt_rand(0, $seen - 1) < $count) {
            $reservoir[mt_rand(0, $count - 1)] = $line;
        }
    }
    fclose($handle);
    return $reservoir;
}
