<?php
/**
 * Port of node-taxonfinder's lib/nametag.js
 *
 * Wraps every name found in the source text in a <name> element.
 */

namespace Taxonfinder;

class NameTag
{
    /** @var Parser */
    private $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser === null ? new Parser() : $parser;
    }

    /**
     * @param string $text
     * @param bool   $isHtml
     * @return string
     */
    public function tagText($text, $isHtml = false)
    {
        $lengthOfInjectedText = 0;
        $endTag = '</name>';
        $namesAndOffsets = $this->parser->findNamesAndOffsets($text, $isHtml);
        foreach ($namesAndOffsets as $found) {
            $startTag = '<name found="' . $found['name'] . '"';
            if (isset($found['original'])) {
                $startTag .= ' original="' . $found['original'] . '"';
            }
            $startTag .= '>';
            $text = self::injectString($text, $startTag, $found['offsets'][0] + $lengthOfInjectedText);
            $lengthOfInjectedText += strlen($startTag);
            $text = self::injectString($text, $endTag, $found['offsets'][1] + $lengthOfInjectedText);
            $lengthOfInjectedText += strlen($endTag);
        }
        // TODO: trying to clean up tags within tags. This is sloppy
        $text = preg_replace('/(<name[^>]+>[^<]*?)(<\/[a-mo-z].*?>)/im', '$2$1', $text);
        $text = preg_replace('/(<name[^>]+>[^<]*)(<[a-z].*?>)(.*?<\/name>)/im', '$2$1$3', $text);
        $text = preg_replace('/(<name[^>]+>[^<]*?)(<\/[a-mo-z].*?>)/im', '$2$1', $text);
        $text = preg_replace('/(<name[^>]+>[^<]*)(<[a-z].*?>)(.*?<\/name>)/im', '$2$1$3', $text);
        return $text;
    }

    public static function injectString($sourceString, $injection, $atIndex)
    {
        $atIndex = max(0, min((int) $atIndex, strlen($sourceString)));
        return substr($sourceString, 0, $atIndex) . $injection . substr($sourceString, $atIndex);
    }
}
