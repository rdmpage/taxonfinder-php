<?php
/**
 * The front door of the library. Everything you normally need is here.
 *
 *   require __DIR__ . '/taxonfinder-php/autoload.php';
 *
 *   $names = taxonfinder_find('Wow, Felis leo rocks');
 *   // array(array('name' => 'Felis leo', 'offsets' => array(5, 14)))
 *
 * or, keeping hold of an instance (recommended - the dictionaries are then
 * only loaded once):
 *
 *   $finder = new Taxonfinder\Finder();
 *   foreach ($documents as $document) {
 *       $names = $finder->find($document);
 *   }
 */

namespace Taxonfinder;

class Finder
{
    /** @var Parser */
    private $parser;

    /** @var NameTag */
    private $nameTag;

    /**
     * @param Dictionaries|null $dictionaries pass your own to use a custom set
     *                                        of dictionary files
     */
    public function __construct(?Dictionaries $dictionaries = null)
    {
        $this->parser = new Parser($dictionaries);
        $this->nameTag = new NameTag($this->parser);
    }

    /**
     * Find the scientific names in a piece of text.
     *
     * @param string $text
     * @param bool   $isHtml  true if $text is HTML rather than plain text
     * @return array list of array(
     *                 'name'     => 'Pomatomus saltator',
     *                 'offsets'  => array(start, end),   // byte offsets into $text
     *                 'original' => 'P. saltator',       // only when abbreviated
     *               )
     */
    public function find($text, $isHtml = false)
    {
        return $this->parser->findNamesAndOffsets($text, $isHtml);
    }

    /** Alias of find(), matching the JavaScript library's method name. */
    public function findNamesAndOffsets($text, $isHtml = false)
    {
        return $this->parser->findNamesAndOffsets($text, $isHtml);
    }

    /** Just the names, deduplicated, in the order they first appear. */
    public function names($text, $isHtml = false)
    {
        $names = array();
        foreach ($this->find($text, $isHtml) as $result) {
            $names[$result['name']] = true;
        }
        return array_keys($names);
    }

    /** Return $text with every name wrapped in a <name found="..."> element. */
    public function tagText($text, $isHtml = false)
    {
        return $this->nameTag->tagText($text, $isHtml);
    }

    /**
     * The dictionaries in use, for inspection or extension:
     *
     *   $finder->dictionaries()->add('genera_new', 'Spamalotus');
     */
    public function dictionaries()
    {
        return $this->parser->dictionaries();
    }

    /** The underlying parser, if you need the lower level pieces. */
    public function parser()
    {
        return $this->parser;
    }
}
