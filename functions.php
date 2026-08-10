<?php
/**
 * Convenience functions wrapping a single shared Finder.
 *
 * Loaded automatically by autoload.php and by Composer.
 */

if (!function_exists('taxonfinder')) {
    /**
     * The shared Finder. The dictionaries behind it are loaded on first use and
     * kept for the life of the process.
     *
     * @return \Taxonfinder\Finder
     */
    function taxonfinder()
    {
        static $finder = null;
        if ($finder === null) {
            $finder = new \Taxonfinder\Finder();
        }
        return $finder;
    }
}

if (!function_exists('taxonfinder_find')) {
    /**
     * Find scientific names in text, as annotation records. See Annotator for
     * their shape; Parser::findNamesAndOffsets() is the level below if all you
     * want is names and offsets.
     *
     * @param string $text
     * @param bool   $isHtml
     * @return array list of annotation records
     */
    function taxonfinder_find($text, $isHtml = false)
    {
        return taxonfinder()->find($text, $isHtml);
    }
}

if (!function_exists('taxonfinder_names')) {
    /**
     * Find scientific names in text, returning just the unique name strings.
     *
     * @param string $text
     * @param bool   $isHtml
     * @return string[]
     */
    function taxonfinder_names($text, $isHtml = false)
    {
        return taxonfinder()->names($text, $isHtml);
    }
}

if (!function_exists('taxonfinder_tag')) {
    /**
     * Wrap every name found in $text in a <name found="..."> element.
     *
     * @param string $text
     * @param bool   $isHtml
     * @return string
     */
    function taxonfinder_tag($text, $isHtml = false)
    {
        return taxonfinder()->tagText($text, $isHtml);
    }
}
