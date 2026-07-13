<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Application\FeatureRegistry;
use Pramnos\Application\LibraryManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * project:reconfigure — reconfigure an existing project without re-running init.
 *
 * One umbrella command for post-scaffold changes:
 *
 *   • Libraries — install/register front-end vendor libraries (mandatory ones
 *     are always ensured). Delegates to the same engine as `project:install`.
 *   • Features  — enable framework features that were not selected at init:
 *     records them in app/app.php, installs the libraries each feature declares
 *     (FeatureRegistry::getLibraries), and prints the follow-up steps (run
 *     migrations / publish views).
 *   • Settings  — reports the current project configuration so the developer can
 *     see what is enabled (deep settings editing stays manual by design).
 *
 * Interactive by default on a TTY; fully scriptable via flags for CI.
 *
 * Examples:
 *   ./pramnos project:reconfigure                              # interactive wizard
 *   ./pramnos project:reconfigure --status                     # show current config
 *   ./pramnos project:reconfigure --enable-feature=queue,auth  # enable features
 *   ./pramnos project:reconfigure --add-library=leaflet        # add a library
 *   ./pramnos project:reconfigure --enable-feature=queue --no-register
 */
class ProjectSync extends Command
{
    /** Target project root. Overridable for testing. */
    public string $targetBaseDir = '';

    /** Injectable library manager (overridable for testing). */
    public ?LibraryManager $manager = null;

    protected function configure(): void
    {
        $this
            ->setName('project:reconfigure')
            ->setDescription('Reconfigure an existing project — add libraries, enable features')
            ->addOption('enable-feature', null, InputOption::VALUE_OPTIONAL, 'Comma-separated feature keys to enable (e.g. queue,auth)')
            ->addOption('add-library', null, InputOption::VALUE_OPTIONAL, 'Comma-separated library keys to install (e.g. leaflet,select2)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-download library assets even if present')
            ->addOption('no-register', null, InputOption::VALUE_NONE, 'Install library assets but do not edit src/Application.php')
            ->addOption('status', null, InputOption::VALUE_NONE, 'Show current features/libraries and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseDir = $this->targetBaseDir ?: (defined('ROOT') ? ROOT : (string) getcwd());
        $wwwDir  = $baseDir . DIRECTORY_SEPARATOR . 'www';
        $appFile = $baseDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Application.php';
        $cfgFile = $baseDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'app.php';

        $manager       = $this->manager ?? new LibraryManager();
        $enabled       = $this->currentFeatures($cfgFile);
        $registered    = $manager->detectRegisteredHandles($appFile);

        if ($input->getOption('status')) {
            $this->printStatus($output, $manager, $wwwDir, $enabled, $registered);
            return Command::SUCCESS;
        }

        // Resolve requested actions from flags, or fall back to an interactive wizard.
        $features  = $this->csv($input->getOption('enable-feature'));
        $libraries = $this->csv($input->getOption('add-library'));

        if ($features === [] && $libraries === [] && $input->isInteractive()) {
            [$features, $libraries] = $this->askInteractive($input, $output, $enabled, $manager, $wwwDir);
        }

        if ($features === [] && $libraries === []) {
            $output->writeln('Nothing to do. Use --enable-feature=, --add-library=, or --status.');
            return Command::SUCCESS;
        }

        $force        = (bool) $input->getOption('force');
        $skipRegister = (bool) $input->getOption('no-register');
        $libsToInstall = $libraries;

        // ── Features ──────────────────────────────────────────────────────────
        $newlyEnabled = [];
        if ($features !== []) {
            $known = FeatureRegistry::getKnown();
            foreach ($features as $feature) {
                if (!in_array($feature, $known, true)) {
                    $output->writeln("  <error>unknown feature</error>  $feature (known: " . implode(', ', $known) . ')');
                    continue;
                }
                if (in_array($feature, $enabled, true)) {
                    $output->writeln("  <comment>enabled</comment>  $feature (already enabled)");
                    continue;
                }
                $newlyEnabled[] = $feature;
                // A feature carries its own front-end dependencies.
                $libsToInstall = array_merge($libsToInstall, FeatureRegistry::getLibraries($feature));
            }

            if ($newlyEnabled !== []) {
                if ($this->addFeaturesToConfig($cfgFile, $newlyEnabled)) {
                    foreach ($newlyEnabled as $f) {
                        $output->writeln("  <info>feature</info>  $f → app/app.php");
                    }
                } else {
                    $output->writeln("  <error>Could not update $cfgFile — add these to the 'features' array manually: " . implode(', ', $newlyEnabled) . '</error>');
                }
            }
        }

        // ── Libraries — delegate to project:install (single source of logic) ──
        // Explicit --add-library plus every library the enabled features declare.
        // project:install always ensures the mandatory set and handles download +
        // registration, so this command never re-implements that flow.
        $libStatus = Command::SUCCESS;
        $libsToInstall = array_values(array_unique($libsToInstall));
        if ($libsToInstall !== [] || $newlyEnabled !== []) {
            $args = ['libraries' => $libsToInstall];
            if ($force)        { $args['--force'] = true; }
            if ($skipRegister) { $args['--no-register'] = true; }
            $libStatus = $this->delegate('project:install', $args, $baseDir, $output);
        }

        // ── Follow-up guidance for newly-enabled features ─────────────────────
        if ($newlyEnabled !== []) {
            $needMigrations = array_filter($newlyEnabled, fn ($f) => FeatureRegistry::getMigrationPaths($f) !== []);
            $output->writeln('');
            $output->writeln('<comment>Next steps:</comment>');
            if ($needMigrations !== []) {
                $output->writeln('  • Run pending migrations:  <info>pramnos migrate</info>  (features: ' . implode(', ', $needMigrations) . ')');
            }
            $output->writeln('  • Publish any feature views: <info>pramnos project:publish-views --list</info> then --group=<name>');
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> %d feature(s) enabled.',
            count($newlyEnabled)
        ));

        return $libStatus === Command::SUCCESS ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Run another console command in-process (single source of logic). Propagates
     * the resolved project root to sub-commands that expose a $targetBaseDir, and
     * degrades gracefully (prints a hint) when the command is not registered.
     *
     * @param array<string, mixed> $args ArrayInput arguments/options for the sub-command.
     */
    private function delegate(string $name, array $args, string $baseDir, OutputInterface $output): int
    {
        $app = $this->getApplication();
        try {
            $cmd = $app?->find($name);
        } catch (\Throwable) {
            $cmd = null;
        }
        if ($cmd === null) {
            $output->writeln("<comment>Run `pramnos $name` to finish this step.</comment>");
            return Command::SUCCESS;
        }
        if (property_exists($cmd, 'targetBaseDir')) {
            $cmd->targetBaseDir = $baseDir;
        }
        return $cmd->run(new ArrayInput(['command' => $name] + $args), $output);
    }

    // =========================================================================
    // Interactive wizard
    // =========================================================================

    /**
     * Prompt for features to enable and libraries to add.
     *
     * @param list<string> $enabled
     * @return array{0: list<string>, 1: list<string>} [features, libraries]
     */
    private function askInteractive(
        InputInterface $input,
        OutputInterface $output,
        array $enabled,
        LibraryManager $manager,
        string $wwwDir
    ): array {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $availableFeatures = array_values(array_diff(FeatureRegistry::getKnown(), $enabled, ['core']));
        $features = [];
        if ($availableFeatures !== []) {
            $q = new ChoiceQuestion(
                'Enable which features? (comma-separated, empty to skip)',
                array_merge(['(none)'], $availableFeatures),
                '(none)'
            );
            $q->setMultiselect(true);
            $picked = $helper->ask($input, $output, $q);
            $features = array_values(array_diff((array) $picked, ['(none)']));
        }

        $missingLibs = array_values(array_filter(
            $manager->availableKeys(),
            fn ($k) => !$manager->isInstalled($k, $wwwDir)
        ));
        $libraries = [];
        if ($missingLibs !== []) {
            $q = new ChoiceQuestion(
                'Add which libraries? (comma-separated, empty to skip)',
                array_merge(['(none)'], $missingLibs),
                '(none)'
            );
            $q->setMultiselect(true);
            $picked = $helper->ask($input, $output, $q);
            $libraries = array_values(array_diff((array) $picked, ['(none)']));
        }

        return [$features, $libraries];
    }

    // =========================================================================
    // Status
    // =========================================================================

    /**
     * @param list<string> $enabled
     * @param list<string> $registered
     */
    private function printStatus(
        OutputInterface $output,
        LibraryManager $manager,
        string $wwwDir,
        array $enabled,
        array $registered
    ): void {
        $output->writeln('<info>Features:</info>');
        foreach (FeatureRegistry::getKnown() as $key) {
            $state = ($key === 'core' || in_array($key, $enabled, true)) ? 'enabled' : 'available';
            $output->writeln(sprintf('  %-14s %s', $key, $state));
        }

        $mandatory = $manager->mandatoryKeys();
        $output->writeln('');
        $output->writeln('<info>Libraries:</info>');
        foreach ($manager->availableKeys() as $key) {
            $inst = $manager->isInstalled($key, $wwwDir) ? 'installed' : 'missing';
            $reg  = in_array($key, $registered, true) ? 'registered' : 'not registered';
            $flag = in_array($key, $mandatory, true) ? ' <comment>(mandatory)</comment>' : '';
            $output->writeln(sprintf('  %-18s %-10s %s%s', $key, $inst, $reg, $flag));
        }
    }

    // =========================================================================
    // app/app.php helpers
    // =========================================================================

    /**
     * Read the enabled feature keys from app/app.php ('features' array).
     *
     * @return list<string>
     */
    private function currentFeatures(string $cfgFile): array
    {
        if (!file_exists($cfgFile)) {
            return [];
        }
        try {
            $config = require $cfgFile;
        } catch (\Throwable) {
            return [];
        }
        $features = is_array($config) ? ($config['features'] ?? []) : [];
        return is_array($features) ? array_values(array_map('strval', $features)) : [];
    }

    /**
     * Add feature keys to the 'features' array in app/app.php, idempotently.
     * Works on the single-line array form emitted by `pramnos init`
     * (`'features' => ['auth', 'queue'],`). Returns false when the array cannot
     * be located so the caller can advise a manual edit.
     *
     * @param list<string> $newFeatures
     */
    private function addFeaturesToConfig(string $cfgFile, array $newFeatures): bool
    {
        if (!file_exists($cfgFile)) {
            return false;
        }
        $src = (string) file_get_contents($cfgFile);

        if (!preg_match("/('features'\s*=>\s*)\[([^\]]*)\]/s", $src, $m, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        // Parse existing quoted entries and merge.
        preg_match_all("/'([^']*)'/", $m[2][0], $existingM);
        $existing = $existingM[1] ?? [];
        $merged   = array_values(array_unique(array_merge($existing, $newFeatures)));
        $rendered = $merged === []
            ? '[]'
            : "['" . implode("', '", $merged) . "']";

        $replacement = $m[1][0] . $rendered;
        $newSrc = substr_replace($src, $replacement, $m[0][1], strlen($m[0][0]));

        return file_put_contents($cfgFile, $newSrc) !== false;
    }

    /**
     * Split a comma-separated option value into a trimmed list.
     *
     * @return list<string>
     */
    private function csv(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }
}
