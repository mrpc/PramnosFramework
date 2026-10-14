<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst;

/**
 * The CSV files the analyst writes for an administrator, and who may download them.
 *
 * Kept outside the web root (`var/analyst-exports/`), named `<admin id>-<title>-<16 hex>` — the
 * admin's own id, so a link handed to somebody else opens nothing; the title, when there is one,
 * so the file says what it is; and fewer than 32 hex digits, which the redaction would take for a
 * token and mask. Served by `/admin/Ai/export/<name>`, without
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

    /** Greek letters as Latin, for a title in a file name. */
    private const GREEK = [
        'α' => 'a', 'ά' => 'a', 'β' => 'v', 'γ' => 'g', 'δ' => 'd', 'ε' => 'e', 'έ' => 'e', 'ζ' => 'z', 'η' => 'i', 'ή' => 'i',
        'θ' => 'th', 'ι' => 'i', 'ί' => 'i', 'ϊ' => 'i', 'ΐ' => 'i', 'κ' => 'k', 'λ' => 'l', 'μ' => 'm', 'ν' => 'n', 'ξ' => 'x',
        'ο' => 'o', 'ό' => 'o', 'π' => 'p', 'ρ' => 'r', 'σ' => 's', 'ς' => 's', 'τ' => 't', 'υ' => 'y', 'ύ' => 'y', 'ϋ' => 'y',
        'ΰ' => 'y', 'φ' => 'f', 'χ' => 'ch', 'ψ' => 'ps', 'ω' => 'o', 'ώ' => 'o',
    ];

    /** A new file's name, for this administrator, with its title in Latin letters and digits. */
    public static function name(int $adminId, string $title = ''): string
    {
        $slug = strtr(mb_strtolower($title, 'UTF-8'), self::GREEK);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $slug), '-');
        $slug = trim(substr($slug, 0, 40), '-');

        return $adminId . ($slug !== '' ? '-' . $slug : '') . '-' . bin2hex(random_bytes(8));
    }

    /** The name a download is saved under: the title's part, or `export`, and when. */
    public static function filename(string $name, int $at): string
    {
        $slug = preg_match('/^\d+-([a-z0-9-]+)-[a-f0-9]{16}$/', $name, $m) === 1 ? $m[1] : 'export';

        return $slug . '-' . date('Y-m-d-His', $at) . '.csv';
    }

    /**
     * The file behind a name, if it is this administrator's and still there.
     */
    public static function path(string $name, int $adminId): ?string
    {
        if (preg_match('/^(\d+)(?:-[a-z0-9-]{1,40})?-[a-f0-9]{16}$/', $name, $m) !== 1 || (int) $m[1] !== $adminId || $adminId <= 0) {
            return null;
        }

        $file = self::directory() . '/' . $name . '.csv';

        return is_file($file) && filemtime($file) >= time() - self::DAYS * 86400 ? $file : null;
    }

    /**
     * Where the administrator downloads it — under `$area`, the area's URL as the request that
     * asked saw it, so the link is absolute and in the area wherever the job runs. See
     * {@see \Pramnos\Http\AdminArea::address()} for what it falls back on.
     */
    public static function url(string $name, string $area = ''): string
    {
        return \Pramnos\Http\AdminArea::address('Ai/export/' . $name, $area);
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
