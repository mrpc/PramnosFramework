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
 * A path is read only from inside the application's root, or the body store's folder, and only
 * a file a body is kept in (`.gz`, `.html`, `.htm`): a row is data, and a path that climbs out
 * (`../../etc/passwd`) or names another kind of file reads as no body at all.
 *
 * The framework's own store ({@see BodyStore}) also keeps bodies in `mail_bodies` (`path` is
 * `db:`) and on a storage disk (`disk:<name>:<path>`), and keeps them before what differs per
 * recipient — read here, they are given back as they were sent.
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
        $path    = (string) ($row['path'] ?? '');
        $rebuild = false;
        if ($path === BodyStore::IN_DATABASE) {
            try {
                $kept = BodyStore::getRow((int) ($row['id'] ?? 0), (int) ($row['date'] ?? 0));
            } catch (\Throwable) {
                $kept = null;
            }
            [$body, $rebuild] = $kept === null ? ['', false] : [$kept['body'], $kept['rebuild']];
        } elseif (str_starts_with($path, 'disk:')) {
            [, $disk, $relative] = array_pad(explode(':', $path, 3), 3, '');
            try {
                $raw = \Pramnos\Storage\Storage::getManager()->disk($disk)->get($relative);
            } catch (\Throwable) {
                $raw = '';
            }
            $body    = str_ends_with($relative, '.gz') ? (string) @gzdecode($raw) : $raw;
            $rebuild = str_ends_with($relative, BodyStore::REBUILD_EXTENSION);
        } else {
            $file    = self::file($path);
            $raw     = $file === null ? false : @file_get_contents($file);
            $body    = $raw === false ? '' : (str_ends_with(strtolower((string) $file), '.gz') ? (string) @gzdecode($raw) : $raw);
            $rebuild = $file !== null && str_ends_with($file, BodyStore::REBUILD_EXTENSION);
        }

        // Kept before what differs per recipient: put back, as it was sent.
        return $rebuild && $body !== ''
            ? BodyStore::rebuild($body, (string) ($row['tomail'] ?? ''), (int) ($row['id'] ?? 0), (int) ($row['date'] ?? 0), self::values($row))
            : $body;
    }

    /**
     * The file a row's path names, as an absolute path — relative to the application's root as
     * applications write it, or already absolute inside it; null for one outside the root, not a
     * body's kind of file, or not there.
     */
    public static function file(string $path): ?string
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('/\.(gz|html?)$/i', $path) !== 1) {
            return null;
        }
        // The application's root, and the store's own folder where it is somewhere else.
        foreach (array_unique(array_filter([self::root(), self::resolve(BodyStore::root())])) as $root) {
            $candidate = str_starts_with($path, $root . DIRECTORY_SEPARATOR) ? $path : $root . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            $real      = realpath($candidate);
            if ($real !== false && is_file($real) && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * A templated message's values, from its row; null for one that was not templated.
     *
     * @param array<string, mixed> $row
     * @return array<string, scalar>|null
     */
    private static function values(array $row): ?array
    {
        $values = json_decode((string) ($row['bodyvars'] ?? ''), true);

        return is_array($values) ? $values : null;
    }

    /** The application's root, resolved; null where there is none. */
    private static function root(): ?string
    {
        return defined('ROOT') ? self::resolve((string) ROOT) : null;
    }

    /** A folder, resolved and without its trailing separator; null where it is not there. */
    private static function resolve(string $folder): ?string
    {
        $real = $folder === '' ? false : realpath($folder);

        return $real === false ? null : rtrim($real, DIRECTORY_SEPARATOR);
    }
}
