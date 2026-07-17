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

        // 1. Re-install theme chrome + assets (delegates to Init).
        $init = new Init();
        $init->targetBaseDir = $base;
        try {
            $init->installUiFramework($ui, $appName, $features);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to install UI assets: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        // 2. Patch app/app.php (scaffold_theme + CSP style-src).
        $this->patchAppConfig($appConfigPath, $ui);

        $output->writeln("<info>✓ Switched UI framework to '{$ui}'.</info>");
        $output->writeln('  - app/app.php: scaffold_theme + CSP updated');
        $output->writeln('  - app/themes/default + www/assets refreshed');
        if ($ui !== 'plain-css') {
            $output->writeln("  - {$ui} vendor assets installed under www/assets/vendor/");
        }
        $output->writeln('<comment>Reload the app to see the new framework.</comment>');

        return Command::SUCCESS;
    }

    /**
     * Update `scaffold_theme` and the CSP `style-src` in app/app.php in place.
     *
     * Tailwind's browser build injects a <style> element at runtime, which a
     * nonce-based style-src blocks — so it needs 'unsafe-inline'. The other
     * frameworks keep the strict (empty) style-src.
     */
    private function patchAppConfig(string $path, string $ui): void
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
        if (preg_match("/'style-src'\s*=>\s*\[[^\]]*\]/", $content)) {
            $content = preg_replace(
                "/'style-src'\s*=>\s*\[[^\]]*\]/",
                "'style-src'  => {$styleSrc}",
                $content,
                1
            );
        }

        file_put_contents($path, $content);
    }
}
