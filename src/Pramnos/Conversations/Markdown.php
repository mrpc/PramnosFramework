<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * A message's markdown-lite as HTML, safe to show: everything is escaped first, and only these are
 * made of it —
 *
 * | written                         | shown                                   |
 * |---------------------------------|-----------------------------------------|
 * | `**bold**`, `*italic*`, `_italic_`, `~~strike~~`, `++underline++` | bold, italic, struck through, underlined |
 * | `` `code` ``, ```` ``` ```` fenced blocks | code                          |
 * | `[text](https://…)`, a bare `https://…` | a link, opening apart; http(s) and mailto only |
 * | `- item`, `1. item`             | a list                                  |
 * | `> quoted`                      | a quote                                 |
 * | `| a | b |` then `|---|:-:|`     | a table — `:-:` centred, `--:` to the right; it scrolls when wide |
 * | a table drawn with lines, `┌─┬─┐ │ a │ b │` or `+---+ | a |` | the same table |
 * | `<@48211>`                      | @ and the person's name                 |
 * | `@here`, `@channel`, `@everyone`, a group's `@name` (and `@all`, `@online`) | the mention, marked ({@see Handles}) |
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
        'smirk' => '😏', 'kappa' => '😏',
    ];

    /**
     * Shortcodes a team may give a picture of its own ({@see CustomEmoji}): where it has, the
     * picture is shown in place of the emoji. `:kappa:` has no emoji of its own, only a smirk.
     */
    public const REPLACEABLE = ['kappa'];

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

        $text  = self::drawnTables($text);
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
        // A `|` a drawn table had inside a cell is kept as \x03 until here, so it splits nothing.
        return array_map(static fn (string $c): string => str_replace("\x03", '|', trim($c)), explode('|', trim(trim($line), '|')));
    }

    /** A drawn table's rule: a corner or a side's crossing at each end, lines and crossings between. */
    private const BORDER = '/^\s*[┌├└╒╞╘╓╟╙╔╠╚┏┣┗+][─━═┄┅╌╍\-=┬┼┴╤╪╧╥╫╨╦╬╩┳╋┻+:\s]*[┐┤┘╕╡╛╖╢╜╗╣╝┓┫┛+]\s*$/u';

    /** A drawn table's line of cells: a bar at each end. */
    private const ROW = '/^\s*[│║┃|].*[│║┃|]\s*$/u';

    /** Where a rule's columns part. */
    private const JUNCTIONS = '┌┬┐├┼┤└┴┘╒╤╕╞╪╡╘╧╛╓╥╖╟╫╢╙╨╜╔╦╗╠╬╣╚╩╝┏┳┓┣╋┫┗┻┛+';

    /**
     * A table drawn with lines — a terminal's or a model's (`┌─┬─┐ │ a │ b │ └─┴─┘`), or ASCII's
     * (`+---+ | a |`) — as the table this markdown knows, so it shows as a table, not a wall of
     * lines; the text around it stays.
     *
     * A block of only rules and lines of cells is one when it has a rule and two such lines. Its
     * columns part where the rule crosses, so a `|` written in a cell stays in it; a line not as long
     * as the rule (a wide emoji shifted it) is split at its bars instead. The first row is the
     * heading. A row drawn over several lines is one row, its pieces joined; a table ruled only
     * under its heading has a row a line.
     */
    private static function drawnTables(string $text): string
    {
        $lines = preg_split('/\r\n?|\n/', $text) ?: [];
        $out   = [];
        for ($i = 0, $count = count($lines); $i < $count;) {
            $end = $i;
            while ($end < $count && (preg_match(self::BORDER, $lines[$end]) === 1 || preg_match(self::ROW, $lines[$end]) === 1)) {
                $end++;
            }
            $table = $end > $i ? self::tableOf(array_slice($lines, $i, $end - $i)) : null;
            if ($table !== null) {
                array_push($out, ...$table);
                $i = $end;
            } else {
                $out[] = $lines[$i];
                $i++;
            }
        }

        return implode("\n", $out);
    }

    /**
     * One drawn block as markdown's table lines; null when it is no table.
     *
     * @param list<string> $block
     * @return list<string>|null
     */
    private static function tableOf(array $block): ?array
    {
        $isRule = static fn (string $l): bool => preg_match(self::BORDER, $l) === 1;
        $rules  = array_values(array_filter($block, $isRule));
        if ($rules === [] || count(array_filter($block, static fn (string $l): bool => preg_match(self::ROW, $l) === 1 && !preg_match(self::BORDER, $l))) < 2) {
            return null;
        }
        $rule  = mb_str_split($rules[0]);
        $cuts  = array_keys(array_filter($rule, static fn (string $ch): bool => str_contains(self::JUNCTIONS, $ch)));
        $cells = static function (string $line) use ($rule, $cuts): array {
            $chars = mb_str_split($line);
            $parts = count($chars) === count($rule) && count($cuts) > 1
                ? array_map(static fn (int $at, int $next): string => implode('', array_slice($chars, $at + 1, $next - $at - 1)), array_slice($cuts, 0, -1), array_slice($cuts, 1))
                : (preg_split('/[│║┃|]/u', mb_substr(trim($line), 1, -1)) ?: []);

            return array_map(static fn (string $c): string => str_replace('|', "\x03", trim($c)), $parts);
        };
        $groups = [];
        $group  = [];
        foreach ($block as $line) {
            if ($isRule($line)) {
                if ($group !== []) {
                    $groups[] = $group;
                }
                $group = [];
            } else {
                $group[] = $cells($line);
            }
        }
        if ($group !== []) {
            $groups[] = $group;
        }
        // Ruled under the heading only: each line after it a row. Ruled between rows: each group one
        // row, a cell's pieces joined.
        $rows = count($groups) === 2 && count($groups[1]) > 1 && count($groups[0]) === 1
            ? [$groups[0][0], ...$groups[1]]
            : array_map(static fn (array $g): array => array_map(
                static fn (int $col): string => implode(' ', array_filter(array_map(static fn (array $l): string => $l[$col] ?? '', $g), static fn (string $p): bool => $p !== '')),
                array_keys($g[0])), $groups);
        $width = max(array_map('count', $rows));
        $line  = static fn (array $cells): string => '| ' . implode(' | ', array_map(static fn (int $c): string => $cells[$c] ?? '', range(0, $width - 1))) . ' |';

        return [$line($rows[0]), '|' . implode('|', array_fill(0, $width, '---')) . '|', ...array_map($line, array_slice($rows, 1))];
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
        $handles = implode('|', array_map(static fn (string $h): string => preg_quote($h, '/'),
            [...Handles::HERE, ...Handles::CHANNEL, Handles::EVERYONE, ...array_keys(Handles::groups())]));
        $html = (string) preg_replace_callback('/(?<![\w@])@(' . $handles . ')(?![\w.-]*\w)/iu', static function (array $m): string {
            $kind = mb_strtolower($m[1]);

            return '<span class="pf-conv-mention pf-conv-mention-' . (in_array($kind, [...Handles::HERE, ...Handles::CHANNEL, Handles::EVERYONE], true) ? $kind : 'group')
                . '">@' . $m[1] . '</span>';
        }, $html);
        // A call on the analyst — `@Ask` or a mention of its own — shown as its name.
        $html = (string) preg_replace(\Pramnos\Llm\Analyst\Analyst::mentionPattern(), '<span class="pf-conv-mention pf-conv-mention-ask">@'
            . htmlspecialchars(\Pramnos\Llm\Analyst\Analyst::name(), ENT_QUOTES, 'UTF-8') . '</span>', $html);
        $html = (string) preg_replace('/(?<![\w#])#([a-z][a-z0-9_]*:\w[\w-]*)/i', '<span class="pf-conv-ref" data-ref="$1">#$1</span>', $html);
        $html = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/', '<del>$1</del>', $html);
        // Not against a letter or another plus, so `C++` and `i++` stay text.
        $html = (string) preg_replace('/(?<![\w+])\+\+(?=[^\s+])(.+?)(?<=[^\s+])\+\+(?![\w+])/u', '<u>$1</u>', $html);
        $html = (string) preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/', '<em>$1</em>', $html);
        $html = (string) preg_replace('/(?<!\w)_(?=\S)(.+?)(?<=\S)_(?!\w)/', '<em>$1</em>', $html);
        $custom = CustomEmoji::all();
        // The team's own first: a name it may have is one of the replaceable shortcodes, or none.
        $html   = (string) preg_replace_callback('/:([a-z0-9_+-]+):/', static fn (array $m): string => isset($custom[$m[1]])
            ? self::customEmoji($m[1], $custom[$m[1]]) : (self::EMOJI[$m[1]] ?? $m[0]), $html);

        return (string) preg_replace_callback("/\x02(\d+)\x02/", static fn (array $m): string => $kept[(int) $m[1]] ?? '', $html);
    }

    /** One of the team's own emoji, as its picture; its name for whoever cannot see it. */
    public static function customEmoji(string $name, string $url): string
    {
        $alt = htmlspecialchars(':' . $name . ':', ENT_QUOTES, 'UTF-8');

        return '<img class="pf-conv-custom-emoji" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" title="' . $alt . '">';
    }

    /** A link that opens apart and tells the page nothing. */
    private static function link(string $url, string $text): string
    {
        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener noreferrer nofollow">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</a>';
    }
}
