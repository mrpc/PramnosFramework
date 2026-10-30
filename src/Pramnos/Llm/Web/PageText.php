<?php

declare(strict_types=1);

namespace Pramnos\Llm\Web;

/**
 * A web page's own words, without its menus, footers and scripts.
 *
 * In order, the first that is long enough:
 *
 * 1. the `articleBody` a publisher declares for search engines in JSON-LD — the article and
 *    nothing else;
 * 2. the paragraphs inside `<article>`;
 * 3. every paragraph on the page.
 *
 * Short lines ("Share", "Advertisement") are left out, and a paragraph that appears twice is kept
 * once. Built with the standard library's DOM, without the network ({@see LIBXML_NONET}).
 */
final class PageText
{
    /** Enough text to be an article rather than a page's furniture. */
    public const ARTICLE = 600;

    /** A line shorter than this is a button, not a paragraph. */
    public const LINE = 40;

    /** The page's words. Empty for a page that has none a server can read. */
    public static function fromPage(string $html): string
    {
        $declared = self::declaredBody($html);
        if (mb_strlen($declared) >= self::ARTICLE) {
            return $declared;
        }

        $dom = self::dom($html);
        if ($dom === null) {
            return $declared;
        }
        $xpath = new \DOMXPath($dom);
        foreach (['//script', '//style', '//noscript', '//nav', '//header', '//footer', '//aside', '//form'] as $noise) {
            foreach (iterator_to_array($xpath->query($noise) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $best = '';
        foreach (['//article//p', '//p'] as $scope) {
            $paragraphs = [];
            foreach ($xpath->query($scope) ?: [] as $node) {
                $line = self::squash($node->textContent);
                if (mb_strlen($line) >= self::LINE) {
                    $paragraphs[] = $line;
                }
            }
            $text = implode("\n\n", array_unique($paragraphs));
            if (mb_strlen($text) >= self::ARTICLE) {
                return $text;
            }
            if (mb_strlen($text) > mb_strlen($best)) {
                $best = $text;
            }
        }

        return mb_strlen($declared) > mb_strlen($best) ? $declared : $best;
    }

    /** The page's `<title>`, or ''. */
    public static function title(string $html): string
    {
        return preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) === 1 ? self::squash($m[1]) : '';
    }

    /** An HTML fragment — a feed item's content — as plain paragraphs. */
    public static function fromFragment(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        if (!str_contains($html, '<')) {
            return self::squash($html);
        }

        // Block ends become paragraph breaks before the tags go.
        $marked = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6]|/blockquote)\b[^>]*>#i', "$0\n\n", $html);
        $marked = (string) preg_replace('#<(script|style)\b.*?</\1>#is', '', $marked);
        $out    = [];
        foreach (preg_split("#\n\s*\n#", strip_tags($marked)) ?: [] as $chunk) {
            $line = self::squash($chunk);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return implode("\n\n", $out);
    }

    /** Markdown — a reader service's answer — as plain paragraphs: links and pictures go, their words stay. */
    public static function fromMarkdown(string $markdown): string
    {
        $content = (string) preg_replace('/\A.*?^Markdown Content:\s*$/ms', '', $markdown, 1);
        $lines   = [];
        foreach (preg_split('/\n\s*\n/', $content) ?: [] as $block) {
            $line = self::squash((string) preg_replace(['/!\[[^\]]*\]\([^)]*\)/', '/\[([^\]]*)\]\([^)]*\)/', '/^[#>*\-\s]+/m'], ['', '$1', ''], $block));
            if (mb_strlen($line) >= self::LINE) {
                $lines[] = $line;
            }
        }

        return implode("\n\n", array_values(array_unique($lines)));
    }

    /** `articleBody` from the page's JSON-LD, the longest if there are several. */
    private static function declaredBody(string $html): string
    {
        preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $blocks);
        $best = '';
        foreach ($blocks[1] ?? [] as $json) {
            $data = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            if (!is_array($data)) {
                continue;
            }
            array_walk_recursive($data, static function ($value, $key) use (&$best): void {
                if ($key === 'articleBody' && is_string($value)) {
                    $text = self::fromFragment($value);
                    if (mb_strlen($text) > mb_strlen($best)) {
                        $best = $text;
                    }
                }
            });
        }

        return $best;
    }

    private static function dom(string $html): ?\DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }
        $dom   = new \DOMDocument();
        $quiet = libxml_use_internal_errors(true);
        // The prefix makes libxml read UTF-8, not Latin-1, when the page's charset comes after its first text.
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($quiet);

        return $loaded ? $dom : null;
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
