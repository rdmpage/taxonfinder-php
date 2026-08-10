<?php
/**
 * Port of node-taxonfinder's lib/parser.js
 *
 * The parser walks a document one word at a time, keeping a small state hash:
 *
 *   workingName  the name built so far, e.g. 'Felis leo'
 *   workingRank  what the last word was: 'genus', 'species' or 'rank'
 *   workingScore one letter per word so far:
 *                  G unambiguous genus      g ambiguous genus
 *                  F unambiguous family+    f ambiguous family+
 *                  S species epithet        R rank (var., subsp., ...)
 *                  a abbreviated genus ('P.')
 *   genusHistory  first one and two letters of every genus seen, so that a
 *                 later 'P.' can be expanded back to 'Pomatomus'
 *
 * Each word either extends the working name or terminates it, in which case
 * the finished name(s) come back in returnNameHashes.
 */

namespace Taxonfinder;

class Parser
{
    /** @var Dictionaries */
    private $dictionaries;

    public function __construct(?Dictionaries $dictionaries = null)
    {
        $this->dictionaries = $dictionaries === null ? Dictionaries::shared() : $dictionaries;
    }

    public function dictionaries()
    {
        return $this->dictionaries;
    }

    /**
     * Find every scientific name in $text.
     *
     * @param string $text
     * @param bool   $isHtml  strip HTML tags before parsing
     * @return array list of array('name' => string, 'offsets' => array(int, int)
     *               [, 'original' => string])
     */
    public function findNamesAndOffsets($text, $isHtml = false)
    {
        $wordsWithOffsets = Utility::explodeText($text);
        if ($isHtml) {
            $wordsWithOffsets = Utility::removeTagsFromElements($wordsWithOffsets);
        }
        // Sentinel: flushes any name still being built when the text runs out.
        $wordsWithOffsets[] = array('word' => null, 'offset' => strlen((string) $text));

        $currentStateHash = null;
        $nameStartIndex = null;
        $lastWorkingScore = null;
        $allNamesWithOffsets = array();

        $wordCount = count($wordsWithOffsets);
        for ($i = 0; $i < $wordCount; $i++) {
            $word = $wordsWithOffsets[$i]['word'];
            $currentStateHash = $this->checkWordAgainstState($word, $currentStateHash);

            if (isset($currentStateHash['returnNameHashes'])) {
                // Determine the start and end indices of the first returned word.
                // This might be a polynomial or uninomial. Check the number of words
                // by splitting on spaces. Add the name and offsets to the results
                $returnedName = $currentStateHash['returnNameHashes'][0]['name'];
                if ($nameStartIndex === null) {
                    $nameStartIndex = $i;
                }
                // we had an abbreviation, and the return name is one word, so we know we just got it
                if ($lastWorkingScore === 'a' && strpos($returnedName, ' ') === false) {
                    $nameStartIndex = $i;
                }
                $words = explode(' ', $returnedName);
                $lastWord = array_pop($words);
                $nameLastIndex = $nameStartIndex + count($words);
                // A name can have more words than it took up in the text: the
                // genus of 'Felis leo, chaus' is carried over to 'Felis chaus',
                // and the last word of a name that runs to the end of the text
                // lands on the sentinel. Walk back to the last real word.
                // (The JavaScript reads an offset off the sentinel here and
                // returns NaN; see tools/compare.php.)
                if ($nameLastIndex >= $wordCount) {
                    $nameLastIndex = $wordCount - 1;
                }
                while ($nameLastIndex > $nameStartIndex
                       && $wordsWithOffsets[$nameLastIndex]['word'] === null) {
                    $nameLastIndex--;
                    $lastWord = Utility::clean($wordsWithOffsets[$nameLastIndex]['word']);
                }
                $allNamesWithOffsets[] = $this->buildResult(
                    $returnedName,
                    $wordsWithOffsets[$nameStartIndex],
                    $wordsWithOffsets[$nameLastIndex],
                    $lastWord,
                    $nameStartIndex === $nameLastIndex
                );
                // If there are two return names, then the second name will always be
                // a uninomial - a Family or above, or a Genus with stop punctuation
                if (count($currentStateHash['returnNameHashes']) == 2) {
                    $secondName = $currentStateHash['returnNameHashes'][1]['name'];
                    $allNamesWithOffsets[] = $this->buildResult(
                        $secondName,
                        $wordsWithOffsets[$i],
                        $wordsWithOffsets[$i],
                        $secondName,
                        true
                    );
                }
                $nameStartIndex = null;
            }

            if (isset($currentStateHash['workingName']) && $currentStateHash['workingName'] !== '') {
                // There is a workingName, but the index hasn't been set yet, so set it
                if ($nameStartIndex === null) {
                    $nameStartIndex = $i;
                } elseif (strpos($currentStateHash['workingName'], ' ') === false) {
                    // or if the workingName has no space in it
                    $nameStartIndex = $i;
                }
                $lastWorkingScore = isset($currentStateHash['workingScore'])
                    ? $currentStateHash['workingScore'] : null;
            } else {
                // There is no workingName, so reset the start index
                $nameStartIndex = null;
            }
        }
        return $allNamesWithOffsets;
    }

    private function buildResult($name, array $startItem, array $lastItem, $lastWord, $startIsLast)
    {
        $startIndent = self::startIndent((string) $startItem['word']);
        $lastOffset = $lastItem['offset'] + strlen($lastWord);
        if ($startIsLast) {
            $lastOffset += $startIndent;
        }
        $resultHash = array(
            'name' => $name,
            'offsets' => array($startItem['offset'] + $startIndent, $lastOffset),
        );
        if (strpos($name, '[') !== false) {
            // 'P[omatomus] saltator' -> name 'Pomatomus saltator', original 'P. saltator'
            $resultHash['name'] = Utility::replaceFirst('[', '', $resultHash['name']);
            $resultHash['name'] = Utility::replaceFirst(']', '', $resultHash['name']);
            $resultHash['original'] = preg_replace('/\[.*?\]/', '.', $name);
        }
        return $resultHash;
    }

    /**
     * How far into a word the name itself starts, in bytes.
     *
     * This is the same leading run that clean() strips to get the word the
     * dictionaries were matched against, so the reported offsets always
     * bracket the name exactly.
     *
     * The JavaScript skips a single leading non-letter instead, which is one
     * UTF-16 unit. That is not enough for 'Upolu :-Vailima', where the name is
     * behind two punctuation characters, and it lands mid-character on a
     * multi-byte one such as the em dash in '1.-Onconotellus'. Byte offsets
     * make both cases visible; matching clean() fixes both.
     */
    private static function startIndent($word)
    {
        if (preg_match('/^[^0-9A-Za-z]+/', $word, $match)) {
            return strlen($match[0]);
        }
        return 0;
    }

    /**
     * Feed one word to the state machine and get the next state back.
     *
     * @param string|null $word
     * @param array|null  $currentStateHash
     * @return array
     */
    public function checkWordAgainstState($word, $currentStateHash = null)
    {
        if ($word === null || $word === false) {
            $word = '';
        }
        if (!$currentStateHash) {
            $currentStateHash = array();
        }
        $word = trim((string) $word);
        $cleanWord = Utility::clean($word);
        $lowerCaseCleanWord = Utility::lower($cleanWord);
        $capitalizedCleanWord = Utility::ucfirst($lowerCaseCleanWord);
        $lastWord = isset($currentStateHash['lastWord']) ? $currentStateHash['lastWord'] : null;
        $workingName = isset($currentStateHash['workingName']) ? $currentStateHash['workingName'] : '';
        $workingRank = isset($currentStateHash['workingRank']) ? $currentStateHash['workingRank'] : null;
        $workingScore = isset($currentStateHash['workingScore']) ? $currentStateHash['workingScore'] : '';
        $genusHistory = isset($currentStateHash['genusHistory']) ? $currentStateHash['genusHistory'] : array();

        $currentStateHash['word'] = $word;
        $currentStateHash['cleanWord'] = $cleanWord;
        $currentStateHash['lowerCaseCleanWord'] = $lowerCaseCleanWord;
        $currentStateHash['workingName'] = $workingName;
        $nextWorkingName = $workingName . ' ' . $cleanWord;

        if ($cleanWord === '') {
            return $this->buildState(null, null, null, null, $genusHistory,
                array(array('name' => $workingName, 'score' => $workingScore)));
        }

        // Found Abbreviation
        if ($score = self::isAbbreviatedGenusWithPeriod($word)) {
            return $this->buildState($word, $cleanWord, 'genus', $score, $genusHistory,
                array(array('name' => $workingName, 'score' => $workingScore)));
        }
        // Within Genus: a subgenus in parentheses
        if ($workingRank === 'genus' && preg_match('/^\(.*\)$/D', $word)) {
            if ($score = $this->scoreGenus($currentStateHash)) {
                if ($expandedGenus = self::expandGenus($workingName, $genusHistory)) {
                    $workingName = $expandedGenus;
                }
                return $this->buildState($word, $workingName . ' (' . $cleanWord . ')', 'genus',
                    $workingScore . $score, $genusHistory);
            }
        }
        // Within Genus or Species
        if ($workingRank === 'genus' || $workingRank === 'species') {
            // getting valid species
            if ($score = $this->scoreSpecies($currentStateHash)) {
                if ($expandedGenus = self::expandGenus($workingName, $genusHistory)) {
                    $nextWorkingName = $expandedGenus . ' ' . $cleanWord;
                }
                // that is the next in a comma-delimited list
                if (preg_match('/,$/D', (string) $lastWord) && preg_match('/(g|a)s/i', $workingScore)) {
                    $parts = explode(' ', $workingName);
                    $genus = $parts[0];
                    return $this->buildState($word, $genus . ' ' . $cleanWord, 'species',
                        substr($workingScore, 0, 1) . $score, $genusHistory,
                        array(array('name' => $workingName, 'score' => $workingScore)));
                }
                // that is the last epithet in a quadrinomial
                if (preg_match_all('/s/i', $workingScore) >= 2) {
                    return $this->buildState(null, null, null, null, $genusHistory,
                        array(array('name' => $nextWorkingName, 'score' => $workingScore . $score)));
                }
                // that ends the name
                if (self::endsWithPunctuation($word)) {
                    return $this->buildState(null, null, null, null, $genusHistory,
                        array(array('name' => $nextWorkingName, 'score' => $workingScore . $score)));
                }
                // that is the next word in a name
                return $this->buildState($word, $nextWorkingName, 'species',
                    $workingScore . $score, $genusHistory);
            }
        }
        // Within Species
        if ($workingRank === 'species') {
            // getting rank
            if ($score = $this->scoreRank($currentStateHash)) {
                // note the use of `word` here to retain original punctuation
                return $this->buildState($word, $workingName . ' ' . $word, 'rank',
                    $workingScore . $score, $genusHistory);
            }
        }
        // Within Rank
        if ($workingRank === 'rank') {
            // getting species
            if ($score = $this->scoreSpecies($currentStateHash)) {
                return $this->buildState($word, $nextWorkingName, 'species',
                    $workingScore . $score, $genusHistory);
            }
        }
        if ($score = $this->scoreGenus($currentStateHash)) {
            $genusHistory[substr($lowerCaseCleanWord, 0, 1)] = $cleanWord;
            $genusHistory[substr($lowerCaseCleanWord, 0, 2)] = $cleanWord;
            $namesToReturn = array();
            if ($workingName !== '') {
                $namesToReturn[] = array('name' => $workingName, 'score' => $workingScore);
            }
            if (self::endsWithPunctuation($word)) {
                $namesToReturn[] = array('name' => $capitalizedCleanWord, 'score' => $score);
                return $this->buildState(null, null, null, null, $genusHistory, $namesToReturn);
            }
            return $this->buildState($word, $cleanWord, 'genus', $score, $genusHistory, $namesToReturn);
        }
        if ($score = $this->scoreFamilyOrAbove($currentStateHash)) {
            $namesToReturn = array();
            if ($workingName !== '') {
                $namesToReturn[] = array('name' => $workingName, 'score' => $workingScore);
            }
            $namesToReturn[] = array('name' => $capitalizedCleanWord, 'score' => $score);
            return $this->buildState(null, null, null, null, $genusHistory, $namesToReturn);
        }
        // Return the last known name
        return $this->buildState(null, null, null, null, $genusHistory,
            array(array('name' => $workingName, 'score' => $workingScore)));
    }

    /**
     * Assemble the next state. Only keys with meaningful values are set, so
     * isset() on the result tells you whether the parser is mid-name.
     */
    public function buildState($lastWord = null, $workingName = null, $workingRank = null,
                               $workingScore = null, $genusHistory = null, $returnNameHashes = null)
    {
        $returnHash = array();
        $finalNameHashes = array();
        if (!$returnNameHashes) {
            $returnNameHashes = array();
        }
        foreach ($returnNameHashes as $returnNameHash) {
            if ($modified = $this->prepareReturnHash($returnNameHash)) {
                $finalNameHashes[] = $modified;
            }
        }
        // Note: tested against '' rather than with a plain truthiness check,
        // because PHP considers the string '0' falsy where JavaScript does not.
        if ($lastWord !== null && $lastWord !== '') {
            $returnHash['lastWord'] = $lastWord;
        }
        if ($workingName !== null && $workingName !== '') {
            $returnHash['workingName'] = $workingName;
        }
        if ($workingRank !== null && $workingRank !== '') {
            $returnHash['workingRank'] = $workingRank;
        }
        if ($workingScore !== null && $workingScore !== '') {
            $returnHash['workingScore'] = $workingScore;
        }
        // An empty genusHistory is still a genusHistory (JS: {} is truthy).
        if ($genusHistory !== null) {
            $returnHash['genusHistory'] = $genusHistory;
        }
        if (count($finalNameHashes) > 0) {
            $returnHash['returnNameHashes'] = $finalNameHashes;
        }
        return $returnHash;
    }

    /**
     * Tidy up a finished name: fix capitalisation, drop a trailing rank,
     * and reject anything too short, too ambiguous or blacklisted.
     *
     * @return array|null
     */
    public function prepareReturnHash($returnNameHash)
    {
        $name = isset($returnNameHash['name']) ? $returnNameHash['name'] : '';
        $score = isset($returnNameHash['score']) ? $returnNameHash['score'] : '';
        if ($name === null || $name === '') {
            return null;
        }
        if (strlen($name) <= 2) {
            return null;
        }
        if (!preg_match('/[FGS]/', $score)) {
            return null;
        }

        // AMANITA MUSCARIA
        if (preg_match('/^([A-Z])([A-Z\[\]-]*)( |$)(.*$)/D', $name, $match)) {
            $name = $match[1] . strtolower($match[2]) . $match[3] . $match[4];
        }
        // Amanita (MUSCARIA) MUSCARIA
        if (preg_match('/^(.+ \()([A-Z])([A-Z\[\]-]*)(\) |\)$)(.*$)/D', $name, $match)) {
            $name = $match[1] . $match[2] . strtolower($match[3]) . $match[4] . $match[5];
        }
        // Amanita MUSCARIA MUSCARIA
        while (preg_match('/^(.+ )([A-Z\[\]-]*)( |$)(.*$)/D', $name, $match)) {
            $nextString = $match[1] . strtolower($match[2]) . $match[3] . $match[4];
            if ($name === $nextString) {
                break;
            }
            $name = $nextString;
        }
        // Amanita sp. / Amanita muscaria gen. nov. / Pseudoneoborus samoanus gen. .
        //
        // Strip every trailing rank word, ignoring whatever punctuation is
        // sitting around it. The JavaScript strips at most one, and only when
        // the name ends in exactly 'rank' or 'rank.', so a second annotation
        // ('gen. nov.') or OCR debris after the first ('gen. .', 'gen. ,')
        // leaves the rank stuck on the end of the name.
        //
        // Each iteration drops at least a separator and a word, so $name gets
        // strictly shorter and this always terminates.
        while (preg_match('/^(.*[^\s.,;])[\s.,;]+([A-Za-z]+)[\s.,;]*$/D', $name, $match)) {
            if (!$this->dictionaries->has('ranks', Utility::lower($match[2]))) {
                break;
            }
            $name = $match[1];
            if ($score !== '') {
                $score = substr($score, 0, strlen($score) - 1);
            }
        }
        if ($this->dictionaries->has('dict_bad', Utility::lower($name))) {
            return null;
        }

        return array('name' => $name, 'score' => $score);
    }

    /** 'P.' or 'Po.' - a genus abbreviated to one or two letters. */
    public static function isAbbreviatedGenusWithPeriod($workingName)
    {
        return preg_match('/^[A-Z][a-z]?\.$/D', (string) $workingName) ? 'a' : false;
    }

    public static function startsWithPunctuation($word)
    {
        return (bool) preg_match('/^[^A-Za-z]*[\(\.\[;,]/', (string) $word);
    }

    public static function endsWithPunctuation($word)
    {
        return (bool) preg_match('/[;\.\)\]][^A-Za-z]*$/iD', (string) $word);
    }

    /**
     * Turn 'P' back into 'P[omatomus]' using the genera seen so far.
     * Returns null when there is nothing to expand.
     */
    public static function expandGenus($possibleAbbreviation, array $genusHistory)
    {
        if (preg_match('/^([A-Z][a-z]?)$/D', (string) $possibleAbbreviation, $match)) {
            $key = strtolower($match[1]);
            if (isset($genusHistory[$key]) && $genusHistory[$key]) {
                $lastGenus = $genusHistory[$key];
                return $match[1] . '[' . substr($lastGenus, strlen($match[1])) . ']';
            }
        }
        return null;
    }

    /** @return string|null 'S' when the word is a plausible species epithet */
    public function scoreSpecies(array $state)
    {
        $word = isset($state['word']) ? $state['word'] : '';
        $cleanWord = isset($state['cleanWord']) ? $state['cleanWord'] : '';
        $lowerCaseCleanWord = isset($state['lowerCaseCleanWord']) ? $state['lowerCaseCleanWord'] : '';
        $workingName = isset($state['workingName']) ? $state['workingName'] : '';

        // (amanita)
        if (preg_match('/^[^A-Za-z]*[\(\.\[;,]/', $word)) {
            return null;
        }
        if ($this->dictionaries->has('species_bad', $lowerCaseCleanWord)) {
            return null;
        }
        if (preg_match('/[0-9]/', $cleanWord)) {
            return null;
        }
        if (strlen($workingName) > 2 && preg_match('/^[A-Z\-\(\)]+$/D', $workingName)) {
            // AMANITA muscaria
            if (preg_match('/[a-z]/', $cleanWord)) {
                return null;
            }
        } else {
            // Amanita MUSCARIA
            if (preg_match('/[A-Z]/', $cleanWord)) {
                return null;
            }
        }
        if ($this->dictionaries->has('species', $lowerCaseCleanWord)
            || $this->dictionaries->has('species_new', $lowerCaseCleanWord)) {
            return 'S';
        }
        return null;
    }

    /** Too short, badly capitalised, or a known everyday word. */
    public function isNotGenusOrFamily(array $state)
    {
        $cleanWord = isset($state['cleanWord']) ? $state['cleanWord'] : '';
        $lowerCaseCleanWord = isset($state['lowerCaseCleanWord']) ? $state['lowerCaseCleanWord'] : '';

        if (strlen($cleanWord) <= 2) {
            return true;
        }
        if (!preg_match('/^[A-Z][a-z\-]+$/D', $cleanWord) && !preg_match('/^[A-Z\-]+$/D', $cleanWord)) {
            return true;
        }
        if ($this->dictionaries->has('overlap_new', $lowerCaseCleanWord)) {
            return true;
        }
        return false;
    }

    /** @return string|null 'G' for a genus, 'g' when the word is also an English word */
    public function scoreGenus(array $state)
    {
        $lowerCaseCleanWord = isset($state['lowerCaseCleanWord']) ? $state['lowerCaseCleanWord'] : '';
        if ($this->isNotGenusOrFamily($state)) {
            return null;
        }
        if (($this->dictionaries->has('genera', $lowerCaseCleanWord)
                || $this->dictionaries->has('genera_new', $lowerCaseCleanWord))
            && !$this->dictionaries->has('genera_family', $lowerCaseCleanWord)) {
            return $this->dictionaries->has('dict_ambig', $lowerCaseCleanWord) ? 'g' : 'G';
        }
        return null;
    }

    /** @return string|null 'F' for a family or above, 'f' when ambiguous */
    public function scoreFamilyOrAbove(array $state)
    {
        $lowerCaseCleanWord = isset($state['lowerCaseCleanWord']) ? $state['lowerCaseCleanWord'] : '';
        if ($this->isNotGenusOrFamily($state)) {
            return null;
        }
        if ($this->dictionaries->has('family', $lowerCaseCleanWord)
            || $this->dictionaries->has('family_new', $lowerCaseCleanWord)
            || $this->dictionaries->has('genera_family', $lowerCaseCleanWord)) {
            return $this->dictionaries->has('dict_ambig', $lowerCaseCleanWord) ? 'f' : 'F';
        }
        return null;
    }

    /** @return string|null 'R' for 'var.', 'subsp.', 'sp.' and friends */
    public function scoreRank(array $state)
    {
        $word = isset($state['word']) ? $state['word'] : '';
        $cleanWord = isset($state['cleanWord']) ? $state['cleanWord'] : '';
        $lowerCaseCleanWord = isset($state['lowerCaseCleanWord']) ? $state['lowerCaseCleanWord'] : '';

        if (preg_match('/^[^A-Za-z]*[\(\.\[;,]/', $word)) {
            return null;
        }
        if (preg_match('/[A-Z]/', $cleanWord)) {
            return null;
        }
        return $this->dictionaries->has('ranks', $lowerCaseCleanWord) ? 'R' : null;
    }
}
