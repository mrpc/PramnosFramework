<?php

declare(strict_types=1);

namespace Pramnos\Logs;

/**
 * A page's path as a log may keep it: without whatever in it identifies a person or opens a door.
 *
 * A password-reset link, an invitation, an RSVP carry their token in the path itself
 * (`/account/reset/u/48211/v/9f3c…`), and a log that keeps paths keeps live tokens for as long as
 * it keeps rows. So, by default:
 *   - lower case, and only the first {@see SEGMENTS} segments;
 *   - a segment with a digit or an `@`, or longer than 60 characters: `:id`;
 *   - a segment with anything but `a-z 0-9 . _ -`: `:x`.
 *
 * `/el/account/rpc/u/48211/v/9F3C` → `/el/account/rpc/u/:id/v`. Page groups match the masked path,
 * so a group's pattern names `:id`, not `\d+`.
 */
final class PagePath
{
    /** The segments kept. */
    public const SEGMENTS = 6;

    /** The path, masked. */
    public static function mask(string $path): string
    {
        $path  = strtolower((string) preg_replace('/[?#].*$/s', '', $path));
        $parts = array_slice(array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== '')), 0, self::SEGMENTS);
        $parts = array_map(static fn (string $p): string => match (true) {
            strlen($p) > 60 || preg_match('/[0-9@]/', $p) === 1 => ':id',
            preg_match('/^[a-z0-9._-]+$/', $p) !== 1             => ':x',
            default                                              => $p,
        }, $parts);

        return '/' . implode('/', $parts) . ($parts !== [] && str_ends_with($path, '/') && count($parts) < self::SEGMENTS ? '/' : '');
    }

    /** A page's address with its path masked: its scheme and host kept, its query and fragment gone. */
    public static function maskUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host'])) {
            return self::mask(trim($url));
        }

        return ($parts['scheme'] ?? 'https') . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . self::mask((string) ($parts['path'] ?? '/'));
    }
}
