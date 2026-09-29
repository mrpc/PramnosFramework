<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * Markup somebody typed into an editor, reduced to what a message may carry.
 *
 * An allowlist, not a filter of known-bad things: the elements the message editor produces —
 * paragraphs, line breaks, emphasis, two heading levels, lists, quotes, links and images — and
 * the one or two attributes each needs. Everything else is removed: a script, a style block or
 * an embed with its content, any other element with its tag only, and every attribute that is
 * not listed, so there is no `on…` handler and no `style` left to carry one.
 *
 * A link keeps an `http`, `https` or `mailto` address and nothing else — `javascript:` is not a
 * link. An image keeps an `https` address, because a mail client loading plain-HTTP images is a
 * mixed-content warning at best.
 *
 * ```php
 * $body = SafeHtml::clean($_POST['message']);   // safe to send and to show
 * ```
 */
final class SafeHtml
{
    /** element => the attributes it keeps */
    private const ALLOWED = [
        'p' => [], 'br' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'ul' => [], 'ol' => [], 'li' => [], 'blockquote' => [],
        'div' => [], 'span' => [],
        'a'   => ['href'],
        'img' => ['src', 'alt'],
    ];

    /** Removed together with everything inside them. */
    private const DROPPED = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'form', 'textarea', 'select', 'head', 'title'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="utf-8"?><div id="pf-safe-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('pf-safe-root');
        if ($root === null) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
        }

        self::walk($root);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $document->saveHTML($child);
        }

        return $out;
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $name = strtolower($child->nodeName);
            if (in_array($name, self::DROPPED, true)) {
                $node->removeChild($child);
                continue;
            }

            self::walk($child);

            if (!array_key_exists($name, self::ALLOWED)) {
                // The tag goes and its content stays, where it was.
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $keep = in_array(strtolower($attribute->nodeName), self::ALLOWED[$name], true)
                    && self::safeValue($name, strtolower($attribute->nodeName), $attribute->nodeValue ?? '');
                if (!$keep) {
                    $child->removeAttribute($attribute->nodeName);
                }
            }

            if ($name === 'img' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
            }
        }
    }

    private static function safeValue(string $element, string $attribute, string $value): bool
    {
        $value = trim($value);

        return match ($attribute) {
            'href'  => preg_match('~^(https?:|mailto:)~i', $value) === 1,
            'src'   => preg_match('~^https://~i', $value) === 1,
            default => true,
        };
    }
}
