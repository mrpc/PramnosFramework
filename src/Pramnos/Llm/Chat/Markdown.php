<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * A model's Markdown as HTML that is safe to print — for the server-rendered conversation page.
 *
 * A reply is text nobody here wrote, so everything is escaped **first** and only then are the
 * few shapes a model uses turned into markup: headings, **bold**, *italic*, `code`, fenced
 * code blocks, lists, tables, links and bare addresses. A link goes only to `http(s)` and opens
 * in a new tab, so following one does not leave the conversation. Anything else stays the
 * escaped text it was.
 *
 * Not a Markdown implementation: no nested lists, no images, no HTML. The SPA uses `marked` with
 * DOMPurify; this is the same promise — safe, readable — with no dependency.
 */
final class Markdown
{
    public static function toHtml(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $html  = [];
        $para  = [];
        $count = count($lines);

        $flush = static function () use (&$para, &$html): void {
            if ($para !== []) {
                $html[] = '<p>' . implode("<br>\n", array_map([self::class, 'inline'], $para)) . '</p>';
                $para   = [];
            }
        };

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // A fenced block, kept as it is.
            if (preg_match('/^\s*```/', $line) === 1) {
                $flush();
                $code = [];
                for ($i++; $i < $count && preg_match('/^\s*```/', $lines[$i]) !== 1; $i++) {
                    $code[] = $lines[$i];
                }
                $html[] = '<pre><code>' . self::escape(implode("\n", $code)) . '</code></pre>';
                continue;
            }

            if (trim($line) === '') {
                $flush();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                $flush();
                $level  = min(6, strlen($m[1]) + 2);   // a reply's "#" is a section, not the page's title
                $html[] = '<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>';
                continue;
            }

            // A table: a row, then a separator of dashes.
            if (str_contains($line, '|') && $i + 1 < $count && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $lines[$i + 1]) === 1) {
                $flush();
                $rows = [self::cells($line)];
                for ($i += 2; $i < $count && str_contains($lines[$i], '|') && trim($lines[$i]) !== ''; $i++) {
                    $rows[] = self::cells($lines[$i]);
                }
                $i--;
                $head   = array_shift($rows);
                $table  = '<table><thead><tr>' . implode('', array_map(static fn (string $c): string => '<th>' . self::inline($c) . '</th>', $head)) . '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $table .= '<tr>' . implode('', array_map(static fn (string $c): string => '<td>' . self::inline($c) . '</td>', $row)) . '</tr>';
                }
                $html[] = $table . '</tbody></table>';
                continue;
            }

            if (preg_match('/^\s*([-*+]|\d+[.)])\s+/', $line, $marker) === 1) {
                $flush();
                $ordered = ctype_digit($marker[1][0]);
                $items   = [];
                for (; $i < $count && preg_match('/^\s*([-*+]|\d+[.)])\s+(.*)$/', $lines[$i], $item) === 1; $i++) {
                    $items[] = '<li>' . self::inline($item[2]) . '</li>';
                }
                $i--;
                $tag    = $ordered ? 'ol' : 'ul';
                $html[] = '<' . $tag . '>' . implode('', $items) . '</' . $tag . '>';
                continue;
            }

            $para[] = $line;
        }

        $flush();

        return implode("\n", $html);
    }

    /** One line's inline marks, after escaping it. */
    private static function inline(string $text): string
    {
        $codes = [];
        // Code first, kept out of every other rule.
        $text = (string) preg_replace_callback('/`([^`]+)`/', static function (array $m) use (&$codes): string {
            $codes[] = '<code>' . self::escape($m[1]) . '</code>';

            return "\x00" . (count($codes) - 1) . "\x00";
        }, $text);

        $links = [];
        $text  = (string) preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', static function (array $m) use (&$links): string {
            $links[] = self::link($m[2], self::escape($m[1]));

            return "\x01" . (count($links) - 1) . "\x01";
        }, $text);

        $text = self::escape($text);
        $text = (string) preg_replace_callback('/(?<![\w"\/])(https?:\/\/[^\s<]+[^\s<.,;:!?)\]])/', static fn (array $m): string => self::link(html_entity_decode($m[1], ENT_QUOTES), $m[1]), $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*|__(.+?)__/', '<strong>$1$2</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])|(?<![\w_])_(?!\s)(.+?)(?<!\s)_(?![\w_])/', '<em>$1$2</em>', $text);

        $text = (string) preg_replace_callback('/\x01(\d+)\x01/', static fn (array $m): string => $links[(int) $m[1]], $text);

        return (string) preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $codes[(int) $m[1]], $text);
    }

    /** @return list<string> A table row's cells */
    private static function cells(string $line): array
    {
        return array_map('trim', explode('|', trim(trim($line), '|')));
    }

    private static function link(string $url, string $label): string
    {
        return '<a href="' . self::escape($url) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
