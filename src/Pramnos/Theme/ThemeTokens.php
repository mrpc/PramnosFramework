<?php

declare(strict_types=1);

namespace Pramnos\Theme;

/**
 * One palette, in daisyUI's own format, readable by everything that needs it.
 *
 * A project's colours used to live wherever its UI system happened to keep them: a
 * daisyUI `@plugin` block for a Tailwind project with npm, hand-written custom
 * properties for a buildless one, Sass variables under Bootstrap, and a third copy in
 * a SPA's own theme file. Four places, one palette, and the first thing to go wrong is
 * that they stop agreeing — usually in the theme nobody develops in.
 *
 * So the source of truth is a single `app/theme.css` written in the format
 * [daisyUI's theme generator](https://daisyui.com/theme-generator/) already emits, and
 * everything else is generated from it:
 *
 * ```css
 * @plugin "daisyui/theme" {
 *     name: "myapp";
 *     default: true;
 *     color-scheme: light;
 *     --color-base-100: oklch(98% 0 0);
 *     --color-primary: oklch(55% 0.16 240);
 *     --radius-box: 1rem;
 * }
 * ```
 *
 * **Why that format and not a JSON file of our own.** It is the one a designer can
 * produce without this framework existing: pick colours on daisyUI's site, copy the
 * block, paste it in. A Tailwind project with npm needs no build step at all — its
 * `app.css` imports the file and the plugin reads it. `pramnos theme:build` is for
 * everybody else: it turns the same blocks into plain custom properties (every UI
 * system) and JSON (a SPA).
 *
 * This class does not care whether daisyUI is installed. It reads token declarations
 * out of a block and writes them back out; the tokens are daisyUI's vocabulary because
 * a vocabulary somebody else maintains is one we do not have to.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @license     MIT
 */
final class ThemeTokens
{
    /** Where a project keeps its palette, relative to the application root. */
    public const DEFAULT_PATH = 'app/theme.css';

    /**
     * Parsed themes, keyed by path, so a request that asks twice reads once.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private static array $cache = [];

    /**
     * Read every `@plugin "daisyui/theme"` block in a stylesheet.
     *
     * Tolerant by design: a block with no `name` is skipped rather than fatal, an
     * unknown property is carried through untouched, and anything outside a block —
     * `@import`, a comment, the project's own CSS — is ignored. The file is a
     * stylesheet a person edits, and refusing to read all of it because of one line
     * would be worse than reading the rest.
     *
     * @param string $css The stylesheet's contents
     * @return array<string, array<string, mixed>> Theme name => definition, where a
     *         definition has `name`, `default`, `prefersdark`, `color_scheme` and
     *         `tokens` (custom property => value)
     */
    public static function parse(string $css): array
    {
        $themes = [];

        // The blocks, in order. Nothing inside one nests, so the first `}` closes it.
        if (!preg_match_all(
            '/@plugin\s+["\']daisyui\/theme["\']\s*\{([^}]*)\}/i',
            $css,
            $matches,
            PREG_SET_ORDER
        )) {
            return $themes;
        }

        foreach ($matches as $match) {
            $theme = [
                'name'         => '',
                'default'      => false,
                'prefersdark'  => false,
                'color_scheme' => '',
                'tokens'       => [],
            ];

            foreach (explode(';', $match[1]) as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $line, 2);
                $key   = trim($key);
                $value = trim(trim($value), '"\'');

                match (true) {
                    $key === 'name'         => $theme['name'] = $value,
                    $key === 'default'      => $theme['default'] = self::isTrue($value),
                    $key === 'prefersdark'  => $theme['prefersdark'] = self::isTrue($value),
                    $key === 'color-scheme' => $theme['color_scheme'] = $value,
                    str_starts_with($key, '--') => $theme['tokens'][$key] = $value,
                    // Something daisyUI understands and this does not. Kept, because
                    // dropping a line a person wrote is the worst answer available.
                    default => $theme['tokens'][$key] = $value,
                };
            }

            if ($theme['name'] === '') {
                continue;
            }

            $themes[$theme['name']] = $theme;
        }

        return $themes;
    }

    /**
     * The palette as plain CSS custom properties — what every non-npm build needs.
     *
     * Three selectors per theme, and each one earns its place:
     *
     *   - `[data-theme="<name>"]` — the theme when something asks for it by name;
     *   - `:root` as well, for the theme marked `default: true`, so a page that sets
     *     no attribute still has a palette;
     *   - a `prefers-color-scheme: dark` block for the theme marked `prefersdark`,
     *     scoped to `:root:not([data-theme])` so an explicit choice still wins over
     *     the operating system's.
     *
     * That last scoping is the whole difference between a theme switch that works and
     * one that works only for visitors whose OS is already in light mode.
     *
     * @param array<string, array<string, mixed>> $themes As returned by {@see parse()}
     */
    public static function toCss(array $themes): string
    {
        if ($themes === []) {
            return '';
        }

        $out = "/*\n"
            . " * Generated by `pramnos theme:build` from " . self::DEFAULT_PATH . ".\n"
            . " * Do not edit: edit that file and build again.\n"
            . " */\n";

        $dark = null;

        foreach ($themes as $theme) {
            $selectors = ['[data-theme="' . $theme['name'] . '"]'];
            if ($theme['default'] === true) {
                array_unshift($selectors, ':root');
            }

            $out .= "\n" . implode(",\n", $selectors) . " {\n"
                . self::declarations($theme)
                . "}\n";

            if ($theme['prefersdark'] === true && $dark === null) {
                $dark = $theme;
            }
        }

        if ($dark !== null) {
            $out .= "\n/* The OS preference, for a visitor who has not chosen. */\n"
                . "@media (prefers-color-scheme: dark) {\n"
                . "    :root:not([data-theme]) {\n"
                . self::declarations($dark, '        ')
                . "    }\n}\n";
        }

        return $out;
    }

    /**
     * The palette as JSON, for a build that reads JavaScript rather than CSS.
     *
     * A SPA's own components need the same values, and a second hand-maintained copy
     * of them is the thing this file exists to prevent. Pretty-printed and with
     * slashes unescaped, because it is a file somebody will open.
     *
     * @param array<string, array<string, mixed>> $themes
     */
    public static function toJson(array $themes): string
    {
        return (string) json_encode(
            $themes,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";
    }

    /**
     * The project's palette, read from disk once per request.
     *
     * @param string|null $path Absolute path, or null for `ROOT/app/theme.css`
     * @return array<string, array<string, mixed>> Empty when the file is absent —
     *         a project that never declared a palette is not an error
     */
    public static function load(?string $path = null): array
    {
        $path ??= (defined('ROOT') ? ROOT . '/' : '') . self::DEFAULT_PATH;

        if (array_key_exists($path, self::$cache)) {
            return self::$cache[$path];
        }

        $css = is_readable($path) ? (string) file_get_contents($path) : '';

        return self::$cache[$path] = self::parse($css);
    }

    /**
     * One token's value, for server-side code that has to know a colour.
     *
     * The case that keeps coming up is `<meta name="theme-color">`: the browser chrome
     * should match the page, and the value has to be in the markup rather than in a
     * stylesheet. An HTML email is the other — it has no custom properties at all, so
     * every colour has to be written out.
     *
     * @param string $token The custom property, with or without the leading `--`
     * @param string $theme Theme name; the default theme when empty
     * @param string $fallback Returned when the token or the theme is not declared
     */
    public static function token(string $token, string $theme = '', string $fallback = ''): string
    {
        $themes = self::load();
        if ($themes === []) {
            return $fallback;
        }

        $definition = $theme !== ''
            ? ($themes[$theme] ?? null)
            : self::defaultTheme($themes);

        if ($definition === null) {
            return $fallback;
        }

        $key = str_starts_with($token, '--') ? $token : '--' . $token;

        return (string) ($definition['tokens'][$key] ?? $fallback);
    }

    /**
     * The theme a page gets when it asks for none.
     *
     * The one flagged `default`, or the first declared — which is what daisyUI itself
     * does, and guessing differently would put the framework and the plugin in
     * disagreement about the same file.
     *
     * @param array<string, array<string, mixed>> $themes
     * @return array<string, mixed>|null
     */
    public static function defaultTheme(array $themes): ?array
    {
        foreach ($themes as $theme) {
            if ($theme['default'] === true) {
                return $theme;
            }
        }

        return $themes === [] ? null : reset($themes);
    }

    /**
     * Clear the read-once cache. For tests, and for a command that has just written
     * the file it is about to read back.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * One theme's declarations, indented.
     *
     * @param array<string, mixed> $theme
     */
    private static function declarations(array $theme, string $indent = '    '): string
    {
        $out = '';

        if ($theme['color_scheme'] !== '') {
            $out .= $indent . 'color-scheme: ' . $theme['color_scheme'] . ";\n";
        }

        foreach ($theme['tokens'] as $property => $value) {
            $out .= $indent . $property . ': ' . $value . ";\n";
        }

        return $out;
    }

    /** daisyUI accepts `true`, and a bare property name means the same thing. */
    private static function isTrue(string $value): bool
    {
        return $value === '' || strtolower($value) === 'true';
    }
}
