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
     * The file a test can `include` with `$this` bound: the view itself, or a template compiled
     * to PHP in a temporary file the caller removes with {@see forget()}.
     */
    public static function includable(string $theme, string $view): string
    {
        $path = self::path($theme, $view);
        if (str_ends_with($path, '.html.php')) {
            return $path;
        }
        $compiled = tempnam(sys_get_temp_dir(), 'pf-tpl-') . '.php';
        file_put_contents($compiled, (new \Pramnos\Application\Template\TemplateCompiler())->compile((string) file_get_contents($path)));

        return $compiled;
    }

    /** Remove a compiled template {@see includable()} made; a view's own file is left alone. */
    public static function forget(string $file): void
    {
        if (str_starts_with(basename($file), 'pf-tpl-')) {
            @unlink($file);
        }
    }
}
