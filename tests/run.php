<?php
/**
 * The node-taxonfinder mocha suite, ported. No test framework needed:
 *
 *   php tests/run.php
 */

require __DIR__ . '/../autoload.php';

use Taxonfinder\Dictionaries;
use Taxonfinder\Finder;
use Taxonfinder\NameTag;
use Taxonfinder\Parser;
use Taxonfinder\SortedIndex;
use Taxonfinder\Utility;

$tests = 0;
$failures = array();
$group = '';

function describe($name, $body)
{
    global $group;
    $group = $name;
    $body();
}

function it($name, $body)
{
    global $tests, $failures, $group;
    $tests++;
    try {
        $body();
    } catch (Exception $e) {
        $failures[] = $group . ' ' . $name . "\n      " . $e->getMessage();
        echo "F";
        return;
    }
    echo ".";
}

function assertEquals($expected, $actual, $note = '')
{
    if ($expected !== $actual) {
        throw new Exception(sprintf(
            'expected %s but got %s%s',
            var_export($expected, true),
            var_export($actual, true),
            $note === '' ? '' : ' (' . $note . ')'
        ));
    }
}

function assertTrue($actual, $note = '')
{
    assertEquals(true, $actual, $note);
}

function assertFalse($actual, $note = '')
{
    assertEquals(false, $actual, $note);
}

function assertNull($actual, $note = '')
{
    if ($actual !== null) {
        throw new Exception('expected null but got ' . var_export($actual, true)
            . ($note === '' ? '' : ' (' . $note . ')'));
    }
}

function assertNotSet(array $hash, $key)
{
    if (isset($hash[$key])) {
        throw new Exception("expected '$key' to be unset, got " . var_export($hash[$key], true));
    }
}

$finder = new Finder();
$parser = $finder->parser();
$dictionaries = $finder->dictionaries();

/** Build the state hash the scoring functions expect, as the mocha tests do. */
function createState($word, $workingName = '')
{
    $cleanWord = Utility::clean($word);
    return array(
        'word' => $word,
        'cleanWord' => $cleanWord,
        'lowerCaseCleanWord' => Utility::lower($cleanWord),
        'workingName' => $workingName,
    );
}

/** As the mocha suite's expectNameInText helper. */
function expectNameInText(array $options)
{
    global $parser;
    if (!isset($options['text'])) {
        return;
    }
    $index = isset($options['index']) ? $options['index'] : 0;
    $result = $parser->findNamesAndOffsets($options['text']);
    if (isset($options['name'])) {
        assertEquals($options['name'], $result[$index]['name']);
    }
    if (isset($options['original'])) {
        assertEquals($options['original'], $result[$index]['original']);
    }
}

// ---------------------------------------------------------------- utility

describe('#clean', function () {
    it('removes non-characters from beginning and ends of lines', function () {
        assertEquals('abc', Utility::clean('---abc'));
        assertEquals('abc', Utility::clean('abc---'));
        assertEquals('abc', Utility::clean('---abc---'));
    });
});

describe('#addWordToEndOfOffsetList', function () {
    it('appends strings to the end of the list', function () {
        $list = array(array('word' => 'Abc', 'offset' => 101));
        $list = Utility::addWordToEndOfOffsetList($list, 'def');
        assertEquals('Abcdef', $list[0]['word']);
        assertEquals(101, $list[0]['offset']);
    });
    it('appends strings to the end of the list even when its empty', function () {
        $list = Utility::addWordToEndOfOffsetList(array(), '', 101);
        assertEquals('', $list[0]['word']);
        assertEquals(101, $list[0]['offset']);
    });
});

describe('#isStopTag', function () {
    it('knows valid stop tags', function () {
        foreach (array('p', 'td', 'tr', 'table', 'hr', 'ul', 'li', '/p') as $tag) {
            assertTrue(Utility::isStopTag($tag), $tag);
        }
    });
    it('knows invalid stop tags', function () {
        foreach (array('paragraph', '\\p', 'img', '/img') as $tag) {
            assertFalse(Utility::isStopTag($tag), $tag);
        }
    });
});

describe('#removeTagsFromElements', function () {
    it('does nothing with plain text', function () {
        $result = Utility::removeTagsFromElements(array(array('word' => 'hello')));
        assertEquals(1, count($result));
        assertEquals('hello', $result[0]['word']);
    });
    it('replaces complete blocker tags with null', function () {
        $result = Utility::removeTagsFromElements(array(array('word' => '<p>')));
        assertEquals(1, count($result));
        assertNull($result[0]['word']);
    });
    it('replaces split blocker tags with null', function () {
        $result = Utility::removeTagsFromElements(array(
            array('word' => '<p'), array('word' => 'class="b"'), array('word' => '>'),
        ));
        assertEquals(1, count($result));
        assertNull($result[0]['word']);
    });
    it('removes complete non-blocker tags', function () {
        assertEquals(array(), Utility::removeTagsFromElements(array(array('word' => '<span>'))));
    });
    it('removes split non-blocker tags', function () {
        assertEquals(array(), Utility::removeTagsFromElements(array(
            array('word' => '<span'), array('word' => 'class="b"'), array('word' => '>'),
        )));
    });
});

describe('#explodeText', function () {
    it('splits text into words with offsets', function () {
        $result = Utility::explodeText('<p class="say">Hello. Goodbye</code>');
        assertEquals('<p ', $result[0]['word']);
        assertEquals(0, $result[0]['offset']);
        assertEquals('class="say">', $result[1]['word']);
        assertEquals(3, $result[1]['offset']);
        assertEquals('Hello. ', $result[2]['word']);
        assertEquals(15, $result[2]['offset']);
        assertEquals('Goodbye', $result[3]['word']);
        assertEquals(22, $result[3]['offset']);
        assertEquals('</code>', $result[4]['word']);
        assertEquals(29, $result[4]['offset']);
    });
});

describe('#removeQualifiers', function () {
    $words = function ($text) {
        $result = array();
        foreach (Utility::removeQualifiers(Utility::explodeText($text)) as $item) {
            $result[] = trim($item['word']);
        }
        return $result;
    };
    it('drops sensu stricto and its abbreviations', function () use ($words) {
        assertEquals(array('Hypogastrura', 'simsi'), $words('Hypogastrura (s. str.) simsi'));
        assertEquals(array('Hypogastrura', 'simsi'), $words('Hypogastrura (s.str.) simsi'));
        assertEquals(array('Hypogastrura', 'simsi'), $words('Hypogastrura (s. lat.) simsi'));
        assertEquals(array('Hypogastrura', 'simsi'), $words('Hypogastrura (s.l.) simsi'));
        assertEquals(array('Hypogastrura', 'simsi'), $words('Hypogastrura (sensu stricto) simsi'));
    });
    it('leaves a real subgenus alone', function () use ($words) {
        assertEquals(array('Felis', '(Felis)', 'leo'), $words('Felis (Felis) leo'));
    });
    it('leaves other parentheses alone', function () use ($words) {
        assertEquals(array('group', '(sensu', 'Christiansen', 'and', 'Bellinger)'),
            $words('group (sensu Christiansen and Bellinger)'));
        assertEquals(array('form', '(Fig.', '2)'), $words('form (Fig. 2)'));
        assertEquals(array('(summer', 'form)'), $words('(summer form)'));
    });
    it('keeps the offsets of the words it keeps', function () {
        $text = 'Hypogastrura (s. str.) simsi';
        $kept = Utility::removeQualifiers(Utility::explodeText($text));
        assertEquals(0, $kept[0]['offset']);
        assertEquals(23, $kept[1]['offset']);
        assertEquals('simsi', substr($text, $kept[1]['offset'], 5));
    });
});

describe('#ucfirst', function () {
    it('capitalizes the first letter', function () {
        assertEquals('Lower', Utility::ucfirst('lower'));
    });
});

// ------------------------------------------------------------ dictionaries

describe('#load', function () use ($dictionaries) {
    it('adds a lot of words', function () use ($dictionaries) {
        $expected = array(
            'family' => 55230, 'family_new' => 41, 'genera' => 437061, 'genera_new' => 897,
            'species' => 660011, 'species_new' => 1840, 'species_bad' => 1187, 'ranks' => 157,
            'overlap_new' => 3025, 'dict_ambig' => 4166, 'genera_family' => 11, 'dict_bad' => 9,
        );
        foreach ($expected as $name => $minimum) {
            $count = $dictionaries->count($name);
            assertTrue($count > $minimum, "$name has $count terms, expected more than $minimum");
        }
    });
    it('adds family and above names', function () use ($dictionaries) {
        foreach (array('animalia', 'chordata', 'mammalia', 'primates', 'hominidae') as $term) {
            assertTrue($dictionaries->has('family', $term), $term);
        }
    });
    it('adds genera names', function () use ($dictionaries) {
        foreach (array('homo', 'geranium', 'giraffe', 'amanita', 'escherichia') as $term) {
            assertTrue($dictionaries->has('genera', $term), $term);
        }
    });
    it('adds species names', function () use ($dictionaries) {
        foreach (array('sapiens', 'cinereum', 'camelopardalis', 'muscaria', 'coli') as $term) {
            assertTrue($dictionaries->has('species', $term), $term);
        }
    });
    it('adds ranks', function () use ($dictionaries) {
        foreach (array('sp', 'var', 'gen', 'f', 'subsp') as $term) {
            assertTrue($dictionaries->has('ranks', $term), $term);
        }
    });
    it('strips the byte order mark from dict_bad', function () use ($dictionaries) {
        assertTrue($dictionaries->has('dict_bad', 'stella marina'));
    });
    it('does not contain nonsense', function () use ($dictionaries) {
        assertFalse($dictionaries->has('genera', 'itsnotaname'));
        assertFalse($dictionaries->has('species', 'nonsense'));
    });
});

// ----------------------------------------------------------------- parser

describe("#findNamesAndOffsets", function () use ($parser) {
    it('finds and returns names and offsets', function () use ($parser) {
        $result = $parser->findNamesAndOffsets('The quick brown Animalia Vulpes vulpes (Canidae; Carnivora; '
            . 'Animalia) jumped over the lazy Canis lupis familiaris');
        assertEquals('Animalia', $result[0]['name']);
        assertEquals(16, $result[0]['offsets'][0]);
        assertEquals(24, $result[0]['offsets'][1]);
        assertEquals('Vulpes vulpes', $result[1]['name']);
        assertEquals(25, $result[1]['offsets'][0]);
        assertEquals(38, $result[1]['offsets'][1]);
        assertEquals('Canidae', $result[2]['name']);
        assertEquals(40, $result[2]['offsets'][0]);
        assertEquals(47, $result[2]['offsets'][1]);
    });
    it('expands abbreviated genera', function () use ($parser) {
        $result = $parser->findNamesAndOffsets('Pomatomus, P. saltator');
        assertEquals('Pomatomus', $result[0]['name']);
        assertEquals(0, $result[0]['offsets'][0]);
        assertEquals(9, $result[0]['offsets'][1]);
        assertEquals('Pomatomus saltator', $result[1]['name']);
        assertEquals('P. saltator', $result[1]['original']);
        assertEquals(11, $result[1]['offsets'][0]);
        assertEquals(22, $result[1]['offsets'][1]);
    });
    it('gets correct offsets when the string is exactly the name', function () use ($parser) {
        $result = $parser->findNamesAndOffsets('Amanita muscaria');
        assertEquals('Amanita muscaria', $result[0]['name']);
        assertEquals(0, $result[0]['offsets'][0]);
        assertEquals(16, $result[0]['offsets'][1]);
    });
    it('gets correct offsets when abbreviations are followed by genera', function () use ($parser) {
        $result = $parser->findNamesAndOffsets('P. Pomatomus more words');
        assertEquals('Pomatomus', $result[0]['name']);
        assertEquals(3, $result[0]['offsets'][0]);
        assertEquals(12, $result[0]['offsets'][1]);
    });
    it('gets correct offsets when abbreviations are followed by families', function () use ($parser) {
        $result = $parser->findNamesAndOffsets('P. Animalia more words');
        assertEquals('Animalia', $result[0]['name']);
        assertEquals(3, $result[0]['offsets'][0]);
        assertEquals(11, $result[0]['offsets'][1]);
    });
    it('allows plain text', function () use ($parser) {
        $result = $parser->findNamesAndOffsets('Text <e this would break HTML parsing Amanita muscaria');
        assertEquals('Amanita muscaria', $result[0]['name']);
        $result = $parser->findNamesAndOffsets('Text <e this would break HTML parsing Amanita muscaria', true);
        assertEquals(array(), $result);
    });
    it('gets the name when the string is exactly the name', function () {
        expectNameInText(array('text' => 'Felis leo', 'name' => 'Felis leo'));
    });
    it('gets the name when there is text before or after', function () {
        expectNameInText(array('text' => 'Wow, Felis leo rocks', 'name' => 'Felis leo'));
    });
    it('keeps track of genera to expand abbreviations', function () {
        expectNameInText(array('text' => 'Pomatomus; P. saltator', 'name' => 'Pomatomus', 'index' => 0));
        expectNameInText(array('text' => 'Pomatomus; P. saltator', 'name' => 'Pomatomus saltator', 'index' => 1));
        expectNameInText(array('text' => 'Pomatomus; P. saltator', 'original' => 'P. saltator', 'index' => 1));
    });
    it('limits strings to quadrinomials', function () {
        expectNameInText(array('text' => 'Felis leo', 'name' => 'Felis leo'));
        expectNameInText(array('text' => 'Felis leo leo', 'name' => 'Felis leo leo'));
        expectNameInText(array('text' => 'Felis leo leo leo', 'name' => 'Felis leo leo leo'));
        expectNameInText(array('text' => 'Felis leo leo leo leo', 'name' => 'Felis leo leo leo'));
        expectNameInText(array('text' => 'Felis leo leo leo leo leo', 'name' => 'Felis leo leo leo'));
    });
    it('finds names in species lists', function () {
        expectNameInText(array('text' => 'Felis leo, chaus, catus', 'name' => 'Felis leo', 'index' => 0));
        expectNameInText(array('text' => 'Felis leo, chaus, catus', 'name' => 'Felis chaus', 'index' => 1));
        expectNameInText(array('text' => 'Felis leo, chaus, catus', 'name' => 'Felis catus', 'index' => 2));
    });
    it('finds subgenera', function () {
        expectNameInText(array('text' => 'Felis (Felis) leo', 'name' => 'Felis (Felis) leo'));
    });
    it('doesnt find nonsense subgenera', function () {
        expectNameInText(array('text' => 'Pomatomus (Ignoreme) saltatrix', 'name' => 'Pomatomus'));
    });
    it('expands with subgenera', function () {
        expectNameInText(array('text' => 'Pomatomus; P. (Pomatomus) saltatrix', 'name' => 'Pomatomus', 'index' => 0));
        expectNameInText(array(
            'text' => 'Pomatomus; P. (Pomatomus) saltatrix',
            'name' => 'Pomatomus (Pomatomus) saltatrix', 'index' => 1,
        ));
    });
    it('confirms the first name by default', function () {
        expectNameInText(array('text' => 'Some text Felis leo more text', 'name' => 'Felis leo'));
    });
    it('can take other indices', function () {
        $text = 'Some Animalia Felis leo more text';
        expectNameInText(array('text' => $text, 'name' => 'Animalia', 'index' => 0));
        expectNameInText(array('text' => $text, 'name' => 'Felis leo', 'index' => 1));
    });
    it('drops nomenclatural annotations left messy by OCR', function () use ($parser) {
        // A real line from Insects of Samoa: 'gen. n., sp. n.' misread by OCR.
        $result = $parser->findNamesAndOffsets('15. Pseudoneoborus samoanus, gen. ., sp. 0. x');
        assertEquals('Pseudoneoborus samoanus', $result[0]['name']);
        assertEquals(1, count($result));
    });
    it('keeps an infraspecific rank that is part of the name', function () use ($parser) {
        $result = $parser->findNamesAndOffsets('Amanita muscaria var. formosa is common');
        assertEquals('Amanita muscaria var. formosa', $result[0]['name']);
    });
    it('gets byte offsets right after a multi-byte character', function () use ($parser) {
        // From Insects of Samoa: 'TExt-ric. 1.<em dash>Onconotellus buztoni'.
        // The em dash is three bytes, so skipping one byte would land the
        // offset in the middle of it.
        $text = "TExt-fig. 1.\xE2\x80\x94Onconotellus buxtoni, n. g., n. sp.";
        $result = $parser->findNamesAndOffsets($text);
        list($start, $end) = $result[0]['offsets'];
        assertEquals($result[0]['name'], substr($text, $start, $end - $start));
        assertEquals('Onconotellus buxtoni', $result[0]['name']);
    });
    it('gives a real end offset for a name that ends the text', function () use ($parser) {
        // The JavaScript returns NaN here; see tools/compare.php.
        $result = $parser->findNamesAndOffsets('Felis leo, chaus, catus');
        assertEquals(array(18, 23), $result[2]['offsets']);
    });
});

describe('#isAbbreviatedGenusWithPeriod', function () {
    it('recognizes abbreviations with periods', function () {
        assertEquals('a', Parser::isAbbreviatedGenusWithPeriod('G.'));
        assertEquals('a', Parser::isAbbreviatedGenusWithPeriod('Gr.'));
    });
    it('recognizes non-abbreviations', function () {
        assertFalse(Parser::isAbbreviatedGenusWithPeriod('Gr'));
        assertFalse(Parser::isAbbreviatedGenusWithPeriod('Gro'));
    });
});

describe('#startsWithPunctuation', function () {
    it('recognizes strings starting with punctuation', function () {
        assertTrue(Parser::startsWithPunctuation('.(Felis'));
        assertTrue(Parser::startsWithPunctuation(';Felis'));
    });
    it('recognizes strings not starting with punctuation', function () {
        assertFalse(Parser::startsWithPunctuation('Felis'));
        assertFalse(Parser::startsWithPunctuation('Felis;'));
    });
});

describe('#endsWithPunctuation', function () {
    it('recognizes strings ending with punctuation', function () {
        assertTrue(Parser::endsWithPunctuation('Felis)'));
        assertTrue(Parser::endsWithPunctuation('Felis;'));
    });
    it('recognizes strings not ending with punctuation', function () {
        assertFalse(Parser::endsWithPunctuation('Felis'));
        assertFalse(Parser::endsWithPunctuation(';Felis'));
    });
});

describe('#checkWordAgainstState', function () use ($parser) {
    it('does nothing with null', function () use ($parser) {
        $response = $parser->checkWordAgainstState(null);
        assertNotSet($response, 'workingName');
        assertNotSet($response, 'workingRank');
        assertNotSet($response, 'returnNameHashes');
        assertEquals(array(), $response['genusHistory']);
    });
    it('does nothing with nonsense', function () use ($parser) {
        $response = $parser->checkWordAgainstState('nonsense');
        assertNotSet($response, 'workingName');
        assertNotSet($response, 'workingRank');
        assertNotSet($response, 'returnNameHashes');
        assertEquals(array(), $response['genusHistory']);
    });
    it('returns working name when finding potential abbreviations', function () use ($parser) {
        $response = $parser->checkWordAgainstState('F.', array(
            'workingName' => 'Felis', 'workingRank' => 'genus', 'workingScore' => 'G'));
        assertEquals('F', $response['workingName']);
        assertEquals('genus', $response['workingRank']);
        assertEquals('Felis', $response['returnNameHashes'][0]['name']);
    });
    it('recognizes potential abbreviated genera', function () use ($parser) {
        $response = $parser->checkWordAgainstState('F.');
        assertEquals('F', $response['workingName']);
        assertEquals('genus', $response['workingRank']);
    });
    it('recognizes genera', function () use ($parser) {
        $response = $parser->checkWordAgainstState('Felis');
        assertEquals('Felis', $response['workingName']);
        assertEquals('genus', $response['workingRank']);
    });
    it('recognizes unambiguous genera with full stops', function () use ($parser) {
        $response = $parser->checkWordAgainstState('Forsythia;');
        assertNotSet($response, 'workingName');
        assertEquals('Forsythia', $response['returnNameHashes'][0]['name']);
    });
    it('recognizes families and above', function () use ($parser) {
        $response = $parser->checkWordAgainstState('Animalia');
        assertNotSet($response, 'workingName');
        assertEquals('Animalia', $response['returnNameHashes'][0]['name']);
    });
    it('returns the last known name when encountering nonsense', function () use ($parser) {
        $response = $parser->checkWordAgainstState('nonsense', array(
            'workingName' => 'Felis', 'workingRank' => 'genus', 'workingScore' => 'G'));
        assertNotSet($response, 'workingName');
        assertEquals('Felis', $response['returnNameHashes'][0]['name']);
    });
    it('returns the species name if there is terminating punctuation', function () use ($parser) {
        $response = $parser->checkWordAgainstState('leo;', array(
            'workingName' => 'Felis', 'workingRank' => 'genus', 'workingScore' => 'G'));
        assertNotSet($response, 'workingName');
        assertEquals('Felis leo', $response['returnNameHashes'][0]['name']);
    });
    it('attaches species to genera', function () use ($parser) {
        $response = $parser->checkWordAgainstState('leo', array(
            'workingName' => 'Felis', 'workingRank' => 'genus', 'workingScore' => 'G'));
        assertEquals('Felis leo', $response['workingName']);
        assertEquals('species', $response['workingRank']);
    });
    it('finds genera after genera', function () use ($parser) {
        $response = $parser->checkWordAgainstState('Felis', array(
            'workingName' => 'Felis', 'workingRank' => 'genus', 'workingScore' => 'G'));
        assertEquals('Felis', $response['workingName']);
        assertEquals('genus', $response['workingRank']);
        assertEquals('Felis', $response['returnNameHashes'][0]['name']);
    });
    it('finds families or above after genera', function () use ($parser) {
        $response = $parser->checkWordAgainstState('Animalia', array(
            'workingName' => 'Felis', 'workingRank' => 'genus', 'workingScore' => 'G'));
        assertNotSet($response, 'workingName');
        assertEquals('Felis', $response['returnNameHashes'][0]['name']);
        assertEquals('Animalia', $response['returnNameHashes'][1]['name']);
    });
    it('attaches species to species', function () use ($parser) {
        $response = $parser->checkWordAgainstState('leo', array(
            'workingName' => 'Felis leo', 'workingRank' => 'species', 'workingScore' => 'GS'));
        assertEquals('Felis leo leo', $response['workingName']);
        assertEquals('species', $response['workingRank']);
    });
    it('attaches ranks to species', function () use ($parser) {
        $response = $parser->checkWordAgainstState('var', array(
            'workingName' => 'Felis leo', 'workingRank' => 'species', 'workingScore' => 'GS'));
        assertEquals('Felis leo var', $response['workingName']);
        assertEquals('rank', $response['workingRank']);
    });
    it('attaches ranks to species and keeps punctuation', function () use ($parser) {
        $response = $parser->checkWordAgainstState('var.', array(
            'workingName' => 'Felis leo', 'workingRank' => 'species', 'workingScore' => 'GS'));
        assertEquals('Felis leo var.', $response['workingName']);
        assertEquals('rank', $response['workingRank']);
    });
    it('starts a new name when in species and found genus', function () use ($parser) {
        $response = $parser->checkWordAgainstState('Amanita', array(
            'workingName' => 'Felis leo', 'workingRank' => 'species', 'workingScore' => 'GS'));
        assertEquals('Amanita', $response['workingName']);
        assertEquals('genus', $response['workingRank']);
        assertEquals('Felis leo', $response['returnNameHashes'][0]['name']);
    });
    it('returns two names when in species and found family or above', function () use ($parser) {
        $response = $parser->checkWordAgainstState('Animalia', array(
            'workingName' => 'Felis leo', 'workingRank' => 'species', 'workingScore' => 'GS'));
        assertNotSet($response, 'workingName');
        assertEquals('Felis leo', $response['returnNameHashes'][0]['name']);
        assertEquals('Animalia', $response['returnNameHashes'][1]['name']);
    });
    it('returns species when in species and the next word is nonsense', function () use ($parser) {
        $response = $parser->checkWordAgainstState('asdfasdf', array(
            'workingName' => 'Felis leo', 'workingRank' => 'species', 'workingScore' => 'GS'));
        assertNotSet($response, 'workingName');
        assertEquals('Felis leo', $response['returnNameHashes'][0]['name']);
    });
    it('returns the species name if there is terminating punctuation in species', function () use ($parser) {
        $response = $parser->checkWordAgainstState('leo;', array(
            'workingName' => 'Felis leo', 'workingRank' => 'species', 'workingScore' => 'GS'));
        assertNotSet($response, 'workingName');
        assertEquals('Felis leo leo', $response['returnNameHashes'][0]['name']);
    });
    it('attaches species to ranks', function () use ($parser) {
        $response = $parser->checkWordAgainstState('leo', array(
            'workingName' => 'Felis leo var.', 'workingRank' => 'rank', 'workingScore' => 'GSR'));
        assertEquals('Felis leo var. leo', $response['workingName']);
        assertEquals('species', $response['workingRank']);
    });
    it('starts a new name when in rank and found genus', function () use ($parser) {
        $response = $parser->checkWordAgainstState('Amanita', array(
            'workingName' => 'Felis leo var.', 'workingRank' => 'rank', 'workingScore' => 'GSR'));
        assertEquals('Amanita', $response['workingName']);
        assertEquals('genus', $response['workingRank']);
        assertEquals('Felis leo', $response['returnNameHashes'][0]['name']);
    });
    it('returns two names when in rank and found family or above', function () use ($parser) {
        $response = $parser->checkWordAgainstState('Animalia', array(
            'workingName' => 'Felis leo var.', 'workingRank' => 'rank', 'workingScore' => 'GSR'));
        assertNotSet($response, 'workingName');
        assertEquals('Felis leo', $response['returnNameHashes'][0]['name']);
        assertEquals('Animalia', $response['returnNameHashes'][1]['name']);
    });
    it('returns name when in rank and the next word is nonsense', function () use ($parser) {
        $response = $parser->checkWordAgainstState('asdfasdf', array(
            'workingName' => 'Felis leo var.', 'workingRank' => 'rank', 'workingScore' => 'GSR'));
        assertNotSet($response, 'workingName');
        assertEquals('Felis leo', $response['returnNameHashes'][0]['name']);
    });
    it('expands abbreviated genera', function () use ($parser) {
        $response = $parser->checkWordAgainstState('saltator;', array(
            'workingName' => 'P', 'workingRank' => 'genus', 'workingScore' => 'g'));
        assertNotSet($response, 'workingName');
        assertEquals('P saltator', $response['returnNameHashes'][0]['name']);
    });
    it('doesnt return bad names', function () use ($parser) {
        $response = $parser->checkWordAgainstState('marina;', array(
            'workingName' => 'Stella', 'workingRank' => 'genus', 'workingScore' => 'g'));
        assertNotSet($response, 'workingName');
        assertNotSet($response, 'returnNameHashes');
    });
});

describe('#prepareReturnHash', function () use ($parser) {
    it('fails on short strings', function () use ($parser) {
        assertNull($parser->prepareReturnHash(array('name' => 'Aa')));
    });
    it('fails on empty strings', function () use ($parser) {
        assertNull($parser->prepareReturnHash(array('name' => '')));
    });
    it('fails on just ambiguous genera', function () use ($parser) {
        assertNull($parser->prepareReturnHash(array('name' => 'Felis', 'score' => 'g')));
    });
    it('chops off trailing ranks', function () use ($parser) {
        $response = $parser->prepareReturnHash(array('name' => 'Amanita sp', 'score' => 'GR'));
        assertEquals('Amanita', $response['name']);
    });
    it('fixes capitalization', function () use ($parser) {
        $response = $parser->prepareReturnHash(array('name' => 'AMANITA MUSCARIA', 'score' => 'GS'));
        assertEquals('Amanita muscaria', $response['name']);
        $response = $parser->prepareReturnHash(array('name' => 'AMANITA (AMANITA) MUSCARIA', 'score' => 'GGS'));
        assertEquals('Amanita (Amanita) muscaria', $response['name']);
        $response = $parser->prepareReturnHash(array('name' => 'AMANITA (AMANITA) MUSCARIA MUSCARIA', 'score' => 'GGSS'));
        assertEquals('Amanita (Amanita) muscaria muscaria', $response['name']);
        $response = $parser->prepareReturnHash(array('name' => 'AMANITA (AMANITA) MUSCARIA MUSCARIA MUSCARIA', 'score' => 'GGSSS'));
        assertEquals('Amanita (Amanita) muscaria muscaria muscaria', $response['name']);
    });
    it('avoids infinite loops', function () use ($parser) {
        $response = $parser->prepareReturnHash(array('name' => 'Amanita [] muscaria', 'score' => 'GRS'));
        assertEquals('Amanita [] muscaria', $response['name']);
    });
    it('leaves everything else alone', function () use ($parser) {
        $response = $parser->prepareReturnHash(array('name' => 'Amanita', 'score' => 'G'));
        assertEquals('Amanita', $response['name']);
        $response = $parser->prepareReturnHash(array('name' => 'Amanita muscaria', 'score' => 'GS'));
        assertEquals('Amanita muscaria', $response['name']);
    });
    // Trailing nomenclatural annotations. The JavaScript strips at most one
    // rank, and only when the name ends in exactly 'rank' or 'rank.'.
    it('chops off a trailing rank followed by punctuation', function () use ($parser) {
        // From an OCR'd 'Pseudoneoborus samoanus, gen. ., sp. 0.'
        $response = $parser->prepareReturnHash(
            array('name' => 'Pseudoneoborus samoanus gen. .', 'score' => 'GSR'));
        assertEquals('Pseudoneoborus samoanus', $response['name']);
        assertEquals('GS', $response['score']);
        $response = $parser->prepareReturnHash(
            array('name' => 'Amanita muscaria gen. ,', 'score' => 'GSR'));
        assertEquals('Amanita muscaria', $response['name']);
    });
    it('chops off several trailing ranks', function () use ($parser) {
        $response = $parser->prepareReturnHash(
            array('name' => 'Amanita muscaria gen. nov.', 'score' => 'GSRR'));
        assertEquals('Amanita muscaria', $response['name']);
        assertEquals('GS', $response['score']);
        $response = $parser->prepareReturnHash(array('name' => 'Amanita sp. nov.', 'score' => 'GRR'));
        assertEquals('Amanita', $response['name']);
        assertEquals('G', $response['score']);
    });
    it('leaves ranks inside a name alone', function () use ($parser) {
        $response = $parser->prepareReturnHash(
            array('name' => 'Amanita muscaria var. formosa', 'score' => 'GSRS'));
        assertEquals('Amanita muscaria var. formosa', $response['name']);
        assertEquals('GSRS', $response['score']);
    });
    it('does not chop a name that is not a rank', function () use ($parser) {
        $response = $parser->prepareReturnHash(array('name' => 'Felis leo', 'score' => 'GS'));
        assertEquals('Felis leo', $response['name']);
        $response = $parser->prepareReturnHash(array('name' => 'Amanita [] muscaria', 'score' => 'GRS'));
        assertEquals('Amanita [] muscaria', $response['name']);
    });
});

describe('#scoreSpecies', function () use ($parser) {
    it('fails on special characters', function () use ($parser) {
        assertNull($parser->scoreSpecies(createState('(musculus', 'Mus')));
    });
    it('fails on strings in the species_bad dictionary', function () use ($parser) {
        assertNull($parser->scoreSpecies(createState('phobia', 'Mus')));
    });
    it('fails on strings with numbers in them', function () use ($parser) {
        assertNull($parser->scoreSpecies(createState('phob1a', 'Mus')));
    });
    it('fails on lowercase strings when genera are capitalized', function () use ($parser) {
        assertNull($parser->scoreSpecies(createState('musculus', 'MUS')));
    });
    it('fails on capitalized strings when genera are lower case', function () use ($parser) {
        assertNull($parser->scoreSpecies(createState('MUSCULUS', 'Mus')));
    });
    it('fails on non-species strings', function () use ($parser) {
        assertNull($parser->scoreSpecies(createState('nonsense', 'Mus')));
    });
    it('returns S for valid species', function () use ($parser) {
        assertEquals('S', $parser->scoreSpecies(createState('musculus', 'Mus')));
    });
    it('returns S for valid species when everything is capitalized', function () use ($parser) {
        assertEquals('S', $parser->scoreSpecies(createState('MUSCULUS', 'MUS')));
    });
});

describe('#isNotGenusOrFamily', function () use ($parser) {
    it('true for short strings', function () use ($parser) {
        assertTrue($parser->isNotGenusOrFamily(createState('Aa')));
    });
    it('true for poorly capitalized strings', function () use ($parser) {
        assertTrue($parser->isNotGenusOrFamily(createState('amanita')));
        assertTrue($parser->isNotGenusOrFamily(createState('AMANita')));
    });
    it('true for strings in the overlap dictionary', function () use ($parser) {
        assertTrue($parser->isNotGenusOrFamily(createState('Goliath')));
    });
    it('false for everything else', function () use ($parser) {
        assertFalse($parser->isNotGenusOrFamily(createState('Amanita')));
    });
});

describe('#scoreGenus', function () use ($parser) {
    it('fails on badly capitalized strings', function () use ($parser) {
        assertNull($parser->scoreGenus(createState('AMANita')));
    });
    it('fails on non-genera strings', function () use ($parser) {
        assertNull($parser->scoreGenus(createState('Itsnotaname')));
    });
    it('returns g for ambiguous genera', function () use ($parser) {
        assertEquals('g', $parser->scoreGenus(createState('Tuberosa')));
    });
    it('returns G for unambiguous genera', function () use ($parser) {
        assertEquals('G', $parser->scoreGenus(createState('Amanita')));
    });
});

describe('#scoreFamilyOrAbove', function () use ($parser) {
    it('fails on badly capitalized strings', function () use ($parser) {
        assertNull($parser->scoreFamilyOrAbove(createState('ANIMalia')));
    });
    it('fails on non-family-or-above strings', function () use ($parser) {
        assertNull($parser->scoreFamilyOrAbove(createState('Itsnotaname')));
    });
    it('returns f for ambiguous families', function () use ($parser) {
        assertEquals('f', $parser->scoreFamilyOrAbove(createState('Sorghum')));
    });
    it('returns F for unambiguous families', function () use ($parser) {
        assertEquals('F', $parser->scoreFamilyOrAbove(createState('Animalia')));
    });
});

describe('#scoreRank', function () use ($parser) {
    it('fails on special characters', function () use ($parser) {
        assertNull($parser->scoreRank(createState('(var')));
    });
    it('fails on capital characters', function () use ($parser) {
        assertNull($parser->scoreRank(createState('VAR')));
    });
    it('fails on non-ranks', function () use ($parser) {
        assertNull($parser->scoreRank(createState('nonsense')));
    });
    it('returns R for valid ranks', function () use ($parser) {
        assertEquals('R', $parser->scoreRank(createState('var')));
    });
});

describe('#buildState', function () use ($parser) {
    it('sets reasonable defaults', function () use ($parser) {
        assertEquals(array(), $parser->buildState());
    });
});

// ---------------------------------------------------------------- nametag

describe('#tagText', function () use ($finder) {
    it('wraps found names', function () use ($finder) {
        assertEquals(
            'Wow, <name found="Felis leo">Felis leo</name> rocks',
            $finder->tagText('Wow, Felis leo rocks')
        );
    });
    it('records the original form of an abbreviated name', function () use ($finder) {
        assertEquals(
            '<name found="Pomatomus">Pomatomus</name>, '
            . '<name found="Pomatomus saltator" original="P. saltator">P. saltator</name>',
            $finder->tagText('Pomatomus, P. saltator')
        );
    });
    it('leaves text without names alone', function () use ($finder) {
        assertEquals('nothing here', $finder->tagText('nothing here'));
    });
});

describe('#injectString', function () {
    it('injects at an index', function () {
        assertEquals('abXcd', NameTag::injectString('abcd', 'X', 2));
        assertEquals('Xabcd', NameTag::injectString('abcd', 'X', 0));
        assertEquals('abcdX', NameTag::injectString('abcd', 'X', 4));
    });
});

// -------------------------------------------------------------- PHP extras

describe('#names', function () use ($finder) {
    it('returns unique names in order', function () use ($finder) {
        assertEquals(
            array('Felis leo', 'Amanita muscaria'),
            $finder->names('Felis leo and Amanita muscaria and Felis leo again')
        );
    });
});

describe('#markText', function () use ($finder) {
    it('wraps found names in <mark>, inside a plain <html> element', function () use ($finder) {
        assertEquals(
            "<html>\n<meta charset=\"utf-8\">\n"
            . "<style>mark.nomenclature { background: pink }"
            . " mark.judgment { background: paleturquoise }</style>\n"
            . "Wow, <mark>Felis leo</mark> rocks\n</html>\n",
            $finder->markText('Wow, Felis leo rocks')
        );
    });
    it('ends each line with <br>', function () use ($finder) {
        assertEquals(
            "<html>\n<meta charset=\"utf-8\">\n"
            . "<style>mark.nomenclature { background: pink }"
            . " mark.judgment { background: paleturquoise }</style>\n"
            . "<mark>Felis leo</mark><br>\n<mark>Amanita muscaria</mark>\n</html>\n",
            $finder->markText("Felis leo\nAmanita muscaria")
        );
    });
    it('reads a carriage return as the end of a line too', function () use ($finder) {
        assertEquals(
            "<html>\n<meta charset=\"utf-8\">\n"
            . "<style>mark.nomenclature { background: pink }"
            . " mark.judgment { background: paleturquoise }</style>\n"
            . "a<br>\nb<br>\nc\n</html>\n",
            $finder->markText("a\r\nb\rc")
        );
    });
    it('escapes markup in the source text', function () use ($finder) {
        assertEquals(
            "<html>\n<meta charset=\"utf-8\">\n"
            . "<style>mark.nomenclature { background: pink }"
            . " mark.judgment { background: paleturquoise }</style>\n"
            . "&lt;b&gt; &amp; <mark>Felis leo</mark>\n</html>\n",
            $finder->markText('<b> & Felis leo')
        );
    });
    it('leaves text without names alone', function () use ($finder) {
        assertEquals(
            "<html>\n<meta charset=\"utf-8\">\n"
            . "<style>mark.nomenclature { background: pink }"
            . " mark.judgment { background: paleturquoise }</style>\n"
            . "nothing here\n</html>\n",
            $finder->markText('nothing here')
        );
    });
    it('marks the nomenclatural annotation after a name', function () use ($finder) {
        assertEquals(
            "<html>\n<meta charset=\"utf-8\">\n"
            . "<style>mark.nomenclature { background: pink }"
            . " mark.judgment { background: paleturquoise }</style>\n"
            . "<mark>Deltonotus</mark> <mark class=\"nomenclature\">gen. nov.</mark>\n</html>\n",
            $finder->markText('Deltonotus gen. nov.')
        );
    });
    it('marks the act where it stands, inserting nothing', function () use ($finder) {
        // the comma between the two is the source's own
        assertTrue(strpos($finder->markText('Lygus buxtoni, sp. n.'),
            '<mark>Lygus buxtoni</mark>, <mark class="nomenclature">sp. n.</mark>') !== false);
    });
    it('leaves a name with no annotation in a plain mark', function () use ($finder) {
        assertTrue(strpos($finder->markText('Wow, Felis leo rocks'), 'nomenclature">') === false);
    });
    it('marks HTML in place, without escaping or a wrapper', function () use ($finder) {
        assertEquals(
            '<p>Wow, <mark>Felis leo</mark> rocks</p>',
            $finder->markText('<p>Wow, Felis leo rocks</p>', true)
        );
    });
});

describe('Nomenclature::detect', function () {
    /** Detect the annotation following $name in $text. */
    $acts = function ($text) {
        $end = strpos($text, '|');           // | marks the end of the name
        $text = str_replace('|', '', $text);
        $found = Taxonfinder\Nomenclature::detect($text, $end);
        return $found === null ? null : $found['acts'];
    };
    /** The taxonomic judgments following $name, if any. */
    $judgments = function ($text) {
        $end = strpos($text, '|');
        $text = str_replace('|', '', $text);
        $found = Taxonfinder\Nomenclature::detect($text, $end);
        return $found === null ? null : $found['judgments'];
    };
    /** The open nomenclature qualifiers following $name, if any. */
    $quals = function ($text) {
        $end = strpos($text, '|');
        $text = str_replace('|', '', $text);
        $found = Taxonfinder\Nomenclature::detect($text, $end);
        return $found === null ? null : $found['qualifiers'];
    };
    it('reads the common new-name annotations', function () use ($acts, $judgments) {
        assertEquals(array('sp. nov.'), $acts('Lygus buxtoni|, sp. n. Fig. 3'));
        assertEquals(array('sp. nov.'), $acts('Lygus buxtoni|, sp. nov.'));
        assertEquals(array('sp. nov.'), $acts('Lygus buxtoni| n. sp.'));
        assertEquals(array('gen. nov.'), $acts('Pseudoneoborus| gen. nov.'));
        assertEquals(array('comb. nov.'), $acts('Lygus buxtoni| comb. nov.'));
        // a synonymy takes a view of names already published, so it is a
        // judgment rather than an act
        assertEquals(array(), $acts('Lygus buxtoni| syn. nov.'));
        // a rank change moves a name already published, so it is a judgment
        assertEquals(array(), $acts('Lygus buxtoni| stat. nov.'));
        assertEquals(array('stat. nov.'), $judgments('Lygus buxtoni| stat. nov.'));
        assertEquals(array('syn. nov.'), $judgments('Lygus buxtoni| syn. nov.'));
        assertEquals(array('nom. nov.'), $acts('Lygus buxtoni| nom. nov.'));
        assertEquals(array('subsp. nov.'), $acts('Lygus buxtoni| ssp. nov.'));
    });
    it('reads several acts on one name', function () use ($acts) {
        assertEquals(array('gen. nov.', 'sp. nov.'),
            $acts('Pseudoneoborus samoanus|, gen. n., sp. n. x'));
    });
    it('distinguishes an indeterminate name from a new one', function () use ($acts, $quals) {
        // 'Amanita sp.' announces nothing: an open nomenclature qualifier,
        // not an act. See Sigovini et al. 2016.
        assertEquals(array(), $acts('Amanita| sp.'));
        assertEquals(array('sp.'), $quals('Amanita| sp.'));
        assertEquals(array('sp. nov.'), $acts('Amanita muscaria| sp. nov.'));
        assertEquals(array(), $quals('Amanita muscaria| sp. nov.'));
    });
    it('reports what survived OCR, without inventing the rest', function () use ($acts, $quals) {
        // 'gen. n., sp. n.' misread. The markers are gone, so nothing is
        // announced and what is left are qualifiers.
        assertEquals(array(), $acts('Pseudoneoborus samoanus|, gen. ., sp. 0. x'));
        assertEquals(array('gen.', 'sp.'), $quals('Pseudoneoborus samoanus|, gen. ., sp. 0. x'));
    });
    it('keeps the verbatim text and its own offsets', function () {
        $text = '15. Pseudoneoborus samoanus, gen. n., sp. n. x';
        $found = Taxonfinder\Nomenclature::detect($text, 27);
        assertEquals('gen. n., sp. n.', $found['verbatim']);
        assertEquals('gen. n., sp. n.',
            substr($text, $found['start'], $found['end'] - $found['start']));
    });
    it('reads annotations spelled out in words', function () use ($acts, $judgments) {
        assertEquals(array('sp. nov.'), $acts('Hypogastrura simsi| NEW SPECIES'));
        assertEquals(array('sp. nov.'), $acts('Hypogastrura simsi| new species'));
        assertEquals(array('syn. nov.'), $judgments('Hypogastrura indiana| NEW SYNONYM.'));
        assertEquals(array('comb. nov.'), $acts('Hypogastrura indiana| new combination'));
        assertEquals(array('stat. nov.'), $judgments('Hypogastrura indiana| NEW STATUS'));
        assertEquals(array('gen. nov.'), $acts('Pseudoneoborus| NEW GENUS'));
    });
    it('reaches across an author citation', function () use ($acts, $judgments) {
        // From Entomological News: the annotation sits after the citation
        assertEquals(array('syn. nov.'),
            $judgments('Alabameubria starki| Brown, 1980:188. NEW SYNONYMY'));
        assertEquals(array('syn. nov.'),
            $judgments("Alabameubria starki| Brown, 1980:188. NEW SYNONYMY\nThe following"));
        assertEquals(array('syn. nov.'), $judgments('Felis leo| Smith, 1900, syn. nov.'));
        assertEquals(array('comb. nov.'),
            $acts('Amanita muscaria| (Fr.) Lam., 1783. NEW COMBINATION'));
        assertEquals(array('syn. nov.'),
            $judgments('Felis leo| Guerin-Meneville and Horn, 1861:531. NEW SYNONYMY'));
    });
    it('does not reach across ordinary prose', function () use ($acts) {
        // 'by original designation' is not a citation, and this NEW SYNONYMY
        // belongs to the name opening the entry, not to the nearest one
        assertNull($acts("Alabameubria starki| Brown, by original designa-\ntion. NEW SYNONYMY."));
        assertNull($acts('Felis leo| was collected. New species were described'));
    });
    it('only reaches across a citation when the act finishes the line', function () use ($acts) {
        // Otherwise the 'New species' starting the next sentence would attach
        // itself to the name the previous sentence ended with
        assertNull($acts('Felis leo| Smith. New species were described from Brazil.'));
        assertEquals(array('sp. nov.'), $acts('Felis leo| Smith. New species'));
    });
    it('does not follow a citation across a line break', function () use ($acts) {
        // A page number and the heading after it look exactly like a citation.
        // Without this the heading's own 'gen. n.' is taken by the last name
        // on the previous page.
        assertNull($acts("Anthocoridae| . 201 \n\n\nOnconotellus, gen. n."));
        assertNull($acts("Lygus| . \n\n\nPlesiolygus, gen. n."));
        // ...but an annotation right after the name may still wrap
        assertEquals(array('sp. nov.'), $acts("Amanita muscaria|\nsp. nov."));
    });
    it('needs a surname in the citation, not just a number', function () use ($acts) {
        assertNull($acts('Felis leo| 1923 sp. nov.'));
        assertNull($acts('Felis leo| 1923. NEW SPECIES'));
    });
    it('does not read the spelled out words on their own as acts', function () use ($acts) {
        // These are ordinary prose without 'new' in front of them
        assertNull($acts('Hypogastrura indiana| synonym of harveyi'));
        assertNull($acts('Hypogastrura indiana| combination of characters'));
        assertNull($acts('Hypogastrura indiana| status uncertain'));
    });
    it('finds nothing where there is nothing', function () use ($acts) {
        assertNull($acts('Felis leo| rocks'));
        assertNull($acts('Felis leo|'));
        assertNull($acts('Felisacus filicicola| (Kirkaldy).'));
        assertNull($acts('Vulpes vulpes| (Linnaeus, 1758)'));
    });
    it('does not read an author initial as a new-name marker', function () use ($acts, $quals) {
        // 'N.' is capitalised, so it is an initial, not 'novum'.
        assertNull($acts('Felis leo| N. Smith'));
        assertEquals(array(), $acts('Amanita| sp. N. Smith'));
        assertEquals(array('sp.'), $quals('Amanita| sp. N. Smith'));
    });
    it('stops at anything that is not part of an annotation', function () use ($acts, $quals) {
        assertNull($acts('Felis leo| and then sp. nov.'));
        assertNull($acts('Felis leo| 1923 sp. nov.'));
        assertEquals(array('sp. nov.'), $acts('Felis leo| sp. n. and then some words'));
        // A digit between the words ends the run, so only 'var' is read, and
        // with nothing announcing anything it is a qualifier
        assertEquals(array(), $acts('Felis leo| var 3 nov.'));
        assertEquals(array('var.'), $quals('Felis leo| var 3 nov.'));
    });
});

describe('acts against qualifiers', function () {
    it('reads n. g. and g. n. as a new genus', function () {
        assertEquals(array('gen. nov.'), Taxonfinder\Nomenclature::detect('GREENIDEA, n. g.', 9)['acts']);
        assertEquals(array('gen. nov.'), Taxonfinder\Nomenclature::detect('Hyalopterus, g. n.', 11)['acts']);
    });
    it('will not read a bare g. as anything', function () {
        assertEquals(null, Taxonfinder\Nomenclature::detect('Plate II, g.', 8));
    });
    it('does not read spelled out Genus or Species as standing alone', function () {
        // 'Genus Tettix, Charp.' gives a rank in a list, and
        // 'Key to Cladonotus Species.' heads a key. Neither is an act.
        assertEquals(null, Taxonfinder\Nomenclature::detect('Tettix Genus', 6));
        assertEquals(null, Taxonfinder\Nomenclature::detect('Cladonotus Species.', 10));
    });
    it('still reads the abbreviations as indeterminate', function () {
        // reported, but as qualifiers - they announce nothing
        assertEquals(array('sp.'), Taxonfinder\Nomenclature::detect('Amanita sp.', 7)['qualifiers']);
        assertEquals(array(), Taxonfinder\Nomenclature::detect('Amanita sp.', 7)['acts']);
        assertEquals(array('gen.'), Taxonfinder\Nomenclature::detect('Amanita gen.', 7)['qualifiers']);
    });
    it('still reads them as acts next to a new word', function () {
        assertEquals(array('gen. nov.'), Taxonfinder\Nomenclature::detect('Tettix genus nov.', 6)['acts']);
    });
});

describe('#markText and nomenclature', function () use ($finder) {
    it('marks an act in pink', function () use ($finder) {
        assertTrue(strpos($finder->markText('Deltonotus gen. nov.'),
            '<mark class="nomenclature">gen. nov.</mark>') !== false);
    });
    it('leaves an open nomenclature qualifier unmarked', function () use ($finder) {
        // 'Cicindela, sp.' says the species was not identified; nothing is
        // being announced, so it is not an act and is not coloured as one
        assertTrue(strpos($finder->markText('Synopsis of the Cicindela, sp.'),
            'nomenclature">') === false);
    });
    it('reports the qualifier all the same', function () use ($finder) {
        $annotations = $finder->find('Synopsis of the Cicindela, sp.');
        assertEquals(array(), $annotations[0]['nomenclature']['acts']);
        assertEquals(array('sp.'), $annotations[0]['nomenclature']['qualifiers']);
    });
});

describe('a capitalised specific epithet', function () {
    $names = function ($text) {
        $finder = new Finder();
        $found = array();
        foreach ($finder->find($text) as $annotation) {
            $found[] = $annotation['body']['value'];
        }
        return $found;
    };
    it('takes in a patronym the parser stopped short of', function () use ($names) {
        // botany capitalised an epithet built on a name until the 1950s
        assertEquals(array('Boscia plantefolii'),
            $names('Boscia Plantefolii Hadj Moust. sp. nov.'));
        assertEquals(array('Cleome perrieri'),
            $names('Cleome Perrieri Hadj Moust. sp. nov.'));
    });
    it('leaves a place name alone even with an act behind it', function () use ($names) {
        // Costa is a genus and rica an epithet; 79 occurrences in the corpus
        assertEquals(array(), $names('Costa Rica is a country'));
        assertEquals(array(), $names('collected in South America sp. nov. nearby'));
    });
    it('needs an act, so an ordinary mention is untouched', function () use ($names) {
        // 575 places read this way without being names; only 12 have an act
        assertEquals(array('Cedrus'), $names('the timber of Cedrus Deodara is used'));
    });
    it('needs the epithet to look like a patronym', function () use ($names) {
        assertEquals(array('Boscia'), $names('Boscia Rica Hadj Moust. sp. nov.'));
    });
    it('will not take a word that is a genus in its own right', function () use ($names) {
        $found = $names('Gastropoda Pulmonata sp. nov.');
        assertFalse(in_array('Gastropoda pulmonata', $found, true));
    });
    it('still refuses a shouted epithet', function () use ($names) {
        // MUSCARIA scores as a genus of its own, which it did before this and
        // is a separate matter; what must not happen is it joining Amanita
        assertFalse(in_array('Amanita muscaria', $names('Amanita MUSCARIA sp. nov.'), true));
    });
});

describe('a one letter act against an initial', function () {
    $acts = function ($text, $end) {
        $found = Taxonfinder\Nomenclature::detect($text, $end);
        return $found === null ? array() : $found['acts'];
    };
    it('reads an act through an initial that looks like one', function () use ($acts) {
        // G is an act, for 'n. g.', and also how G.Watt signs his name
        assertEquals(array('comb. nov.'),
            $acts('Oreoseris lacei (G.Watt) V.A.Funk & W.Zheng, comb. nov.', 15));
        assertEquals(array('comb. nov.'),
            $acts('Oreoseris rupicola (T.G.Gao & D.J.N.Hind) X.D.Xu & V.A.Funk, comb. nov.', 18));
    });
    it('reads one through an F initial too', function () use ($acts) {
        assertEquals(array('sp. nov.'),
            $acts('Camposporium chinense Jian Ma & R.F. Castaneda, sp. nov.', 21));
    });
    it('still reads the one letter acts themselves', function () use ($acts) {
        assertEquals(array('gen. nov.'), $acts('GREENIDEA, n. g.', 9));
        assertEquals(array('gen. nov.'), $acts('Hyalopterus, g. n.', 11));
        assertEquals(array('f. nov.'), $acts('Amanita muscaria f. nov.', 16));
    });
    it('leaves a lone letter alone', function () use ($acts) {
        assertEquals(array(), $acts('Plate II, g.', 8));
    });
});

describe('an act read against its name', function () {
    $split = function ($text) {
        $finder = new Finder();
        $found = $finder->find($text);
        if (!$found || !isset($found[0]['nomenclature'])) {
            return array(array(), array());
        }
        return array($found[0]['nomenclature']['acts'],
                     $found[0]['nomenclature']['qualifiers']);
    };
    it('publishes a species only from a binomen', function () use ($split) {
        assertEquals(array(array('sp. nov.'), array()), $split('Amanita muscaria sp. nov.'));
    });
    it('reads sp. nov. on a genus alone as open nomenclature', function () use ($split) {
        // 'Pristiophorus sp. nov.' points at a species not yet named; there
        // is no name there to publish. Sigovini et al. 2016.
        assertEquals(array(array(), array('sp. nov.')), $split('Pristiophorus sp. nov.'));
    });
    it('leaves a new genus or family alone', function () use ($split) {
        // those ranks really are published as one word
        assertEquals(array(array('gen. nov.'), array()), $split('Baruna gen. nov.'));
        assertEquals(array(array('fam. nov.'), array()), $split('Onconotellus fam. nov.'));
    });
    it('applies to the ranks below species too', function () use ($split) {
        assertEquals(array(array(), array('var. nov.')), $split('Pristiophorus var. nov.'));
        assertEquals(array(array('var. nov.'), array()), $split('Amanita muscaria var. nov.'));
    });
});

describe('a qualifier between genus and epithet', function () {
    $read = function ($text) {
        $finder = new Finder();
        $found = $finder->find($text);
        if (!$found) {
            return array(null, array());
        }
        return array($found[0]['body']['value'],
            isset($found[0]['nomenclature']) ? $found[0]['nomenclature']['qualifiers'] : array());
    };
    it('reads the epithet through the qualifier', function () use ($read) {
        // where Sigovini et al. 2016 say the qualifier belongs
        assertEquals(array('Odontostilbe stenodon', array('cf.')),
            $read('Odontostilbe cf. stenodon'));
        assertEquals(array('Pourtalesia alcocki', array('aff.')),
            $read('Pourtalesia aff. alcocki'));
    });
    it('keeps a qualifier that follows the whole name', function () use ($read) {
        assertEquals(array('Amanita muscaria', array('cf.')), $read('Amanita muscaria cf.'));
    });
    it('reads a name with both a qualifier and an act', function () use ($read) {
        $finder = new Finder();
        $found = $finder->find('Amanita cf. muscaria sp. nov.');
        assertEquals('Amanita muscaria', $found[0]['body']['value']);
        assertEquals(array('sp. nov.'), $found[0]['nomenclature']['acts']);
        assertEquals(array('cf.'), $found[0]['nomenclature']['qualifiers']);
    });
    it('needs a capital before it and a lowercase word after', function () use ($read) {
        // 'cf.' opening a sentence, or with no epithet behind it, is left be
        assertEquals(array(null, array()), $read('cf. the account given above'));
        assertEquals(array('Amanita muscaria', array()), $read('Amanita muscaria'));
    });
    it('leaves an ordinary name untouched', function () use ($read) {
        assertEquals(array('Felis leo', array()), $read('Wow, Felis leo rocks'));
    });
});

describe('taxonomic judgments', function () {
    $split = function ($text, $end) {
        $found = Taxonfinder\Nomenclature::detect($text, $end);
        return $found === null
            ? array(array(), array(), array())
            : array($found['acts'], $found['judgments'], $found['qualifiers']);
    };
    it('reads a synonymy as a judgment, not an act', function () use ($split) {
        // it sinks a name published elsewhere; nothing new is published
        assertEquals(array(array(), array('syn. nov.'), array()),
            $split('Lygus buxtoni syn. n.', 13));
    });
    it('reads a rank change the same way', function () use ($split) {
        assertEquals(array(array(), array('stat. nov.'), array()),
            $split('Lygus buxtoni stat. nov.', 13));
    });
    it('leaves the acts alone', function () use ($split) {
        // these do put something into the world
        assertEquals(array(array('comb. nov.'), array(), array()),
            $split('Lygus buxtoni comb. nov.', 13));
        assertEquals(array(array('nom. nov.'), array(), array()),
            $split('Lygus buxtoni nom. nov.', 13));
        assertEquals(array(array('sp. nov.'), array(), array()),
            $split('Lygus buxtoni sp. nov.', 13));
    });
    it('reads a bare synonymy too', function () use ($split) {
        assertEquals(array(array(), array('syn.'), array()),
            $split('Lygus buxtoni syn.', 13));
    });
    it('reads one spelled out, and across a citation', function () use ($split) {
        assertEquals(array(array(), array('syn. nov.'), array()),
            $split('Alabameubria starki Brown, 1980:188. NEW SYNONYMY', 19));
    });
    it('shares a marker along a run like an act does', function () use ($split) {
        // 'syn. et stat. nov.' - one marker, both judgments
        assertEquals(array(array(), array('syn. nov.', 'stat. nov.'), array()),
            $split('Lygus buxtoni syn. et stat. nov.', 13));
    });
    it('colours a judgment apart from an act', function () {
        $finder = new Finder();
        assertTrue(strpos($finder->markText('Lygus buxtoni syn. n.'),
            '<mark class="judgment">syn. n.</mark>') !== false);
        assertTrue(strpos($finder->markText('Deltonotus gen. nov.'),
            '<mark class="nomenclature">gen. nov.</mark>') !== false);
    });
});

describe('open nomenclature qualifiers', function () {
    $of = function ($text, $end) {
        $found = Taxonfinder\Nomenclature::detect($text, $end);
        return $found === null ? null : $found['qualifiers'];
    };
    it('reads the qualifiers of Sigovini et al. 2016', function () use ($of) {
        assertEquals(array('indet.'), $of('Lekanesphaera indet.', 13));
        assertEquals(array('stet.'), $of('Teredinidae stet.', 11));
        assertEquals(array('spp.'), $of('Unio spp.', 4));
        assertEquals(array('prox.'), $of('Pourtalesia prox. alcocki', 11));
        assertEquals(array('nr.'), $of('Pourtalesia nr. alcocki', 11));
        assertEquals(array('gr.'), $of('Pseudocandona gr. eremita', 13));
        assertEquals(array('complex'), $of('Capitella capitata complex', 18));
    });
    it('folds the spellings of confer together', function () use ($of) {
        assertEquals(array('cf.'), $of('Polycera cf. hedgpethi', 8));
        assertEquals(array('cf.'), $of('Polycera cfr. hedgpethi', 8));
        assertEquals(array('cf.'), $of('Polycera conf. hedgpethi', 8));
    });
    it('keeps an act out of the qualifiers', function () use ($of) {
        assertEquals(array(), $of('Amanita muscaria sp. nov.', 16));
    });
    it('never lets a qualifier take a new word', function () {
        // there is no such thing as 'indet. nov.'
        $found = Taxonfinder\Nomenclature::detect('Amanita indet. nov.', 7);
        assertEquals(array(), $found['acts']);
        assertEquals(array('indet.'), $found['qualifiers']);
    });
    it('will not reach a qualifier across an author citation', function () {
        // beyond a citation it is a word in a title, not a statement about a
        // specimen; an act may still be reached there, a qualifier may not
        assertEquals(null, Taxonfinder\Nomenclature::detect('Boscia Hadj Moust. indet.', 6));
    });
});

describe('acts joined into one run', function () {
    $acts = function ($text, $end) {
        $found = Taxonfinder\Nomenclature::detect($text, $end);
        return $found === null ? array() : $found['acts'];
    };
    it('reads gen. et sp. nov. as both', function () use ($acts) {
        assertEquals(array('gen. nov.', 'sp. nov.'),
            $acts('Ptilototheca soutpansbergensis gen. et sp. nov.', 30));
    });
    it('reads "and" and "&" the same way', function () use ($acts) {
        assertEquals(array('gen. nov.', 'sp. nov.'),
            $acts('Ptilototheca soutpansbergensis gen. and sp. nov.', 30));
        assertEquals(array('gen. nov.', 'sp. nov.'),
            $acts('Ptilototheca soutpansbergensis gen. & sp. nov.', 30));
    });
    it('shares a marker written before the run', function () use ($acts) {
        // 'n. g. et sp.' - the genus and the species are both new
        assertEquals(array('gen. nov.', 'sp. nov.'),
            $acts('Hcemocystidium simondi, n. g. et sp.', 22));
    });
    it('leaves a run that writes its own markers alone', function () use ($acts) {
        assertEquals(array('gen. nov.', 'sp. nov.'),
            $acts('Ptilototheca soutpansbergensis gen. nov., sp. nov.', 30));
    });
    it('shares nothing when there is nothing to share', function () use ($acts) {
        assertEquals(array(), $acts('Amanita muscaria sp.', 16));
        assertEquals(array('sp.'),
            Taxonfinder\Nomenclature::detect('Amanita muscaria sp.', 16)['qualifiers']);
    });
    it('will not follow a joining word into the next name', function () use ($acts) {
        assertEquals(array('sp. nov.'),
            $acts('Amanita muscaria sp. nov. and Felis leo is a cat', 16));
    });
    it('never makes an uncertainty qualifier new', function () use ($acts) {
        // 'cf. nov.' is not a thing, whether the marker is beside it
        assertEquals(array(), $acts('Amanita muscaria cf. nov.', 16));
        assertEquals(array('cf.'),
            Taxonfinder\Nomenclature::detect('Amanita muscaria cf. nov.', 16)['qualifiers']);
        assertEquals(array('aff.'),
            Taxonfinder\Nomenclature::detect('Amanita muscaria aff. nov.', 16)['qualifiers']);
    });
});

describe('the annotation vocabulary', function () {
    $acts = function ($text) {
        $end = strpos($text, '|');
        $text = str_replace('|', '', $text);
        $found = Taxonfinder\Nomenclature::detect($text, $end);
        return $found === null ? null : $found['acts'];
    };
    it('comes from dictionaries/annotations.txt', function () use ($acts) {
        assertTrue(is_file(dirname(__DIR__) . '/dictionaries/annotations.txt'),
            'the vocabulary file exists');
        Taxonfinder\Nomenclature::reset();
        // One entry from each section of the file: an act, a new marker
        // reached through a citation, and a citation word
        assertEquals(array('sp. nov.'), $acts('Felis leo| sp. nov.'));
        assertEquals(array('syn. nov.'),
            Taxonfinder\Nomenclature::detect('Felis leo Brown et al., 1980. NEW SYNONYMY', 9)['judgments']);
    });
    it('takes additions at runtime', function () use ($acts) {
        assertNull($acts('Felis leo| nudum'));
        Taxonfinder\Nomenclature::add('act', 'nudum', 'nom. nud.', true);
        // standing alone, so it reports as a qualifier
        assertEquals(array(), $acts('Felis leo| nudum'));
        assertEquals(array('nom. nud.'),
            Taxonfinder\Nomenclature::detect('Felis leo nudum', 9)['qualifiers']);
        Taxonfinder\Nomenclature::reset();
        assertNull($acts('Felis leo| nudum'));
    });
    it('merges a vocabulary file', function () use ($acts) {
        $file = sys_get_temp_dir() . '/taxonfinder-annotations-' . getmypid() . '.txt';
        file_put_contents($file, "# a comment\n\nact  zzztest  test.  bare\nnew  novissima\n");
        Taxonfinder\Nomenclature::reset();
        Taxonfinder\Nomenclature::addFile($file);
        // bare, so a qualifier; beside the new word, an act
        assertEquals(array(), $acts('Felis leo| zzztest'));
        assertEquals(array('test.'),
            Taxonfinder\Nomenclature::detect('Felis leo zzztest', 9)['qualifiers']);
        assertEquals(array('test. nov.'), $acts('Felis leo| zzztest novissima'));
        // the shipped vocabulary is still there
        assertEquals(array('sp. nov.'), $acts('Felis leo| sp. nov.'));
        unlink($file);
        Taxonfinder\Nomenclature::reset();
    });
    it('rejects a malformed line', function () {
        $file = sys_get_temp_dir() . '/taxonfinder-bad-' . getmypid() . '.txt';
        file_put_contents($file, "act  missingcanonical\n");
        Taxonfinder\Nomenclature::reset();
        $thrown = false;
        try {
            Taxonfinder\Nomenclature::addFile($file);
        } catch (Exception $e) {
            $thrown = strpos($e->getMessage(), 'canonical') !== false;
        }
        unlink($file);
        Taxonfinder\Nomenclature::reset();
        assertTrue($thrown, 'a malformed line is reported');
    });
});

describe('#find (annotations)', function () use ($finder) {
    it('builds a W3C-ish annotation', function () use ($finder) {
        $text = 'Wow, Felis leo rocks';
        $annotations = $finder->find($text);
        assertEquals(1, count($annotations));
        $annotation = $annotations[0];
        assertEquals('Annotation', $annotation['type']);
        assertEquals('TextualBody', $annotation['body']['type']);
        assertEquals('identifying', $annotation['body']['purpose']);
        assertEquals('Felis leo', $annotation['body']['value']);

        list($quote, $position) = $annotation['target']['selector'];
        assertEquals('TextQuoteSelector', $quote['type']);
        assertEquals('Wow, ', $quote['prefix']);
        assertEquals('Felis leo', $quote['exact']);
        assertEquals(' rocks', $quote['suffix']);
        assertEquals('TextPositionSelector', $position['type']);
        assertEquals(5, $position['start']);
        assertEquals(14, $position['end']);
        assertEquals($quote['exact'],
            substr($text, $position['start'], $position['end'] - $position['start']));
        assertNotSet($annotation, 'nomenclature');
    });
    it('puts the original string in exact and the interpreted one in body', function () use ($finder) {
        $annotations = $finder->find('Pomatomus; P. saltator');
        assertEquals('Pomatomus saltator', $annotations[1]['body']['value']);
        assertEquals('P. saltator', $annotations[1]['target']['selector'][0]['exact']);
    });
    it('reports the annotation separately from the name', function () use ($finder) {
        $text = '15. Pseudoneoborus samoanus, gen. n., sp. n. x';
        $annotation = $finder->find($text)[0];
        list($quote, $position) = $annotation['target']['selector'];
        // The name span stops at the name
        assertEquals('Pseudoneoborus samoanus', $quote['exact']);
        assertEquals('Pseudoneoborus samoanus', $annotation['body']['value']);
        // and the annotation carries its own
        assertEquals(array('gen. nov.', 'sp. nov.'), $annotation['nomenclature']['acts']);
        assertEquals('gen. n., sp. n.', $annotation['nomenclature']['verbatim']);
        assertTrue($annotation['nomenclature']['start'] >= $position['end']);
        assertEquals('gen. n., sp. n.', substr($text, $annotation['nomenclature']['start'],
            $annotation['nomenclature']['end'] - $annotation['nomenclature']['start']));
    });
    it('takes a configurable amount of context', function () {
        $finder = new Finder(null, 4);
        $quote = $finder->find('Wow, Felis leo rocks')[0]['target']['selector'][0];
        assertEquals('ow, ', $quote['prefix']);
        assertEquals(' roc', $quote['suffix']);
        $finder->setContextLength(0);
        $quote = $finder->find('Wow, Felis leo rocks')[0]['target']['selector'][0];
        assertEquals('', $quote['prefix']);
        assertEquals('', $quote['suffix']);
    });
    it('never cuts a multi-byte character in half', function () {
        // Two bytes of context, with an em dash three bytes away on each
        // side, so a naive cut lands inside it.
        $finder = new Finder(null, 2);
        $text = "x\xE2\x80\x94 Felis leo \xE2\x80\x94x";
        $annotations = $finder->find($text);
        assertEquals('Felis leo', $annotations[0]['body']['value']);
        $quote = $annotations[0]['target']['selector'][0];
        assertTrue(mb_check_encoding($quote['prefix'], 'UTF-8'), 'prefix is valid UTF-8');
        assertTrue(mb_check_encoding($quote['suffix'], 'UTF-8'), 'suffix is valid UTF-8');
        assertTrue(json_encode($quote) !== false, 'encodes as JSON');
    });
    it('keeps spans inside the text', function () use ($finder) {
        $text = 'Felis leo, chaus, catus';
        foreach ($finder->find($text) as $annotation) {
            $position = $annotation['target']['selector'][1];
            assertTrue($position['start'] >= 0 && $position['end'] <= strlen($text),
                $annotation['body']['value'] . ' is within the text');
        }
    });
    it('reads through a sensu stricto qualifier', function () use ($finder) {
        // A real heading from Entomological News
        $text = 'Hypogastrura (s. str.) simsi NEW SPECIES';
        $annotation = $finder->find($text)[0];
        assertEquals('Hypogastrura simsi', $annotation['body']['value']);
        // The original string keeps the qualifier, the interpreted one does not
        assertEquals('Hypogastrura (s. str.) simsi',
            $annotation['target']['selector'][0]['exact']);
        assertEquals(array('sp. nov.'), $annotation['nomenclature']['acts']);
        assertEquals('NEW SPECIES', $annotation['nomenclature']['verbatim']);
        $position = $annotation['target']['selector'][1];
        assertEquals($annotation['target']['selector'][0]['exact'],
            substr($text, $position['start'], $position['end'] - $position['start']));
    });
    it('finds nothing in text without names', function () use ($finder) {
        assertEquals(array(), $finder->find('nothing to see here at all'));
    });
});

describe('#setCarryOverKeyGenus', function () {
    $names = function ($text) {
        $finder = new Finder();
        $finder->setCarryOverKeyGenus(true);
        $found = array();
        foreach ($finder->find($text) as $annotation) {
            $found[$annotation['body']['value']] = true;
        }
        return array_keys($found);
    };
    it('is off unless asked for', function () {
        $finder = new Finder();
        $found = array();
        foreach ($finder->find("Genus Loxilobus.\nexcised. rugosus, sp. nov.") as $annotation) {
            $found[] = $annotation['body']['value'];
        }
        assertEquals(array('Loxilobus'), $found);
    });
    it('carries a genus from Genus X down to a bare epithet', function () use ($names) {
        assertTrue(in_array('Loxilobus rugosus',
            $names("Genus Loxilobus.\nexcised. rugosus, sp. nov."), true));
    });
    it('carries it from a key heading too', function () use ($names) {
        assertTrue(in_array('Criotettix spinilobus',
            $names("Key to Species of Criotettix.\nforward. spinilobus , sp. nov."), true));
    });
    it('takes two epithets together', function () use ($names) {
        assertTrue(in_array('Tettix atypicalis ceylonus',
            $names("Genus Tettix, Bol.\nabbreviated. atypicalis ceylonus , form. nov."), true));
    });
    it('will not read a sentence-final "genus" as a heading', function () use ($names) {
        // '... an undescribed genus. In the following ...' - In is in the
        // genus dictionary, and would otherwise become the section genus.
        assertEquals(array(), $names("an undescribed genus. In stylis , sp. nov."));
        assertEquals(array(), $names("a monotypic genus. This hills , sp. nov."));
    });
    it('needs an act announcing something new, not a bare one', function () use ($names) {
        // 'species' and 'genus' are acts that stand alone, and ordinary words
        assertEquals(array('Spodoptera'),
            $names("Genus Spodoptera.\nSpodoptera is a monotypic genus represented"));
    });
    it('refuses a heading whose genus it cannot read', function () use ($names) {
        // Gladonotus is OCR damage for Cladonotus, so nothing is carried
        // down to latiramus - and Loxilobus, the section before, is not
        // quietly used in its place. Loxilobus itself is still a name.
        assertEquals(array('Loxilobus'),
            $names("Genus Loxilobus.\nGenus Gladonotus.\nparts. latiramus , sp. nov."));
    });
    it('will not supply a genus where the text names its own', function () use ($names) {
        // 'Hcemocystidium simondi, n. g. et sp.' is Haemocystidium with the
        // OCR against it. Unreadable, but it is the genus being named, and
        // handing simondi to the section heading's genus invents a species.
        assertEquals(array('Filaria'),
            $names("Genus Filaria.\nend of paper). Hcemocystidium simondi, n. g. et sp."));
    });
    it('leaves ordinary prose alone', function () use ($names) {
        assertEquals(array('Criotettix'),
            $names("Genus Criotettix.\nthe pronotum is convex and rugosus in form."));
    });
});

describe('Identifiers::find', function () {
    $one = function ($text) {
        $found = Taxonfinder\Identifiers::find($text);
        return $found ? $found[0] : null;
    };
    it('reads a ZooBank act LSID', function () use ($one) {
        $found = $one('Gulella salpinx sp. nov. urn:lsid:zoobank.org:act:C7607188-AF52-4258-BF6A-4956282B6671');
        assertEquals('zoobank', $found['scheme']);
        assertEquals('act', $found['type']);
        assertEquals('urn:lsid:zoobank.org:act:C7607188-AF52-4258-BF6A-4956282B6671', $found['value']);
    });
    it('reads an IPNI LSID', function () use ($one) {
        $found = $one('gen. nov. urn:lsid:ipni.org:names:77123456-1');
        assertEquals('ipni', $found['scheme']);
        assertEquals('urn:lsid:ipni.org:names:77123456-1', $found['value']);
    });
    it('reads a MycoBank number when the registry is named', function () use ($one) {
        assertEquals('MB812345', $one('sp. nov. MycoBank MB 812345')['value']);
    });
    it('leaves a bare MB number alone', function () use ($one) {
        // as likely a museum accession as a MycoBank number
        assertEquals(null, $one('a museum lot MB123456 from the collection'));
    });
    it('reads through the spaces the scanner puts in', function () use ($one) {
        $found = $one('urn: lsid:zoobank.org : act : 51C2C9E7-9514-43DF-A09B-3E391D3B61DD');
        assertEquals('urn:lsid:zoobank.org:act:51C2C9E7-9514-43DF-A09B-3E391D3B61DD', $found['value']);
    });
    it('puts a UUID broken over a line back together', function () use ($one) {
        $found = $one("urn:lsid:zoobank.org:act:2C26E39F-EB96-4864-8F82-\nB7ABCBDD0F1F");
        assertEquals('urn:lsid:zoobank.org:act:2C26E39F-EB96-4864-8F82-B7ABCBDD0F1F', $found['value']);
    });
    it('puts one broken into several pieces back together', function () use ($one) {
        // exactly as it stands in European Journal of Taxonomy 236
        $found = $one('urn: lsid:zoobank.org : author: 0C09EE45-6198-482E-85 7A-EF690C2 AO 16F');
        assertEquals('urn:lsid:zoobank.org:author:0C09EE45-6198-482E-857A-EF690C2A016F',
            $found['value']);
    });
    it('reads l for 1 and O for 0 inside a UUID', function () use ($one) {
        // hex has no l or O, so this cannot be anything else
        $found = $one('urn:lsid:zoobank.org:act:DDCAA18B-CC50-4ECl-B63B-28ABAE6904C2');
        assertEquals('urn:lsid:zoobank.org:act:DDCAA18B-CC50-4EC1-B63B-28ABAE6904C2',
            $found['value']);
    });
    it('stops at the end of the UUID', function () use ($one) {
        $found = $one('urn:lsid:zoobank.org:act:C7607188-AF52-4258-BF6A-4956282B6671 Figs 2-4');
        assertEquals('urn:lsid:zoobank.org:act:C7607188-AF52-4258-BF6A-4956282B6671',
            $found['value']);
    });
    it('keeps the text it came from findable', function () use ($one) {
        $text = 'sp. nov. urn: lsid:zoobank.org : act : 51C2C9E7-9514-43DF-A09B-3E391D3B61DD';
        $found = $one($text);
        assertEquals($found['verbatim'], substr($text, $found['start'], $found['end'] - $found['start']));
    });
});

describe('fungal registry numbers', function () {
    $one = function ($text) {
        $found = Taxonfinder\Identifiers::find($text);
        return $found ? $found[0]['scheme'] . ':' . $found[0]['value'] : null;
    };
    it('reads a bracketed number from an index of new taxa', function () use ($one) {
        assertEquals('indexfungorum:IF557506', $one('Blastophragmia Jian Ma [IF 557506], p. 168'));
        assertEquals('mycobank:MB834522', $one('Neomassaria K.D. Hyde [MB 834522], p. 191'));
        assertEquals('fungalnames:FN570000', $one('Xiaoella pentagona Z.J. Xiao [FN 570000], p. 160'));
    });
    it('reads a bare number where an act stands in front of it', function () use ($one) {
        // how Mycotaxon prints it in the body
        assertEquals('mycobank:MB834819', $one('Helicoma barretoi sp. nov. PLATE 1 MB 834819 Differs'));
        assertEquals('indexfungorum:IF557315', $one('Jian Ma & X.G. Zhang, gen. nov. IF 557315 Differs'));
    });
    it('reads one with the registry spelled out', function () use ($one) {
        assertEquals('mycobank:MB812345', $one('MycoBank MB 812345'));
        assertEquals('indexfungorum:IF557506', $one('Index Fungorum IF557506'));
        assertEquals('fungalnames:FN570000', $one('Fungal Names FN 570000'));
    });
    it('will not take a prefix on its own', function () use ($one) {
        // IF is an English word and MB a museum accession
        assertEquals(null, $one('we asked IF 123456 people attended'));
        assertEquals(null, $one('a museum lot MB123456 from the collection'));
        assertEquals(null, $one('IF 557506 with nothing to say what it is'));
    });
});

describe('identifiers on an annotation', function () {
    it('gives the identifier to the name it is printed under', function () {
        $finder = new Finder();
        $text = "Gulella salpinx sp. nov.\n"
            . "urn:lsid:zoobank.org:act:C7607188-AF52-4258-BF6A-4956282B6671\n"
            . "Figs 2-4. Gulella salpinx is known only from the type locality.";
        $carrying = array();
        foreach ($finder->find($text) as $annotation) {
            if (isset($annotation['identifiers'])) {
                $carrying[] = $annotation['body']['value'];
            }
        }
        // named twice, and only the one the LSID sits under carries it
        assertEquals(array('Gulella salpinx'), $carrying);
    });
    it('does not reach an identifier far from any name', function () {
        $finder = new Finder();
        $text = 'Felis leo is a cat. ' . str_repeat('Filler words here. ', 20)
            . 'urn:lsid:zoobank.org:act:C7607188-AF52-4258-BF6A-4956282B6671';
        foreach ($finder->find($text) as $annotation) {
            assertFalse(isset($annotation['identifiers']));
        }
    });
});

describe('SortedIndex', function () {
    it('finds every term it was built from and nothing else', function () {
        $terms = array('zebra', 'aardvark', 'mole', 'mole', 'Newt', ' vole ', '', 'yak');
        $file = tempnam(sys_get_temp_dir(), 'tfidx');
        file_put_contents($file, implode("\n", $terms));
        $index = SortedIndex::build($file);
        unlink($file);
        assertEquals(6, $index->count());
        foreach (array('zebra', 'aardvark', 'mole', 'newt', 'vole', 'yak') as $term) {
            assertTrue($index->has($term), $term);
        }
        foreach (array('', 'Newt', ' vole ', 'aardvar', 'aardvarkk', 'zzz', 'a', 'zebraa') as $term) {
            assertFalse($index->has($term), $term);
        }
        assertEquals(array('aardvark', 'mole', 'newt', 'vole', 'yak', 'zebra'), $index->terms());
    });
    it('handles an empty index', function () {
        $index = new SortedIndex('');
        assertEquals(0, $index->count());
        assertFalse($index->has('anything'));
    });
});

describe('dated source folders under local/', function () {
    $build = function (array $files) {
        $root = sys_get_temp_dir() . '/taxonfinder-src-' . getmypid();
        @mkdir($root . '/local/bionames-2026-08-26', 0777, true);
        foreach ($files as $name => $contents) {
            file_put_contents($root . '/local/bionames-2026-08-26/' . $name, $contents);
        }
        return $root;
    };
    $clean = function ($root) {
        foreach ((array) glob($root . '/local/bionames-2026-08-26/*') as $file) {
            unlink($file);
        }
        @rmdir($root . '/local/bionames-2026-08-26');
        @rmdir($root . '/local');
        foreach ((array) glob($root . '/cache/*') as $file) {
            unlink($file);
        }
        @rmdir($root . '/cache');
        @rmdir($root);
    };
    it('picks a folder up without being told to', function () use ($build, $clean) {
        $root = $build(array('species_new.txt' => "hadroglossa\nmonsmaripi\n"));
        $dictionaries = new Dictionaries($root);
        assertTrue($dictionaries->has('species_new', 'hadroglossa'));
        assertTrue($dictionaries->has('species_new', 'monsmaripi'));
        assertFalse($dictionaries->has('species_new', 'neverpublished'));
        $clean($root);
    });
    it('ignores whatever else is in the folder', function () use ($build, $clean) {
        $root = $build(array(
            'genera_new.txt' => "Ptilototheca\n",
            'query.sql' => "SELECT genus FROM names;\n",
            'notes.md' => "harvested 2026-08-26\n",
        ));
        $dictionaries = new Dictionaries($root);
        assertTrue($dictionaries->has('genera_new', 'ptilototheca'));
        // the query is not a genus
        assertFalse($dictionaries->has('genera_new', 'select genus from names;'));
        $clean($root);
    });
    it('compiles the folder and caches it', function () use ($build, $clean) {
        $root = $build(array('genera_new.txt' => "Ptilototheca\n"));
        $dictionaries = new Dictionaries($root);
        $dictionaries->load();
        $cached = (array) glob($root . '/cache/local-bionames-2026-08-26-genera_new-*.idx');
        assertEquals(1, count($cached));
        // and a second reading finds the same terms through the cache
        $again = new Dictionaries($root);
        assertTrue($again->has('genera_new', 'ptilototheca'));
        $clean($root);
    });
    it('lets a harvested genus and epithet make a name', function () use ($build, $clean) {
        $root = $build(array(
            'genera_new.txt' => "Ptilototheca\n",
            'species_new.txt' => "hadroglossa\n",
        ));
        $finder = new Finder(new Dictionaries($root));
        $found = array();
        foreach ($finder->find('Ptilototheca hadroglossa gen. et sp. nov.') as $annotation) {
            $found[] = $annotation['body']['value'];
        }
        // neither was ever written down together, the dictionaries being
        // consulted one for the genus and one for the epithet
        assertEquals(array('Ptilototheca hadroglossa'), $found);
        $clean($root);
    });
});

describe('extending the dictionaries', function () {
    it('finds a name added at runtime', function () {
        $finder = new Finder();
        assertEquals(array(), $finder->names('Spamalotus montypythonae was collected'));
        $finder->dictionaries()->add('genera_new', 'Spamalotus');
        $finder->dictionaries()->add('species_new', 'montypythonae');
        assertEquals(array('Spamalotus montypythonae'),
            $finder->names('Spamalotus montypythonae was collected'));
    });
    it('stops finding a name removed at runtime', function () {
        $finder = new Finder();
        assertEquals(array('Felis leo'), $finder->names('Felis leo'));
        $finder->dictionaries()->remove('genera', 'felis');
        assertEquals(array(), $finder->names('Felis leo'));
    });
    it('merges an extra dictionary directory', function () {
        $directory = sys_get_temp_dir() . '/taxonfinder-test-dict-' . getmypid();
        @mkdir($directory);
        file_put_contents($directory . '/genera_new.txt', "Spamalotus\n");
        file_put_contents($directory . '/species_new.txt', "montypythonae\n");
        $dictionaries = new Dictionaries();
        $dictionaries->addDirectory($directory);
        $finder = new Finder($dictionaries);
        assertEquals(array('Spamalotus montypythonae'),
            $finder->names('Spamalotus montypythonae was collected'));
        unlink($directory . '/genera_new.txt');
        unlink($directory . '/species_new.txt');
        rmdir($directory);
    });
    it('keeps separate Finders separate', function () {
        $finder = new Finder(new Dictionaries());
        assertEquals(array(), $finder->names('Spamalotus montypythonae was collected'));
    });
});

// ----------------------------------------------------------------- results

echo "\n\n";
if ($failures) {
    foreach ($failures as $i => $failure) {
        echo '  ', $i + 1, ') ', $failure, "\n";
    }
    echo "\n", count($failures), " of $tests tests failed\n";
    exit(1);
}
echo "$tests tests passed\n";
