<?php
/**
 * Port of node-taxonfinder's lib/dictionaries.js
 *
 * A dictionary is a set of lowercase terms. On disk each one is a plain text
 * file with one term per line, so extending them means editing a text file.
 * Blank lines are ignored; terms are lowercased and trimmed when loaded.
 *
 *   dict_ambig     names that are also everyday words ('lari', 'teresa')
 *   dict_bad       whole names never to report ('Stella marina')
 *   family         family names and above
 *   family_new     extra family names and above
 *   genera         genus names
 *   genera_family  names that are both a genus and a family, treated as family
 *   genera_new     extra genus names
 *   overlap_new    words that look like names but never are ('Goliath')
 *   ranks          rank abbreviations ('var', 'subsp', 'sp')
 *   species        species epithets
 *   species_bad    epithets never to accept ('phobia')
 *   species_new    extra species epithets
 *
 * Three ways to add your own terms, in increasing order of intrusiveness:
 *
 *   1. Append to dictionaries/genera_new.txt, species_new.txt, family_new.txt
 *      or any of the others. These files exist for exactly this purpose.
 *   2. Drop files into dictionaries/local/, e.g. dictionaries/local/genera_new.txt.
 *      Anything there is merged on top of the shipped dictionaries and is a
 *      tidy place to keep your own additions separate.
 *   2a. For a harvest from a database, a folder per source and date:
 *
 *         dictionaries/local/bionames-2026-08-26/genera_new.txt
 *         dictionaries/local/bionames-2026-08-26/species_new.txt
 *         dictionaries/local/bionames-2026-08-26/query.sql
 *
 *      Every folder there is found automatically, and compiled and cached the
 *      way the shipped dictionaries are, so the sorting is done once and not
 *      every run. Files not named after a dictionary are ignored, which is
 *      what lets the query that found the names sit beside them. See
 *      dictionaries/local/README.md.
 *   3. At runtime:
 *        $finder->dictionaries()->add('genera_new', 'Spamalotus');
 *        $finder->dictionaries()->addTerms('species_new', array('montypythonae'));
 *        $finder->dictionaries()->remove('overlap_new', 'goliath');
 *
 * The main directory's files are compiled into a sorted index the first time
 * they are used, and the result is cached in dictionaries/cache/. Edit a
 * dictionary and the index rebuilds itself automatically on the next run.
 */

namespace Taxonfinder;

class Dictionaries
{
    /** The dictionaries the parser knows about. */
    public static $dictionaryNames = array(
        'dict_ambig',
        'dict_bad',
        'family',
        'family_new',
        'genera',
        'genera_family',
        'genera_new',
        'overlap_new',
        'ranks',
        'species',
        'species_bad',
        'species_new',
    );

    /** @var Dictionaries|null */
    private static $shared = null;

    /** @var string the main dictionary directory, compiled to a sorted index */
    private $directory;

    /** @var string[] extra directories, merged in as plain hashes */
    private $extraDirectories = array();

    /** @var string|null where compiled indexes are cached; null disables caching */
    private $cacheDirectory;

    /** @var array<string, SortedIndex> */
    private $indexes = array();

    /** @var array<string, array<string, bool>> additions kept in memory */
    private $overlay = array();

    /** @var bool */
    private $loaded = false;

    /**
     * @param string|null $directory      dictionary directory; defaults to the
     *                                    dictionaries/ folder of this library
     * @param string|null $cacheDirectory where to cache compiled indexes;
     *                                    defaults to <directory>/cache, falling
     *                                    back to the system temp directory
     */
    public function __construct($directory = null, $cacheDirectory = null)
    {
        if ($directory === null) {
            $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dictionaries';
        }
        $this->directory = rtrim($directory, DIRECTORY_SEPARATOR);
        $this->cacheDirectory = $cacheDirectory;
        $local = $this->directory . DIRECTORY_SEPARATOR . 'local';
        if (is_dir($local)) {
            $this->extraDirectories[] = $local;
            // A folder per source and date - local/bionames-2026-08-26/ -
            // holding the same dictionary files, so where a name came from is
            // written down beside it. Anything else in there is ignored, which
            // is what lets the query that found them sit alongside.
            foreach ((array) glob($local . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) as $source) {
                $this->sourceDirectories[] = $source;
            }
            sort($this->sourceDirectories);
        }
    }

    /** @var string[] dated source folders under dictionaries/local/ */
    private $sourceDirectories = array();

    /** @var array<string, SortedIndex[]> compiled indexes from those folders */
    private $sourceIndexes = array();

    /** The lazily created default instance, shared by all Finders. */
    public static function shared()
    {
        if (self::$shared === null) {
            self::$shared = new self();
        }
        return self::$shared;
    }

    /**
     * Merge in another directory of dictionary files. These are read straight
     * into memory rather than compiled, so keep them small.
     */
    public function addDirectory($directory)
    {
        $this->extraDirectories[] = rtrim($directory, DIRECTORY_SEPARATOR);
        if ($this->loaded) {
            $this->loadDirectory($directory);
        }
        return $this;
    }

    /** Load every dictionary. Safe to call repeatedly; only loads once. */
    public function load()
    {
        if ($this->loaded) {
            return $this;
        }
        // Set the flag first: addTerms() calls load(), and we are already loading.
        $this->loaded = true;
        foreach (self::$dictionaryNames as $name) {
            $file = $this->directory . DIRECTORY_SEPARATOR . $name . '.txt';
            if (is_file($file)) {
                $this->indexes[$name] = $this->indexFor($name, $file);
            }
        }
        foreach ($this->sourceDirectories as $directory) {
            $this->loadSourceDirectory($directory);
        }
        foreach ($this->extraDirectories as $directory) {
            $this->loadDirectory($directory);
        }
        return $this;
    }

    /**
     * Load one dated source folder, compiling it the way the shipped
     * dictionaries are compiled rather than reading it every run. A harvest
     * from IPNI or Index Fungorum is not small, and the work of sorting it is
     * worth doing once.
     *
     * The cache key carries the folder name, so two sources contributing to
     * the same dictionary do not overwrite one another's index, and an
     * updated harvest rebuilds only itself.
     */
    private function loadSourceDirectory($directory)
    {
        $source = basename($directory);
        foreach (self::$dictionaryNames as $name) {
            $file = $directory . DIRECTORY_SEPARATOR . $name . '.txt';
            if (!is_file($file)) {
                continue;
            }
            $this->sourceIndexes[$name][] = $this->indexFor('local-' . $source . '-' . $name, $file);
        }
    }

    /** Read every known dictionary file in a directory into the overlay. */
    private function loadDirectory($directory)
    {
        foreach (self::$dictionaryNames as $name) {
            $file = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name . '.txt';
            if (is_file($file)) {
                $this->addFile($name, $file);
            }
        }
    }

    /**
     * Get the compiled index for a dictionary, building and caching it if the
     * source file has changed since it was last compiled.
     */
    private function indexFor($name, $file)
    {
        $cacheDirectory = $this->cacheDirectory();
        if ($cacheDirectory === null) {
            return SortedIndex::build($file);
        }
        $stamp = (int) filemtime($file) . '-' . (int) filesize($file);
        $cacheFile = $cacheDirectory . DIRECTORY_SEPARATOR . $name . '-' . $stamp . '.idx';
        if (is_file($cacheFile)) {
            return SortedIndex::fromFile($cacheFile);
        }
        foreach ((array) glob($cacheDirectory . DIRECTORY_SEPARATOR . $name . '-*.idx') as $stale) {
            @unlink($stale);
        }
        return SortedIndex::build($file, $cacheFile);
    }

    /** Where to cache compiled indexes, or null if nowhere is writable. */
    private function cacheDirectory()
    {
        if ($this->cacheDirectory === null) {
            $candidates = array(
                $this->directory . DIRECTORY_SEPARATOR . 'cache',
                sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'taxonfinder-cache',
            );
            foreach ($candidates as $candidate) {
                if (is_dir($candidate) ? is_writable($candidate) : @mkdir($candidate, 0777, true)) {
                    $this->cacheDirectory = $candidate;
                    break;
                }
            }
            if ($this->cacheDirectory === null) {
                $this->cacheDirectory = false;
            }
        }
        return $this->cacheDirectory === false ? null : $this->cacheDirectory;
    }

    /** Merge the terms in a text file (one per line) into a dictionary. */
    public function addFile($dictionary, $file)
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new \RuntimeException('Could not read dictionary file: ' . $file);
        }
        if (substr($contents, 0, 3) === "\xEF\xBB\xBF") {
            $contents = substr($contents, 3);
        }
        return $this->addTerms($dictionary, explode("\n", $contents));
    }

    /** @param string[] $terms */
    public function addTerms($dictionary, array $terms)
    {
        if (!$this->loaded) {
            $this->load();
        }
        if (!isset($this->overlay[$dictionary])) {
            $this->overlay[$dictionary] = array();
        }
        foreach ($terms as $term) {
            $term = Utility::lower(trim($term));
            if ($term === '') {
                continue;
            }
            $this->overlay[$dictionary][$term] = true;
        }
        return $this;
    }

    /** Add a single term. */
    public function add($dictionary, $term)
    {
        return $this->addTerms($dictionary, array($term));
    }

    /**
     * Remove a single term. Removals live in memory too, so a term from a
     * compiled dictionary comes back the next time the process starts.
     */
    public function remove($dictionary, $term)
    {
        if (!$this->loaded) {
            $this->load();
        }
        $term = Utility::lower(trim($term));
        unset($this->overlay[$dictionary][$term]);
        $this->overlay[$dictionary][$term] = false;
        return $this;
    }

    /**
     * Is $term in $dictionary? $term must already be lowercased and trimmed -
     * the parser does that once per word and this is the hot path.
     */
    public function has($dictionary, $term)
    {
        if (!$this->loaded) {
            $this->load();
        }
        if (isset($this->overlay[$dictionary]) && array_key_exists($term, $this->overlay[$dictionary])) {
            return $this->overlay[$dictionary][$term];
        }
        if (isset($this->indexes[$dictionary]) && $this->indexes[$dictionary]->has($term)) {
            return true;
        }
        if (isset($this->sourceIndexes[$dictionary])) {
            foreach ($this->sourceIndexes[$dictionary] as $index) {
                if ($index->has($term)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Like has(), but lowercases and trims $term first. */
    public function contains($dictionary, $term)
    {
        return $this->has($dictionary, Utility::lower(trim($term)));
    }

    /** Number of terms in a dictionary. */
    public function count($dictionary)
    {
        if (!$this->loaded) {
            $this->load();
        }
        $total = isset($this->indexes[$dictionary]) ? $this->indexes[$dictionary]->count() : 0;
        if (isset($this->sourceIndexes[$dictionary])) {
            // A harvest usually repeats names we already hold, so count only
            // what it adds. Deduplicated across the sources too, two of them
            // being just as likely to overlap.
            $seen = array();
            foreach ($this->sourceIndexes[$dictionary] as $index) {
                foreach ($index->terms() as $term) {
                    if (isset($seen[$term])) {
                        continue;
                    }
                    $seen[$term] = true;
                    if (!isset($this->indexes[$dictionary])
                        || !$this->indexes[$dictionary]->has($term)) {
                        $total++;
                    }
                }
            }
        }
        if (isset($this->overlay[$dictionary])) {
            foreach ($this->overlay[$dictionary] as $term => $present) {
                if ($present && !(isset($this->indexes[$dictionary])
                        && $this->indexes[$dictionary]->has($term))) {
                    $total++;
                } elseif (!$present && isset($this->indexes[$dictionary])
                    && $this->indexes[$dictionary]->has($term)) {
                    $total--;
                }
            }
        }
        return $total;
    }

    /** Delete every cached index, forcing a rebuild on the next run. */
    public function clearCache()
    {
        $cacheDirectory = $this->cacheDirectory();
        if ($cacheDirectory === null) {
            return $this;
        }
        foreach ((array) glob($cacheDirectory . DIRECTORY_SEPARATOR . '*.idx') as $file) {
            @unlink($file);
        }
        return $this;
    }
}
