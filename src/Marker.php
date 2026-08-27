<?php
/**
 * Renders the source text as HTML with every name found wrapped in <mark>,
 * and the nomenclatural annotation that follows one, where there is one, in a
 * <mark> of its own.
 *
 * The HTML is deliberately plain: an <html> element around the text, a charset
 * declaration so accented names survive the round trip, one style rule, <br>
 * at the end of each line, and <mark> around each name. Nothing else - no
 * head, no classes beyond the one - so the output can be dropped straight into
 * a page or restyled.
 *
 *   <html>
 *   <meta charset="utf-8">
 *   <style>mark.nomenclature { background: pink }</style>
 *   <mark>Deltonotus</mark> <mark class="nomenclature">gen. nov.</mark><br>
 *   </html>
 *
 * The act is marked where it stands in the text, which is just after the name.
 * Nothing is inserted, so the text still reads as it was scanned.
 *
 * If the input is already HTML the <mark> elements are injected into it in
 * place, and it is returned as it stands: no escaping, no <br>, no wrapper.
 */

namespace Taxonfinder;

class Marker
{
    /** @var Annotator */
    private $annotator;

    /** The one style rule, so the act reads as an aside beside the name. */
    const STYLE = '<style>mark.nomenclature { background: pink }'
        . ' mark.judgment { background: paleturquoise }</style>';

    public function __construct(?Annotator $annotator = null)
    {
        $this->annotator = $annotator === null ? new Annotator() : $annotator;
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
            list($start, $end, $kind) = $span;
            $open = $kind === 'name' ? '<mark>' : '<mark class="' . $kind . '">';
            $marked .= $this->render(substr($text, $position, $start - $position), $isHtml);
            $marked .= $open . $this->render(substr($text, $start, $end - $start), $isHtml) . '</mark>';
            $position = $end;
        }
        $marked .= $this->render(substr($text, $position), $isHtml);

        if ($isHtml) {
            return $marked;
        }
        return "<html>\n<meta charset=\"utf-8\">\n" . self::STYLE . "\n"
            . $marked . "\n</html>\n";
    }

    /**
     * The offsets to mark, in order, clamped to the text and with any overlaps
     * dropped: a span reported inside one already marked would nest one <mark>
     * inside another.
     *
     * @return array list of array(start, end, kind)
     */
    private function spans($text, $isHtml)
    {
        $length = strlen($text);
        $spans = array();
        foreach ($this->annotator->annotate($text, $isHtml) as $annotation) {
            $position = $annotation['target']['selector'][1];
            $spans[] = array($position['start'], $position['end'], 'name');
            if (isset($annotation['nomenclature'])) {
                $kind = self::kindOf($annotation['nomenclature']);
                if ($kind !== null) {
                    $spans[] = array($annotation['nomenclature']['start'],
                        $annotation['nomenclature']['end'], $kind);
                }
            }
        }
        foreach ($spans as $i => $span) {
            $start = max(0, min((int) $span[0], $length));
            $end = max($start, min((int) $span[1], $length));
            if ($start === $end) {
                unset($spans[$i]);
                continue;
            }
            $spans[$i] = array($start, $end, $span[2]);
        }
        $spans = array_values($spans);
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

    /**
     * Which of the three an annotation is, or null where it is not worth
     * colouring.
     *
     * Nomenclature keeps the two apart: .acts holds what was read beside a
     * "new" word, .qualifiers what stood on its own. Only the first is an
     * act: 'Cicindela, sp.'
     * says the species was not identified, and 'Genus Tettix, Charp.' gives
     * the rank of a name in a list. Neither announces anything, and marking
     * them alongside 'gen. nov.' claims more than the text says.
     */
    private static function kindOf(array $nomenclature)
    {
        if (!empty($nomenclature['acts'])) {
            return 'nomenclature';
        }
        if (!empty($nomenclature['judgments'])) {
            return 'judgment';
        }
        // A qualifier says how sure the identification was and announces
        // nothing, so it is left uncoloured.
        return null;
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
