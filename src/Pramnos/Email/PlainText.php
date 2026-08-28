<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * The `text/plain` half of an HTML email, written so that it is worth sending.
 *
 * It used to be `strip_tags($body)`. That produces a part which is technically present and
 * practically useless, in three specific ways:
 *
 * - **Every link disappears.** `strip_tags` keeps the anchor *text* and throws the `href` away,
 *   so «click here to confirm your address» arrives with nothing to click and no address to
 *   copy. On a confirmation mail that is the entire message gone.
 * - **The text runs together.** HTML mail is built from nested tables, and adjacent cells have
 *   no whitespace between them, so a header, a heading and a paragraph arrive as one line.
 * - **The stylesheet comes along.** `strip_tags` removes the `<style>` tags and keeps what was
 *   inside them, so a reader in a text-only client is shown the CSS.
 *
 * And a multipart message whose alternative part is broken is not a formality: a text part that
 * does not match the HTML is a documented spam signal, so the thing that was supposed to help
 * deliverability was hurting it.
 *
 * Written against `DOMDocument` rather than with regular expressions, and against the shapes
 * this framework's own mail actually uses. **No new dependency:** an html-to-text package is a
 * reasonable choice for an application and the wrong one for a framework, which would impose it
 * on every project that ever sends a message.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class PlainText
{
    /** Where lines are wrapped. 78 leaves room for two levels of `> ` quoting in a reply. */
    public const WIDTH = 78;

    /**
     * Elements that contribute nothing a reader wants.
     *
     * `style` and `script` are the two that made the old output embarrassing, `head` carries the
     * `<title>` — which would otherwise repeat the subject line as the first line of the body —
     * and the rest are invisible in the HTML too.
     *
     * @var list<string>
     */
    private const DROPPED = ['head', 'style', 'script', 'noscript', 'template', 'title', 'meta', 'link'];

    /**
     * Elements separated from what follows by a blank line.
     *
     * @var list<string>
     */
    private const BLOCKS = [
        'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol',
        'table', 'blockquote', 'section', 'article', 'header', 'footer', 'pre', 'hr',
    ];

    /**
     * Elements that start a new line but not a new paragraph.
     *
     * A list whose items are separated by blank lines does not read as a list, and neither does
     * a table whose rows are. Both were, before this existed: every block wrapped its contents
     * in newlines on both sides, so consecutive items each contributed two.
     *
     * @var list<string>
     */
    private const LINES = ['li', 'tr'];

    /**
     * Convert one HTML body to plain text.
     *
     * @param  string $html The rendered HTML mail
     * @return string
     */
    public static function fromHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument();

        /*
         * The charset has to be declared *before* the markup, or libxml assumes ISO-8859-1 and
         * every Greek character in the message becomes mojibake. The old
         * `mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')` recipe is deprecated as of PHP
         * 8.2, so the meta tag is the supported way to say it.
         */
        $loaded = @$document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        if ($loaded === false) {
            // Unparseable markup. The old behaviour is still better than an empty part, and a
            // message with no text alternative at all is a worse spam signal than a poor one.
            return self::tidy(strip_tags($html));
        }

        $text = self::walk($document->documentElement ?? $document, ['list' => null, 'index' => 0]);

        return self::wrap(self::tidy($text));
    }

    /**
     * One node, and everything under it.
     *
     * @param array{list: ?string, index: int} $context Which list we are inside, and how far in
     */
    private static function walk(\DOMNode $node, array $context): string
    {
        if ($node instanceof \DOMText) {
            // Whitespace in HTML is not significant; a newline in the source is not a newline
            // in the message.
            return (string) preg_replace('~\s+~u', ' ', $node->nodeValue ?? '');
        }

        if (!$node instanceof \DOMElement) {
            return '';
        }

        $name = strtolower($node->nodeName);

        if (in_array($name, self::DROPPED, true)) {
            return '';
        }

        // A preheader is hidden text meant only for the inbox preview. It is invisible in the
        // HTML part, so repeating it as the first line of the text part would be a difference
        // between the two halves — which is the thing this class exists to avoid.
        if (self::isHidden($node)) {
            return '';
        }

        if ($name === 'br') {
            return "\n";
        }

        if ($name === 'hr') {
            return "\n" . str_repeat('-', 24) . "\n";
        }

        if ($name === 'img') {
            $alt = trim($node->getAttribute('alt'));

            // No alt means decoration, and a line reading `[]` is worse than nothing.
            return $alt === '' ? '' : '[' . $alt . ']';
        }

        if ($name === 'a') {
            return self::link($node, $context);
        }

        if ($name === 'ul' || $name === 'ol') {
            $context = ['list' => $name, 'index' => 0];
        }

        $inner = '';
        $index = 0;

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'li') {
                $index++;
                $inner .= self::listItem($child, $context, $index);

                continue;
            }

            $inner .= self::walk($child, $context);
        }

        if ($name === 'td' || $name === 'th') {
            return self::cell($node, $inner);
        }

        if (in_array($name, self::LINES, true)) {
            /*
             * A row of a *layout* table is a section of the page, not a row of data — so it is
             * separated by a blank line, while a data table's rows sit on consecutive lines and
             * read as a table. The same `role="presentation"` marker that decides how the cells
             * are joined, used consistently.
             */
            if ($name === 'tr' && self::isLayoutRow($node)) {
                return "\n" . trim($inner) . "\n";
            }

            return "\n" . trim($inner);
        }

        if (in_array($name, self::BLOCKS, true)) {
            return "\n" . trim($inner) . "\n";
        }

        return $inner;
    }

    /**
     * A link, with its address.
     *
     * The whole point of the rewrite. `text <https://…>` rather than `text`, because a text-only
     * reader has to be able to reach the same place — and when the text already *is* the
     * address, printing it twice is noise.
     *
     * @param array{list: ?string, index: int} $context
     */
    private static function link(\DOMElement $node, array $context): string
    {
        // `childText()`, not `walk()`: walking this node would come straight back here.
        $label = trim(self::childText($node, $context));
        $href  = trim($node->getAttribute('href'));

        if ($href === '' || str_starts_with($href, '#')) {
            return $label;   // an anchor within the page has nowhere to go in a mail
        }

        if (str_starts_with(strtolower($href), 'mailto:')) {
            $address = substr($href, 7);

            return strcasecmp($label, $address) === 0 ? $label : $label . ' <' . $address . '>';
        }

        if ($label === '') {
            return $href;
        }

        // A link whose text is already the URL — common in a footer — needs it once.
        return strcasecmp($label, $href) === 0 ? $href : $label . ' <' . $href . '>';
    }

    /**
     * A cell, separated from its neighbours according to what the table is *for*.
     *
     * A layout table carries `role="presentation"`, which the framework's own wrapper sets, and
     * its cells are a page rather than a row of data — so they are separated by a line break.
     * A real data table gets ` | `, which is what makes it readable as a table at all. Using the
     * accessibility marker for this is not a trick: it is the same distinction, written down
     * already.
     */
    private static function cell(\DOMElement $node, string $inner): string
    {
        $inner = trim($inner);

        if ($inner === '') {
            return '';
        }

        $table  = self::closest($node, 'table');
        $layout = $table !== null
            && strtolower($table->getAttribute('role')) === 'presentation';

        return $layout ? "\n" . $inner . "\n" : $inner . ' | ';
    }

    /**
     * @param array{list: ?string, index: int} $context
     */
    private static function listItem(\DOMElement $node, array $context, int $index): string
    {
        $marker = ($context['list'] ?? 'ul') === 'ol' ? $index . '. ' : '- ';

        return "\n" . $marker . trim(self::childText($node, $context));
    }

    /**
     * The text of a node's children, without the node's own block handling.
     *
     * @param array{list: ?string, index: int} $context
     */
    private static function childText(\DOMElement $node, array $context): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            $text .= self::walk($child, $context);
        }

        return $text;
    }

    /** Is this row part of a layout table rather than a data one? */
    private static function isLayoutRow(\DOMElement $node): bool
    {
        $table = self::closest($node, 'table');

        return $table !== null && strtolower($table->getAttribute('role')) === 'presentation';
    }

    /** The nearest ancestor with this tag name. */
    private static function closest(\DOMNode $node, string $name): ?\DOMElement
    {
        $parent = $node->parentNode;

        while ($parent instanceof \DOMElement) {
            if (strtolower($parent->nodeName) === $name) {
                return $parent;
            }

            $parent = $parent->parentNode;
        }

        return null;
    }

    /**
     * Is this element hidden from a reader of the HTML?
     *
     * Only the spellings that actually appear in mail: `display:none`, and the zero-height
     * preheader trick. A general CSS engine is not the job — the question is whether the two
     * parts of the message agree.
     */
    private static function isHidden(\DOMElement $node): bool
    {
        $style = strtolower(str_replace(' ', '', $node->getAttribute('style')));

        return str_contains($style, 'display:none')
            || str_contains($style, 'max-height:0')
            || $node->hasAttribute('hidden');
    }

    /**
     * Collapse the whitespace a DOM walk inevitably produces.
     */
    private static function tidy(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Non-breaking spaces are invisible here and break the wrap; a space is what they mean.
        $text = str_replace(["\xc2\xa0", "\r\n", "\r"], [' ', "\n", "\n"], $text);
        $text = (string) preg_replace('~[ \t]+~', ' ', $text);
        $text = (string) preg_replace('~ *\n *~', "\n", $text);
        $text = (string) preg_replace('~\n{3,}~', "\n\n", $text);
        $text = (string) preg_replace('~ \| *\n~', "\n", $text);   // a trailing cell separator

        return trim($text);
    }

    /**
     * Wrap to {@see WIDTH}, without breaking a URL.
     *
     * A wrapped URL is an unusable URL: the reader's client turns half of it into a link and
     * leaves the rest as text. So a word longer than the line is left long — a slightly ragged
     * paragraph is a smaller problem than an address that does not work.
     */
    private static function wrap(string $text): string
    {
        $lines = [];

        foreach (explode("\n", $text) as $line) {
            if (mb_strlen($line) <= self::WIDTH) {
                $lines[] = $line;

                continue;
            }

            $current = '';

            foreach (explode(' ', $line) as $word) {
                if ($current === '') {
                    $current = $word;

                    continue;
                }

                if (mb_strlen($current . ' ' . $word) > self::WIDTH) {
                    $lines[] = $current;
                    $current = $word;

                    continue;
                }

                $current .= ' ' . $word;
            }

            if ($current !== '') {
                $lines[] = $current;
            }
        }

        return implode("\n", $lines);
    }
}
