<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * A sent message's body, from its `mails` row: the `content` column, or the file the row's `path`
 * names.
 *
 * ```php
 * $html = Body::of($row);   // $row: a mails row, as an array — content and path read
 * ```
 *
 * Applications built on this framework have kept bodies on disk for years, gzipped, with the
 * file's place in `path` relative to the application's root and `content` left empty:
 * `/var/emails/2026/10/19/09/<md5>.html.gz`, or `/_history/emails/…`. Read through this, every
 * screen and command shows those messages as they were sent rather than as blank.
 *
 * A path is read only from inside the application's root, and only a file a body is kept in
 * (`.gz`, `.html`, `.htm`): a row is data, and a path that climbs out of the root
 * (`../../etc/passwd`) or names another kind of file reads as no body at all.
 */
final class Body
{
    /**
     * The body of a `mails` row: its content where it has one, else the file its path names.
     * '' when it has neither, or the file is not there any more.
     *
     * @param array<string, mixed> $row
     */
    public static function of(array $row): string
    {
        $content = (string) ($row['content'] ?? '');
        if ($content !== '') {
            return $content;
        }
        $file = self::file((string) ($row['path'] ?? ''));
        $raw  = $file === null ? false : @file_get_contents($file);
        if ($raw === false) {
            return '';
        }

        return str_ends_with(strtolower($file), '.gz') ? (string) @gzdecode($raw) : $raw;
    }

    /**
     * The file a row's path names, as an absolute path — relative to the application's root as
     * applications write it, or already absolute inside it; null for one outside the root, not a
     * body's kind of file, or not there.
     */
    public static function file(string $path): ?string
    {
        $root = self::root();
        if ($path === '' || $root === null || str_contains($path, "\0") || preg_match('/\.(gz|html?)$/i', $path) !== 1) {
            return null;
        }
        $candidate = str_starts_with($path, $root . DIRECTORY_SEPARATOR) ? $path : $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
        $real      = realpath($candidate);

        return $real !== false && is_file($real) && str_starts_with($real, $root . DIRECTORY_SEPARATOR) ? $real : null;
    }

    /** The application's root, resolved; null where there is none. */
    private static function root(): ?string
    {
        $root = defined('ROOT') ? realpath((string) ROOT) : false;

        return $root === false ? null : rtrim($root, DIRECTORY_SEPARATOR);
    }
}
