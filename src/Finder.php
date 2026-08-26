<?php
/**
 * The front door of the library. Everything you normally need is here.
 *
 *   require __DIR__ . '/taxonfinder-php/autoload.php';
 *
 *   $annotations = taxonfinder_find('Wow, Felis leo rocks');
 *
 * or, keeping hold of an instance (recommended - the dictionaries are then
 * only loaded once):
 *
 *   $finder = new Taxonfinder\Finder();
 *   foreach ($documents as $document) {
 *       $annotations = $finder->find($document);
 *   }
 *
 * find() returns annotation records; see Annotator for their shape. If you
 * want the bare name-and-offset pairs instead, Parser::findNamesAndOffsets()
 * is the level below.
 */

namespace Taxonfinder;

class Finder
{
    /** @var Parser */
    private $parser;

    /** @var Annotator */
    private $annotator;

    /** @var NameTag */
    private $nameTag;

    /** @var Marker */
    private $marker;

    /**
     * @param Dictionaries|null $dictionaries  pass your own to use a custom set
     *                                         of dictionary files
     * @param int               $contextLength bytes of context either side of a
     *                                         name in its TextQuoteSelector
     */
    public function __construct(?Dictionaries $dictionaries = null, $contextLength = 32)
    {
        $this->parser = new Parser($dictionaries);
        $this->annotator = new Annotator($this->parser, $contextLength);
        $this->nameTag = new NameTag($this->parser);
        $this->marker = new Marker($this->annotator);
    }

    /**
     * Find the scientific names in a piece of text.
     *
     * @param string $text
     * @param bool   $isHtml  true if $text is HTML rather than plain text
     * @return array list of annotation records
     */
    public function find($text, $isHtml = false)
    {
        return $this->annotator->annotate($text, $isHtml);
    }

    /** Just the names, deduplicated, in the order they first appear. */
    public function names($text, $isHtml = false)
    {
        $names = array();
        foreach ($this->parser->findNamesAndOffsets($text, $isHtml) as $result) {
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
     * Return $text as plain HTML with every name wrapped in <mark>. See Marker
     * for the shape of the output.
     */
    public function markText($text, $isHtml = false)
    {
        return $this->marker->markText($text, $isHtml);
    }

    /**
     * Carry the genus of a section heading down to the bare epithets beneath
     * it, so a key entry like 'spinilobus , sp. nov.' under 'Key to Species of
     * Criotettix' is reported as 'Criotettix spinilobus'. See KeyGenus. Off by
     * default.
     */
    public function setCarryOverKeyGenus($carryOver)
    {
        $this->annotator->setCarryOverKeyGenus($carryOver);
        return $this;
    }

    /** How much context each TextQuoteSelector carries. Default 32 bytes. */
    public function setContextLength($contextLength)
    {
        $this->annotator->setContextLength($contextLength);
        return $this;
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
