<?php
/**
 * Renders the source text as HTML with every name found wrapped in <mark>.
 *
 * The HTML is deliberately plain: an <html> element around the text, a charset
 * declaration so accented names survive the round trip, <br> at the end of each
 * line, and <mark> around each name. Nothing else - no head, no styling, no
 * classes - so the output can be dropped straight into a page or styled with a
 * stylesheet of your own.
 *
 *   <html>
 *   <meta charset="utf-8">
 *   The type species is <mark>Pomatomus saltator</mark>.<br>
 *   </html>
 *
 * If the input is already HTML the <mark> elements are injected into it in
 * place, and it is returned as it stands: no escaping, no <br>, no wrapper.
 */

namespace Taxonfinder;

class Marker
{
    /** @var Parser */
    private $parser;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser === null ? new Parser() : $parser;
    }

    /**
     * @param string $text
     * @param bool   $isHtml true if $text is HTML rather than plain text
     * @return string
     */
    public function markText($text, $isHtml = false)
    {
        $text = (string) $text;
        $spans = $this->spans($text, $isHtml);

        $marked = '';
        $position = 0;
        foreach ($spans as $span) {
            list($start, $end) = $span;
            $marked .= $this->render(substr($text, $position, $start - $position), $isHtml);
            $marked .= '<mark>' . $this->render(substr($text, $start, $end - $start), $isHtml) . '</mark>';
            $position = $end;
        }
        $marked .= $this->render(substr($text, $position), $isHtml);

        if ($isHtml) {
            return $marked;
        }
        return "<html>\n<meta charset=\"utf-8\">\n" . $marked . "\n</html>\n";
    }

    /**
     * The offsets of the names, in order, clamped to the text and with any
     * overlaps dropped: a name reported inside one already marked would nest
     * one <mark> inside another.
     *
     * @return array list of array(start, end)
     */
    private function spans($text, $isHtml)
    {
        $length = strlen($text);
        $spans = array();
        foreach ($this->parser->findNamesAndOffsets($text, $isHtml) as $found) {
            list($start, $end) = $found['offsets'];
            $start = max(0, min((int) $start, $length));
            $end = max($start, min((int) $end, $length));
            if ($start === $end) {
                continue;
            }
            $spans[] = array($start, $end);
        }
        usort($spans, function ($a, $b) {
            return $a[0] === $b[0] ? $a[1] - $b[1] : $a[0] - $b[0];
        });

        $kept = array();
        $furthest = 0;
        foreach ($spans as $span) {
            if ($span[0] < $furthest) {
                continue;
            }
            $kept[] = $span;
            $furthest = $span[1];
        }
        return $kept;
    }

    /** A run of source text as it should appear in the output. */
    private function render($piece, $isHtml)
    {
        if ($isHtml) {
            return $piece;
        }
        return self::lineBreaks(self::escape($piece));
    }

    /**
     * Escape the characters that would otherwise be read as markup. Source
     * text is not always valid UTF-8; substitute rather than return nothing.
     */
    private static function escape($piece)
    {
        $flags = ENT_QUOTES;
        if (defined('ENT_SUBSTITUTE')) {
            $flags |= ENT_SUBSTITUTE;
        }
        return htmlspecialchars($piece, $flags, 'UTF-8');
    }

    /** End of line becomes <br>, whichever line ending the source uses. */
    private static function lineBreaks($piece)
    {
        return preg_replace('/\r\n|\r|\n/', "<br>\n", $piece);
    }
}
