<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * Static utilities for resolving the framework's bundled scaffolding directory
 * and for determining which scaffold theme a project was initialised with.
 *
 * Used by:
 *  - Controller::getView()      — to locate fallback view files
 *  - Init command               — to know where scaffold assets live
 *  - scaffold:views command     — to copy bundled views into a project
 *
 * @package    PramnosFramework
 * @subpackage Application
 */
class ScaffoldingHelper
{
    /** Ordered list of all valid scaffold theme identifiers. */
    public const THEMES = ['plain-css', 'bootstrap', 'tailwind'];

    /**
     * Locate the framework's `scaffolding/` directory by walking up the
     * filesystem from this source file.
     *
     * In a Composer-installed package the walk reaches the package root in
     * 4–5 steps; in the development repository it reaches it in 3 steps.
     * Falls back to the standard vendor path as a last resort.
     *
     * @return string Absolute path to the scaffolding/ directory (no trailing slash)
     */
    public static function resolveScaffoldingDir(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . 'scaffolding';
            if (is_dir($candidate . DIRECTORY_SEPARATOR . 'templates')) {
                return $candidate;
            }
            $dir = dirname($dir);
        }
        // Standard Composer vendor installation path
        return (defined('ROOT') ? ROOT : getcwd())
            . DIRECTORY_SEPARATOR . 'vendor'
            . DIRECTORY_SEPARATOR . 'mrpc'
            . DIRECTORY_SEPARATOR . 'pramnosframework'
            . DIRECTORY_SEPARATOR . 'scaffolding';
    }

    /**
     * Return the absolute path to the bundled theme directory for a given
     * scaffold theme name (e.g. `bootstrap`).
     *
     * The returned directory is structured as:
     *   {themeDir}/views/{viewGroup}/{viewName}.html.php
     *
     * @param string $scaffoldTheme One of the values in self::THEMES
     * @return string
     */
    public static function getThemeDir(string $scaffoldTheme): string
    {
        return static::resolveScaffoldingDir()
            . DIRECTORY_SEPARATOR . 'themes'
            . DIRECTORY_SEPARATOR . $scaffoldTheme;
    }

    /**
     * Extract the `scaffold_theme` key from an application info array.
     *
     * Returns `null` when the key is absent (projects initialised before
     * scaffold_theme was tracked) so callers can apply a multi-theme fallback.
     *
     * @param array $applicationInfo The array from `app/app.php`
     * @return string|null
     */
    public static function getScaffoldTheme(array $applicationInfo): ?string
    {
        $theme = $applicationInfo['scaffold_theme'] ?? null;
        return (is_string($theme) && $theme !== '') ? $theme : null;
    }

    /**
     * Return all theme directories that exist in the framework's scaffolding
     * directory, in the canonical THEMES order.
     *
     * Used when `scaffold_theme` is not configured — the controller tries
     * each theme directory in turn until it finds the requested view.
     *
     * @return string[] Absolute paths to existing theme directories
     */
    public static function getAvailableThemeDirs(): array
    {
        $base = static::resolveScaffoldingDir()
            . DIRECTORY_SEPARATOR . 'themes';
        $dirs = [];
        foreach (static::THEMES as $theme) {
            $d = $base . DIRECTORY_SEPARATOR . $theme;
            if (is_dir($d)) {
                $dirs[] = $d;
            }
        }
        return $dirs;
    }

    /**
     * Return a map of view-group name → list of relative file paths within
     * a theme directory, built by scanning the actual `views/` subtree.
     *
     * Used by the scaffold:views command to enumerate what can be published.
     *
     * @param string $scaffoldTheme Theme name (e.g. 'bootstrap')
     * @return array<string, string[]> Keys are group names (e.g. 'login'),
     *                                 values are relative paths (e.g. ['login/login.html.php'])
     */
    public static function listViewGroups(string $scaffoldTheme): array
    {
        $viewsRoot = static::getThemeDir($scaffoldTheme)
            . DIRECTORY_SEPARATOR . 'views';
        if (!is_dir($viewsRoot)) {
            return [];
        }
        $groups = [];
        foreach (scandir($viewsRoot) as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $groupDir = $viewsRoot . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($groupDir)) continue;
            $files = [];
            foreach (scandir($groupDir) as $file) {
                if ($file === '.' || $file === '..') continue;
                if (is_file($groupDir . DIRECTORY_SEPARATOR . $file)) {
                    $files[] = $entry . DIRECTORY_SEPARATOR . $file;
                }
            }
            if (!empty($files)) {
                sort($files);
                $groups[$entry] = $files;
            }
        }
        ksort($groups);
        return $groups;
    }
}
