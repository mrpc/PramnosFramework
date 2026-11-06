<?php

declare(strict_types=1);

namespace Pramnos\Tests\Support;

/**
 * A bundled theme view for a test, whichever kind it is written in: `.html.php`, or `.tpl.php`
 * in the template engine. The scaffold's views move to the engine one directory at a time, and a
 * test that reads or draws one should not have to know which kind it is today.
 */
final class ScaffoldView
{
    /** The view's file: `.html.php` when there is one, `.tpl.php` otherwise. */
    public static function path(string $theme, string $view): string
    {
        $base = dirname(__DIR__, 2) . '/scaffolding/themes/' . $theme . '/views/' . $view;

        return is_file($base . '.html.php') ? $base . '.html.php' : $base . '.tpl.php';
    }

    /** The view's source as written. */
    public static function source(string $theme, string $view): string
    {
        return (string) @file_get_contents(self::path($theme, $view));
    }

    /**
     * Whether the view includes a partial — `$this->insert('x')` in PHP, `@include('x')` in a
     * template.
     */
    public static function includes(string $theme, string $view, string $partial): bool
    {
        $source = self::source($theme, $view);

        return str_contains($source, "insert('" . $partial . "')") || str_contains($source, "@include('" . $partial . "')");
    }

    /**
     * The file a test can `include` with `$this` bound: the view itself, or the template compiled
     * to PHP — kept in the temporary directory under a name of its content, so the same template
     * compiles once and nothing piles up.
     */
    public static function includable(string $theme, string $view): string
    {
        return self::file(dirname(__DIR__, 2) . '/scaffolding/themes/' . $theme . '/views/' . $view);
    }

    /**
     * {@see includable()} for a path without its extension: `<base>.html.php` when there is one,
     * `<base>.tpl.php` compiled otherwise.
     */
    public static function file(string $base): string
    {
        if (is_file($base . '.html.php') || !is_file($base . '.tpl.php')) {
            return $base . '.html.php';
        }
        $source   = (string) file_get_contents($base . '.tpl.php');
        $compiled = sys_get_temp_dir() . '/pf-tpl-' . md5($base . "\0" . $source) . '.php';
        if (!is_file($compiled)) {
            file_put_contents($compiled, (new \Pramnos\Application\Template\TemplateCompiler())->compile($source));
        }

        return $compiled;
    }
}
