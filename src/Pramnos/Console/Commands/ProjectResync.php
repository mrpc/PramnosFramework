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
 * (scaffolding/scripts/apidoc-to-openapi.cjs + scripts/doc.sh). When the framework
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
 * A SPA project additionally owns the debug panel (lib/debug.js): the framework
 * both produces the `_debug` payload and draws it, so the renderer is framework
 * code that happens to live in the project. Without a way to refresh it, a
 * project scaffolded before it existed has no panel and no route to one — which
 * is how hand-rolled copies get written next to it.
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
 *   ./pramnos project:resync --debug-panel --all    # add/refresh the SPA debug panel
 *   ./pramnos project:resync --spa-components       # take a newer DataTable, Field, …
 */
class ProjectResync extends Command
{
    /** Target project root. Overridable for testing. */
    public string $targetBaseDir = '';

    /** Scaffolding source dir. Overridable for testing. */
    public string $scaffoldingDir = '';

    /**
     * app/app.php, loaded at most once.
     *
     * `require` of an already-included file returns `true`, not the array it
     * evaluates to — so two callers reading the config each with their own
     * `require` would leave the second holding a boolean.
     *
     * @var array<string, mixed>|null
     */
    private ?array $appConfig = null;

    protected function configure(): void
    {
        $this
            ->setName('project:resync')
            ->setDescription('Re-copy framework-owned scaffolded files (pf-*.js UI hooks, docs tooling, SPA debug panel) into an existing project')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing any files')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also copy framework files that are not present in the project yet')
            ->addOption('js', null, InputOption::VALUE_NONE, 'Only sync the pf-*.js UI hook scripts')
            ->addOption('scripts', null, InputOption::VALUE_NONE, 'Only sync the docs tooling scripts (apidoc-to-openapi.cjs, doc.sh)')
            ->addOption('debug-panel', null, InputOption::VALUE_NONE, 'Only sync the framework-owned SPA debug panel (lib/debug.js)')
            ->addOption('pwa', null, InputOption::VALUE_NONE, 'Only sync the PWA files: icons (never overwritten) and manifest.json (merged)')
            ->addOption('spa-components', null, InputOption::VALUE_NONE, 'Only sync the shared Svelte components (DataTable, Pagination, ConfirmDialog, Field, i18n) and their tests');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = $this->targetBaseDir;
        if ($base === '') {
            // tests always pre-set targetBaseDir
            // @codeCoverageIgnoreStart
            $base = defined('ROOT') ? ROOT : getcwd();
            // @codeCoverageIgnoreEnd
        }

        if (!is_file($base . '/app/app.php')) {
            $output->writeln("<error>Could not find app/app.php at {$base}. Run this from a project root.</error>");
            return Command::FAILURE;
        }

        $scaffoldingDir = $this->scaffoldingDir;
        if ($scaffoldingDir === '') {
            // tests always pre-set scaffoldingDir
            // @codeCoverageIgnoreStart
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
        $onlyPanel = (bool) $input->getOption('debug-panel');
        $onlyComponents = (bool) $input->getOption('spa-components');
        // No scope flag → sync every group **except** the shared components.
        //
        // They are deliberately opt-in, and that is the whole point of them: a
        // project extends its DataTable, and a resync that refreshed it by
        // default would undo that work the first time somebody ran the command
        // for an unrelated reason. --spa-components is how you say you want the
        // newer version.
        $onlyPwa   = (bool) $input->getOption('pwa');
        $allGroups = !$onlyJs && !$onlyScr && !$onlyPanel && !$onlyComponents && !$onlyPwa;
        $doJs      = $onlyJs || $allGroups;
        $doScripts = $onlyScr || $allGroups;
        $doPanel     = $onlyPanel || $allGroups;
        // In the default set, unlike --spa-components: the icons are never overwritten
        // and the manifest is only added to, so there is nothing here a plain resync
        // can undo.
        $doPwa       = $onlyPwa || $allGroups;

        $files = $this->collectFiles($scaffoldingDir, $doJs, $doScripts);
        if ($doPanel) {
            $files = array_merge($files, $this->collectPanelFiles($scaffoldingDir, $base));
        }
        if ($doPwa) {
            $files = array_merge($files, $this->collectPwaFiles($scaffoldingDir, $base));
        }
        if ($onlyComponents) {
            $files = array_merge(
                $files, $this->collectSpaComponentFiles($scaffoldingDir, $base)
            );
        }

        // package.json is a merge (not a copy): fold in the API-docs npm scripts +
        // dev-deps that init writes, so an old project's manifest gains docs:build
        // etc. Only when the docs tooling itself is being synced (its scripts would
        // otherwise reference files that don't exist).
        $syncPkg = $doScripts && (
            (glob($scaffoldingDir . '/scripts/*.{js,cjs}', GLOB_BRACE) ?: []) !== []
            || is_file($scaffoldingDir . '/templates/doc.sh.stub')
        );

        if ($files === [] && !$syncPkg) {
            $output->writeln('<comment>Nothing to sync (no framework-owned source files found).</comment>');
            $this->explainEmptyResync($base, $output);
            return Command::SUCCESS;
        }

        $output->writeln($dryRun
            ? "<comment>Dry run — no files will be written.</comment>"
            : "<info>Resyncing framework-owned files…</info>");

        $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'kept' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($files as $file) {
            $action = $this->applyFile(
                $base, $file['dest'], $file['content'], $file['exec'], $dryRun, $copyAll,
                $output, $file['onlyIfMissing'] ?? false
            );
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

        if ($doPanel) {
            $this->warnIfPanelNotWired($base, $output);
        }

        // Everything skipped is the other shape of "nothing happened", and it is the
        // one a project whose front end lives elsewhere actually hits: the file is
        // named, reported as absent, and nothing says that the *directory* is the
        // assumption rather than the file.
        if ($tally['skipped'] > 0
            && ($tally['created'] + $tally['updated'] + $tally['unchanged'] + $tally['kept'] + $tally['failed']) === 0
        ) {
            $this->explainEmptyResync($base, $output);
        }

        $output->writeln('');
        $summary = sprintf(
            'Done. %d created, %d updated, %d unchanged, %d kept, %d skipped',
            $tally['created'],
            $tally['updated'],
            $tally['unchanged'],
            $tally['kept'],
            $tally['skipped']
        );
        // The failure count is only ever printed when there is one. A "0 failed" in
        // every successful run is noise that teaches the reader to skip the line the
        // one time it matters.
        if ($tally['failed'] > 0) {
            $output->writeln('<error>' . $summary . ', ' . $tally['failed'] . ' FAILED.</error>');
        } else {
            $output->writeln('<info>' . $summary . '.</info>');
        }
        if ($tally['skipped'] > 0 && !$copyAll) {
            $output->writeln('<comment>Some framework files are not present in this project. Re-run with --all to add them.</comment>');
        }

        // Non-zero when anything could not be written. A caller that checks the exit
        // code — the correct way to run this from a deploy script or CI — must not be
        // told a resync succeeded when the files on disk are still the old ones.
        return $tally['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * What to say when a write fails.
     *
     * Permissions first, by a distance: the command is normally run by a developer or
     * a deploy user against files owned by the web-server user, and the fix is *who*
     * runs it rather than anything in the code. Naming the likely cause is the
     * difference between a message that ends the investigation and one that starts it.
     *
     * @var string
     */
    private const PERMISSION_HINT =
        'Usually a permissions problem: the user running this command must be able to '
        . 'write the file. Check ownership, or re-run as the user that owns the project.';

    /**
     * A path relative to the project root, for messages.
     *
     * @param  string $base Project root
     * @param  string $path Absolute path
     * @return string
     */
    private function relativise(string $base, string $path): string
    {
        $prefix = rtrim($base, '/') . '/';

        return str_starts_with($path, $prefix)
            ? substr($path, strlen($prefix))
            : $path;
    }

    /**
     * Write one file's desired content into the project, reporting and returning
     * the action taken so the caller can tally it.
     *
     * Honours the two cross-cutting flags: missing files are skipped unless
     * --all, and nothing is written under --dry-run.
     *
     * @return string One of: created, updated, unchanged, skipped, failed.
     */
    private function applyFile(
        string $base,
        string $rel,
        string $content,
        bool $exec,
        bool $dryRun,
        bool $copyAll,
        OutputInterface $output,
        bool $onlyIfMissing = false
    ): string {
        $abs    = $base . '/' . $rel;
        $exists = is_file($abs);

        // **The project's file wins, unconditionally.**
        //
        // Everything else here is framework-owned: the framework's copy is the correct
        // one and overwriting is the command's entire purpose. Artwork is the opposite.
        // A project is *expected* to replace the placeholder icons with its own, and a
        // resync run for an unrelated reason must not put the framework's logo back —
        // there is no undo for that, and nothing in the output would look wrong.
        //
        // Reported rather than silently skipped, because "your icons are still yours"
        // is information: without the line, somebody who wanted the newer defaults
        // would have no way to tell whether the command had considered them.
        if ($exists && $onlyIfMissing) {
            $output->writeln("  kept       {$rel} (yours)");
            return 'kept';
        }

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
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $output->writeln(sprintf(
                    '  <error>failed</error>    %s (cannot create %s)',
                    $rel,
                    $this->relativise($base, $dir)
                ));
                $output->writeln('             ' . self::PERMISSION_HINT);

                return 'failed';
            }

            // The return value is checked, and that is the whole point of this
            // method's existence in its current shape.
            //
            // This used to be a bare `file_put_contents($abs, $content);`, so a write
            // that failed still printed "updated", still counted as updated in the
            // summary, and still exited 0. The only trace was a PHP warning on stderr,
            // inside a wall of output that a CI job or a habitual `2>/dev/null`
            // discards — so a caller checking the exit code, which is the correct way
            // to call this, was told the resync had succeeded.
            //
            // That inverts the command's entire purpose. `project:resync` exists so a
            // framework-owned file downstream *is* the framework's current one; a
            // resync that reports success without writing means a project runs an old
            // copy with confidence, which is precisely the failure it was built to
            // prevent.
            if (@file_put_contents($abs, $content) === false) {
                $output->writeln(sprintf(
                    '  <error>failed</error>    %s (could not write)',
                    $rel
                ));
                $output->writeln('             ' . self::PERMISSION_HINT);

                return 'failed';
            }

            if ($exec) {
                @chmod($abs, 0755);
            }
            $output->writeln("  <info>{$verb}d</info>  {$rel}");
        }

        return $exists ? 'updated' : 'created';
    }

    /**
     * The project's app/app.php, loaded once per command run.
     *
     * @return array<string, mixed>
     */
    private function appConfig(string $base): array
    {
        if ($this->appConfig === null) {
            $config = require $base . '/app/app.php';
            $this->appConfig = is_array($config) ? $config : [];
        }

        return $this->appConfig;
    }

    /**
     * Where this project keeps its front-end sources, with a trailing slash, or
     * '' when it has no SPA at all.
     *
     * Mirrors Init::scaffoldSpa(): a build stack keeps sources out of the web
     * root, the build-less stack serves them from it. Read from app.php rather
     * than guessed, so a project that has both (hybrid) is still resolved to the
     * one directory its SPA actually lives in.
     */
    private function spaSourceDir(string $base): string
    {
        // The rule lives in Init::spaSourceDirFor(), which is where scaffoldSpa()
        // decides the same thing — three commands were carrying a copy of it.
        // An explicit spa_source_dir wins there, so a project whose front end
        // lives somewhere else can be helped without a repo-wide rename;
        // reported by one that had to move `admin-ui/` to `frontend/` to receive
        // a file, which was the right move for other reasons but not something a
        // resync should require.
        return Init::spaSourceDirFor($this->appConfig($base));
    }

    /**
     * Framework-owned SPA front-end files: the debug panel.
     *
     * The panel is the framework's toolbar rewritten for a SPA — the drawing is
     * as much framework code as the `_debug` payload it draws, and nothing in it
     * is application-specific. It is listed here so an existing project can gain
     * or refresh it, instead of the next person concluding the framework ships
     * only the data and writing a second renderer.
     *
     * @return list<array{content: string, dest: string, exec: bool}>
     */
    private function collectPanelFiles(string $scaffoldingDir, string $base): array
    {
        $sourceDir = $this->spaSourceDir($base);
        if ($sourceDir === '') {
            return [];
        }

        $appName = (string) ($this->appConfig($base)['name'] ?? 'App');

        try {
            $content = \Pramnos\Debug\DebugBarAsset::spaModule($appName);
        } catch (\RuntimeException) {
            // the asset ships with the framework
            // @codeCoverageIgnoreStart
            return [];
            // @codeCoverageIgnoreEnd
        }

        return [[
            'content' => $content,
            'dest'    => $sourceDir . 'lib/debug.js',
            'exec'    => false,
        ]];
    }

    /**
     * The directory this project serves as its document root.
     *
     * Detected rather than read from configuration, because it is not in any: `init`
     * takes `--web-root` and uses it to place files, and nothing writes it down. So the
     * evidence on disk is all there is — the directory that has the front controller in
     * it.
     *
     * `www` is both the default and the fallback, which keeps a project with an unusual
     * layout from having icons written into a directory nothing serves.
     */
    private function webRootOf(string $base): string
    {
        foreach (['www', 'public', 'public_html', 'htdocs', 'web'] as $candidate) {
            if (is_file($base . '/' . $candidate . '/index.php')) {
                return $candidate;
            }
        }

        return 'www';
    }

    /**
     * The PWA furniture: the icon set, the Windows tile config, and the manifest.
     *
     * Two different rules, because the two kinds of file mean different things.
     *
     * **The artwork is written only when it is missing.** The framework ships
     * placeholders and a project is expected to replace them; a resync that restored the
     * framework's logo would be undoing work with no undo of its own. Requested
     * exactly that way after this group was proposed.
     *
     * **The manifest is merged.** Only keys it does not already have are added, so a
     * project scaffolded before `start_url` and `display` existed becomes installable
     * without losing a `short_name` somebody shortened or an `icons` array somebody
     * curated. That is why it is not simply "copy when missing" like the images: an
     * existing manifest is precisely the case that needs fixing.
     *
     * The group runs by default, unlike `--spa-components`, and it can afford to: with
     * those two rules there is nothing here that can overwrite a decision.
     *
     * @return list<array{content: string, dest: string, exec: bool, onlyIfMissing?: bool}>
     */
    private function collectPwaFiles(string $scaffoldingDir, string $base): array
    {
        $brand = dirname($scaffoldingDir) . '/brand/favicons';
        if (!is_dir($brand)) {
            // brand/ ships with the framework
            return []; // @codeCoverageIgnore
        }

        $webRoot = $this->webRootOf($base);
        $init    = new Init();
        $init->targetBaseDir = $base;
        $files   = [];

        // The sized icons and favicon.ico — artwork, so never overwritten.
        foreach (glob($brand . '/*.png') ?: [] as $png) {
            $files[] = [
                'content'       => (string) file_get_contents($png),
                'dest'          => $webRoot . '/assets/favicons/' . basename($png),
                'exec'          => false,
                'onlyIfMissing' => true,
            ];
        }

        if (is_file($brand . '/favicon.ico')) {
            $files[] = [
                'content'       => (string) file_get_contents($brand . '/favicon.ico'),
                'dest'          => $webRoot . '/favicon.ico',
                'exec'          => false,
                'onlyIfMissing' => true,
            ];
        }

        if (is_file($brand . '/browserconfig.xml')) {
            $files[] = [
                'content'       => $init->rewriteBrowserconfig($brand . '/browserconfig.xml'),
                'dest'          => $webRoot . '/browserconfig.xml',
                'exec'          => false,
                'onlyIfMissing' => true,
            ];
        }

        $manifest = $this->buildMergedManifest($base, $webRoot, $brand, $init);
        if ($manifest !== null) {
            $files[] = [
                'content' => $manifest,
                'dest'    => $webRoot . '/manifest.json',
                'exec'    => false,
            ];
        }

        return $files;
    }

    /**
     * The project's manifest with any missing standard keys filled in.
     *
     * Returns null when there is nothing to do — no project manifest and no master to
     * build one from — so the caller adds no entry rather than an empty one.
     *
     * An unparseable manifest is left completely alone. It is hand-editable, so a
     * syntax error is somebody midway through a change; overwriting it with a generated
     * file would destroy the edit and the reason for it.
     */
    private function buildMergedManifest(
        string $base, string $webRoot, string $brand, Init $init
    ): ?string {
        $path = $base . '/' . $webRoot . '/manifest.json';

        if (!is_file($path)) {
            return is_file($brand . '/manifest.json')
                ? $init->rewriteFaviconManifest(
                    $brand . '/manifest.json',
                    (string) ($this->appConfig($base)['name'] ?? 'App')
                )
                : null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return null;
        }

        $data += Init::MANIFEST_DEFAULTS;

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * The shared Svelte components and their tests, rendered for this project.
     *
     * Opt-in only (`--spa-components`), because these become the project's files
     * the moment they exist: the value of shipping a DataTable is that projects
     * extend it. Taking a newer version is a decision somebody makes, and
     * `--all` then decides whether local edits are overwritten — the same two
     * steps every other group in this command uses.
     *
     * @param  string $scaffoldingDir
     * @param  string $base
     * @return array<int, array{content: string, dest: string, exec: bool}>
     */
    private function collectSpaComponentFiles(string $scaffoldingDir, string $base): array
    {
        $sourceDir = $this->spaSourceDir($base);
        if ($sourceDir === '') {
            return [];
        }

        $config = $this->appConfig($base);
        if ((string) ($config['spa_stack'] ?? '') !== 'svelte') {
            // The components are Svelte. A component library for a no-build
            // stack is a different project, and silently writing .svelte files
            // into one would be worse than doing nothing.
            return [];
        }

        $tokens = [
            'appName'       => (string) ($config['name'] ?? 'App'),
            'apiPrefix'     => rtrim((string) ($config['api_prefix'] ?? '/api/1.0'), '/'),
            'routerBase'    => (string) ($config['app_style'] ?? '') === 'hybrid' ? '/app' : '',
            'localeMapJson' => json_encode(['english' => 'en-GB']),
        ];

        $files = [];
        $wanted = Init::SPA_SHARED_COMPONENTS + Init::SPA_SHARED_COMPONENT_TESTS;
        foreach ($wanted as $relative => $stub) {
            $path = $scaffoldingDir . '/templates/' . $stub . '.stub';
            if (!is_file($path)) {
                // ships with the framework
                continue; // @codeCoverageIgnore
            }

            $files[] = [
                'content' => $this->renderStubFile($path, $tokens),
                'dest'    => $sourceDir . $relative,
                'exec'    => false,
            ];
        }

        return $files;
    }

    /**
     * Substitute `{{ token }}` placeholders in a stub read from disk.
     *
     * This command is not a MakeCommandBase, so it has no renderStub() — and it
     * only needs the substitution, not the stub-resolution around it.
     *
     * @param  string                $path
     * @param  array<string, string> $tokens
     * @return string
     */
    private function renderStubFile(string $path, array $tokens): string
    {
        $contents = (string) file_get_contents($path);

        foreach ($tokens as $name => $value) {
            $contents = str_replace('{{ ' . $name . ' }}', (string) $value, $contents);
        }

        return $contents;
    }

    /**
     * Say *why* nothing was found, when nothing was.
     *
     * "Nothing to sync" is the same sentence for a project with no SPA and for a
     * project whose SPA sources are somewhere this command does not look — and the
     * second reading is the one that sends somebody hunting in the wrong place. It
     * cost a reviewer exactly that, and the fix was a repo-wide rename they would
     * otherwise not have made.
     *
     * @param  string          $base
     * @param  OutputInterface $output
     * @return void
     */
    private function explainEmptyResync(string $base, OutputInterface $output): void
    {
        $config   = $this->appConfig($base);
        $appStyle = (string) ($config['app_style'] ?? 'mvc');

        if ($appStyle === 'mvc') {
            $output->writeln('  <comment>app_style</comment> is <comment>mvc</comment> in '
                . 'app/app.php, so there is no SPA front end to sync.');
            return;
        }

        $sourceDir  = $this->spaSourceDir($base);
        $configured = trim((string) ($config['spa_source_dir'] ?? ''));

        $output->writeln('  Looked for the front end in <comment>' . $sourceDir . '</comment>'
            . ($configured !== ''
                ? ' (from <comment>spa_source_dir</comment> in app/app.php).'
                : ' (derived from <comment>spa_stack</comment>).'));

        if (!is_dir($base . '/' . rtrim($sourceDir, '/'))) {
            $output->writeln('  That directory does not exist.');
        }

        if ($configured === '') {
            $output->writeln('  If this project keeps its front end elsewhere, add '
                . "<comment>'spa_source_dir' => 'your-dir/'</comment> to app/app.php "
                . 'rather than renaming the directory.');
        }
    }

    /**
     * Say so when the panel is present but nothing feeds it.
     *
     * `lib/api.js` is the project's own file — people edit it, and rewriting it
     * from the stub would throw those edits away. So the wiring is reported, not
     * repaired: a panel nothing calls is silent in exactly the way a missing
     * panel is, and that silence is what gets read as "the framework has no
     * panel".
     */
    private function warnIfPanelNotWired(string $base, OutputInterface $output): void
    {
        $sourceDir = $this->spaSourceDir($base);
        if ($sourceDir === '') {
            return;
        }

        $panel = $base . '/' . $sourceDir . 'lib/debug.js';
        $api   = $base . '/' . $sourceDir . 'lib/api.js';
        if (!is_file($panel) || !is_file($api)) {
            return;
        }

        if (str_contains((string) file_get_contents($api), './debug.js')) {
            return;
        }

        $output->writeln('');
        $output->writeln("<comment>{$sourceDir}lib/api.js does not feed the debug panel.</comment>");
        $output->writeln('  Add the import and record each response (left as a manual edit because');
        $output->writeln('  this file is yours):');
        $output->writeln("    import { record as recordDebug } from './debug.js';");
        $output->writeln('    recordDebug(method, path, response.status, payload && payload._debug);');
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
        $config   = $this->appConfig($base);
        $features = (isset($config['features']) && is_array($config['features']))
            ? $config['features']
            : [];
        $hasAuth       = in_array('auth', $features, true);
        $hasAuthServer = in_array('authserver', $features, true);
        if (!$hasAuth && !$hasAuthServer) {
            return null;
        }

        $appName = isset($config['name']) ? (string) $config['name'] : 'App';

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
            foreach (glob($scaffoldingDir . '/scripts/*.{js,cjs}', GLOB_BRACE) ?: [] as $src) {
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
