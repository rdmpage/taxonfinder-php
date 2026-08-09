<?php
/**
 * A set of terms held as one sorted, newline separated string and searched
 * with a binary search.
 *
 * The dictionaries are big: genera.txt and species.txt have about 1.1 million
 * terms between them. As a PHP hash they need ~110 MB, which is more than the
 * default memory_limit of 128M. Held like this they need ~14 MB, load in a
 * couple of milliseconds, and a lookup still costs only a few microseconds.
 *
 * The sorted form is built once and cached; see Dictionaries.
 */

namespace Taxonfinder;

class SortedIndex
{
    /** @var string terms, lowercase, sorted, unique, each followed by "\n" */
    private $blob;

    public function __construct($blob)
    {
        $this->blob = (string) $blob;
    }

    /** Read a previously built index file. */
    public static function fromFile($file)
    {
        $blob = file_get_contents($file);
        if ($blob === false) {
            throw new \RuntimeException('Could not read index file: ' . $file);
        }
        return new self($blob);
    }

    /**
     * Build the sorted form from a dictionary text file (one term per line,
     * any case, any order, duplicates allowed).
     *
     * @param string      $sourceFile
     * @param string|null $destinationFile  where to write it; null to only
     *                                      keep it in memory
     * @return SortedIndex
     */
    public static function build($sourceFile, $destinationFile = null)
    {
        $contents = file_get_contents($sourceFile);
        if ($contents === false) {
            throw new \RuntimeException('Could not read dictionary file: ' . $sourceFile);
        }
        // Strip a UTF-8 BOM; JavaScript's trim() removes it, PHP's does not.
        if (substr($contents, 0, 3) === "\xEF\xBB\xBF") {
            $contents = substr($contents, 3);
        }
        $terms = explode("\n", $contents);
        unset($contents);

        foreach ($terms as $i => $term) {
            $terms[$i] = Utility::lower(trim($term));
        }
        sort($terms, SORT_STRING);

        $blob = '';
        $chunk = array();
        $previous = null;
        foreach ($terms as $term) {
            if ($term === '' || $term === $previous) {
                continue;
            }
            $previous = $term;
            $chunk[] = $term;
            if (count($chunk) >= 20000) {
                $blob .= implode("\n", $chunk) . "\n";
                $chunk = array();
            }
        }
        if ($chunk) {
            $blob .= implode("\n", $chunk) . "\n";
        }
        unset($terms, $chunk);

        if ($destinationFile !== null) {
            // Write then rename, so that two processes building at the same
            // time can't leave a half written index behind.
            $temporary = $destinationFile . '.' . getmypid() . '.tmp';
            if (@file_put_contents($temporary, $blob) !== false) {
                if (!@rename($temporary, $destinationFile)) {
                    @unlink($temporary);
                }
            }
        }
        return new self($blob);
    }

    /**
     * Is $term in the set? $term must already be lowercased and trimmed.
     *
     * Binary search over line starts: snap the midpoint forward to the start
     * of a line, compare, and narrow. Both bounds move on every iteration, so
     * this always terminates.
     */
    public function has($term)
    {
        $blob = $this->blob;
        $length = strlen($blob);
        $low = 0;
        $high = $length;
        while ($low < $high) {
            $middle = ($low + $high) >> 1;
            if ($middle > $low) {
                $newline = strpos($blob, "\n", $middle - 1);
                $start = ($newline === false) ? $length : $newline + 1;
            } else {
                $start = $low;
            }
            if ($start >= $high) {
                // No line begins in [middle, high), so look lower down.
                $high = $middle;
                continue;
            }
            $end = strpos($blob, "\n", $start);
            if ($end === false) {
                $end = $length;
            }
            $comparison = strcmp(substr($blob, $start, $end - $start), $term);
            if ($comparison === 0) {
                return true;
            }
            if ($comparison < 0) {
                $low = $end + 1;
            } else {
                $high = $start;
            }
        }
        return false;
    }

    /** How many terms are in the set. */
    public function count()
    {
        return $this->blob === '' ? 0 : substr_count($this->blob, "\n");
    }

    /** Every term, in sorted order. Only useful for inspection. */
    public function terms()
    {
        if ($this->blob === '') {
            return array();
        }
        return explode("\n", rtrim($this->blob, "\n"));
    }
}
