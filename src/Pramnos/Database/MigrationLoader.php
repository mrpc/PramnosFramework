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

        $root = $root ?? (defined('ROOT') ? ROOT : getcwd());
        $fromVendor = $root . '/vendor/mrpc/pramnosframework/database/migrations/framework';
        if (is_dir($fromVendor)) {
            return $fromVendor;
        }

        return null;
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
                } catch (\ReflectionException $e) {
                    continue;
                }

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
