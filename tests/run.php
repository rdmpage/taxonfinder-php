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
    global $finder;
    if (!isset($options['text'])) {
        return;
    }
    $index = isset($options['index']) ? $options['index'] : 0;
    $result = $finder->find($options['text']);
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
            'species' => 660011, 'species_new' => 1840, 'species_bad' => 1187, 'ranks' => 158,
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

describe('#findNamesAndOffsets', function () use ($finder) {
    it('finds and returns names and offsets', function () use ($finder) {
        $result = $finder->find('The quick brown Animalia Vulpes vulpes (Canidae; Carnivora; '
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
    it('expands abbreviated genera', function () use ($finder) {
        $result = $finder->find('Pomatomus, P. saltator');
        assertEquals('Pomatomus', $result[0]['name']);
        assertEquals(0, $result[0]['offsets'][0]);
        assertEquals(9, $result[0]['offsets'][1]);
        assertEquals('Pomatomus saltator', $result[1]['name']);
        assertEquals('P. saltator', $result[1]['original']);
        assertEquals(11, $result[1]['offsets'][0]);
        assertEquals(22, $result[1]['offsets'][1]);
    });
    it('gets correct offsets when the string is exactly the name', function () use ($finder) {
        $result = $finder->find('Amanita muscaria');
        assertEquals('Amanita muscaria', $result[0]['name']);
        assertEquals(0, $result[0]['offsets'][0]);
        assertEquals(16, $result[0]['offsets'][1]);
    });
    it('gets correct offsets when abbreviations are followed by genera', function () use ($finder) {
        $result = $finder->find('P. Pomatomus more words');
        assertEquals('Pomatomus', $result[0]['name']);
        assertEquals(3, $result[0]['offsets'][0]);
        assertEquals(12, $result[0]['offsets'][1]);
    });
    it('gets correct offsets when abbreviations are followed by families', function () use ($finder) {
        $result = $finder->find('P. Animalia more words');
        assertEquals('Animalia', $result[0]['name']);
        assertEquals(3, $result[0]['offsets'][0]);
        assertEquals(11, $result[0]['offsets'][1]);
    });
    it('allows plain text', function () use ($finder) {
        $result = $finder->find('Text <e this would break HTML parsing Amanita muscaria');
        assertEquals('Amanita muscaria', $result[0]['name']);
        $result = $finder->find('Text <e this would break HTML parsing Amanita muscaria', true);
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
    it('gives a real end offset for a name that ends the text', function () use ($finder) {
        // The JavaScript returns NaN here; see tools/compare.php.
        $result = $finder->find('Felis leo, chaus, catus');
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

describe('extending the dictionaries', function () {
    it('finds a name added at runtime', function () {
        $finder = new Finder();
        assertEquals(array(), $finder->find('Spamalotus montypythonae was collected'));
        $finder->dictionaries()->add('genera_new', 'Spamalotus');
        $finder->dictionaries()->add('species_new', 'montypythonae');
        assertEquals('Spamalotus montypythonae',
            $finder->find('Spamalotus montypythonae was collected')[0]['name']);
    });
    it('stops finding a name removed at runtime', function () {
        $finder = new Finder();
        assertEquals('Felis leo', $finder->find('Felis leo')[0]['name']);
        $finder->dictionaries()->remove('genera', 'felis');
        assertEquals(array(), $finder->find('Felis leo'));
    });
    it('merges an extra dictionary directory', function () {
        $directory = sys_get_temp_dir() . '/taxonfinder-test-dict-' . getmypid();
        @mkdir($directory);
        file_put_contents($directory . '/genera_new.txt', "Spamalotus\n");
        file_put_contents($directory . '/species_new.txt', "montypythonae\n");
        $dictionaries = new Dictionaries();
        $dictionaries->addDirectory($directory);
        $finder = new Finder($dictionaries);
        assertEquals('Spamalotus montypythonae',
            $finder->find('Spamalotus montypythonae was collected')[0]['name']);
        unlink($directory . '/genera_new.txt');
        unlink($directory . '/species_new.txt');
        rmdir($directory);
    });
    it('keeps separate Finders separate', function () {
        $finder = new Finder(new Dictionaries());
        assertEquals(array(), $finder->find('Spamalotus montypythonae was collected'));
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
