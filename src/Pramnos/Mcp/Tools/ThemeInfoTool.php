<?php

declare(strict_types=1);

namespace Pramnos\Mcp\Tools;

use Pramnos\Mcp\McpToolInterface;
use Pramnos\Theme\ThemeTokens;

/**
 * MCP tool: the palette, the themes, and whether the compiled stylesheet is stale.
 *
 * Two questions, and the second is the one that bites. «What colour is `--color-primary`
 * here» is answerable by reading a file once you know which file. «Is the CSS on disk built
 * from the CSS in the repository» is not answerable by reading anything, and getting it wrong
 * is silent: daisyUI is a Tailwind *plugin*, so a project that edits `app.css` and does not
 * rebuild serves a stylesheet in which its component classes simply do nothing. The page
 * renders. It renders unstyled.
 *
 * Which is why the staleness check is here rather than the token dump being the point. A
 * compiled stylesheet is committed in these projects — deliberately, so a checkout serves the
 * site without npm — and a committed build artifact is one somebody forgets to regenerate.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class ThemeInfoTool implements McpToolInterface
{
    /** Files newer than the build, at most, before the list is a wall. */
    private const MAX_STALE = 12;

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(
            $root ?? (defined('ROOT') ? (string) ROOT : (string) getcwd()),
            DIRECTORY_SEPARATOR
        );
    }

    public function name(): string
    {
        return 'theme-info';
    }

    public function description(): string
    {
        return 'The application\'s design tokens and front-end build: where the palette lives, '
            . 'which themes it defines and their colours, which theme directories exist and '
            . 'which are active, the build command, and — the useful part — whether the '
            . 'compiled stylesheet is older than its sources. daisyUI is a Tailwind plugin, so '
            . 'an unbuilt stylesheet renders the page with its component classes doing nothing.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'theme' => [
                    'type' => 'string',
                    'description' => 'One theme\'s full token list. Omit for the summary.',
                ],
                'token' => [
                    'type' => 'string',
                    'description' => 'One token\'s value across every theme, e.g. '
                        . '`--color-primary` or `color-base-100`.',
                ],
            ],
        ];
    }

    public function execute(array $input): mixed
    {
        $palette = ThemeTokens::locateIn($this->root);
        ThemeTokens::flush();

        try {
            $themes = ThemeTokens::load($palette);
        } catch (\Throwable $exception) {
            return ['error' => 'Could not read the palette: ' . $exception->getMessage()];
        }

        $wantedTheme = trim((string) ($input['theme'] ?? ''));
        $wantedToken = trim((string) ($input['token'] ?? ''));

        if ($wantedToken !== '') {
            return $this->oneToken($themes, $wantedToken, $palette);
        }

        if ($wantedTheme !== '') {
            if (!isset($themes[$wantedTheme])) {
                return [
                    'error'  => 'No theme named ' . $wantedTheme . ' in ' . $this->relative($palette),
                    'themes' => array_keys($themes),
                ];
            }

            return [
                'palette' => $this->relative($palette),
                'theme'   => $themes[$wantedTheme],
            ];
        }

        $default = ThemeTokens::defaultTheme($themes);

        return [
            'palette' => [
                'file'    => $this->relative($palette),
                'exists'  => is_readable($palette),
                'default' => $default['name'] ?? null,
                'themes'  => $this->summarise($themes),
                'note'    => is_readable($palette)
                    ? 'daisyUI `@plugin "daisyui/theme"` blocks. Ask again with `theme` for one '
                        . 'theme\'s full token list, or `token` for one value across all of them.'
                    : 'No palette file. `theme:build` and the token helpers read '
                        . ThemeTokens::DEFAULT_PATH . '; create it there.',
            ],
            'themeDirectories' => $this->themeDirectories(),
            'build'            => $this->build(),
        ];
    }

    /**
     * Each theme in a line, rather than every token of every theme.
     *
     * A two-theme palette is sixty tokens, and the answer to "what themes are there" should
     * not be sixty tokens. The colours here are the ones a reader is actually placing:
     * surface, text and the accent.
     *
     * @param  array<string, array<string, mixed>> $themes
     * @return list<array<string, mixed>>
     */
    private function summarise(array $themes): array
    {
        $summary = [];

        foreach ($themes as $name => $theme) {
            $tokens = is_array($theme['tokens'] ?? null) ? $theme['tokens'] : [];

            $summary[] = array_filter([
                'name'         => $name,
                'default'      => !empty($theme['default']) ? true : null,
                'prefersdark'  => !empty($theme['prefersdark']) ? true : null,
                'color_scheme' => $theme['color_scheme'] ?? null,
                'tokens'       => count($tokens),
                'key_colours'  => array_filter([
                    '--color-base-100' => $tokens['--color-base-100'] ?? null,
                    '--color-base-content' => $tokens['--color-base-content'] ?? null,
                    '--color-primary' => $tokens['--color-primary'] ?? null,
                ], static fn ($value): bool => $value !== null),
            ], static fn ($value): bool => $value !== null && $value !== []);
        }

        return $summary;
    }

    /**
     * One token across every theme.
     *
     * The question behind it is almost always "is this readable in the other theme", and a
     * value that exists in one theme and not the other is the answer.
     *
     * @param array<string, array<string, mixed>> $themes
     * @return array<string, mixed>
     */
    private function oneToken(array $themes, string $token, string $palette): array
    {
        $token  = str_starts_with($token, '--') ? $token : '--' . $token;
        $values = [];

        foreach ($themes as $name => $theme) {
            $tokens = is_array($theme['tokens'] ?? null) ? $theme['tokens'] : [];
            // Null rather than omitted: a token missing from one theme is the finding, and an
            // absent key reads as "not asked about".
            $values[$name] = $tokens[$token] ?? null;
        }

        return array_filter([
            'palette' => $this->relative($palette),
            'token'   => $token,
            'values'  => $values,
            'note'    => in_array(null, $values, true)
                ? 'A null means the token is not declared in that theme, so it falls back to '
                    . 'daisyUI\'s own value — which is usually not the one beside it here.'
                : null,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * The theme directories, and which of them the application is using.
     *
     * `app.php` names one theme for the site and one for the administration area; a directory
     * that is neither is either dead or reached some other way, and both are worth seeing.
     *
     * @return array<string, mixed>
     */
    private function themeDirectories(): array
    {
        $info   = \Pramnos\Application\Application::currentInstance()?->applicationInfo ?? [];
        $active = array_filter([
            'site'  => $info['theme'] ?? null,
            'admin' => $info['admin']['theme'] ?? null,
        ]);

        $directories = [];

        foreach ((array) glob($this->root . '/app/themes/*', GLOB_ONLYDIR) as $path) {
            if (!is_string($path)) {
                continue;
            }

            $name = basename($path);

            $directories[] = array_filter([
                'name'   => $name,
                'used'   => array_search($name, $active, true) ?: null,
                'layout' => is_file($path . '/theme.html.php') ? 'theme.html.php' : null,
                // The chromeless layout the framework switches to for auth screens. Its
                // absence is why a sign-in page can come out wearing the full site chrome.
                'login'  => is_file($path . '/login.php') ? 'login.php' : null,
                'views'  => is_dir($path . '/views') ? true : null,
            ], static fn ($value): bool => $value !== null);
        }

        return ['active' => $active, 'directories' => $directories];
    }

    /**
     * How the stylesheet is built here, and whether it has been.
     *
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $npm = $this->npmPipeline();

        if ($npm === null) {
            return [
                'pipeline' => 'framework',
                'command'  => 'php <cli> theme:build',
                'note'     => 'No Tailwind script in package.json, so the palette is turned '
                    . 'into plain custom properties (and JSON for a SPA) by `theme:build`. '
                    . 'That output has no build-freshness problem the way a Tailwind bundle '
                    . 'does, because it is a direct translation of the palette.',
            ];
        }

        return $npm;
    }

    /**
     * The npm/Tailwind pipeline, read out of `package.json`.
     *
     * The input and output are taken from the script rather than assumed: `-i assets/src/app.css
     * -o www/assets/css/style.css` is the convention, and a project that moved either would
     * otherwise get a confident answer about the wrong files.
     *
     * @return array<string, mixed>|null
     */
    private function npmPipeline(): ?array
    {
        $file = $this->root . '/package.json';

        if (!is_file($file)) {
            return null;
        }

        $package = json_decode((string) file_get_contents($file), true);
        $scripts = is_array($package['scripts'] ?? null) ? $package['scripts'] : [];

        foreach ($scripts as $name => $command) {
            if (!is_string($command) || !str_contains($command, 'tailwindcss')) {
                continue;
            }

            if (str_contains($command, '--watch')) {
                continue;   // the dev watcher, not the build
            }

            $input  = $this->flagValue($command, '-i');
            $output = $this->flagValue($command, '-o');

            return array_filter([
                'pipeline' => 'tailwind',
                'command'  => 'npm run ' . $name,
                'watch'    => $this->watchScript($scripts),
                'input'    => $input,
                'output'   => $output,
                'freshness' => $this->freshness($input, $output),
                'note'     => 'daisyUI is a Tailwind **plugin**, so it cannot be loaded from a '
                    . 'CDN and the build is not optional: without it the component classes '
                    . 'resolve to nothing and the page renders unstyled rather than failing.',
            ], static fn ($value): bool => $value !== null);
        }

        return null;
    }

    /**
     * Is the compiled stylesheet newer than everything it is built from?
     *
     * Three kinds of source, because Tailwind depends on all three: the entry stylesheet, the
     * palette it imports, and **every file it scans for class names**. The third is the one
     * that surprises people — adding `btn-primary` to a view means the class has to be
     * generated, so an untouched `app.css` does not mean an up-to-date bundle.
     *
     * @return array<string, mixed>
     */
    private function freshness(?string $input, ?string $output): array
    {
        if ($input === null || $output === null) {
            return ['known' => false, 'why' => 'The build script does not name -i and -o.'];
        }

        $outputPath = $this->root . '/' . ltrim($output, '/');

        if (!is_file($outputPath)) {
            return [
                'built' => false,
                'why'   => $output . ' does not exist — the stylesheet has never been built '
                    . 'here, and every daisyUI class on every page is inert.',
            ];
        }

        $builtAt = (int) filemtime($outputPath);
        $newer   = [];

        foreach ($this->sources($input) as $source) {
            $path = $this->root . '/' . ltrim($source, '/');

            if (is_file($path)) {
                if ((int) filemtime($path) > $builtAt) {
                    $newer[] = $source;
                }

                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            foreach ($this->newerFilesIn($path, $builtAt) as $found) {
                $newer[] = $found;

                if (count($newer) > self::MAX_STALE) {
                    break 2;
                }
            }
        }

        $truncated = count($newer) > self::MAX_STALE;

        return array_filter([
            'built'    => true,
            'built_at' => date('d/m/Y H:i', $builtAt),
            'stale'    => $newer !== [],
            'newer_than_the_build' => $newer === []
                ? null
                : array_slice($newer, 0, self::MAX_STALE),
            'truncated' => $truncated ?: null,
            'why' => $newer === []
                ? null
                : 'These changed after the last build, so the served stylesheet does not '
                    . 'reflect them. Run the build command above.',
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Everything the entry stylesheet depends on: itself, its imports, its `@source` scans.
     *
     * @return list<string> Project-relative paths, files and directories
     */
    private function sources(string $input): array
    {
        $sources = [$input];
        $path    = $this->root . '/' . ltrim($input, '/');
        $css     = is_readable($path) ? (string) file_get_contents($path) : '';
        $base    = dirname($input);

        foreach (['~@import\s+"([^"]+)"~', '~@source\s+"([^"]+)"~'] as $pattern) {
            $matches = [];
            preg_match_all($pattern, $css, $matches);

            foreach ($matches[1] ?? [] as $reference) {
                // A bare package name — `tailwindcss` — is a node import, not a path.
                if (!str_contains($reference, '/') && !str_contains($reference, '.')) {
                    continue;
                }

                $resolved = $this->normalise($base . '/' . $reference);

                if ($resolved !== null) {
                    $sources[] = $resolved;
                }
            }
        }

        return array_values(array_unique($sources));
    }

    /**
     * `../../app/themes` relative to `assets/src` becomes `app/themes`.
     *
     * Returns null for anything that climbs out of the project, which is not ours to watch.
     */
    private function normalise(string $path): ?string
    {
        $parts  = explode('/', $path);
        $result = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                if ($result === []) {
                    return null;
                }

                array_pop($result);
                continue;
            }

            $result[] = $part;
        }

        return $result === [] ? null : implode('/', $result);
    }

    /**
     * Files under a directory modified after the build, capped.
     *
     * @return list<string>
     */
    private function newerFilesIn(string $directory, int $since): array
    {
        $found = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getMTime() <= $since) {
                    continue;
                }

                $found[] = $this->relative($file->getPathname());

                if (count($found) > self::MAX_STALE) {
                    break;
                }
            }
        } catch (\Throwable) {
            // An unreadable subtree is not worth failing the whole answer over.
        }

        return $found;
    }

    /** The watch script beside the build one, since that is the next thing anybody wants. */
    private function watchScript(array $scripts): ?string
    {
        foreach ($scripts as $name => $command) {
            if (is_string($command)
                && str_contains($command, 'tailwindcss')
                && str_contains($command, '--watch')
            ) {
                return 'npm run ' . $name;
            }
        }

        return null;
    }

    /** The value after `-i` / `-o` in a shell command. */
    private function flagValue(string $command, string $flag): ?string
    {
        $matches = [];

        return preg_match('~' . preg_quote($flag, '~') . '\s+(\S+)~', $command, $matches) === 1
            ? $matches[1]
            : null;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->root, '', $path), '/');
    }
}
