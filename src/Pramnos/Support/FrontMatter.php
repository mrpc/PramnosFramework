<?php

declare(strict_types=1);

namespace Pramnos\Support;

/**
 * The front matter of a Markdown file — the `---` block at its top — and the body after it.
 *
 * ```php
 * ['meta' => $meta, 'body' => $body] = FrontMatter::parse(file_get_contents($file));
 * ```
 *
 * The small part of YAML these files use, and nothing more: `key: value`, a value in quotes, a
 * list on one line (`[a, b]`) or as `- item` lines under its key. Keys are lower-cased. An empty
 * value is an empty list. A file without the block is all body.
 */
final class FrontMatter
{
    /**
     * @return array{meta: array<string, string|list<string>>, body: string} The body trimmed
     */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $meta = [];
        $body = $text;

        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $text, $m) === 1) {
            $body = $m[2];
            $key  = '';
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^([A-Za-z_]+):\s*(.*)$/', $line, $pair) === 1) {
                    $key   = strtolower($pair[1]);
                    $value = trim($pair[2]);
                    // `[a, b]` is a list on one line.
                    $meta[$key] = preg_match('/^\[(.*)\]$/', $value, $list) === 1
                        ? array_values(array_filter(array_map(static fn (string $v): string => trim($v, " \"'"), explode(',', $list[1])), static fn (string $v): bool => $v !== ''))
                        : (trim($value, " \"'") === '' ? [] : trim($value, " \"'"));
                } elseif ($key !== '' && preg_match('/^\s*-\s+(.*)$/', $line, $item) === 1) {
                    $meta[$key]   = is_array($meta[$key]) ? $meta[$key] : [];
                    $meta[$key][] = trim($item[1], " \"'");
                }
            }
        }

        return ['meta' => $meta, 'body' => trim($body)];
    }

    /**
     * A front matter value as a list of names: one name, or a list of them.
     *
     * @return list<string>
     */
    public static function names(mixed $value): array
    {
        $names = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map('trim', array_map('strval', $names)), static fn (string $n): bool => $n !== ''));
    }
}
