<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * project:switch-ui — switch an existing project's scaffolding UI framework.
 *
 * Flips a project between the bundled UI frameworks (plain-css / bootstrap /
 * tailwind) in place, so the built-in scaffolded UI can be previewed under each
 * without re-scaffolding. It:
 *
 *   1. Updates `scaffold_theme` in app/app.php (and the CSP style-src, which
 *      Tailwind's runtime build needs relaxed to 'unsafe-inline').
 *   2. Re-installs the theme chrome (app/themes/default) + www/assets/css/
 *      style.css + pf-*.js and pulls the framework's CSS/JS vendor assets on
 *      the spot (delegates to Init::installUiFramework()).
 *   3. In a project with a built SPA, rewrites its `ui.css` — the stylesheet of
 *      the UI system the SPA draws with — for the next `npm run build`.
 *
 * The scaffolded account/auth views themselves are theme-agnostic: they are
 * resolved per-framework from the bundled scaffolding, so no view files need
 * copying here.
 *
 * Example:
 *   ./pramnos project:switch-ui bootstrap
 *   ./pramnos project:switch-ui tailwind
 *   ./pramnos project:switch-ui plain-css
 */
class SwitchUi extends Command
{
    /** Target project root. Overridable for testing. */
    public string $targetBaseDir = '';

    /** Valid UI frameworks (must match scaffolding/themes/* directories). */
    private const FRAMEWORKS = ['plain-css', 'bootstrap', 'tailwind'];

    protected function configure(): void
    {
        $this
            ->setName('project:switch-ui')
            ->setDescription('Switch the scaffolding UI framework (plain-css, bootstrap, tailwind)')
            ->addArgument(
                'framework',
                InputArgument::REQUIRED,
                'UI framework to switch to: ' . implode(', ', self::FRAMEWORKS)
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ui = (string) $input->getArgument('framework');
        if (!in_array($ui, self::FRAMEWORKS, true)) {
            $output->writeln("<error>Unknown UI framework '{$ui}'. Choose one of: "
                . implode(', ', self::FRAMEWORKS) . '</error>');
            return Command::INVALID;
        }

        $base = $this->targetBaseDir !== ''
            ? $this->targetBaseDir
            : (defined('ROOT') ? ROOT : getcwd());
        $appConfigPath = $base . '/app/app.php';

        if (!is_file($appConfigPath)) {
            $output->writeln("<error>Could not find app/app.php at {$appConfigPath}. "
                . 'Run this from a project root.</error>');
            return Command::FAILURE;
        }

        $config   = require $appConfigPath;
        $appName  = is_array($config) ? (string) ($config['name'] ?? 'App') : 'App';
        $features = is_array($config) && isset($config['features']) && is_array($config['features'])
            ? $config['features']
            : [];
        $current  = is_array($config) ? (string) ($config['scaffold_theme'] ?? '') : '';

        if ($current === $ui) {
            $output->writeln("<comment>UI framework is already '{$ui}'. Re-installing assets…</comment>");
        }

        $webRoot = basename(\Pramnos\Application\FrameworkScripts::webRoot($base));
        // A Svelte SPA builds its own stylesheet, ui.css included; the other stacks draw with the
        // web root's, which the theme install has just rewritten.
        $spaDir  = is_array($config) && (string) ($config['spa_stack'] ?? '') === 'svelte'
            ? rtrim(Init::spaSourceDirFor($config), '/')
            : '';
        // The files the switch rewrites, as they are before it — the application may have changed
        // any of them, and the switch writes over them whatever they hold.
        $before = $this->read($base, array_merge(
            $this->overwritten($webRoot),
            $spaDir !== '' ? [$spaDir . '/ui.css'] : []
        ));

        // 1. Re-install theme chrome + assets (delegates to Init), into the web root it serves.
        $init = $this->makeInit();
        $init->targetBaseDir = $base;
        $init->useWebRoot($webRoot);
        try {
            $init->installUiFramework($ui, $appName, $features);
            if ($spaDir !== '') {
                $init->installSpaUiCss($ui, $spaDir);
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to install UI assets: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // 2. Patch app/app.php (scaffold_theme + CSP style-src).
        $cspPatched = $this->patchAppConfig($appConfigPath, $ui);

        $backedUp = $this->backUp($base, $before);

        $output->writeln("<info>✓ Switched UI framework to '{$ui}'.</info>");
        $output->writeln('  - app/app.php: scaffold_theme' . ($cspPatched ? ' + CSP' : '') . ' updated');
        $output->writeln("  - app/themes/default + {$webRoot}/assets refreshed");
        if ($ui !== 'plain-css') {
            $output->writeln("  - {$ui} vendor assets installed under {$webRoot}/assets/vendor/");
        }

        if ($backedUp !== []) {
            $output->writeln('<comment>These files held something else and were replaced; the previous copies are in '
                . $backedUp['dir'] . ' — re-apply your changes from them:</comment>');
            foreach ($backedUp['files'] as $file) {
                $output->writeln('    ' . $file);
            }
        }

        if (!$cspPatched && $ui === 'tailwind') {
            $output->writeln("<comment>app/app.php has no CSP 'style-src' to update. Tailwind's browser build injects a "
                . "<style> element, which a strict policy blocks: add 'style-src' => [\"'unsafe-inline'\"] to the CSP block.</comment>");
        }

        $published = $current !== '' && $current !== $ui ? $this->published($base, $current) : [];
        if ($published !== []) {
            $output->writeln('<comment>' . count($published) . " view(s) copied with project:publish-views keep their '{$current}' markup:</comment>");
            foreach (array_slice($published, 0, 10) as $view) {
                $output->writeln('    ' . $view);
            }
            if (count($published) > 10) {
                $output->writeln('    … and ' . (count($published) - 10) . ' more');
            }
            $output->writeln("<comment>  Take the '{$ui}' copy of a group with project:publish-views --group=<name> --force, then re-apply your changes.</comment>");
        }

        if ($spaDir !== '') {
            $this->spaNotes($output, $base, $spaDir, $ui);
        }

        $output->writeln('<comment>Reload the app to see the new framework.</comment>');

        return Command::SUCCESS;
    }

    /**
     * The files `installUiFramework()` writes that an application may have changed, relative to the
     * project: the default theme's layout files and the scaffolded stylesheet.
     *
     * @return list<string>
     */
    private function overwritten(string $webRoot): array
    {
        $files = [];
        foreach (['theme.html.php', 'head.php', 'header.php', 'footer.php', 'login.php'] as $layout) {
            $files[] = 'app/themes/default/' . $layout;
        }
        $files[] = $webRoot . '/assets/css/style.css';

        return $files;
    }

    /**
     * The files that exist, with their contents.
     *
     * @param list<string> $files
     * @return array<string, string>
     */
    private function read(string $base, array $files): array
    {
        $contents = [];
        foreach ($files as $file) {
            if (is_file($base . '/' . $file)) {
                $contents[$file] = (string) file_get_contents($base . '/' . $file);
            }
        }

        return $contents;
    }

    /**
     * Keep the previous copy of every file the switch changed, under `var/switch-ui/<time>/`.
     *
     * Only a file whose contents changed: one the switch rewrote with the same bytes needs no copy.
     *
     * @param array<string, string> $before
     * @return array{dir?: string, files?: list<string>}
     */
    private function backUp(string $base, array $before): array
    {
        $changed = [];
        foreach ($before as $file => $contents) {
            if (!is_file($base . '/' . $file) || (string) file_get_contents($base . '/' . $file) !== $contents) {
                $changed[$file] = $contents;
            }
        }
        if ($changed === []) {
            return [];
        }

        $dir = 'var/switch-ui/' . date('Ymd-His');
        foreach ($changed as $file => $contents) {
            $target = $base . '/' . $dir . '/' . $file;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0775, true);
            }
            file_put_contents($target, $contents);
        }

        return ['dir' => $dir, 'files' => array_keys($changed)];
    }

    /**
     * The application's views that are copies of the `$ui` system's bundled ones — what
     * `project:publish-views` wrote — and so stay in that system's markup.
     *
     * @return list<string>
     */
    private function published(string $base, string $ui): array
    {
        $bundled = $this->scaffoldingDir() . '/themes/' . $ui . '/views';
        if (!is_dir($bundled)) {
            return [];
        }

        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($bundled, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $relative = substr((string) $file->getPathname(), strlen($bundled) + 1);
            foreach (['src/Views/', 'src/Admin/Views/'] as $views) {
                if (is_file($base . '/' . $views . $relative)) {
                    $found[] = $views . $relative;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * What a SPA with a build needs before it draws with the new system: a stylesheet that
     * imports ui.css, Bootstrap in its dependencies, and a build.
     */
    private function spaNotes(OutputInterface $output, string $base, string $spaDir, string $ui): void
    {
        $output->writeln("  - {$spaDir}/ui.css rewritten for '{$ui}'");

        $css = $base . '/' . $spaDir . '/app.css';
        if (is_file($css) && !str_contains((string) file_get_contents($css), './ui.css')) {
            $output->writeln("<comment>{$spaDir}/app.css does not import ui.css, so the SPA still draws with daisyUI: "
                . 'replace its `@plugin "daisyui";` line with `@import "./ui.css";`.</comment>');
        }

        $package = json_decode((string) @file_get_contents($base . '/package.json'), true);
        if ($ui === 'bootstrap' && is_array($package) && !isset($package['devDependencies']['bootstrap'])
            && !isset($package['dependencies']['bootstrap'])) {
            $output->writeln('<comment>ui.css imports Bootstrap from npm: run `npm install -D bootstrap@^5.3.0`.</comment>');
        }

        $output->writeln('<comment>Rebuild the SPA (`npm run build`, or a running `npm run dev` picks it up) — its bundle carries the stylesheet.</comment>');
    }

    /** The framework's scaffolding directory. */
    protected function scaffoldingDir(): string
    {
        return dirname(__DIR__, 4) . '/scaffolding';
    }

    /**
     * Build the Init command used to (re)install the UI framework's assets.
     *
     * A protected seam so tests can inject a stub that skips the real asset
     * installation (which would otherwise pull vendor CSS/JS).
     */
    protected function makeInit(): Init
    {
        return new Init();
    }

    /**
     * Update `scaffold_theme` and the CSP `style-src` in app/app.php in place; whether there was a
     * `style-src` to update.
     *
     * Tailwind's browser build injects a <style> element at runtime, which a
     * nonce-based style-src blocks — so it needs 'unsafe-inline'. The other
     * frameworks keep the strict (empty) style-src.
     */
    private function patchAppConfig(string $path, string $ui): bool
    {
        $content  = (string) file_get_contents($path);
        $styleSrc = $ui === 'tailwind' ? "[\"'unsafe-inline'\"]" : '[]';

        // scaffold_theme — replace value, or insert after the 'theme' line.
        if (preg_match("/'scaffold_theme'\s*=>\s*'[^']*'/", $content)) {
            $content = preg_replace(
                "/'scaffold_theme'\s*=>\s*'[^']*'/",
                "'scaffold_theme' => '{$ui}'",
                $content,
                1
            );
        } else {
            $content = preg_replace(
                "/('theme'\s*=>\s*'[^']*',\n)/",
                "$1    'scaffold_theme' => '{$ui}',\n",
                $content,
                1
            );
        }

        // CSP style-src — toggle for Tailwind.
        $csp = preg_match("/'style-src'\s*=>\s*\[[^\]]*\]/", $content) === 1;
        if ($csp) {
            $content = preg_replace(
                "/'style-src'\s*=>\s*\[[^\]]*\]/",
                "'style-src'  => {$styleSrc}",
                $content,
                1
            );
        }

        file_put_contents($path, $content);

        return $csp;
    }
}
