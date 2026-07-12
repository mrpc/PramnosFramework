<?php

namespace Pramnos\Application\Template;

/**
 * File-based cache for compiled .tpl.php templates.
 *
 * Compiled PHP files are stored in a configurable directory under a name
 * derived from sha1(realpath) so that templates with the same basename in
 * different directories never collide.
 *
 * Invalidation is modification-time based: if the source file is newer than
 * the cached version, the source is recompiled. Compiled files are not
 * invalidated in production unless the source changes, making includes fast
 * after the first request.
 *
 * Default cache directory: ROOT/var/viewcache (falls back to sys_get_temp_dir()
 * when ROOT is not defined, e.g. in unit tests).
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license    MIT
 */
class TemplateCache
{
    private string $cacheDir;

    public function __construct(string $cacheDir = '')
    {
        $this->cacheDir = $cacheDir !== '' ? $cacheDir : $this->resolveDefaultCacheDir();
    }

    /**
     * Returns the default cache directory when no explicit path is given.
     * Extracted as a protected method so tests can override it without
     * depending on the ROOT constant being defined or undefined.
     */
    protected function resolveDefaultCacheDir(): string
    {
        if (defined('ROOT')) {
            return ROOT . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'viewcache';
        }
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pramnos_viewcache';
    }

    // =========================================================================
    // Cache resolution
    // =========================================================================

    /**
     * Resolve $sourcePath to an includable compiled path.
     *
     * If the cached file is up to date, returns it immediately. Otherwise
     * compiles via $compiler, stores the result, and returns the cached path.
     *
     * @param string   $sourcePath Absolute path to the .tpl.php source.
     * @param callable $compiler   callable(string $source): string
     * @return string              Absolute path to the compiled .php file.
     * @throws \RuntimeException   When the cache directory or file cannot be written.
     */
    public function resolve(string $sourcePath, callable $compiler): string
    {
        if (!$this->isUpToDate($sourcePath)) {
            $source   = (string) file_get_contents($sourcePath);
            $compiled = $compiler($source);
            $this->store($sourcePath, $compiled);
        }
        return $this->getCachedPath($sourcePath);
    }

    // =========================================================================
    // Individual operations
    // =========================================================================

    /**
     * Return the absolute path to the cached compiled file for $sourcePath.
     * The file may or may not exist yet.
     */
    public function getCachedPath(string $sourcePath): string
    {
        $key = sha1(realpath($sourcePath) ?: $sourcePath);
        return $this->cacheDir . DIRECTORY_SEPARATOR . $key . '.php';
    }

    /**
     * True when a valid cached version exists and is at least as new as the source.
     */
    public function isUpToDate(string $sourcePath): bool
    {
        $cached = $this->getCachedPath($sourcePath);
        if (!file_exists($cached)) {
            return false;
        }
        // filemtime returns false on error — treat as outdated
        $cachedMtime = filemtime($cached);
        $sourceMtime = filemtime($sourcePath);
        if ($cachedMtime === false || $sourceMtime === false) {
            return false;
        }
        return $cachedMtime >= $sourceMtime;
    }

    /**
     * Write compiled PHP to the cache directory.
     *
     * Creates the cache directory if it does not exist.
     *
     * @return string Absolute path to the written file.
     * @throws \RuntimeException On I/O failure.
     */
    public function store(string $sourcePath, string $compiled): string
    {
        if (!is_dir($this->cacheDir)) {
            if (!@mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                throw new \RuntimeException(
                    "Cannot create template cache directory: {$this->cacheDir}"
                );
            }
        }
        $cached = $this->getCachedPath($sourcePath);
        if (file_put_contents($cached, $compiled) === false) {
            throw new \RuntimeException("Cannot write compiled template: {$cached}");
        }
        return $cached;
    }

    // =========================================================================
    // Maintenance
    // =========================================================================

    /** Delete all compiled files from the cache directory. */
    public function flush(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }
        foreach (glob($this->cacheDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            unlink($file);
        }
    }

    // =========================================================================
    // Configuration
    // =========================================================================

    public function getCacheDir(): string   { return $this->cacheDir; }
    public function setCacheDir(string $dir): void { $this->cacheDir = $dir; }
}
