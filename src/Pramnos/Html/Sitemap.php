<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * The registry behind `sitemap:generate` — what belongs in the sitemap, entirely the
 * application's own criterion.
 *
 * ```php
 * // app/sitemap.php
 * use Pramnos\Html\Sitemap;
 *
 * Sitemap::provider('ads', function (): \Generator {
 *     foreach (Ad::query()->where('published', true)->cursor() as $ad) {
 *         yield ['loc' => URL . 'ads/' . $ad->slug, 'lastmod' => $ad->updated_at];
 *     }
 * });
 * ```
 *
 * A sitemap is a crawl-budget instrument, not an inventory
 * (`docs/Hreflang-And-Sitemap-Design.md` §6): the question is which pages to **include**, and
 * that selection is the application's knowledge, not the framework's. This registry offers no
 * `latest N` / `changed since` shape — a provider decides, and yields.
 *
 * Providers return **generators**, not arrays — two thousand pages must stream to a file, not
 * be assembled in memory first. {@see \Pramnos\Html\SitemapWriter} is what consumes them.
 *
 * Two sources need no registration here at all, because the declaration already exists
 * elsewhere: a {@see \Pramnos\Routing\Router} localized route group
 * ({@see \Pramnos\Routing\Router::collectedLocaleGroups()}) and a hand-declared
 * {@see Hreflang::group()}. `sitemap:generate` reads both automatically; this registry is for
 * everything else.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class Sitemap
{
    /**
     * Registered providers, keyed by label so a second registration replaces the first.
     *
     * @var array<string, callable(): \Generator>
     */
    private static array $providers = [];

    /** Guard so `app/sitemap.php` is required once per process. */
    private static bool $loaded = false;

    /**
     * Register a provider of sitemap entries.
     *
     * @param string               $label     A name for this provider — shown in the
     *                                         generator's summary, and the registry key
     *                                         (registering the same label twice replaces the
     *                                         earlier entry rather than running both).
     * @param callable(): \Generator $provider  Yields entries: `['loc' => string, 'lastmod' =>
     *                                         ?string, 'changefreq' => ?string, 'priority' =>
     *                                         ?float, 'alternates' => ?array<string, string>]`.
     *                                         Only `loc` is required.
     */
    public static function provider(string $label, callable $provider): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('A sitemap provider needs a label: it is the registry key.');
        }

        self::$providers[$label] = $provider;
    }

    /** Whether anything is registered. */
    public static function hasProviders(): bool
    {
        return self::$providers !== [];
    }

    /**
     * The registered providers, keyed by label.
     *
     * @return array<string, callable(): \Generator>
     */
    public static function providers(): array
    {
        return self::$providers;
    }

    /**
     * Load the application's sitemap definitions.
     *
     * The same convention as `app/search.php` ({@see \Pramnos\Search\Registry::loadDefinitions()})
     * and `app/hreflang.php` ({@see Hreflang::loadDefinitions()}): a plain PHP file that calls
     * the static API. Idempotent within a process; returns false when there is no such file,
     * which is not an error — an application with nothing registered here still has whatever
     * `sitemap:generate`'s two automatic sources find.
     *
     * @param string|null $file Absolute path; defaults to ROOT/app/sitemap.php.
     */
    public static function loadDefinitions(?string $file = null): bool
    {
        if (self::$loaded) {
            return self::hasProviders();
        }

        self::$loaded = true;

        $file ??= defined('ROOT')
            ? ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'sitemap.php'
            : '';

        if ($file === '' || !is_file($file)) {
            return false;
        }

        require $file; // registers providers via the static API

        return self::hasProviders();
    }

    /**
     * Forget every registered provider.
     *
     * Test isolation only — the same reason {@see \Pramnos\Search\Registry::reset()} exists.
     */
    public static function reset(): void
    {
        self::$providers = [];
        self::$loaded = false;
    }
}
