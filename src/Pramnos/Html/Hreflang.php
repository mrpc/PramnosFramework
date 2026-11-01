<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * The registry behind hreflang for a page the router cannot derive
 * alternates for — a page whose own slug comes from the database, or a
 * hand-maintained family of translated URLs.
 *
 * ```php
 * // app/hreflang.php
 * use Pramnos\Html\Hreflang;
 *
 * // 1. A group — for what no rule can derive
 * Hreflang::group([
 *     'el'    => '/syxnes-erwthseis',
 *     'en'    => '/faqs',
 *     'en-gb' => '/uk/faqs',
 * ]);
 *
 * // 2. A rule for a family of URLs — the controller already knows its own key
 * Hreflang::resolver('Jobs@view', fn (array $p) => Ad::find($p['id'])?->urlsByLanguage() ?? []);
 *
 * // 3. The third state, stated
 * Hreflang::singleLanguage('/oroi-xrisis');
 * ```
 *
 * For a page registered as a {@see \Pramnos\Routing\Router} localized route group instead,
 * {@see \Pramnos\Routing\Router::getLocaleSiblings()} already answers this with no
 * declaration at all — this registry exists for everything that mechanism cannot reach.
 * See {@see \Pramnos\Document\Document::addAlternatesFromHreflang()} for the consuming side,
 * and `docs/Hreflang-And-Sitemap-Design.md` for the design this implements.
 *
 * ## Three states, not two
 *
 * A page with nothing registered for it gets no hreflang at all — that is not the same as
 * {@see singleLanguage()}, which says explicitly "this page has no translations" so a reader
 * of `app/hreflang.php` can tell "not covered yet" apart from "deliberately one language".
 *
 * ## Fail-closed, the same idiom as {@see \Pramnos\Search\Registry}
 *
 * A resolver that throws, or returns something that is not an array, answers with no
 * alternates rather than fataling the page it was meant to help — hreflang is a `<head>`
 * enhancement, not something a broken registration should be able to take a page down over.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class Hreflang
{
    /**
     * Hand-declared locale groups — each a `locale => path` map, looked up by whether the
     * current path is one of its values.
     *
     * @var list<array<string, string>>
     */
    private static array $groups = [];

    /**
     * Resolvers, keyed by whatever identifier the caller chooses for its own page — a route
     * name, a `'Controller@action'` string, anything stable for that page.
     *
     * @var array<string, callable>
     */
    private static array $resolvers = [];

    /**
     * Paths explicitly declared to exist in one language only.
     *
     * @var array<string, true>
     */
    private static array $singleLanguagePaths = [];

    /** Guard so `app/hreflang.php` is required once per process. */
    private static bool $loaded = false;

    /**
     * Declare a family of URLs that are translations of one another.
     *
     * Every member is checked for reciprocity by nothing but its own presence in this one
     * map — each key's URL will emit the whole map as its alternates, itself included, so
     * there is no way for the Greek page to disagree with the English one about the pair.
     *
     * @param array<string, string> $localeMap  locale => path, e.g. `['el' => '/x', 'en' => '/y']`.
     */
    public static function group(array $localeMap): void
    {
        $normalized = [];
        foreach ($localeMap as $locale => $path) {
            $normalized[$locale] = self::normalizePath($path);
        }
        self::$groups[] = $normalized;
    }

    /**
     * Register a resolver for a family of URLs whose alternates come from the database.
     *
     * @param string   $key       A stable identifier the caller chooses for its own page —
     *                            a route name, a `'Controller@action'` string.
     * @param callable $resolver  `fn (array $params): array<string, string>` — locale => URL,
     *                            including the current locale itself.
     */
    public static function resolver(string $key, callable $resolver): void
    {
        self::$resolvers[$key] = $resolver;
    }

    /**
     * Declare that a path exists in one language only — the third state, so a reader of
     * `app/hreflang.php` can tell "deliberately single-language" apart from "not covered".
     *
     * @param string $path
     */
    public static function singleLanguage(string $path): void
    {
        self::$singleLanguagePaths[self::normalizePath($path)] = true;
    }

    /** Whether $path was declared single-language. */
    public static function isSingleLanguage(string $path): bool
    {
        return isset(self::$singleLanguagePaths[self::normalizePath($path)]);
    }

    /**
     * The alternates for $path, from whichever registered group contains it as one of its
     * values — or `[]` when no group covers it.
     *
     * @param  string $path
     * @return array<string, string>
     */
    public static function alternatesForPath(string $path): array
    {
        $path = self::normalizePath($path);
        foreach (self::$groups as $localeMap) {
            if (in_array($path, $localeMap, true)) {
                return $localeMap;
            }
        }
        return [];
    }

    /**
     * Run the resolver registered under $key, if any.
     *
     * Fail-closed: a missing key, a resolver that throws, or one that returns something
     * other than an array all answer `[]` — never a page that stops rendering because its
     * hreflang registration had a bug.
     *
     * @param  string               $key
     * @param  array<string, mixed> $params
     * @return array<string, string>
     */
    public static function resolve(string $key, array $params = []): array
    {
        if (!isset(self::$resolvers[$key])) {
            return [];
        }

        try {
            $result = (self::$resolvers[$key])($params);
        } catch (\Throwable) {
            return [];
        }

        return is_array($result) ? $result : [];
    }

    /**
     * Load the application's hreflang definitions.
     *
     * The same convention as `app/search.php` ({@see \Pramnos\Search\Registry::loadDefinitions()}):
     * a plain PHP file that calls the static API. Idempotent within a process; returns false
     * when there is no such file, which is not an error — an application with nothing
     * registered simply has no hreflang from this registry.
     *
     * @param string|null $file Absolute path; defaults to ROOT/app/hreflang.php.
     */
    public static function loadDefinitions(?string $file = null): bool
    {
        if (self::$loaded) {
            return self::hasDefinitions();
        }

        self::$loaded = true;

        $file ??= defined('ROOT')
            ? ROOT . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'hreflang.php'
            : '';

        if ($file === '' || !is_file($file)) {
            return false;
        }

        require $file; // registers groups/resolvers/single-language paths via the static API

        return self::hasDefinitions();
    }

    /**
     * Every hand-declared group — read-only, for a sitemap generator to turn into entries
     * the same way the `<head>` already does. Does not include `resolver()`-covered pages:
     * a resolver needs params the generator cannot enumerate, which is what
     * {@see \Pramnos\Html\Sitemap} registers a provider for instead.
     *
     * @return list<array<string, string>>
     */
    public static function groups(): array
    {
        return self::$groups;
    }

    /** Whether anything at all has been registered. */
    public static function hasDefinitions(): bool
    {
        return self::$groups !== [] || self::$resolvers !== [] || self::$singleLanguagePaths !== [];
    }

    /**
     * Forget every registration.
     *
     * Test isolation only — the same reason {@see \Pramnos\Search\Registry::reset()} exists.
     */
    public static function reset(): void
    {
        self::$groups = [];
        self::$resolvers = [];
        self::$singleLanguagePaths = [];
        self::$loaded = false;
    }

    /** Leading-slash, no-trailing-slash form, so registration and lookup always agree. */
    private static function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        return $path === '/' ? $path : rtrim($path, '/');
    }
}
