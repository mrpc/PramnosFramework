<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * A message's markdown-lite as HTML, safe to show: everything is escaped first, and only these are
 * made of it —
 *
 * | written                         | shown                                   |
 * |---------------------------------|-----------------------------------------|
 * | `**bold**`, `*italic*`, `_italic_`, `~~strike~~` | bold, italic, struck through |
 * | `` `code` ``, ```` ``` ```` fenced blocks | code                          |
 * | `[text](https://…)`, a bare `https://…` | a link, opening apart; http(s) and mailto only |
 * | `- item`, `1. item`             | a list                                  |
 * | `> quoted`                      | a quote                                 |
 * | `| a | b |` then `|---|:-:|`     | a table — `:-:` centred, `--:` to the right; it scrolls when wide |
 * | `<@48211>`                      | @ and the person's name                 |
 * | `@all`, `@online`               | the mention, marked                     |
 * | `#user:48211`, `NAN-6212`       | a reference, marked for a card ({@see Messages::refs()}) |
 * | `:smile:`                       | the emoji, where the shortcode is known |
 *
 * No HTML of the writer's own, no images: a picture is a file sent with the message.
 */
final class Markdown
{
    /** The shortcodes shown as emoji; others stay as written. */
    public const EMOJI = [
        'smile' => '😄', 'slightly_smiling_face' => '🙂', 'grinning' => '😀', 'joy' => '😂', 'wink' => '😉', 'blush' => '😊',
        'heart' => '❤️', 'thumbsup' => '👍', '+1' => '👍', 'thumbsdown' => '👎', '-1' => '👎', 'clap' => '👏', 'pray' => '🙏',
        'eyes' => '👀', 'fire' => '🔥', 'tada' => '🎉', 'rocket' => '🚀', 'white_check_mark' => '✅', 'heavy_check_mark' => '✔️',
        'x' => '❌', 'warning' => '⚠️', 'thinking_face' => '🤔', 'ok_hand' => '👌', 'wave' => '👋', 'muscle' => '💪',
        'raised_hands' => '🙌', 'sweat_smile' => '😅', 'cry' => '😢', 'sob' => '😭', 'angry' => '😠', 'point_up' => '☝️',
        'question' => '❓', 'exclamation' => '❗', 'star' => '⭐', 'bulb' => '💡', 'memo' => '📝', 'calendar' => '📅',
        'phone' => '📞', 'email' => '📧', 'moneybag' => '💰', 'bug' => '🐛', 'coffee' => '☕', 'sunglasses' => '😎',
    ];

    /**
     * The HTML of a message.
     *
     * @param callable(int): ?string $name A person's name by account id, for `<@id>`; null when unknown
     */
    public static function render(string $text, callable $name): string
    {
        // Fenced code first: nothing inside it is markdown. A ```chart block that is one — Ask's —
        // is drawn as on Ask's page (pf-chat.js), over its code, which is what a page without it shows.
        $blocks = [];
        $text   = (string) preg_replace_callback('/```([a-z0-9_-]*)\n?(.*?)```/s', static function (array $m) use (&$blocks): string {
            $code  = '<pre class="pf-conv-code"><code>' . htmlspecialchars(rtrim($m[2], "\n"), ENT_QUOTES, 'UTF-8') . '</code></pre>';
            $chart = strtolower($m[1]) === 'chart' ? \Pramnos\Llm\Chat\Markdown::chart($m[2]) : null;
            $blocks[] = $chart === null ? $code
                : '<div class="pf-chart" data-pf-chart="' . htmlspecialchars((string) json_encode($chart, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') . '"'
                    . ' role="img" aria-label="' . htmlspecialchars((string) ($chart['title'] ?? 'Chart'), ENT_QUOTES, 'UTF-8') . '">' . $code . '</div>';

            return "\x01" . (count($blocks) - 1) . "\x01";
        }, $text);

        $html  = [];
        $list  = null;
        $quote = [];
        $flush = static function () use (&$html, &$list, &$quote): void {
            if ($list !== null) {
                $html[] = '<' . $list[0] . '>' . implode('', array_map(static fn (string $i): string => '<li>' . $i . '</li>', $list[1])) . '</' . $list[0] . '>';
                $list   = null;
            }
            if ($quote !== []) {
                $html[] = '<blockquote>' . implode('<br>', $quote) . '</blockquote>';
                $quote  = [];
            }
        };
        $paragraph = [];
        $end       = static function () use (&$html, &$paragraph): void {
            if ($paragraph !== []) {
                $html[]    = '<p>' . implode('<br>', $paragraph) . '</p>';
                $paragraph = [];
            }
        };
        $lines = preg_split('/\r?\n/', $text) ?: [];
        for ($i = 0, $count = count($lines); $i < $count; $i++) {
            $line = $lines[$i];
            // A table: a row, then a separator of dashes — `:---:` centred, `---:` to the right.
            if (str_contains($line, '|') && $i + 1 < $count && preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)*\|?\s*$/', $lines[$i + 1]) === 1) {
                $end();
                $flush();
                $align = array_map(static fn (string $c): string => str_ends_with($c, ':') ? (str_starts_with($c, ':') ? 'center' : 'right') : '', self::cells($lines[$i + 1]));
                $cell  = static fn (string $tag, string $text, int $n): string => '<' . $tag . (($align[$n] ?? '') !== '' ? ' style="text-align:' . $align[$n] . '"' : '') . '>'
                    . self::inline($text, $name) . '</' . $tag . '>';
                $row   = static fn (string $tag, array $cells): string => '<tr>' . implode('', array_map(static fn (string $c, int $n): string => $cell($tag, $c, $n), $cells, array_keys($cells))) . '</tr>';
                $table = '<div class="pf-conv-table"><table><thead>' . $row('th', self::cells($line)) . '</thead><tbody>';
                for ($i += 2; $i < $count && str_contains($lines[$i], '|') && trim($lines[$i]) !== ''; $i++) {
                    $table .= $row('td', self::cells($lines[$i]));
                }
                $i--;
                $html[] = $table . '</tbody></table></div>';
                continue;
            }
            // A fenced block is a block of its own, never inside a paragraph.
            if (preg_match("/^\x01\d+\x01$/", trim($line))) {
                $end();
                $flush();
                $html[] = trim($line);
                continue;
            }
            if (preg_match('/^\s*[-*]\s+(.*)$/', $line, $m) || preg_match('/^\s*\d+[.)]\s+(.*)$/', $line, $n)) {
                $end();
                $kind = isset($m[1]) ? 'ul' : 'ol';
                if ($list !== null && $list[0] !== $kind) {
                    $flush();
                }
                $list    ??= [$kind, []];
                $list[1][] = self::inline($m[1] ?? $n[1], $name);
                unset($m, $n);
                continue;
            }
            if (preg_match('/^\s*(?:>|&gt;)\s?(.*)$/', $line, $q)) {
                $end();
                if ($list !== null) {
                    $flush();
                }
                $quote[] = self::inline($q[1], $name);
                continue;
            }
            $flush();
            if (trim($line) === '') {
                $end();
                continue;
            }
            $paragraph[] = self::inline($line, $name);
        }
        $flush();
        $end();

        return (string) preg_replace_callback("/\x01(\d+)\x01/", static fn (array $m): string => $blocks[(int) $m[1]] ?? '', implode('', $html));
    }

    /** @return list<string> A table row's cells */
    private static function cells(string $line): array
    {
        return array_map('trim', explode('|', trim(trim($line), '|')));
    }

    /**
     * One line's inline marks.
     *
     * @param callable(int): ?string $name
     */
    private static function inline(string $text, callable $name): string
    {
        // Inline code and links are set aside before escaping and marking, then put back.
        $kept = [];
        $keep = static function (string $html) use (&$kept): string {
            $kept[] = $html;

            return "\x02" . (count($kept) - 1) . "\x02";
        };
        $text = (string) preg_replace_callback('/`([^`]+)`/', static fn (array $m): string => $keep('<code>' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</code>'), $text);
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|mailto:)[^\s)]+)\)/i', static fn (array $m): string => $keep(self::link($m[2], $m[1])), $text);
        $text = (string) preg_replace_callback('/\bhttps?:\/\/[^\s<>"\']+[^\s<>"\'.,;:!?)\]]/i', static fn (array $m): string => $keep(self::link($m[0], $m[0])), $text);
        $text = (string) preg_replace_callback('/<@(\d+)>/', static function (array $m) use ($keep, $name): string {
            $who = $name((int) $m[1]);

            return $keep('<span class="pf-conv-mention" data-user="' . (int) $m[1] . '">@' . htmlspecialchars($who ?? ('#' . $m[1]), ENT_QUOTES, 'UTF-8') . '</span>');
        }, $text);

        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $html = (string) preg_replace('/(?<![\w@])@(all|online)\b/', '<span class="pf-conv-mention pf-conv-mention-$1">@$1</span>', $html);
        // A call on the analyst — `@Ask` or a mention of its own — shown as its name.
        $html = (string) preg_replace(\Pramnos\Llm\Analyst\Analyst::mentionPattern(), '<span class="pf-conv-mention pf-conv-mention-ask">@'
            . htmlspecialchars(\Pramnos\Llm\Analyst\Analyst::name(), ENT_QUOTES, 'UTF-8') . '</span>', $html);
        $html = (string) preg_replace('/(?<![\w#])#([a-z][a-z0-9_]*:\w[\w-]*)/i', '<span class="pf-conv-ref" data-ref="$1">#$1</span>', $html);
        $html = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/', '<del>$1</del>', $html);
        $html = (string) preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/', '<em>$1</em>', $html);
        $html = (string) preg_replace('/(?<!\w)_(?=\S)(.+?)(?<=\S)_(?!\w)/', '<em>$1</em>', $html);
        $html = (string) preg_replace_callback('/:([a-z0-9_+-]+):/', static fn (array $m): string => self::EMOJI[$m[1]] ?? $m[0], $html);

        return (string) preg_replace_callback("/\x02(\d+)\x02/", static fn (array $m): string => $kept[(int) $m[1]] ?? '', $html);
    }

    /** A link that opens apart and tells the page nothing. */
    private static function link(string $url, string $text): string
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer nofollow">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}
