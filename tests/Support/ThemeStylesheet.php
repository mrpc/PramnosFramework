<?php

declare(strict_types=1);

namespace Pramnos\Tests\Support;

/**
 * A scaffold theme's stylesheet as the browser receives it: `style.css`, and the
 * `components.css` it imports where the theme has one.
 *
 * Tests that check a theme styles a hook read it through here, so moving a rule between the
 * two files is not a failure — and a rule in neither still is.
 */
final class ThemeStylesheet
{
    /**
     * @param string $themeDir A theme's directory, or its name under scaffolding/themes/
     */
    public static function read(string $themeDir): string
    {
        if (!str_contains($themeDir, '/')) {
            $themeDir = dirname(__DIR__, 2) . '/scaffolding/themes/' . $themeDir;
        }
        $css = (string) file_get_contents($themeDir . '/style.css');
        if (is_file($themeDir . '/components.css')) {
            $css .= "\n" . (string) file_get_contents($themeDir . '/components.css');
        }

        return $css;
    }
}
