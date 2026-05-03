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
 * @package     PramnosFramework
 * @subpackage  Database
 */
class MigrationLoader
{
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
