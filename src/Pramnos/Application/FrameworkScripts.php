<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * The framework's own page scripts — `assets/js/pf-*.js` — and whether this project serves them.
 *
 * They ship in the framework's `scaffolding/assets/js/` and reach a project's web root only by
 * `init` or `project:resync --js --all`; `composer update` brings the new framework view that
 * loads one, and not the script. The page then works as a plain form with a 404 in the console
 * and nothing anywhere saying why. So: a script the framework ships, referenced through
 * `assetUrl()` and missing from the web root, is logged once with the command that fixes it, and
 * the `framework_scripts` health check lists every one missing.
 */
final class FrameworkScripts
{
    /** @var array<string, true> Scripts already reported in this process */
    private static array $warned = [];

    /**
     * The scripts the framework ships, by file name.
     *
     * @return list<string>
     */
    public static function shipped(): array
    {
        $names = array_map('basename', glob(ScaffoldingHelper::resolveScaffoldingDir() . '/assets/js/pf-*.js') ?: []);
        sort($names);

        return $names;
    }

    /**
     * The shipped scripts missing from a web root — with `$features`, only those an application
     * with these features loads ({@see wanted()}).
     *
     * @param list<string>|null $features
     * @return list<string>
     */
    public static function missing(string $webRoot, ?array $features = null): array
    {
        return array_values(array_filter(self::shipped(), static fn (string $name): bool => !is_file($webRoot . '/assets/js/' . $name)
            && ($features === null || self::wanted($name, $features))));
    }

    /**
     * The scripts that only a feature's screens load, by the features that load them: one the
     * application has none of these on for is not missing from its web root.
     */
    public const FEATURES = [
        'pf-conversations.js'       => ['conversations'],
        'pf-conversations-basic.js' => ['conversations'],
        'pf-chat.js'                => ['llm'],
        'pf-assist.js'              => ['help', 'llm'],
    ];

    /**
     * Whether an application with these features loads a script: every script but a feature's own,
     * and those where one of its features is on.
     *
     * @param list<string> $features
     */
    public static function wanted(string $name, array $features): bool
    {
        return !isset(self::FEATURES[$name]) || array_intersect(self::FEATURES[$name], $features) !== [];
    }

    /**
     * The shipped scripts the web root has an older — or otherwise different — copy of.
     *
     * The scripts are copied, not linked, so a `composer update` brings the new ones into vendor/
     * and leaves the web root's as they were: a page then runs last month's behaviour with
     * nothing on it saying so.
     *
     * @return list<string>
     */
    public static function outdated(string $webRoot): array
    {
        $source = ScaffoldingHelper::resolveScaffoldingDir() . '/assets/js/';

        return array_values(array_filter(self::shipped(), static function (string $name) use ($webRoot, $source): bool {
            $copy = $webRoot . '/assets/js/' . $name;

            return is_file($copy) && md5_file($copy) !== md5_file($source . $name);
        }));
    }

    /**
     * A page asked for `$path` and the web root has no such file: if it is one of the
     * framework's scripts, say once which and how to publish it.
     */
    public static function referencedButMissing(string $path): void
    {
        if (preg_match('#^assets/js/(pf-[\w.-]+\.js)$#', $path, $m) !== 1 || isset(self::$warned[$m[1]])) {
            return;
        }

        if (!in_array($m[1], self::shipped(), true)) {
            return;
        }

        self::$warned[$m[1]] = true;
        \Pramnos\Logs\Logger::warning(
            $path . ' is loaded by a page but is not in the web root, so the page runs without it. '
            . 'Run `php bin/pramnos project:resync --js --all` to publish the framework\'s scripts.'
        );
    }

    /** The directory this project serves, beside the front controller: www, public, … */
    public static function webRoot(string $base): string
    {
        foreach (['www', 'public', 'public_html', 'htdocs', 'web'] as $candidate) {
            if (is_file($base . '/' . $candidate . '/index.php')) {
                return $base . '/' . $candidate;
            }
        }

        return $base . '/www';
    }

    /** For a test: forget what was reported. */
    public static function reset(): void
    {
        self::$warned = [];
    }
}
