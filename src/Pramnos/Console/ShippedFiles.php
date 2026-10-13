<?php

declare(strict_types=1);

namespace Pramnos\Console;

/**
 * What the framework last wrote into a project, for the files the project is expected to change.
 *
 * The shared Svelte components become the project's the moment they exist: a project fixes its
 * DataTable, and a resync that replaced it would undo the fix without a word. So each one is
 * recorded here by hash when `init`, `create:crud` or `project:resync` writes it, and a resync
 * replaces a file only while it is still exactly what was written — anything else is the
 * project's, and is kept. A file with no record is the project's too: something wrote it, and
 * it was not this.
 *
 * The record is `app/shipped-files.json`, committed with the project, so every checkout agrees.
 */
final class ShippedFiles
{
    public const PATH = 'app/shipped-files.json';

    /** The hash recorded for a file, or null when the framework never recorded writing it. */
    public static function recorded(string $base, string $rel): ?string
    {
        return self::read($base)[$rel] ?? null;
    }

    /** Whether the file on disk is still what the framework last wrote. */
    public static function untouched(string $base, string $rel, string $current): bool
    {
        return self::recorded($base, $rel) === hash('sha256', $current);
    }

    /** Record what was just written. */
    public static function record(string $base, string $rel, string $content): void
    {
        $all       = self::read($base);
        $all[$rel] = hash('sha256', $content);
        ksort($all);

        $path = $base . '/' . self::PATH;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @return array<string, string> Path → sha256 */
    private static function read(string $base): array
    {
        $path    = $base . '/' . self::PATH;
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($decoded) ? array_filter($decoded, 'is_string') : [];
    }
}
