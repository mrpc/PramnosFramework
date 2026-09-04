<?php

namespace Pramnos\Database;

/**
 * Discovers and instantiates Migration subclasses from the filesystem.
 *
 * The loader includes each PHP file in a directory, then inspects the full
 * list of declared classes to find any that (a) are subclasses of Migration
 * and (b) are defined in the file that was just included.  This approach
 * works reliably even when PHP's include_once has already cached a file from
 * a previous call.
 *
 * Files should be named following the YYYY_MM_DD_HHmmss_slug.php convention
 * so that Migration::getTimestamp() and getSlug() can extract ordering data
 * from the filename.  Legacy non-timestamped filenames are supported as
 * well — their migrations are sorted by class-name-derived slug.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license    MIT
 */
class MigrationLoader
{
    // =========================================================================
    // Directory resolution (shared by CLI Migrate command and Application auto-run)
    // =========================================================================

    /**
     * Locates the framework's database/migrations/framework directory.
     *
     * Works whether the framework is the project root (development) or is
     * installed as a Composer package inside vendor/.
     *
     * @param string|null $root Project root override. Defaults to ROOT constant
     *                          or getcwd() when ROOT is not defined.
     * @return string|null Absolute path, or null when the directory cannot be found.
     */
    public static function resolveFrameworkMigrationsBase(?string $root = null): ?string
    {
        // Path relative to this file: src/Pramnos/Database → ../../../database/migrations/framework
        $fromSource = dirname(__DIR__, 3) . '/database/migrations/framework';
        if (is_dir($fromSource)) {
            return realpath($fromSource) ?: $fromSource;
        }

        // @codeCoverageIgnoreStart
        // Vendor fallback — only reachable when the framework is installed via
        // Composer rather than run from source. Not reachable during test runs.
        $root = $root ?? (defined('ROOT') ? ROOT : getcwd());
        $fromVendor = $root . '/vendor/mrpc/pramnosframework/database/migrations/framework';
        if (is_dir($fromVendor)) {
            return $fromVendor;
        }

        return null;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Returns the default set of migration directories for an application:
     *   [0] app/Migrations            — application-level migrations (old system)
     *   [1..N] framework/{feature}/   — one per framework feature sub-directory
     *
     * This is the same list the CLI `pramnos migrate` command uses, so that
     * auto-run in Application::exec() and manual `pramnos migrate` always
     * operate on the same set of migrations.
     *
     * @param string|null $root Project root override.
     * @return string[]
     */
    public static function resolveDefaultDirectories(?string $root = null): array
    {
        $root = $root ?? (defined('ROOT') ? ROOT : getcwd());
        $dirs = [$root . '/app/Migrations'];

        $base = static::resolveFrameworkMigrationsBase($root);
        if ($base !== null && is_dir($base)) {
            foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $featureDir) {
                $dirs[] = $featureDir;
            }
        }

        return $dirs;
    }

    /**
     * Which migrations apply to this installation — the safe way to ask.
     *
     * `Application::migrationScope()` is the answer; this is the accessor every
     * caller should use, because the callers are console commands, MCP tools and
     * a debug panel that each reach an application they did not construct. One
     * of them may hold no application at all, and a test may hold a mock whose
     * every method returns null. Both used to be somebody's `?? []`, which is how
     * a missing key becomes a warning in the middle of a report.
     *
     * With nothing to ask, every directory on disk is returned with no cutoff:
     * a report that lists too much can be read, while one that silently applies
     * a gate it could not actually read cannot.
     *
     * @param  object|null $app Ideally a Pramnos application.
     * @param  bool $includeConventionalAppDir See Application::migrationScope().
     * @return array{dirs: string[], skipped: array<string, string>, cutoff: string}
     */
    public static function scopeFor(?object $app, bool $includeConventionalAppDir = false): array
    {
        $scope = null;
        if ($app instanceof \Pramnos\Application\Application) {
            $scope = $app->migrationScope($includeConventionalAppDir);
        }

        // Keyed on the presence of `dirs`, not on whether it is empty. An
        // application that sets `migrations.framework => false` and declares no
        // paths of its own answers `dirs => []` and means it, so emptiness cannot
        // be the signal. A stub answers `[]` — no keys at all — because PHPUnit
        // returns the return type's default for an unstubbed `array` method,
        // which is why testing `is_array()` alone silently reported that an
        // installation had no migrations anywhere.
        if (!is_array($scope) || !array_key_exists('dirs', $scope)) {
            return [
                'dirs'    => static::resolveDefaultDirectories(),
                'skipped' => [],
                'cutoff'  => '',
            ];
        }

        // Defaulted rather than trusted: a subclass may answer with less.
        return [
            'dirs'    => is_array($scope['dirs'] ?? null) ? $scope['dirs'] : [],
            'skipped' => is_array($scope['skipped'] ?? null) ? $scope['skipped'] : [],
            'cutoff'  => is_string($scope['cutoff'] ?? null) ? $scope['cutoff'] : '',
        ];
    }

    /**
     * Scans directories for timestamped migration filenames and returns a
     * slug → timestamp map WITHOUT loading (require-ing) any PHP file.
     *
     * Only files whose basename matches YYYY_MM_DD_HHmmss_slug.php are
     * included. Non-timestamped files (e.g. Migration0126.php) are ignored
     * because their slug depends on the class short-name which cannot be
     * derived from the filename alone.
     *
     * Used by MigrationRunner::hasPendingFromSlugs() for a fast "anything
     * pending?" check that avoids disk I/O of loading every PHP migration file.
     *
     * @param string[] $dirs Absolute paths of directories to scan.
     * @return array<string, string> [slug => YYYY_MM_DD_HHmmss timestamp]
     */
    public static function slugsFromDirectories(array $dirs): array
    {
        $result = [];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $base = basename($file, '.php');
                if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_(.+)$/', $base, $m)) {
                    $result[strtolower($m[2])] = $m[1];
                }
            }
        }
        return $result;
    }
    /**
     * Discovers and instantiates all Migration subclasses in a directory.
     *
     * @param string                            $dir Absolute path to the directory.
     * @param \Pramnos\Application\Application  $app Application instance passed to each migration constructor.
     * @return Migration[]
     */
    public static function loadFromDirectory(
        string $dir,
        \Pramnos\Application\Application $app
    ): array {
        if (!is_dir($dir)) {
            return [];
        }

        $migrations = [];
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $realPath = realpath($file);
            include_once $file;

            foreach (get_declared_classes() as $class) {
                if (!is_subclass_of($class, Migration::class)) {
                    continue;
                }
                try {
                    $ref = new \ReflectionClass($class);
                    // Only pick up classes whose defining file is this exact file,
                    // to avoid re-instantiating migrations from previously loaded files.
                    if (realpath($ref->getFileName()) !== $realPath) {
                        continue;
                    }
                    // Skip abstract classes
                    if ($ref->isAbstract()) {
                        continue;
                    }
                // Start
                } catch (\ReflectionException $e) { // @codeCoverageIgnore
                    continue;
                // End
                } // @codeCoverageIgnore

                $migrations[] = new $class($app);
            }
        }

        return $migrations;
    }

    /**
     * Loads migrations from multiple directories, preserving per-directory order.
     *
     * @param string[]                          $dirs
     * @param \Pramnos\Application\Application  $app
     * @return Migration[]
     */
    public static function loadFromDirectories(
        array $dirs,
        \Pramnos\Application\Application $app
    ): array {
        $migrations = [];
        foreach ($dirs as $dir) {
            foreach (static::loadFromDirectory($dir, $app) as $m) {
                $migrations[] = $m;
            }
        }
        return $migrations;
    }
}
