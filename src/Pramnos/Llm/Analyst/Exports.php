<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

/**
 * The CSV files the analyst writes for an administrator, and who may download them.
 *
 * Kept outside the web root (`var/analyst-exports/`), named `<admin id>-<16 hex>` — the admin's
 * own id, so a link handed to somebody else opens nothing, and fewer than 32 hex digits, which
 * the redaction would take for a token and mask. Served by `/admin/Ai/export/<name>`, without
 * `.csv`, which the router would read as a format. Gone after {@see DAYS} days.
 */
final class Exports
{
    public const DAYS = 7;

    /** @var string|null Where the files are kept; null for `var/analyst-exports` */
    private static ?string $directory = null;

    public static function directory(): string
    {
        return self::$directory ?? (defined('ROOT') ? ROOT : getcwd()) . '/var/analyst-exports';
    }

    /** For a test: somewhere else, or null for the default. */
    public static function useDirectory(?string $directory): void
    {
        self::$directory = $directory;
    }

    /** A new file's name, for this administrator. */
    public static function name(int $adminId): string
    {
        return $adminId . '-' . bin2hex(random_bytes(8));
    }

    /**
     * The file behind a name, if it is this administrator's and still there.
     */
    public static function path(string $name, int $adminId): ?string
    {
        if (preg_match('/^(\d+)-[a-f0-9]{16}$/', $name, $m) !== 1 || (int) $m[1] !== $adminId || $adminId <= 0) {
            return null;
        }

        $file = self::directory() . '/' . $name . '.csv';

        return is_file($file) && filemtime($file) >= time() - self::DAYS * 86400 ? $file : null;
    }

    /** Where the administrator downloads it. */
    public static function url(string $name): string
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $prefix = trim((string) (is_object($app) ? ($app->applicationInfo['admin']['prefix'] ?? '') : ''), '/');

        return \Pramnos\Http\SiteUrl::to(($prefix !== '' ? $prefix . '/' : '') . 'Ai/export/' . $name);
    }

    /** Remove the files past their days. */
    public static function prune(): void
    {
        foreach (glob(self::directory() . '/*.csv') ?: [] as $file) {
            if (filemtime($file) < time() - self::DAYS * 86400) {
                @unlink($file);
            }
        }
    }
}
