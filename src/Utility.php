<?php
/**
 * Port of node-taxonfinder's lib/utility.js
 *
 * Note on offsets: JavaScript string offsets are UTF-16 code units, PHP's are
 * bytes. For ASCII text the two agree exactly. For text with non-ASCII
 * characters the PHP offsets are byte offsets, which is what substr(),
 * mb_substr(..., '8bit') and friends expect, so they stay usable in PHP.
 */

namespace Taxonfinder;

class Utility
{
    /** Strip leading and trailing non-alphanumeric characters. */
    public static function clean($string)
    {
        $string = preg_replace('/^[^0-9A-Za-z]+/', '', (string) $string);
        $string = preg_replace('/[^0-9A-Za-z]+$/D', '', $string);
        return $string;
    }

    /**
     * Lowercase a string. Uses mbstring for anything that isn't plain ASCII so
     * that we match JavaScript's Unicode-aware toLowerCase().
     */
    public static function lower($string)
    {
        $lower = strtolower((string) $string);
        if (preg_match('/[\x80-\xFF]/', $lower)
            && function_exists('mb_strtolower')
            && mb_check_encoding($lower, 'UTF-8')) {
            return mb_strtolower($lower, 'UTF-8');
        }
        return $lower;
    }

    /** Uppercase the first character (the input is expected to be lowercase). */
    public static function ucfirst($string)
    {
        if ($string === '' || $string === null) {
            return '';
        }
        return strtoupper($string[0]) . substr($string, 1);
    }

    /**
     * Append $word to the last entry of the list, or start the list with it.
     * Unlike the JavaScript original this returns a new list rather than
     * mutating in place, so callers must use the return value.
     */
    public static function addWordToEndOfOffsetList(array $wordsWithOffsets, $word, $offset = null)
    {
        if (count($wordsWithOffsets) === 0) {
            $wordsWithOffsets[] = array('word' => $word, 'offset' => $offset);
        } else {
            $last = array_pop($wordsWithOffsets);
            $last['word'] .= $word;
            $wordsWithOffsets[] = $last;
        }
        return $wordsWithOffsets;
    }

    /**
     * Split text into candidate words, each with its offset in the source text.
     * Punctuation is kept attached to the word it follows.
     *
     * @return array list of array('word' => string, 'offset' => int)
     */
    public static function explodeText($text)
    {
        $words = preg_split(
            '/( |&nbsp;|<|>|\t|\n|\r|;|\.)/i',
            (string) $text,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );
        $offset = 0;
        $wordsWithOffsets = array();
        $index = 0;
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            // Appending in place rather than through addWordToEndOfOffsetList:
            // passing the list to a function copies it, which would make this
            // loop quadratic. The keys are always 0..n-1, so the last entry is
            // at count() - 1.
            if ($word === '<') {
                $wordsWithOffsets[$index] = array('word' => $word, 'offset' => $offset);
            } elseif ($word === '>' || $word === '.' || $word === ';' || $word === ':'
                      || preg_match('/^\s+$/D', $word)) {
                if (!$wordsWithOffsets) {
                    $wordsWithOffsets[] = array('word' => $word, 'offset' => $offset);
                } else {
                    $wordsWithOffsets[count($wordsWithOffsets) - 1]['word'] .= $word;
                }
            } else {
                if (!isset($wordsWithOffsets[$index])) {
                    $wordsWithOffsets[$index] = array('word' => $word, 'offset' => $offset);
                } else {
                    $wordsWithOffsets[count($wordsWithOffsets) - 1]['word'] .= $word;
                }
                $index += 1;
            }
            $offset += strlen($word);
        }
        return $wordsWithOffsets;
    }

    /** HTML tags that interrupt a name (a name can't span one of these). */
    public static function isStopTag($tag)
    {
        $tag = strtolower((string) $tag);
        if (isset($tag[0]) && $tag[0] === '/') {
            $tag = substr($tag, 1);
        }
        return in_array($tag, array('p', 'td', 'tr', 'table', 'hr', 'ul', 'li'), true);
    }

    /**
     * Drop HTML tags from an exploded word list. Stop tags are replaced by a
     * null word, which resets the parser state.
     */
    public static function removeTagsFromElements(array $wordsWithOffsets)
    {
        $withinTag = false;
        $finalWords = array();
        foreach ($wordsWithOffsets as $item) {
            $word = isset($item['word']) ? trim($item['word']) : '';
            if ($withinTag) {
                // ...tag>
                if (preg_match('/^(.*)>$/im', $word)) {
                    $withinTag = false;
                }
                continue;
            }
            // <tag>
            if (preg_match('/^<(\/?.*[a-z0-9-]\/?)>$/im', $word, $match)) {
                if (self::isStopTag($match[1])) {
                    $finalWords[] = array('word' => null);
                }
                continue;
            }
            // <tag...
            if (preg_match('/^<([a-z0-9!].*)$/im', $word, $match)) {
                if (self::isStopTag($match[1])) {
                    $finalWords[] = array('word' => null);
                }
                $withinTag = true;
                continue;
            }
            $finalWords[] = $item;
        }
        return $finalWords;
    }

    /**
     * Parenthetical qualifiers that are not subgenera, normalised to letters
     * only: (s. str.), (s.s.), (s. lat.), (s.l.), (sensu stricto), (sensu lato).
     */
    private static $qualifiers = array(
        'sstr' => true, 'ss' => true, 'slat' => true, 'sl' => true, 'sampl' => true,
        'sensustricto' => true, 'sensulato' => true, 'sensuamplo' => true,
        'sstrict' => true, 'slato' => true,
    );

    /**
     * Open nomenclature qualifiers that stand between a genus and an epithet,
     * where Sigovini et al. 2016 say they belong: 'Odontostilbe cf. stenodon',
     * 'Bryconamericus aff. novae'. Left in place they end the name at the
     * genus and the epithet is lost.
     */
    private static $interposed = array(
        'cf' => true, 'cfr' => true, 'conf' => true, 'aff' => true,
        'prox' => true, 'nr' => true, 'gr' => true,
    );

    /**
     * Drop qualifiers such as the '(s. str.)' in
     * 'Hypogastrura (s. str.) simsi', which otherwise cuts the name in two and
     * loses the species. A real subgenus, '(Felis)' in 'Felis (Felis) leo', is
     * left alone, as is '(sensu Christiansen and Bellinger)', which qualifies a
     * group rather than a name.
     *
     * The remaining words keep their original offsets, so a name spanning a
     * qualifier reports a span that includes it.
     */
    public static function removeQualifiers(array $wordsWithOffsets)
    {
        $finalWords = array();
        $count = count($wordsWithOffsets);
        for ($i = 0; $i < $count; $i++) {
            $last = self::qualifierEndsAt($wordsWithOffsets, $i, $count);
            if ($last !== null) {
                $i = $last;
                continue;
            }
            if (self::isInterposedQualifier($wordsWithOffsets, $i, $count, $finalWords)) {
                continue;
            }
            $finalWords[] = $wordsWithOffsets[$i];
        }
        return $finalWords;
    }

    /**
     * Is this word a qualifier standing between a genus and its epithet?
     *
     * Only there. A capital before it and a lowercase word after it is what
     * says so, and without both the word is left alone - 'cf.' opening a
     * sentence, or following a whole name, is somebody else's business. The
     * qualifier is not lost by being dropped here: it falls inside the span
     * the finished name carries, and Annotator reads it back out.
     */
    private static function isInterposedQualifier(array $words, $index, $count, array $kept)
    {
        if (!$kept || !isset($words[$index]['word'])) {
            return false;
        }
        $word = strtolower(self::clean($words[$index]['word']));
        if (!isset(self::$interposed[$word])) {
            return false;
        }
        $before = self::clean($kept[count($kept) - 1]['word']);
        if ($before === '' || !preg_match('/^[A-Z]/', $before)) {
            return false;
        }
        if (!isset($words[$index + 1]['word'])) {
            return false;
        }
        $after = self::clean($words[$index + 1]['word']);
        return $after !== '' && preg_match('/^[a-z]/', $after);
    }

    /**
     * If a qualifier starts at $start, the index of the word it ends on.
     * A qualifier may be split over several words, because explodeText breaks
     * '(s. str.)' at every full stop and space.
     *
     * @return int|null
     */
    private static function qualifierEndsAt(array $words, $start, $count)
    {
        $text = '';
        $limit = min($start + 4, $count);
        for ($i = $start; $i < $limit; $i++) {
            if (!isset($words[$i]['word'])) {
                return null;
            }
            $word = trim($words[$i]['word']);
            if ($i === $start && substr($word, 0, 1) !== '(') {
                return null;
            }
            $text .= $word;
            if (substr($text, -1) === ')') {
                $inside = strtolower(preg_replace('/[^A-Za-z]+/', '', substr($text, 1, -1)));
                return isset(self::$qualifiers[$inside]) ? $i : null;
            }
        }
        return null;
    }

    /** Replace only the first occurrence of $search (JS String.replace semantics). */
    public static function replaceFirst($search, $replace, $subject)
    {
        $position = strpos($subject, $search);
        if ($position === false) {
            return $subject;
        }
        return substr_replace($subject, $replace, $position, strlen($search));
    }
}
