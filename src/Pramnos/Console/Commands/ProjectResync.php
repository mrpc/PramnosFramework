<?php

declare(strict_types=1);

namespace Pramnos\Console\Commands;

use Pramnos\Application\ScaffoldingHelper;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * project:resync — refresh framework-owned scaffolded files in an existing project.
 *
 * Some scaffolded files are "framework-owned": the CSP-safe UI hook scripts
 * (scaffolding/assets/js/pf-*.js) and the API-docs tooling
 * (scaffolding/scripts/apidoc-to-openapi.js + scripts/doc.sh). When the framework
 * updates those, an existing project keeps its stale copies. This command
 * re-copies them from the installed framework's scaffolding into the project.
 *
 * As part of the docs-tooling group it also merges, in lock-step with `init`:
 *   - the API-docs npm scripts + dev-dependencies into package.json
 *     (Init::mergeApiDocsPackageJson), so an old project gains docs:build etc.;
 *   - the framework-documented endpoints into src/Api/openapi-overrides.json
 *     (Init::buildApiOverrides), so the feature-scaffolded API controllers'
 *     inherited endpoints appear in the docs. Both merges preserve whatever the
 *     project already declared.
 *
 * By default it only refreshes files that ALREADY exist in the project — a pure
 * "update what you have" operation that never adds tooling a project didn't opt
 * into. Pass --all to also copy files that are missing.
 *
 *   ./pramnos project:resync                # refresh existing framework files
 *   ./pramnos project:resync --dry-run      # preview changes only
 *   ./pramnos project:resync --all          # also copy files not present yet
 *   ./pramnos project:resync --js           # only the pf-*.js UI hooks
 *   ./pramnos project:resync --scripts      # only the docs tooling scripts
 */
class ProjectResync extends Command
{
    /** Target project root. Overridable for testing. */
    public string $targetBaseDir = '';

    /** Scaffolding source dir. Overridable for testing. */
    public string $scaffoldingDir = '';

    protected function configure(): void
    {
        $this
            ->setName('project:resync')
            ->setDescription('Re-copy framework-owned scaffolded files (pf-*.js UI hooks + docs tooling) into an existing project')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing any files')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also copy framework files that are not present in the project yet')
            ->addOption('js', null, InputOption::VALUE_NONE, 'Only sync the pf-*.js UI hook scripts')
            ->addOption('scripts', null, InputOption::VALUE_NONE, 'Only sync the docs tooling scripts (apidoc-to-openapi.js, doc.sh)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = $this->targetBaseDir;
        if ($base === '') {
            // @codeCoverageIgnoreStart — tests always pre-set targetBaseDir
            $base = defined('ROOT') ? ROOT : getcwd();
            // @codeCoverageIgnoreEnd
        }

        if (!is_file($base . '/app/app.php')) {
            $output->writeln("<error>Could not find app/app.php at {$base}. Run this from a project root.</error>");
            return Command::FAILURE;
        }

        $scaffoldingDir = $this->scaffoldingDir;
        if ($scaffoldingDir === '') {
            // @codeCoverageIgnoreStart — tests always pre-set scaffoldingDir
            $scaffoldingDir = ScaffoldingHelper::resolveScaffoldingDir();
            // @codeCoverageIgnoreEnd
        }

        if ($scaffoldingDir === '' || !is_dir($scaffoldingDir)) {
            $output->writeln("<error>Could not locate the framework scaffolding directory.</error>");
            return Command::FAILURE;
        }

        $dryRun  = (bool) $input->getOption('dry-run');
        $copyAll = (bool) $input->getOption('all');
        $onlyJs  = (bool) $input->getOption('js');
        $onlyScr = (bool) $input->getOption('scripts');
        // Neither scope flag → sync both groups.
        $both      = !$onlyJs && !$onlyScr;
        $doJs      = $onlyJs || $both;
        $doScripts = $onlyScr || $both;

        $files = $this->collectFiles($scaffoldingDir, $doJs, $doScripts);

        // package.json is a merge (not a copy): fold in the API-docs npm scripts +
        // dev-deps that init writes, so an old project's manifest gains docs:build
        // etc. Only when the docs tooling itself is being synced (its scripts would
        // otherwise reference files that don't exist).
        $syncPkg = $doScripts && (
            (glob($scaffoldingDir . '/scripts/*.js') ?: []) !== []
            || is_file($scaffoldingDir . '/templates/doc.sh.stub')
        );

        if ($files === [] && !$syncPkg) {
            $output->writeln('<comment>Nothing to sync (no framework-owned source files found).</comment>');
            return Command::SUCCESS;
        }

        $output->writeln($dryRun
            ? "<comment>Dry run — no files will be written.</comment>"
            : "<info>Resyncing framework-owned files…</info>");

        $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        foreach ($files as $file) {
            $action = $this->applyFile($base, $file['dest'], $file['content'], $file['exec'], $dryRun, $copyAll, $output);
            $tally[$action]++;
        }

        if ($syncPkg) {
            $pkgContent = $this->buildMergedPackageJson($base);
            $action = $this->applyFile($base, 'package.json', $pkgContent, false, $dryRun, $copyAll, $output);
            $tally[$action]++;

            // Refresh the framework-documented endpoints in openapi-overrides.json
            // (the feature-scaffolded API controllers' actions are inherited, so
            // apidoc can't see them). Merged so user customisations survive.
            $ovContent = $this->buildMergedApiOverrides($base);
            if ($ovContent !== null) {
                $action = $this->applyFile($base, 'src/Api/openapi-overrides.json', $ovContent, false, $dryRun, $copyAll, $output);
                $tally[$action]++;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> %d created, %d updated, %d unchanged, %d skipped.',
            $tally['created'],
            $tally['updated'],
            $tally['unchanged'],
            $tally['skipped']
        ));
        if ($tally['skipped'] > 0 && !$copyAll) {
            $output->writeln('<comment>Some framework files are not present in this project. Re-run with --all to add them.</comment>');
        }

        return Command::SUCCESS;
    }

    /**
     * Write one file's desired content into the project, reporting and returning
     * the action taken so the caller can tally it.
     *
     * Honours the two cross-cutting flags: missing files are skipped unless
     * --all, and nothing is written under --dry-run.
     *
     * @return string One of: created, updated, unchanged, skipped.
     */
    private function applyFile(
        string $base,
        string $rel,
        string $content,
        bool $exec,
        bool $dryRun,
        bool $copyAll,
        OutputInterface $output
    ): string {
        $abs    = $base . '/' . $rel;
        $exists = is_file($abs);

        if (!$exists && !$copyAll) {
            $output->writeln("  <comment>skip</comment>      {$rel} (not present; use --all to add)");
            return 'skipped';
        }

        $current = $exists ? (string) file_get_contents($abs) : null;
        if ($current === $content) {
            $output->writeln("  unchanged  {$rel}");
            return 'unchanged';
        }

        $verb = $exists ? 'update' : 'create';
        if ($dryRun) {
            $output->writeln("  <info>would {$verb}</info> {$rel}");
        } else {
            $dir = dirname($abs);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($abs, $content);
            if ($exec) {
                @chmod($abs, 0755);
            }
            $output->writeln("  <info>{$verb}d</info>  {$rel}");
        }

        return $exists ? 'updated' : 'created';
    }

    /**
     * Build the desired package.json content with the API-docs scripts merged in.
     *
     * Reuses Init::mergeApiDocsPackageJson() so `init` and `project:resync` write
     * identical entries. An existing manifest is preserved and only augmented; a
     * missing one is seeded with a minimal manifest (used only on the --all path).
     */
    private function buildMergedPackageJson(string $base): string
    {
        $pkgAbs = $base . '/package.json';
        if (is_file($pkgAbs)) {
            $decoded = json_decode((string) file_get_contents($pkgAbs), true);
            $pkg = is_array($decoded) ? $decoded : [];
        } else {
            $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', basename($base)));
            $pkg = ['name' => $slug, 'version' => '1.0.0', 'private' => true];
        }

        return json_encode(Init::mergeApiDocsPackageJson($pkg), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Build the desired openapi-overrides.json with the framework-documented
     * endpoints refreshed, or null when the project has no auth-related features
     * (nothing framework-owned to document).
     *
     * Reuses Init::buildApiOverrides() (single source of truth with `init`) and
     * deep-merges over the existing file so user customisations — extra paths,
     * schemas, contact info — survive; only the framework-owned paths, oauth2
     * scheme and global security are overwritten.
     */
    private function buildMergedApiOverrides(string $base): ?string
    {
        // Enabled features determine which endpoints get documented.
        $config   = require $base . '/app/app.php';
        $features = (is_array($config) && isset($config['features']) && is_array($config['features']))
            ? $config['features']
            : [];
        $hasAuth       = in_array('auth', $features, true);
        $hasAuthServer = in_array('authserver', $features, true);
        if (!$hasAuth && !$hasAuthServer) {
            return null;
        }

        $appName = (is_array($config) && isset($config['name'])) ? (string) $config['name'] : 'App';

        // Prefer the API base URL already recorded in apidoc.json.
        $apiUrl     = 'https://api.example.com';
        $apidocPath = $base . '/src/Api/apidoc.json';
        if (is_file($apidocPath)) {
            $apidoc = json_decode((string) file_get_contents($apidocPath), true);
            if (is_array($apidoc) && !empty($apidoc['url'])) {
                $apiUrl = (string) $apidoc['url'];
            }
        }

        // Reuse the author email from composer.json as the docs support contact,
        // matching what `init` records — so a project scaffolded before this was
        // supported gets a real email instead of the generic placeholder.
        $supportEmail = '';
        $composerPath = $base . '/composer.json';
        if (is_file($composerPath)) {
            $composer = json_decode((string) file_get_contents($composerPath), true);
            if (is_array($composer) && !empty($composer['authors'][0]['email'])) {
                $supportEmail = (string) $composer['authors'][0]['email'];
            }
        }

        $framework = Init::buildApiOverrides($appName, $apiUrl, $hasAuth, $hasAuthServer, '', $supportEmail);

        // Merge over any existing overrides so user additions survive.
        $existing = [];
        $ovPath   = $base . '/src/Api/openapi-overrides.json';
        if (is_file($ovPath)) {
            $decoded  = json_decode((string) file_get_contents($ovPath), true);
            $existing = is_array($decoded) ? $decoded : [];
        }

        $merged             = $existing;
        $merged['_comment'] = $existing['_comment'] ?? $framework['_comment'];
        $merged['_usage']   = $existing['_usage']   ?? $framework['_usage'];
        $merged['info']     = $existing['info']     ?? $framework['info'];
        // Framework paths refreshed; user-added paths preserved.
        $merged['paths']    = array_merge($existing['paths'] ?? [], $framework['paths']);

        $components      = $existing['components'] ?? [];
        $existingSchemas = $components['schemas'] ?? null;
        $components['schemas'] = empty($existingSchemas) ? (object) [] : $existingSchemas;
        if (isset($framework['components']['securitySchemes'])) {
            $components['securitySchemes'] = array_merge(
                $components['securitySchemes'] ?? [],
                $framework['components']['securitySchemes']
            );
        }
        $merged['components'] = $components;

        if (isset($framework['security'])) {
            $merged['security'] = $framework['security'];
        }

        return json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Build the list of framework-owned files to sync.
     *
     * Each entry is: ['content' => string, 'dest' => relative path, 'exec' => bool].
     * Content is read eagerly so the same list can drive both the dry-run preview
     * and the actual write.
     *
     * @return list<array{content: string, dest: string, exec: bool}>
     */
    private function collectFiles(string $scaffoldingDir, bool $doJs, bool $doScripts): array
    {
        $files = [];

        if ($doJs) {
            // All pf-*.js UI hooks (glob so newly-added ones are picked up too).
            foreach (glob($scaffoldingDir . '/assets/js/*.js') ?: [] as $src) {
                $files[] = [
                    'content' => (string) file_get_contents($src),
                    'dest'    => 'www/assets/js/' . basename($src),
                    'exec'    => false,
                ];
            }
        }

        if ($doScripts) {
            // Raw-copied JS tooling under scaffolding/scripts/.
            foreach (glob($scaffoldingDir . '/scripts/*.js') ?: [] as $src) {
                $files[] = [
                    'content' => (string) file_get_contents($src),
                    'dest'    => 'scripts/' . basename($src),
                    'exec'    => false,
                ];
            }
            // doc.sh is rendered from a stub (no tokens → raw stub content).
            $docStub = $scaffoldingDir . '/templates/doc.sh.stub';
            if (is_file($docStub)) {
                $files[] = [
                    'content' => (string) file_get_contents($docStub),
                    'dest'    => 'scripts/doc.sh',
                    'exec'    => true,
                ];
            }
        }

        return $files;
    }
}
