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
 * So the source of truth is a single `app/themes/theme.css` written in the format
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
    /**
     * Where a project keeps its palette, relative to the application root.
     *
     * Under `app/themes/`, beside the theme directories that read it, rather than loose
     * in `app/` next to `app.php` and `settings.php` — those are configuration, this is
     * design, and a stylesheet in a directory of PHP config files is the first thing
     * somebody tidying up moves.
     */
    public const DEFAULT_PATH = 'app/themes/theme.css';

    /**
     * Paths {@see load()} tries, in order.
     *
     * The second is where the file was first written. Kept as a fallback because the
     * failure mode of not looking is silent: a project that upgrades and keeps its
     * palette where it was would render with no palette and nothing to say why.
     *
     * @var list<string>
     */
    private const CANDIDATE_PATHS = ['app/themes/theme.css', 'app/theme.css'];

    /**
     * The palette, under the names each UI system's own stylesheet already reads.
     *
     * Only the tailwind theme speaks daisyUI. Bootstrap's components read `--bs-*`,
     * defined by the stylesheet it vendors, and the plain-CSS theme reads a vocabulary
     * of its own — so for two of the three bundled themes "one palette, every UI system"
     * was a claim the generated file could not keep. Both rendered their framework's
     * stock colours whatever the project declared.
     *
     * Aliasing is the whole fix, and it is a better one than rewriting those stylesheets
     * to read `--color-*`: Bootstrap's **own** components read `--bs-*` too, and nothing
     * in this repository can edit those. Redefining the variable reaches `.btn-primary`
     * and `.navbar` as well as the theme's own rules.
     *
     * Emitted per theme rather than once, so the dark palette carries its aliases with
     * it — and `head.php` links this file after the UI framework's, so an alias here
     * wins on source order against an equally specific `:root` in Bootstrap's.
     *
     * @var array<string, string> alias => the daisyUI token it takes its value from
     */
    private const BRIDGE = [
        // Bootstrap 5.3. Its surfaces, its text, its borders and its semantic colours.
        '--bs-body-bg'          => '--color-base-100',
        '--bs-body-color'       => '--color-base-content',
        '--bs-emphasis-color'   => '--color-base-content',
        '--bs-secondary-bg'     => '--color-base-200',
        '--bs-tertiary-bg'      => '--color-base-200',
        '--bs-border-color'     => '--color-base-300',
        '--bs-primary'          => '--color-primary',
        '--bs-link-color'       => '--color-primary',
        '--bs-link-hover-color' => '--color-primary',
        '--bs-secondary'        => '--color-secondary',
        '--bs-success'          => '--color-success',
        '--bs-danger'           => '--color-error',
        '--bs-warning'          => '--color-warning',
        '--bs-info'             => '--color-info',

        // The plain-CSS theme's own names. It composes the rest — a hover shade, a
        // muted text tone — from these in its own stylesheet, because daisyUI has no
        // token for either and guessing one here would be inventing vocabulary.
        '--primary-color'       => '--color-primary',
        '--text-main'           => '--color-base-content',
        '--surface'             => '--color-base-100',
        '--bg-subtle'           => '--color-base-200',
        '--border-color'        => '--color-base-300',
    ];

    /**
     * The aliases Bootstrap needs as an `r, g, b` triplet rather than as a colour.
     *
     * `.bg-primary` is `background-color: rgba(var(--bs-primary-rgb), var(--bs-bg-opacity))`,
     * and the scaffolded bootstrap navbar is a `.bg-primary`. A palette written in
     * `oklch()` cannot be fed to `rgba()` and CSS has no way to decompose it, so these
     * are converted at build time — without them the most visible element on the page
     * would keep Bootstrap's blue while everything around it followed the project.
     *
     * @var array<string, string> alias => the daisyUI token to convert
     */
    private const BRIDGE_RGB = [
        '--bs-body-bg-rgb'    => '--color-base-100',
        '--bs-body-color-rgb' => '--color-base-content',
        '--bs-primary-rgb'    => '--color-primary',
        '--bs-link-color-rgb' => '--color-primary',
        '--bs-secondary-rgb'  => '--color-secondary',
        '--bs-success-rgb'    => '--color-success',
        '--bs-danger-rgb'     => '--color-error',
        '--bs-warning-rgb'    => '--color-warning',
        '--bs-info-rgb'       => '--color-info',
    ];

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
     * `@import`, the project's own CSS — is ignored. Comments are removed first,
     * wherever they are, so annotating a colour inside a block is safe. The file is a
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

        // Comments go first, before anything looks for a block or a `;`. The file's own
        // doc-block invites annotating colours, so a `/* … */` inside a block is expected
        // — and to a parser that splits on `;` it reads as one more `name: value`, which
        // put `"/* The surfaces of the site": "#FFF cards on a #F7F7F7 page"` in a
        // project's theme-tokens.json and swallowed the token on the line after it.
        // Stripping here rather than per line also keeps a commented-out `}` from ending
        // a block early.
        $css = (string) preg_replace('!/\\*.*?\\*/!s', '', $css);

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
     * @param string|null $path Absolute path, or null for `ROOT/app/themes/theme.css`
     * @return array<string, array<string, mixed>> Empty when the file is absent —
     *         a project that never declared a palette is not an error
     */
    public static function load(?string $path = null): array
    {
        $path ??= self::locate();

        if (array_key_exists($path, self::$cache)) {
            return self::$cache[$path];
        }

        $css = is_readable($path) ? (string) file_get_contents($path) : '';

        return self::$cache[$path] = self::parse($css);
    }

    /**
     * The palette's path in this project, whichever of the two it uses.
     *
     * Returns the conventional path when neither exists, so a caller reporting "no
     * palette at …" names the place to create one.
     */
    public static function locate(): string
    {
        return self::locateIn(defined('ROOT') ? (string) ROOT : '');
    }

    /**
     * The same answer for a project root that is not `ROOT`.
     *
     * A console command may be pointed at another directory, and a test always is.
     */
    public static function locateIn(string $root): string
    {
        $base = $root === '' ? '' : rtrim($root, '/') . '/';

        foreach (self::CANDIDATE_PATHS as $candidate) {
            if (is_readable($base . $candidate)) {
                return $base . $candidate;
            }
        }

        return $base . self::DEFAULT_PATH;
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
     * One token as a `#rrggbb` hex, for a client that cannot read anything else.
     *
     * {@see token()} returns what the palette says, and daisyUI's theme generator writes
     * `oklch()` — which is the right thing for a stylesheet and useless in the two places
     * that doc-block sends people. An HTML email has no custom properties *and* no mail
     * client that parses oklch: Outlook and Gmail drop the declaration, so the colour
     * falls back to whatever the surrounding markup says, which is usually black on
     * black. `<meta name="theme-color">` is the softer case — modern browsers parse
     * oklch, older ones ignore the tag.
     *
     * The conversion is the same one `theme:build` uses for Bootstrap's triplets, so a
     * colour in an email and the colour on the page agree by construction.
     *
     * A value that cannot be resolved to a colour here — `color-mix()`, a named colour,
     * a `var()` reference — returns the fallback rather than itself: a caller that asked
     * for a hex and received `color-mix(…)` has been handed the same broken email one
     * step later.
     *
     * @param string $token    The custom property, with or without the leading `--`
     * @param string $theme    Theme name; the default theme when empty
     * @param string $fallback Returned when the token, the theme, or the conversion fails
     */
    public static function hex(string $token, string $theme = '', string $fallback = ''): string
    {
        $triplet = self::rgbTriplet(self::token($token, $theme, ''));

        if ($triplet === null) {
            return $fallback;
        }

        return '#' . implode('', array_map(
            static fn (string $channel): string => str_pad(
                dechex((int) trim($channel)),
                2,
                '0',
                STR_PAD_LEFT
            ),
            explode(',', $triplet)
        ));
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

        return $out . self::bridge($theme, $indent);
    }

    /**
     * The same palette again, under the names Bootstrap and the plain-CSS theme read.
     *
     * An alias is emitted only for a token the theme actually declares: aliasing to a
     * property nothing defines would hand those stylesheets the same silent fallback
     * this exists to remove.
     *
     * @param array<string, mixed> $theme
     */
    private static function bridge(array $theme, string $indent = '    '): string
    {
        $out = '';

        foreach (self::BRIDGE as $alias => $token) {
            if (isset($theme['tokens'][$token])) {
                $out .= $indent . $alias . ': var(' . $token . ");\n";
            }
        }

        foreach (self::BRIDGE_RGB as $alias => $token) {
            $triplet = self::rgbTriplet((string) ($theme['tokens'][$token] ?? ''));
            if ($triplet !== null) {
                $out .= $indent . $alias . ': ' . $triplet . ";\n";
            }
        }

        return $out === '' ? '' : $indent . "/* The same palette, for Bootstrap and the plain-CSS theme. */\n" . $out;
    }

    /**
     * A colour as the `r, g, b` triplet Bootstrap's opacity utilities expect.
     *
     * Three input forms are accepted, which is what a palette file actually contains:
     * `oklch()` (what daisyUI's generator emits), a hex literal (what a designer
     * pastes), and `rgb()`. Anything else — a named colour, `color-mix()`, a `var()`
     * reference — returns null and the alias is simply not emitted, because a wrong
     * triplet is worse than an absent one: Bootstrap falls back to its own value, which
     * at least agrees with itself.
     *
     * @return string|null `r, g, b`, or null when the value cannot be resolved here
     */
    private static function rgbTriplet(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $hex)) {
            $digits = $hex[1];
            if (strlen($digits) === 3) {
                $digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
            }

            return implode(', ', [
                (string) hexdec(substr($digits, 0, 2)),
                (string) hexdec(substr($digits, 2, 2)),
                (string) hexdec(substr($digits, 4, 2)),
            ]);
        }

        if (preg_match('/^rgba?\\(\\s*(\\d+)[,\\s]+(\\d+)[,\\s]+(\\d+)/i', $value, $rgb)) {
            return $rgb[1] . ', ' . $rgb[2] . ', ' . $rgb[3];
        }

        if (preg_match(
            '/^oklch\\(\\s*([\\d.]+)(%?)\\s+([\\d.]+)\\s+([\\d.]+)/i',
            $value,
            $oklch
        )) {
            return self::oklchToRgb(
                (float) $oklch[1] / ($oklch[2] === '%' ? 100 : 1),
                (float) $oklch[3],
                (float) $oklch[4]
            );
        }

        return null;
    }

    /**
     * Oklch to sRGB, by the conversion CSS Color 4 defines.
     *
     * Oklch to Oklab (polar to cartesian), Oklab to cone responses and cubed, the
     * matrix to linear sRGB, then the sRGB transfer function. The coefficients are
     * Björn Ottosson's, as published in the specification — not tuned here, and not to
     * be adjusted: a browser rendering the same `oklch()` uses these numbers, and the
     * triplet has to agree with the colour beside it.
     *
     * Out-of-gamut components are clamped, which is what a browser does for a colour it
     * cannot show rather than refusing to paint.
     *
     * @param float $lightness 0..1
     * @param float $chroma    0..~0.4
     * @param float $hue       degrees
     */
    private static function oklchToRgb(float $lightness, float $chroma, float $hue): string
    {
        $a = $chroma * cos(deg2rad($hue));
        $b = $chroma * sin(deg2rad($hue));

        $long   = ($lightness + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
        $medium = ($lightness - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
        $short  = ($lightness - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

        $linear = [
             4.0767416621 * $long - 3.3077115913 * $medium + 0.2309699292 * $short,
            -1.2684380046 * $long + 2.6097574011 * $medium - 0.3413193965 * $short,
            -0.0041960863 * $long - 0.7034186147 * $medium + 1.7076147010 * $short,
        ];

        $channels = [];
        foreach ($linear as $component) {
            $component = max(0.0, min(1.0, $component));
            $channels[] = (string) (int) round(255 * (
                $component <= 0.0031308
                    ? 12.92 * $component
                    : 1.055 * $component ** (1 / 2.4) - 0.055
            ));
        }

        return implode(', ', $channels);
    }

    /** daisyUI accepts `true`, and a bare property name means the same thing. */
    private static function isTrue(string $value): bool
    {
        return $value === '' || strtolower($value) === 'true';
    }
}
