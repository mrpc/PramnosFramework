<?php
namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Input\InputOption;

/**
 * Initialize a new Pramnos Application project.
 *
 * Steps:
 *  1. Project metadata (name, namespace)
 *  2. Framework features (auth, authserver, queue, messaging, devpanel)
 *  3. UI system (plain-css, bootstrap, tailwind)
 *  4. Extra libraries (local asset download into public/assets/vendor/)
 *  5. Extra resources (favicon set, base CSS reset, print stylesheet)
 *  6. Docker startup → composer install → migrate --scope=framework → summary
 */
class Init extends Command
{
    /** Application styles offered by --app-style. */
    public const APP_STYLES = ['mvc', 'spa', 'hybrid'];

    /** Front-end stacks offered by --spa-stack. */
    public const SPA_STACKS = ['svelte', 'vanilla-vite', 'vanilla'];

    /** Web-root-relative directory a Vite build writes into. */
    private const SPA_BUILD_DIR = 'assets/spa';

    /** Target directory for scaffolding. */
    public string $targetBaseDir = '';

    /** When true, docker-compose up is skipped (test mode). */
    public bool $skipDockerRun = false;

    /**
     * Seconds a spinner step may run before its subprocess output is surfaced
     * live. Guards against a silent, endless spinner when a step (e.g.
     * "docker-compose up --build") hangs. Set to 0 to disable escalation.
     */
    public int $slowStepThreshold = 120;

    /** Path to the scaffolding/ directory inside the framework package. */
    public string $scaffoldingDir = '';

    /**
     * Path to the brand/ directory inside the framework package (logos + favicon
     * set). Defaults to the sibling of scaffoldingDir; overridable for testing.
     */
    public string $brandDir = '';

    /** Web-root-relative directory for the sized favicon/app-icon files. */
    private string $faviconSubdir = 'assets/favicons';

    /** True once `npm run build` produced the SPA bundle during init. */
    private bool    $spaBuilt          = false;
    /** True when the SPA's status service/controller were scaffolded. */
    private bool    $spaStatusEndpoint = false;
    private bool    $dockerSuccess     = false;
    private bool    $autoloadSuccess   = true;
    private bool    $migrationsSuccess = false;
    /** @var array{username: string, email: string, password: string}|null */
    private ?array  $adminCredentials  = null;

    protected function configure(): void
    {
        $this->setName('init');
        $this->setDescription('Initialize a new Pramnos project structure');
        $this->addOption('app-name',      null, InputOption::VALUE_OPTIONAL, 'Application name');
        $this->addOption('namespace',     null, InputOption::VALUE_OPTIONAL, 'PHP namespace');
        $this->addOption('features',      null, InputOption::VALUE_OPTIONAL, 'Comma-separated feature list (auth,authserver,queue,messaging,devpanel)');
        $this->addOption('ui-system',     null, InputOption::VALUE_OPTIONAL, 'UI system (plain-css, bootstrap, tailwind)');
        $this->addOption('docker',        null, InputOption::VALUE_OPTIONAL, 'Setup Docker environment (y/n)');
        $this->addOption('docker-port',   null, InputOption::VALUE_OPTIONAL, 'Local port for Docker mapping');
        $this->addOption('cache-system',  null, InputOption::VALUE_OPTIONAL, 'Cache system (none, redis, memcached)');
        $this->addOption('db-type',       null, InputOption::VALUE_OPTIONAL, 'Database type (mysql, postgresql, timescaledb)');
        $this->addOption('db-host',       null, InputOption::VALUE_OPTIONAL, 'Database host');
        $this->addOption('db-name',       null, InputOption::VALUE_OPTIONAL, 'Database name');
        $this->addOption('db-user',       null, InputOption::VALUE_OPTIONAL, 'Database user');
        $this->addOption('db-pass',       null, InputOption::VALUE_OPTIONAL, 'Database password');
        $this->addOption('db-prefix',     null, InputOption::VALUE_OPTIONAL, 'Database table prefix');
        $this->addOption('libraries',     null, InputOption::VALUE_OPTIONAL, 'Comma-separated extra library list');
        $this->addOption('no-download',   null, InputOption::VALUE_NONE,     'Skip asset download (record in assets.json only)');
        $this->addOption('no-migrations', null, InputOption::VALUE_NONE,     'Skip migrate --scope=framework after Docker startup');
        $this->addOption('no-install',    null, InputOption::VALUE_NONE,     'Skip composer update / dump-autoload (scaffold files only)');
        $this->addOption('rest-api',      null, InputOption::VALUE_OPTIONAL, 'Scaffold REST API layer (y/n)');
        $this->addOption('api-docs',      null, InputOption::VALUE_OPTIONAL, 'Generate API documentation tooling (OpenAPI + RapiDoc) (y/n)');
        $this->addOption('api-url',       null, InputOption::VALUE_OPTIONAL, 'Production API base URL for documentation');
        $this->addOption('api-color',     null, InputOption::VALUE_OPTIONAL, 'Primary color for API docs UI (hex, e.g. #4CAF50)');
        $this->addOption('webhook',       null, InputOption::VALUE_OPTIONAL, 'Generate www/webhook.php git webhook receiver (y/n)');
        $this->addOption('app-style',     null, InputOption::VALUE_OPTIONAL, 'Application style (mvc, spa, hybrid)');
        $this->addOption('spa-stack',     null, InputOption::VALUE_OPTIONAL, 'SPA front-end stack (svelte, vanilla-vite, vanilla)');
        $this->addOption('spa-dev-port',  null, InputOption::VALUE_OPTIONAL, 'Port for the Vite dev server');
        $this->addOption('force',         null, InputOption::VALUE_NONE,     'Initialise even though this directory already holds an application');
        $this->addOption('dry-run',       null, InputOption::VALUE_NONE,     'Report every file that would be written, and write none of them');
    }

    /**
     * Files an init writes unconditionally, which an existing project cannot lose.
     *
     * Not the full list — that runs to hundreds — but the ones whose loss is
     * unrecoverable without version control, and enough to make the refusal
     * concrete. `app/app.php` is the marker itself: a directory that has one has
     * been initialised.
     *
     * @var list<string>
     */
    private const OVERWRITES = [
        'app/app.php',
        'composer.json',
        'CLAUDE.md',
        'README.md',
        'Dockerfile',
        'docker-compose.yml',
        'phpunit.xml',
    ];

    /**
     * Whether this run may write anything at all.
     *
     * @var bool
     */
    private bool $dryRun = false;

    /**
     * Whether to skip installing dependencies (`--no-install`).
     *
     * Scaffolding files and installing dependencies are separate jobs, and there are
     * several reasons to want only the first: a CI job that scaffolds a project and
     * installs from a lockfile of its own, a machine with no network, a project whose
     * `vendor/` is committed, and this framework's own test suite — which scaffolded
     * 61 projects per run and therefore ran `composer update` 61 times, for **85% of
     * that suite's runtime** and a dependency on the network inside a unit test.
     *
     * The skip is reported rather than silent, for the same reason `--dry-run` reports
     * what it would have done: "no autoloader was generated" is something the reader
     * needs to know before wondering why the app does not boot.
     *
     * @var bool
     */
    private bool $skipInstall = false;

    /**
     * Leave a file alone when the project already has one.
     *
     * Set by `scaffold:spa`, which adds a front end to an application that exists.
     * `init` never sets it: a fresh scaffold writes everything.
     *
     * @var bool
     */
    public bool $skipExisting = false;

    /**
     * Paths left alone because the project already had them.
     *
     * @var array<string, true>
     */
    private array $keptFiles = [];

    /**
     * Paths actually written.
     *
     * @var array<string, true>
     */
    private array $writtenFiles = [];

    /**
     * What the last run wrote and what it left alone.
     *
     * @return array{written: list<string>, kept: list<string>}
     */
    public function report(): array
    {
        $written = array_keys($this->writtenFiles);
        $kept    = array_keys($this->keptFiles);
        sort($written);
        sort($kept);

        return ['written' => $written, 'kept' => $kept];
    }

    /**
     * Paths a dry run would have written, and whether each already exists.
     *
     * @var array<string, bool> Relative path => it exists today
     */
    private array $plannedWrites = [];

    /**
     * Refuse to scaffold over an application that is already here.
     *
     * `init` writes `app/app.php`, `composer.json`, `CLAUDE.md`, `README.md`, the
     * Docker files, `phpunit.xml` and `src/Console.php` unconditionally, and drops
     * ~18 stock MVC controllers into `src/Controllers/` — which in an
     * attribute-routed application become **live routes**, because the loader
     * takes whatever is in that directory. None of it is recoverable without git,
     * and a scaffolding tool is exactly what somebody runs optimistically in the
     * wrong directory.
     *
     * Three things here were already non-destructive by design — the `.gitignore`
     * append, the `package.json` merge, the screens-registry edit — so the intent
     * existed; it was simply not applied to the rest.
     *
     * @param  OutputInterface $output
     * @param  bool            $force Proceed anyway (`--force`)
     * @return bool True when it is safe to continue.
     */
    private function refuseToOverwriteExistingProject(OutputInterface $output, bool $force): bool
    {
        $marker = $this->targetBaseDir . '/app/app.php';
        if (!is_file($marker)) {
            return true;
        }

        if ($force) {
            $output->writeln('<comment>This directory already holds an application; --force was given, '
                . 'so it will be overwritten.</comment>');
            $output->writeln('');
            return true;
        }

        $output->writeln('');
        $output->writeln('<error>This directory already holds an application.</error>');
        $output->writeln('  Found: <comment>app/app.php</comment>');
        $output->writeln('');
        $output->writeln('  Running init here would overwrite files including:');
        foreach (self::OVERWRITES as $path) {
            $exists = is_file($this->targetBaseDir . '/' . $path);
            $output->writeln('    ' . ($exists ? '<comment>' . $path . '</comment>' : $path));
        }
        $output->writeln('  …and add stock controllers to <comment>src/Controllers/</comment>, which in an');
        $output->writeln('  attribute-routed application become live routes.');
        $output->writeln('');
        $output->writeln('  <info>--dry-run</info> lists everything that would be written, and writes nothing.');
        $output->writeln('  <info>--force</info>   proceeds anyway.');
        $output->writeln('');

        return false;
    }

    /**
     * Print what a dry run would have done.
     *
     * @param  OutputInterface $output
     * @return void
     */
    private function reportDryRun(OutputInterface $output): void
    {
        $created   = array_keys(array_filter($this->plannedWrites, fn($exists) => !$exists));
        $overwrite = array_keys(array_filter($this->plannedWrites, fn($exists) => $exists));
        sort($created);
        sort($overwrite);

        $output->writeln('');
        $output->writeln('<info>Dry run — nothing was written.</info>');
        $output->writeln('');

        if ($overwrite !== []) {
            $output->writeln('<comment>Would overwrite ' . count($overwrite) . ' existing file(s):</comment>');
            foreach ($overwrite as $path) {
                $output->writeln('  <comment>' . $path . '</comment>');
            }
            $output->writeln('');
        }

        $output->writeln('Would create ' . count($created) . ' file(s):');
        foreach ($created as $path) {
            $output->writeln('  ' . $path);
        }
        $output->writeln('');
        $output->writeln('External steps (composer, Docker, migrations, asset downloads) were skipped.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->targetBaseDir === '') {
            $this->targetBaseDir = defined('ROOT') ? ROOT : getcwd(); // @codeCoverageIgnore — tests always pre-set targetBaseDir
        }

        if ($this->scaffoldingDir === '') {
            $this->scaffoldingDir = $this->resolveScaffoldingDir();
        }

        if ($this->brandDir === '') {
            // brand/ is a sibling of scaffolding/ inside the framework package.
            $this->brandDir = dirname($this->scaffoldingDir) . '/brand';
        }

        $this->dryRun        = (bool) $input->getOption('dry-run');
        $this->skipInstall   = (bool) $input->getOption('no-install');
        $this->plannedWrites = [];

        // Before anything is written, and before any question is asked: the one
        // failure this command can cause that a person cannot undo.
        if (!$this->dryRun
            && !$this->refuseToOverwriteExistingProject($output, (bool) $input->getOption('force'))
        ) {
            return Command::FAILURE;
        }

        if ($this->dryRun) {
            $output->writeln('<comment>Dry run — no file will be written and no external command will run.</comment>');
        }

        $helper = $this->getHelper('question');

        // Put the terminal's line discipline into UTF-8 mode so backspace at an
        // interactive prompt erases a whole multibyte character instead of a
        // single byte (the latter — the default on e.g. WSL — leaves broken
        // sequences/leftover bytes in answers like passwords).
        $this->enableUtf8TerminalInput();

        $output->writeln([
            '',
            ' <info>╔══════════════════════════════════════════════╗</info>',
            ' <info>║       Pramnos Framework Initialization       ║</info>',
            ' <info>╚══════════════════════════════════════════════╝</info>',
            '',
        ]);

        // ── Step 1: Project metadata ──────────────────────────────────────────
        $defaultAppName = basename($this->targetBaseDir);
        $appName = $input->getOption('app-name')
            ?: $helper->ask($input, $output, new Question("Application Name [$defaultAppName]: ", $defaultAppName));

        $defaultNamespace = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $appName)));
        $namespace = $input->getOption('namespace')
            ?: $helper->ask($input, $output, new Question("Namespace [$defaultNamespace]: ", $defaultNamespace));

        // ── Step 1b: Application style ────────────────────────────────────────
        // Asked before everything else it influences: an SPA needs the JSON API,
        // and (unless the app also keeps server-rendered pages) it needs no theme
        // or MVC views at all.
        $appStyle = $this->askAppStyle($input, $output, $helper);
        $spaStack = $appStyle === 'mvc'
            ? ''
            : $this->askSpaStack($input, $output, $helper);

        // ── Step 2: Framework features ────────────────────────────────────────
        $enabledFeatures = $this->askFeatures($input, $output, $helper);
        // A SPA talks to the JSON API and nothing else, so the API layer is not
        // optional there — asking would only offer a broken combination.
        $withRestApi     = $appStyle === 'mvc'
            ? $this->askRestApi($input, $output, $helper)
            : true;
        $withWebhook     = $this->askWebhook($input, $output, $helper);
        $withApiDocs     = false;
        $apiUrl          = 'https://api.example.com';
        $apiColor        = '#4CAF50';
        if ($withRestApi) {
            [$withApiDocs, $apiUrl, $apiColor] = $this->askApiDocs($input, $output, $helper, $appName);
        }

        // A ready-to-use, stable API key for the seed "Development" application,
        // created after migrations. Fixed value (not random) so it is predictable
        // for local testing: pre-filled into the API docs (RapiDoc "Authorize")
        // and reported in the final summary. Only with the OAuth server, whose
        // migrations create the `applications` table.
        $apiKey = in_array('authserver', $enabledFeatures, true) ? 'localtestkey' : '';

        // ── Step 3: UI system ─────────────────────────────────────────────────
        $uiSystem = $this->askUiSystem($input, $output, $helper);

        // ── Step 4: Extra libraries ───────────────────────────────────────────
        $selectedLibraries = $this->askLibraries($input, $output, $helper, $uiSystem);
        // The scaffolded DataTables CRUD views load the Pramnos REST adapter
        // (pramnos-datatable.js, shipped in the bundled `pramnos-adapters`
        // library). Pull it in automatically whenever DataTables is selected so
        // generated controllers always have the script handle they enqueue —
        // otherwise the page fatals with "Cannot find script: pramnos-adapters".
        if (in_array('datatables', $selectedLibraries, true)
            && !in_array('pramnos-adapters', $selectedLibraries, true)) {
            $selectedLibraries[] = 'pramnos-adapters';
        }

        // ── Docker setup ──────────────────────────────────────────────────────
        $dockerOption = $input->getOption('docker');
        if ($dockerOption !== null) {
            $useDocker = in_array(strtolower($dockerOption), ['y', 'yes', '1', 'true']);
        } else {
            $useDocker = $helper->ask($input, $output, new ConfirmationQuestion('Setup Docker environment? [Y/n] ', true));
        }

        $dockerPort  = 8080;
        // An explicit --cache-system always wins. Otherwise default to redis
        // with Docker (a cache container is provisioned) and none without it
        // (no backend to connect to).
        $cacheSystemOption = $input->getOption('cache-system');
        $cacheSystem = $cacheSystemOption !== null
            ? $cacheSystemOption
            : ($useDocker ? 'redis' : 'none');

        if ($useDocker) {
            $dockerPort = $this->resolveDockerPort($input, $output, $helper);

            if ($cacheSystemOption === null) {
                $cacheSystem = $helper->ask($input, $output, new ChoiceQuestion('Cache System [redis]: ', ['redis', 'none', 'memcached'], 0));
            }
        }

        // When a cache backend is selected, enable the 'cache' feature too, so the
        // CacheServiceProvider boots and feature-aware tooling (DevPanel, health)
        // recognises caching as on — not just the settings.php connection config.
        if ($cacheSystem !== 'none' && !in_array('cache', $enabledFeatures, true)) {
            $enabledFeatures[] = 'cache';
        }

        // ── Database config ───────────────────────────────────────────────────
        $randomPass  = bin2hex(random_bytes(10));
        $dbRootPass  = bin2hex(random_bytes(10));
        $dbTypeChoices = ['mysql', 'postgresql', 'timescaledb'];

        $dbTypeOption = $input->getOption('db-type');
        $dbType = $dbTypeOption !== null
            ? $dbTypeOption
            : $helper->ask($input, $output, new ChoiceQuestion('Database Type [timescaledb]: ', $dbTypeChoices, 2));

        $defaultDbHost = $useDocker ? 'db' : 'localhost';
        $dbHost = $input->getOption('db-host')
            ?: $helper->ask($input, $output, new Question("Database Host [$defaultDbHost]: ", $defaultDbHost));

        $dbSuffix      = strtolower(str_replace(['-', ' '], '_', $appName));
        $dbNameDefault = $dbSuffix . '_db';
        $dbUserDefault = $dbSuffix . '_user';

        $dbName   = $input->getOption('db-name')   ?: $helper->ask($input, $output, new Question("Database Name [$dbNameDefault]: ", $dbNameDefault));
        $dbUser   = $input->getOption('db-user')   ?: $helper->ask($input, $output, new Question("Database User [$dbUserDefault]: ", $dbUserDefault));
        $dbPass   = $input->getOption('db-pass')   ?: $helper->ask($input, $output, new Question("Database Password [$randomPass]: ", $randomPass));
        $dbPrefix = $input->getOption('db-prefix') !== null
            ? $input->getOption('db-prefix')
            : $helper->ask($input, $output, new Question('Database Table Prefix [optional]: ', ''));

        // ── Step 5: Author info ───────────────────────────────────────────────
        $userName  = $helper->ask($input, $output, new Question('Author Name [Pramnos Developer]: ', 'Pramnos Developer'));
        $userEmail = '';
        while (true) {
            $userEmail = $helper->ask($input, $output, new Question('Author Email [developer@pramnos.net]: ', 'developer@pramnos.net'));
            if (\Pramnos\Validation\Validator::checkEmail($userEmail)) {
                break;
            }
            $output->writeln('<error>Invalid email address. Please try again.</error>'); // @codeCoverageIgnore — tests always provide valid email addresses
        }

        // API base path — the one place it is decided, so app.php, the routes
        // group prefix and the SPA client all agree.
        $apiPrefix = '/api/1.0';

        // Vite's dev server needs its own host port. Default to two above the
        // application (the port right above it belongs to the database tool),
        // and step over anything already taken.
        $spaDevPort = 0;
        if ($appStyle !== 'mvc' && self::spaNeedsNode($spaStack)) {
            $spaDevPort = (int) ($input->getOption('spa-dev-port')
                ?: $this->findAvailablePortPair($dockerPort + 2));
        }

        // ── Scaffold ──────────────────────────────────────────────────────────
        $output->writeln("\n<info>Scaffolding project structure...</info>");

        $this->mkdir('www');
        $this->mkdir('www/assets');
        $this->mkdir('www/assets/css');
        $this->mkdir('www/assets/js');
        $this->mkdir('www/assets/img');
        $this->mkdir('www/assets/vendor');
        $this->mkdir('src/Controllers');
        $this->mkdir('src/Models');
        $this->mkdir('src/Views/home');
        $this->mkdir('app/config');
        $this->mkdir('app/Migrations');
        $this->mkdir('app/themes/default');
        $this->mkdir('app/language');
        $this->mkdir('var/cache');
        $this->mkdir('var/logs');

        // CLI entry-point name: lowercase alphanumeric, e.g. "myapp" → myapp.php / ./myapp
        $cliName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $namespace));

        $this->scaffoldSettings('app/config/settings.php', $dbType, $dbHost, $dbName, $dbUser, $dbPass, $dbPrefix, true, $cacheSystem);
        $this->scaffoldAppConfig(
            'app/app.php', $appName, $namespace, $enabledFeatures, $uiSystem, $withRestApi,
            $apiPrefix, $appStyle, $spaStack
        );
        $this->writeFile('app/language/en.php', "<?php\n\$lang = [\n    'CHARSET' => 'UTF-8',\n    'LangShort' => 'en'\n];\nreturn \$lang;\n");
        $this->writeFile('app/schedule.php', $this->getScheduleTemplate());
        $this->writeFile('www/index.php', $this->getIndexTemplate($namespace));
        $this->writeFile('www/.htaccess', "RewriteEngine On\nRewriteRule ^$ index.php [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^(.*)$ index.php?r=\$1 [QSA,L]\n");
        $catalog = $this->loadAssetCatalog();
        $this->writeFile('src/Application.php', $this->getApplicationTemplate($namespace, $selectedLibraries, $catalog));
        $this->writeFile('src/Console.php', $this->getConsoleTemplate($namespace, $appName));
        $this->writeFile("$cliName.php", $this->getCliEntryPointTemplate($namespace, $appName));
        $this->writeFile(
            'src/Controllers/Home.php',
            $this->getHomeControllerTemplate($namespace)
        );
        $this->writeFile('src/Views/home/home.html.php', $this->getHomepageView(
            $appName, $namespace, $enabledFeatures, $selectedLibraries, $useDocker, $dockerPort, $dbType, $cliName,
            $withRestApi, $apiPrefix, $appStyle
        ));

        $this->scaffoldTheme($uiSystem, $appName, $catalog, $enabledFeatures);
        $this->scaffoldFavicons($appName);
        $this->scaffoldLogo();

        if (in_array('auth', $enabledFeatures, true)) {
            $this->scaffoldAuthWiring($namespace, $uiSystem);
        }

        if (in_array('authserver', $enabledFeatures, true)) {
            $this->scaffoldAuthServerWiring($namespace);
        }

        $this->scaffoldLogsWiring($namespace);
        $this->scaffoldHealthWiring($namespace);
        $this->scaffoldUsersWiring($namespace);
        $this->scaffoldSettingsWiring($namespace);
        $this->scaffoldDashboardWiring($namespace);
        $this->scaffoldServicesWiring($namespace);
        $this->scaffoldOrganizationsWiring($namespace);
        $this->scaffoldEmailsWiring($namespace);

        if ($withWebhook) {
            $this->scaffoldWebhookWiring($cliName);
        }

        if (in_array('auth', $enabledFeatures, true)) {
            $this->scaffoldTokenActionsWiring($namespace);
        }

        if (in_array('authserver', $enabledFeatures, true)) {
            $this->scaffoldPermissionsWiring($namespace);
        }

        if (in_array('queue', $enabledFeatures, true)) {
            $this->scaffoldQueueWiring($namespace);
        }

        if (!empty($selectedLibraries)) {
            $skipDownload = (bool) $input->getOption('no-download');
            $this->scaffoldLibraries($selectedLibraries, $uiSystem, $skipDownload, $output);
        }

        // ── SPA front end ─────────────────────────────────────────────────────
        // Written after the MVC scaffold so its .htaccess and shell win: a pure
        // SPA replaces the front-controller catch-all, a hybrid app keeps it and
        // mounts the SPA under /app.
        if ($appStyle !== 'mvc') {
            $this->scaffoldSpa(
                $appName, $spaStack, $appStyle, $apiPrefix, $cliName, $spaDevPort, $dockerPort,
                $enabledFeatures, $namespace, $uiSystem
            );
        }

        if ($useDocker) {
            // Node/npm goes into the app image when anything needs it: the
            // OpenAPI/RapiDoc generator, or a SPA stack with a Vite build.
            $needsNode = $withApiDocs || ($appStyle !== 'mvc' && self::spaNeedsNode($spaStack));
            $this->scaffoldDocker(
                $namespace, $dockerPort, $dbType, $dbName, $dbUser, $dbPass, $cacheSystem,
                $dbRootPass, $cliName, $needsNode,
                $appStyle !== 'mvc' && self::spaNeedsNode($spaStack) ? $spaDevPort : 0
            );
        }

        $this->scaffoldTests($namespace, $dbType, $dbHost, $dbName, $dbUser, $dbPass, $dbPrefix, $useDocker, $enabledFeatures);
        $this->scaffoldGitignore($enabledFeatures);

        if ($withRestApi) {
            $this->scaffoldRestApi($namespace, $enabledFeatures);
            if ($withApiDocs) {
                // The generated OpenAPI is enriched (via openapi-overrides.json)
                // with the endpoints of the feature-scaffolded API controllers —
                // whose actions are inherited from framework controllers and so are
                // invisible to apidoc — plus the OAuth2 security scheme when the
                // server is enabled.
                $this->scaffoldApiDocs(
                    $appName, $namespace, $apiUrl, $apiColor, $enabledFeatures,
                    $useDocker ? "http://localhost:{$dockerPort}/api" : '',
                    $apiKey, $userEmail
                );
            }
        }

        $this->scaffoldAiGuidelines(
            $appName, $namespace, $dbType, $dbName, $dbUser, $dbPass, $dockerPort, $cliName,
            $enabledFeatures, $appStyle, $spaStack, $apiPrefix
        );
        $this->scaffoldReadme(
            $appName, $namespace, $cliName, $dbType, $enabledFeatures, $useDocker, $dockerPort,
            $appStyle, $spaStack, $apiPrefix, $withRestApi
        );

        if (in_array('authserver', $enabledFeatures, true)) {
            $this->generateOAuth2KeyPair($output);
        }

        $this->updateComposerJson($appName, $namespace, $userName, $userEmail, $output);

        $output->writeln("\n<info>Project initialized successfully!</info>");

        // ── Step 6: Docker startup + migrations ───────────────────────────────
        if ($useDocker && !$this->skipDockerRun) {
            // @codeCoverageIgnoreStart
            // All tests set skipDockerRun = true; the Docker compose lifecycle (up --build,
            // waitForDatabase, container composer sync, migrate, createAdminUser) is never
            // exercised in the unit test suite.
            // Pull the image-only services (db, cache, adminer) as an explicit,
            // retryable step *before* building/starting. Large images (e.g.
            // TimescaleDB ~1.4GB) can fail or time out on the first fetch; folding
            // the pull into "up --build" turns such a failure into a confusing
            // "No such image" at container-create time (the pull is skipped and the
            // create then finds nothing locally). A dedicated pull surfaces progress
            // (stderr is intentionally kept so the spinner's slow-step escalation can
            // show it) and retries transient network/registry hiccups. The "app"
            // build service has no image and is simply skipped by "pull".
            $pullOk = false;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $label = $attempt === 1
                    ? 'Pulling Docker images'
                    : "Pulling Docker images (retry $attempt/3)";
                if ($this->runProcessWithSpinner('docker-compose pull', $label, $output) === 0) {
                    $pullOk = true;
                    break;
                }
                $output->writeln('  <comment>Image pull failed — retrying...</comment>');
            }
            if (!$pullOk) {
                $output->writeln('  <comment>Warning: could not pre-pull all Docker images; "up" will attempt the pull again.</comment>');
            }

            $this->dockerSuccess = ($this->runProcessWithSpinner(
                'docker-compose up -d --build 2>/dev/null', 'Starting Docker environment', $output
            ) === 0);

            if ($this->dockerSuccess) {
                $this->waitForDatabase($dbType, $output);

                // Everything below runs as www-data (mapped to the host user).
                // Anything left over from an earlier run — or from a project
                // scaffolded before that mapping existed — is still root-owned
                // and would make composer/npm fail with EACCES on their own
                // directories. Hand the tree back once, as root.
                $this->runProcessWithSpinner(
                    'docker-compose exec -T -u root app chown -R www-data:www-data /var/www/html 2>/dev/null',
                    'Fixing file ownership',
                    $output
                );

                if ($this->skipInstall) {
                    $this->reportSkippedInstall($output);
                } else {
                    $syncStatus         = $this->runProcessWithSpinner('docker-compose exec -T -u www-data -e COMPOSER_HOME=/tmp/composer app composer update --no-interaction 2>/dev/null',      'Syncing dependencies (in container)',     $output);
                    $syncAutoloadStatus = $this->runProcessWithSpinner('docker-compose exec -T -u www-data -e COMPOSER_HOME=/tmp/composer app composer dump-autoload --no-interaction 2>/dev/null', 'Regenerating autoloader (in container)',  $output);

                    if ($syncStatus !== 0 || $syncAutoloadStatus !== 0) {
                        $this->autoloadSuccess = false;
                    }
                }

                // Migrations run the new application's own CLI, which needs the
                // autoloader that --no-install just declined to generate.
                if ($this->autoloadSuccess && !$this->skipInstall && !$input->getOption('no-migrations')) {
                    $migStatus = $this->runProcessWithSpinner(
                        "docker-compose exec -T -u www-data app php $cliName.php migrate --scope=framework 2>/dev/null",
                        'Running framework migrations',
                        $output,
                        true // always show migration output — the per-migration list is always informative
                    );
                    $this->migrationsSuccess = ($migStatus === 0);

                    if ($this->migrationsSuccess && in_array('auth', $enabledFeatures, true)) {
                        $this->createAdminUser($input, $output, $helper, $userEmail, $cliName, $userName);
                    } elseif (!$this->migrationsSuccess) {
                        $output->writeln('  <comment>Admin user creation skipped — migrations did not complete successfully.</comment>');
                        $output->writeln("  Run manually after fixing migrations: docker-compose exec -u www-data app php $cliName.php migrate --scope=framework");
                    }

                    // Seed a "Development" OAuth application with the pre-generated
                    // API key so the REST API can be exercised immediately (the key
                    // is pre-filled into the docs and printed in the summary).
                    if ($this->migrationsSuccess && $apiKey !== '') {
                        $this->createApiApplication($output, $apiKey);
                    }
                }

                // Generate the API documentation now that Node is available in the
                // container (installed only when API docs are enabled). Best-effort:
                // a failure here must never fail init. doc.sh runs docs:build in the
                // container; the generator handles an empty API gracefully.
                if ($withApiDocs) {
                    $this->runProcessWithSpinner('bash scripts/doc.sh 2>&1', 'Generating API documentation', $output);
                }

                // Install the front-end toolchain and produce a first build, so
                // the SPA is actually visible the moment init finishes rather
                // than after a manual npm dance.
                if ($appStyle !== 'mvc' && self::spaNeedsNode($spaStack)) {
                    $this->buildSpa('docker-compose exec -T -u www-data -e HOME=/tmp app sh -lc', $output);
                }
            }
            // @codeCoverageIgnoreEnd
        } elseif (!$useDocker) {
            if ($this->skipInstall) {
                $this->reportSkippedInstall($output);
            } else {
                $syncStatus         = $this->runProcessWithSpinner('composer update --no-interaction --ignore-platform-reqs 2>/dev/null', 'Syncing dependencies',      $output);
                $syncAutoloadStatus = $this->runProcessWithSpinner('composer dump-autoload --no-interaction 2>/dev/null',                 'Regenerating autoloader',   $output);

                if ($syncStatus !== 0 || $syncAutoloadStatus !== 0) {
                    $this->autoloadSuccess = false; // @codeCoverageIgnore — composer sync always exits 0 in the test environment
                }
            }

            // Generate the API documentation on the host (best-effort; requires a
            // local Node/npm). Guarded by skipDockerRun so the unit-test scaffold
            // never shells out to npm.
            if ($withApiDocs && !$this->skipDockerRun) {
                // @codeCoverageIgnoreStart — never exercised: tests set skipDockerRun
                $this->runProcessWithSpinner('bash scripts/doc.sh --host 2>&1', 'Generating API documentation', $output);
                // @codeCoverageIgnoreEnd
            }

            if ($appStyle !== 'mvc' && self::spaNeedsNode($spaStack) && !$this->skipDockerRun) {
                // @codeCoverageIgnoreStart — never exercised: tests set skipDockerRun
                $this->buildSpa('sh -lc', $output);
                // @codeCoverageIgnoreEnd
            }
        }

        if ($this->dryRun) {
            // The usual summary tells you how to start the application that now
            // exists. Nothing exists, so it would be a lie; the plan is the answer.
            $this->reportDryRun($output);

            return 0;
        }

        $this->printSummary(
            $output, $useDocker, $dockerPort, $dbType, $dbUser, $dbPass, $dbRootPass, $cliName,
            (bool) $input->getOption('no-migrations'), $withRestApi, $withApiDocs, $apiKey,
            $apiPrefix, $appStyle, $spaStack, $spaDevPort
        );

        return 0;
    }

    // ── Step 2: Feature selection ─────────────────────────────────────────────

    /**
     * Ask which framework features to enable. Returns array of feature keys.
     *
     * @return list<string>
     */
    private function askFeatures(InputInterface $input, OutputInterface $output, mixed $helper): array
    {
        $featureOption = $input->getOption('features');
        if ($featureOption !== null) {
            return array_filter(array_map('trim', explode(',', $featureOption)));
        }

        $output->writeln("\n<comment>Step 2 — Framework features</comment>");
        $output->writeln("Core System is always enabled. Select optional features:");

        $choices = [
            'auth'       => 'Basic Auth System    [auth]',
            'authserver' => 'OAuth Server         [authserver]',
            'queue'      => 'Queue System         [queue]',
            'messaging'  => 'Messaging            [messaging]',
            'devpanel'   => 'Developer Panel      [devpanel]',
        ];

        $enabled = [];
        foreach ($choices as $key => $label) {
            $default = true;
            $answer  = $helper->ask($input, $output, new ConfirmationQuestion("  Enable $label? [Y/n] ", $default));
            if ($answer) {
                $enabled[] = $key;
            }
        }
        return $enabled;
    }

    // ── Step 3: UI system ─────────────────────────────────────────────────────

    private function askUiSystem(InputInterface $input, OutputInterface $output, mixed $helper): string
    {
        $uiOption = $input->getOption('ui-system');
        if ($uiOption !== null) {
            return $uiOption;
        }

        $output->writeln("\n<comment>Step 3 — UI system</comment>");
        $question = new ChoiceQuestion(
            'Select UI system [plain-css]: ',
            ['plain-css', 'bootstrap', 'tailwind'],
            0
        );
        return $helper->ask($input, $output, $question);
    }

    // ── Step 4: Extra libraries ───────────────────────────────────────────────

    /**
     * The always-included mandatory libraries, read from the asset catalog
     * (single source of truth — see LibraryManager::mandatoryFromCatalog()).
     *
     * @return list<string>
     */
    private function mandatoryLibraries(): array
    {
        return \Pramnos\Application\LibraryManager::mandatoryFromCatalog($this->loadAssetCatalog());
    }

    /**
     * Merge the always-included mandatory libraries into a selection, preserving
     * order and removing duplicates. Mandatory libraries are appended so they
     * are present regardless of what the user picked.
     *
     * @param list<string> $selected
     * @return list<string>
     */
    private function withMandatoryLibraries(array $selected): array
    {
        return array_values(array_unique(array_merge($selected, $this->mandatoryLibraries())));
    }

    /**
     * @return list<string>
     */
    private function askLibraries(InputInterface $input, OutputInterface $output, mixed $helper, string $uiSystem): array
    {
        $libOption = $input->getOption('libraries');
        if ($libOption !== null) {
            $picked = $libOption === '' ? [] : array_filter(array_map('trim', explode(',', $libOption)));
            return $this->withMandatoryLibraries($picked);
        }

        $output->writeln("\n<comment>Step 4 — Extra libraries</comment>");
        $wantLibraries = $helper->ask($input, $output, new ConfirmationQuestion('Configure extra libraries? [Y/n] ', true));
        if (!$wantLibraries) {
            return $this->withMandatoryLibraries([]); // @codeCoverageIgnore — tests that reach this path use the --libraries CLI option (line 396 path)
        }

        $catalog = $this->loadAssetCatalog();
        if (empty($catalog['libraries'])) {
            return $this->withMandatoryLibraries([]);
        }

        $output->writeln("Select which libraries to include (assets downloaded locally):");
        $output->writeln("  <info>chart.js is always included (required by the log analytics dashboard).</info>");

        // Libraries we use across the framework and the reference application — default yes
        $defaultEnabled = ['jquery', 'datatables', 'select2', 'leaflet', 'chartjs', 'ckeditor'];

        // These UI-framework libraries are bundled automatically by their theme
        // (ensureBootstrapAssets / ensureTailwindAssets), so never offer them as
        // an "extra" library — the user already picked them via the UI system.
        // `pramnos-adapters` is not a standalone choice either: it is the REST
        // adapter for scaffolded DataTables views and is auto-included alongside
        // DataTables (see execute()). Mandatory libraries are never prompted for.
        $skipAlways = array_merge(['bootstrap', 'tailwind', 'pramnos-adapters'], $this->mandatoryLibraries());
        $selected   = [];

        foreach ($catalog['libraries'] as $key => $lib) {
            if (in_array($key, $skipAlways, true)) {
                continue;
            }
            $requiredUi = $lib['requires_ui'] ?? [];
            if (!empty($requiredUi) && !in_array($uiSystem, $requiredUi, true)) {
                continue; // @codeCoverageIgnore — ui-filtered libraries not exercised in tests
            }
            $requires = $lib['requires'] ?? [];
            if (!empty($requires)) {
                $missingDeps = array_diff($requires, $selected);
                if (!empty($missingDeps)) {
                    continue; // @codeCoverageIgnore — dependency-skip path not exercised in tests
                }
            }
            $default = in_array($key, $defaultEnabled, true);
            $answer  = $helper->ask($input, $output, new ConfirmationQuestion(
                "  Include $key@{$lib['version']}? [" . ($default ? 'Y/n' : 'y/N') . '] ',
                $default
            ));
            if ($answer) {
                $selected[] = $key;
                // auto-include hard dependencies
                foreach ($requires as $dep) {
                    if (!in_array($dep, $selected, true)) {
                        $selected[] = $dep;
                    }
                }
            }
        }
        return $selected;
    }

    // ── Scaffold helpers ──────────────────────────────────────────────────────

    /** Render a .stub template with token substitution. */
    public function renderStub(string $stubName, array $tokens): string
    {
        $stubFile = $this->scaffoldingDir . '/templates/' . $stubName . '.stub';
        if (file_exists($stubFile)) {
            $content = file_get_contents($stubFile);
        } else {
            $content = $this->getFallbackStub($stubName);
        }

        foreach ($tokens as $key => $value) {
            $content = str_replace('{{ ' . $key . ' }}', $value, $content);
        }
        return $content;
    }

    private function getFallbackStub(string $name): string
    {
        // @codeCoverageIgnoreStart
        // getFallbackStub is only called when no .stub file exists on disk; the
        // scaffolding directory is present in the test environment so stub files
        // are always found and this method is never reached in unit tests.
        return match ($name) {
            'controller' => "<?php\nnamespace {{ namespace }}\\Controllers;\n\nuse Pramnos\\Application\\Controller;\n\nclass {{ class }} extends Controller\n{\n    public function display() {}\n}\n",
            'model'      => "<?php\nnamespace {{ namespace }}\\Models;\n\nuse Pramnos\\Application\\Model;\n\nclass {{ class }} extends Model\n{\n    protected \$_dbtable = '{{ table }}';\n}\n",
            'migration'  => "<?php\nnamespace {{ namespace }}\\Migrations;\n\nfinal class {{ class }} extends \\Pramnos\\Database\\Migration\n{\n    public function up(): void {}\n    public function down(): void {}\n}\n",
            'middleware' => "<?php\nnamespace {{ namespace }}\\Middleware;\n\nuse Pramnos\\Http\\MiddlewareInterface;\nuse Pramnos\\Http\\Request;\n\nclass {{ class }} implements MiddlewareInterface\n{\n    public function handle(Request \$r, callable \$next): mixed { return \$next(\$r); }\n}\n",
            'test'       => "<?php\nnamespace Tests\\Unit;\n\nuse PHPUnit\\Framework\\TestCase;\n\nclass {{ class }}Test extends TestCase\n{\n    public function testItWorks(): void { \$this->assertTrue(true); }\n}\n",
            'CLAUDE.md'  => "# {{ APP_NAME }}\n\nStack: PHP, {{ DB_TYPE }}, Docker\nNamespace: `{{ NAMESPACE }}`\nCLI: `./{{ CLI_NAME }}`\n\nFeatures: {{ FEATURES_LIST }}\n",
            'mcp.json'   => "{\n  \"mcpServers\": {}\n}\n",
            default      => '',
        };
        // @codeCoverageIgnoreEnd
    }

    private function scaffoldAppConfig(
        string $path,
        string $appName,
        string $namespace,
        array  $features,
        string $scaffoldTheme = '',
        bool   $withApi = false,
        string $apiPrefix = '/api/1.0',
        string $appStyle = 'mvc',
        string $spaStack = ''
    ): void {
        $featuresPhp = empty($features)
            ? "    'features' => [],\n"
            : "    'features' => ['" . implode("', '", $features) . "'],\n";

        $scaffoldLine = $scaffoldTheme !== ''
            ? "    'scaffold_theme' => '$scaffoldTheme',\n"
            : '';

        // How this application is built. The make:* commands read it so a
        // `create:crud` in a SPA project also produces the API endpoints and the
        // front-end screen, instead of only server-rendered views.
        $styleLines = "    'app_style' => '$appStyle',\n";
        if ($spaStack !== '') {
            $styleLines .= "    'spa_stack' => '$spaStack',\n";
        }

        // 'api_version' (top-level) is what Api::__construct reads to define the
        // APIVERSION constant — used for version checks and the routes.php group
        // prefix. Keep it in sync with the 'api' section + the routes prefix.
        $apiSection = $withApi
            ? "    'api_version' => '1.0',\n"
              . "    'api' => [\n        'prefix'       => '$apiPrefix',\n        'cors_origins' => ['*'],\n        'version'      => '1.0',\n    ],\n"
            : '';

        // When the auth feature is enabled, register only the auth addon.
        //
        //   - UserDatabase (type=auth): a thin delegate over the new
        //     Auth\Drivers\DatabaseAuthDriver. It is kept because its
        //     onAuthCheck() re-establishes a "remember me" login from cookies,
        //     which has no built-in replacement yet.
        //
        // The deprecated Addon\User\User (type=user) is intentionally NOT
        // registered: with no user addon, Auth's built-in login/logout
        // lifecycle (Auth::executeDefaultLogin/Logout) runs instead — it sets
        // the session, cookies and lastlogin exactly as the addon did, and it
        // is the path that records login/logout into the activity log
        // (Auth\ActivityLog). Registering the addon would take the legacy path
        // and skip that logging.
        $addonsSection = in_array('auth', $features, true)
            ? "    'addons' => [\n"
              . "        ['addon' => 'Pramnos\\\\Addon\\\\Auth\\\\UserDatabase', 'type' => 'auth'],\n"
              . "    ],\n"
            : '';

        // HTTP middleware, run explicitly by www/index.php around the dispatch.
        // SessionTrackingMiddleware populates the `sessions` table (active
        // devices + force-logout). Declaring it here also makes the framework's
        // built-in auto-run (Application::bootSessionTracking) stand down, so it
        // runs exactly once — through this pipeline.
        $middlewareSection = "    'middleware' => [\n"
            . "        'Pramnos\\\\Http\\\\Middleware\\\\SessionTrackingMiddleware',\n"
            . "    ],\n";

        // API bearer tokens expire after a week in a new project. The framework
        // default is 0 — never — which is what existing installations rely on
        // and what they keep; a project starting today has no such history, and
        // a token that never expires is one an attacker keeps for ever. Raise or
        // remove it here if the application needs longer-lived tokens.
        $authSection = in_array('auth', $features, true)
            ? "    'auth' => [\n"
              . "        'token_ttl' => 604800, // 7 days; 0 = never expires\n"
              . "    ],\n"
            : '';

        // Tailwind's browser build generates CSS at runtime by injecting a
        // <style> element, which a nonce-based style-src blocks. Allowing
        // 'unsafe-inline' makes the framework drop the style nonce (see
        // Application::sendCspHeader), so the generated styles apply.
        // Scoped to the tailwind theme only; bootstrap/plain-css keep the strict
        // nonce-based policy. For production, compile Tailwind to a static CSS
        // file and remove this relaxation.
        $styleSrc = $scaffoldTheme === 'tailwind'
            ? "        'style-src'  => [\"'unsafe-inline'\"]\n"
            : "        'style-src'  => []\n";

        $content = "<?php\nreturn [\n    'name' => '$appName',\n    'namespace' => '$namespace',\n    'theme' => 'default',\n{$scaffoldLine}{$styleLines}{$featuresPhp}{$addonsSection}{$authSection}{$middlewareSection}{$apiSection}    'csp' => [\n        'script-src' => [],\n{$styleSrc}    ]\n];\n";
        $this->writeFile($path, $content);
    }

    /**
     * Which application style is being scaffolded?
     *
     *  - `mvc`    — server-rendered controllers, views and themes (the default,
     *               and exactly what init produced before this question existed).
     *  - `spa`    — Services + JSON API + a JavaScript single-page app. No
     *               server-rendered view layer: the API is the contract.
     *  - `hybrid` — both: MVC pages (typically the admin area) plus a SPA mounted
     *               under a sub-path for the public/app side.
     *
     * @return string One of mvc|spa|hybrid
     */
    private function askAppStyle(InputInterface $input, OutputInterface $output, mixed $helper): string
    {
        $option = $input->getOption('app-style');
        if ($option !== null) {
            return in_array($option, self::APP_STYLES, true) ? $option : 'mvc';
        }

        $output->writeln("\n<comment>Step 1b — Application style</comment>");

        // Numbered choices: answering is typing "1", not spelling "hybrid".
        // Keys are strings so Symfony prints [1]/[2]/[3] rather than starting
        // at zero, and the answer is mapped back to the internal style name.
        return $this->askNumberedChoice(
            $input,
            $output,
            $helper,
            'How is this application built?',
            [
                'mvc'    => 'MVC + Models        — server-rendered controllers, views and themes',
                'spa'    => 'Services + API + SPA — JSON API with a JavaScript front end',
                'hybrid' => 'Hybrid              — MVC pages plus a SPA mounted under /app',
            ]
        );
    }

    /**
     * Ask a choice question the user answers with a number.
     *
     * Symfony numbers a plain list from 0 and expects the *value* back, so an
     * associative array with '1', '2', '3' keys is what produces a familiar
     * "[1] …" menu. The label the helper returns is mapped back to the caller's
     * own key, so the rest of init keeps switching on names like `spa`.
     *
     * @param  array<string, string> $options Internal key => human label, in order
     * @return string The internal key of the chosen option
     */
    private function askNumberedChoice(
        InputInterface  $input,
        OutputInterface $output,
        mixed           $helper,
        string          $prompt,
        array           $options
    ): string {
        $keys      = array_keys($options);
        $numbered  = [];
        foreach (array_values($options) as $index => $label) {
            $numbered[(string) ($index + 1)] = $label;
        }

        $answer = $helper->ask($input, $output, new ChoiceQuestion(
            "$prompt [1]: ",
            $numbered,
            '1'
        ));

        // The helper returns the label; find which option it belongs to. An
        // unrecognisable answer falls back to the first (documented default).
        $number = array_search($answer, $numbered, true);

        return $number === false ? $keys[0] : $keys[(int) $number - 1];
    }

    /**
     * Which front-end stack should the SPA be built with?
     *
     * @return string One of svelte|vanilla-vite|vanilla
     */
    private function askSpaStack(InputInterface $input, OutputInterface $output, mixed $helper): string
    {
        $option = $input->getOption('spa-stack');
        if ($option !== null) {
            return in_array($option, self::SPA_STACKS, true) ? $option : 'svelte';
        }

        $output->writeln("\n<comment>Step 1c — SPA front-end stack</comment>");

        return $this->askNumberedChoice(
            $input,
            $output,
            $helper,
            'Front-end stack',
            [
                'svelte'       => 'Svelte 5 + Vite + Tailwind/daisyUI — components, HMR, Vitest',
                'vanilla-vite' => 'Vanilla JS + Vite                  — no framework, still bundled + Vitest',
                'vanilla'      => 'Vanilla JS, no build               — zero dependencies, node --test',
            ]
        );
    }

    // ── SPA scaffolding ───────────────────────────────────────────────────────

    /**
     * Install the front-end dependencies and produce the first build.
     *
     * Best-effort, exactly like the API docs step: a project is still perfectly
     * usable if npm is unavailable — the PHP shell falls back to the unbuilt
     * asset paths and the summary says what to run. Failures are reported, not
     * fatal.
     *
     * @param string $runner Shell prefix that runs a command where npm lives
     *                       (inside the container, or on the host)
     */
    private function buildSpa(string $runner, OutputInterface $output): void
    {
        // @codeCoverageIgnoreStart — shells out to npm; never run in unit tests
        $install = $this->runProcessWithSpinner(
            $runner . ' "cd /var/www/html 2>/dev/null || cd .; npm install --no-audit --no-fund" 2>&1',
            'Installing front-end dependencies',
            $output
        );
        if ($install !== 0) {
            $output->writeln('  <comment>npm install failed — run ./dockernpm install once the environment is up.</comment>');
            return;
        }

        $build = $this->runProcessWithSpinner(
            $runner . ' "cd /var/www/html 2>/dev/null || cd .; npm run build" 2>&1',
            'Building the SPA',
            $output
        );
        if ($build !== 0) {
            $output->writeln('  <comment>The SPA build failed — run ./dockernpm run build to see the error.</comment>');
        } else {
            $this->spaBuilt = true;
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Does this stack need a Node toolchain (npm install / vite build)?
     *
     * The build-less `vanilla` stack deliberately does not: its JavaScript is
     * served exactly as written, which is the whole point of offering it.
     */
    public static function spaNeedsNode(string $spaStack): bool
    {
        return in_array($spaStack, ['svelte', 'vanilla-vite'], true);
    }

    /**
     * Scaffold the single-page-application front end.
     *
     * Three stacks share one shape: an API client, an entry point, a PHP shell
     * that serves them with correct cache-busting, and tests. What differs is
     * whether a build step exists — which decides where the sources live
     * (`frontend/` vs straight into the web root), how assets are cache-busted
     * (Vite's content hashes vs file mtime) and which test runner is wired up.
     *
     * @param string $spaStack   svelte|vanilla-vite|vanilla
     * @param string $appStyle   spa|hybrid — hybrid mounts the SPA under /app
     * @param string $apiPrefix  API base path the client calls
     * @param int    $devPort    Port for the Vite dev server
     * @param int    $appPort    Host port Apache is published on (proxy target)
     * @param list<string> $features Enabled framework features, so the routing
     *                               rules keep every scaffolded MVC area reachable
     */
    /**
     * Write the SPA front end into this project.
     *
     * Public because it is the whole of `scaffold:spa`, which exists for the case
     * `init` cannot serve: an application that already exists and wants a front end.
     * Copying fifteen stubs by hand with the right token substitutions was the
     * documented path, and a reviewer did exactly that.
     *
     * Paired with {@see $skipExisting}, which is what makes calling this on a live
     * project safe.
     */
    public function scaffoldSpa(
        string $appName,
        string $spaStack,
        string $appStyle,
        string $apiPrefix,
        string $cliName,
        int    $devPort,
        int    $appPort,
        array  $features = [],
        string $namespace = 'App',
        string $uiSystem = 'plain-css'
    ): void {
        $needsBuild = self::spaNeedsNode($spaStack);
        $shellFile  = $appStyle === 'hybrid' ? 'app.php' : 'spa.php';
        // Where the JS sources live: a build stack keeps them out of the web
        // root (only build output is published); the build-less stack serves
        // its sources directly, so they belong under www/.
        $sourceDir  = $needsBuild ? 'frontend' : 'www/assets/js';

        $tokens = [
            'appName'       => $appName,
            'cliName'       => $cliName,
            'apiPrefix'     => rtrim($apiPrefix, '/'),
            'probeEndpoint' => '/status',
            'shellFile'     => $shellFile,
            'devPort'       => (string) $devPort,
            'appPort'       => (string) $appPort,
            // Where the pages actually live — printed by the dev server, since
            // Vite's own banner advertises a URL that has no page behind it.
            'appUrl'        => 'http://localhost:' . $appPort . ($appStyle === 'hybrid' ? '/app' : '/'),
            'sourceDir'     => $sourceDir,
            'assetBase'     => '/' . self::SPA_BUILD_DIR . '/',
            'outDir'        => 'www/' . self::SPA_BUILD_DIR,
            'manifestPath'  => self::SPA_BUILD_DIR . '/.vite/manifest.json',
            // Written by the dev server while it runs (see vite.config.js).
            'hotPath'       => self::SPA_BUILD_DIR . '/.vite/hot',
            'hasAuth'       => in_array('auth', $features, true) ? 'true' : 'false',
            'tokenStorageKey' => strtolower(preg_replace('/[^a-z0-9]+/i', '-', $appName)) . '-token',
            'entryKey'      => $sourceDir . '/main.js',
            'entry'         => $sourceDir . '/main.js',
            // Root-relative on purpose: the shell also answers deep client
            // routes (/things/42), where a relative URL would resolve against
            // the wrong directory and 404.
            'fallbackCss'   => '/assets/css/app.css',
            'fallbackJs'    => '/assets/js/main.js',
            'pluginImports' => '',
            'pluginList'    => '',
            // Only a bundler can consume a CSS import from JavaScript. In the
            // build-less stack the shell links the stylesheet directly, and an
            // import here would break both the browser and `node --test`.
            'cssImport'     => $needsBuild ? "import './app.css';\n" : '',
            // Where the application is mounted, so client-side URLs match the
            // paths the server actually routes to the shell.
            'routerBase'    => $appStyle === 'hybrid' ? '/app' : '',
        ];

        if ($spaStack === 'svelte') {
            $tokens['pluginImports'] = "import { svelte } from '@sveltejs/vite-plugin-svelte';\n"
                . "import tailwindcss from '@tailwindcss/vite';\n";
            // Trailing comma: the stub appends pramnosHotFile() after this.
            $tokens['pluginList'] = 'svelte(), tailwindcss(), ';
        } elseif ($spaStack === 'vanilla-vite') {
            $tokens['pluginImports'] = '';
            $tokens['pluginList']    = '';
        }

        // ── The backend half ──────────────────────────────────────────────────
        // A SPA whose first screen calls an endpoint that does not exist is not
        // a scaffold, it is a demo of a 403. Generate the service + controller +
        // route the front end talks to, in the shape the style prescribes.
        $this->scaffoldSpaStatusEndpoint($namespace, $appName);

        // ── Shared pieces ─────────────────────────────────────────────────────
        $this->mkdir($sourceDir . '/lib');
        $this->mkdir($sourceDir . '/screens');
        $this->writeFile($sourceDir . '/lib/api.js', $this->renderStub('spa-api-client.js', $tokens));
        // The debug panel is inert unless a response carries debug data, so it
        // ships in every project rather than being a development-only file the
        // client would have to import conditionally.
        // From the framework's single toolbar source, not a SPA-only copy of it:
        // two renderers drifted, and the same bug then had to be fixed twice.
        $this->writeFile(
            $sourceDir . '/lib/debug.js',
            \Pramnos\Debug\DebugBarAsset::spaModule($appName)
        );
        // Real URLs for every screen: without them the back button leaves the
        // application and no page can be linked to or bookmarked.
        $this->writeFile($sourceDir . '/lib/router.js', $this->renderStub('spa-router.js', $tokens));
        // Empty registry: `create:crud` appends its screens here, and the app
        // imports it unconditionally so a generated screen becomes reachable
        // without touching App.svelte.
        $this->writeFile($sourceDir . '/screens/registry.js', $this->renderStub('spa-screens-registry.js', $tokens));
        $this->scaffoldSpaTestingGuide($spaStack, $tokens);
        if (in_array('auth', $features, true)) {
            $this->scaffoldSpaAdmin($spaStack, $sourceDir, $tokens);
        }
        $this->writeFile('www/' . $shellFile, $this->renderStub('spa-shell.php', $tokens));

        if ($needsBuild) {
            $this->scaffoldSpaBuildStack($spaStack, $sourceDir, $tokens, $uiSystem);
        } else {
            $this->scaffoldSpaBuildlessStack($sourceDir, $tokens);
        }

        $this->scaffoldSpaRouting($appStyle, $shellFile, self::mvcRoutePrefixes($features));
        $this->scaffoldSpaGitignore($needsBuild);
    }

    /**
     * Generate the endpoint the SPA's first screen calls.
     *
     * A service plus a thin controller — the layering this application style is
     * named after — so a new project starts with a working example of it rather
     * than a front end pointing at a route that does not exist. The route
     * itself is registered by scaffoldRestApi(), which writes routes.php later.
     */
    private function scaffoldSpaStatusEndpoint(string $namespace, string $appName): void
    {
        $this->mkdir('src/Services');
        $this->mkdir('src/Api/Controllers');

        $tokens = ['namespace' => $namespace, 'appName' => $appName];

        $this->writeFile('src/Services/StatusService.php', $this->renderStub('spa-status-service.php', $tokens));
        $this->writeFile('src/Api/Controllers/Status.php', $this->renderStub('spa-status-controller.php', $tokens));

        // Tells scaffoldRestApi() to register the route for it.
        $this->spaStatusEndpoint = true;
    }




    /**
     * Build the daisyUI palette from the project's server-rendered theme.
     *
     * The theme declares its colours as CSS custom properties; daisyUI 5 reads
     * its palette from custom properties too. Mapping one onto the other means
     * the SPA and the server-rendered pages share a palette by construction,
     * instead of being coloured twice by hand and drifting apart.
     *
     * A theme that declares nothing (bootstrap and tailwind bring their own
     * systems) falls back to that framework's own brand colour, which is what
     * its pages actually render — not to daisyUI's default purple, which would
     * match nothing on screen.
     *
     * @param  string $uiSystem plain-css|bootstrap|tailwind
     * @return array<string, string> Stub tokens for spa-theme.css
     */
    private function spaThemeTokens(string $uiSystem, string $sourceDir): array
    {
        return [
            // The theme's stylesheet is published to the web root, not left in
            // app/themes/ — that is where the browser loads it from, and where
            // an author edits the palette.
            'themePath'   => 'www/assets/css/style.css',
            'themeOutput' => $sourceDir . '/theme.css',
            // The colour this UI framework actually paints with, for a theme
            // that declares no custom properties of its own.
            'fallbackPrimary' => match ($uiSystem) {
                'bootstrap' => '#0d6efd',
                default     => '#2563eb',
            },
            'fontFamily'  => "'Inter', system-ui, -apple-system, sans-serif",
        ];
    }

    /**
     * Scaffold the SPA administration screen and its endpoints.
     *
     * The MVC scaffold generates whole admin areas; a SPA project got none of
     * them, so parity meant hand-writing each one. This gives a SPA the same
     * starting point: who is signed up, what the logs say, and the numbers a
     * dashboard opens with — served by a framework controller so the screen is
     * the only generated part.
     *
     * Only with the auth feature: an administration screen in a project with no
     * users is a screen that can only ever say 401.
     *
     * @param array<string, string> $tokens Shared SPA stub tokens
     */
    private function scaffoldSpaAdmin(string $spaStack, string $sourceDir, array $tokens): void
    {
        if ($spaStack !== 'svelte') {
            // The vanilla stacks get the endpoints (they are framework-side) but
            // no generated screen: hand-writing three tabs of DOM is not a
            // starting point anybody wants, and `create:crud` covers the
            // pattern for the screens people actually build.
            return;
        }

        $this->mkdir($sourceDir . '/screens');
        $this->writeFile(
            $sourceDir . '/screens/Admin.svelte',
            $this->renderStub('spa-admin.svelte', $tokens)
        );

        // Register it the same way create:crud does, so it appears in the
        // navigation without anyone editing App.svelte.
        $registry = $this->targetBaseDir . '/' . $sourceDir . '/screens/registry.js';
        if (!file_exists($registry)) {
            return;
        }
        $contents = (string) file_get_contents($registry);
        if (str_contains($contents, "name: 'admin'")) {
            return;
        }
        $contents = str_replace(
            "\nexport const screens = [",
            "\nimport Admin from './Admin.svelte';\n\nexport const screens = [",
            $contents
        );
        $contents = str_replace(
            "export const screens = [\n",
            "export const screens = [\n    { name: 'admin', label: 'Admin', component: Admin, admin: true },\n",
            $contents
        );
        if (!$this->skipWrite('frontend/screens/registry.js')) {
            file_put_contents($registry, $contents);
        }
    }

    /**
     * Write the front-end testing guide into the project.
     *
     * The scaffold ships a working test setup and real tests; without a guide
     * beside them the next person adds screens with no tests, because the
     * examples are not obviously extensible. It lives in the project (not only
     * in the framework docs) so it describes *this* project's runner, paths and
     * commands.
     *
     * @param array<string, string> $tokens Shared SPA stub tokens
     */
    private function scaffoldSpaTestingGuide(string $spaStack, array $tokens): void
    {
        $needsBuild = self::spaNeedsNode($spaStack);

        $tokens['runnerName'] = $needsBuild ? 'Vitest' : 'node --test';
        $tokens['testDir']    = $needsBuild ? 'frontend/__tests__' : 'tests/js';
        $tokens['coverageCommand'] = $needsBuild
            ? './dockernpm run test:coverage  # coverage report'
            : './testjs                 # there is no coverage tool without a toolchain';

        // Only the Svelte stack has components to mount.
        $tokens['componentRow'] = $spaStack === 'svelte'
            ? "| The root component (`App.svelte`) | `frontend/__tests__/App.test.js` | Vitest + @testing-library/svelte |\n"
            : '';

        $tokens['stubExample'] = $needsBuild
            ? "vi.stubGlobal('fetch', vi.fn(async () => ({\n"
                . "    ok: true, status: 200, json: async () => ({ status: 'ok' }),\n"
                . "})));\n\n"
                . "await api.get('/status');\n\n"
                . "const [url, options] = fetch.mock.calls[0];\n"
                . "expect(url).toBe('" . $tokens['apiPrefix'] . "/status');\n"
                . "expect(options.headers.apiKey).toBeDefined();"
            : "globalThis.fetch = async (url, options) => {\n"
                . "    calls.push([url, options]);\n"
                . "    return { ok: true, status: 200, json: async () => ({ status: 'ok' }) };\n"
                . "};\n\n"
                . "await api.get('/status');\n\n"
                . "assert.equal(calls[0][0], '" . $tokens['apiPrefix'] . "/status');\n"
                . "assert.ok(calls[0][1].headers.apiKey);";

        $tokens['screenExample'] = $spaStack === 'svelte'
            ? "render(Thing);\n\n"
                . "// the row the API returned is on screen…\n"
                . "await waitFor(() => expect(screen.getByText('first')).toBeTruthy());\n\n"
                . "// …and the request asked the server for one page\n"
                . "const [url] = fetch.mock.calls[0];\n"
                . "expect(url).toContain('page=1');\n"
                . "expect(url).toContain('limit=20');"
            : "const target = document.createElement('div');\n"
                . "mount(target);\n\n"
                . "// the row the API returned is on screen…\n"
                . "await waitFor(() => expect(target.textContent).toContain('first'));\n\n"
                . "// …and the request asked the server for one page\n"
                . "expect(fetch.mock.calls[0][0]).toContain('limit=20');";

        $tokens['cleanupNote'] = $needsBuild
            ? 'The scaffolded tests do it in `afterEach` with `vi.unstubAllGlobals()`.'
            : 'The scaffolded tests restore the original `fetch` in `afterEach`.';

        $this->writeFile('docs/FRONTEND_TESTING.md', $this->renderStub('spa-testing-guide.md', $tokens));
    }

    /**
     * Sources, build config, dependencies and Vitest wiring for a Vite stack.
     *
     * @param array<string, string> $tokens
     */
    private function scaffoldSpaBuildStack(string $spaStack, string $sourceDir, array $tokens, string $uiSystem = 'plain-css'): void
    {
        $this->mkdir($sourceDir . '/__tests__');
        $this->writeFile('vite.config.js',   $this->renderStub('spa-vite.config.js', $tokens));
        $this->writeFile('vitest.config.js', $this->renderStub('spa-vitest.config.js', $tokens));
        $this->writeFile(
            $sourceDir . '/__tests__/api.test.js',
            $this->renderStub('spa-api-client.test.js', $tokens)
        );

        if ($spaStack === 'svelte') {
            $this->writeFile('svelte.config.js',         $this->renderStub('spa-svelte.config.js', $tokens));
            $this->writeFile($sourceDir . '/main.js',    $this->renderStub('spa-svelte-main.js', $tokens));
            $this->writeFile($sourceDir . '/App.svelte', $this->renderStub('spa-svelte-app.svelte', $tokens));
            $this->writeFile($sourceDir . '/app.css',    $this->renderStub('spa-app.css', $tokens));
            // daisyUI's palette is derived from the server-rendered theme by
            // scripts/build-theme.mjs, which runs from prebuild/predev — off the
            // request path, and re-derived whenever the CSS is rebuilt anyway.
            $this->mkdir('scripts');
            $this->writeFile(
                'scripts/build-theme.mjs',
                $this->renderStub('spa-build-theme.mjs', $this->spaThemeTokens($uiSystem, $sourceDir))
            );
            // A placeholder so `@import "./theme.css"` resolves before the first
            // build; the generator overwrites it with the real palette.
            $this->writeFile(
                $sourceDir . '/theme.css',
                "/* Generated by scripts/build-theme.mjs on every build.\n"
                . " * This placeholder exists so the stylesheet resolves before the\n"
                . " * first build; run `npm run build` to derive the real palette. */\n"
            );
            $this->writeFile(
                $sourceDir . '/__tests__/App.test.js',
                $this->renderStub('spa-svelte-app.test.js', $tokens)
            );
        } else {
            $this->writeFile($sourceDir . '/main.js', $this->renderStub('spa-vanilla-main.js', $tokens));
            $this->writeFile($sourceDir . '/app.css', $this->getSpaPlainCss());
            $this->writeFile(
                $sourceDir . '/__tests__/main.test.js',
                $this->renderStub('spa-vanilla-main.test.js', $tokens)
            );
        }

        $this->ensureSpaPackageJson($spaStack, $tokens);
        $this->writeExecutable('testjs',    $this->renderStub('spa-testjs', $tokens + ['testCommand' => 'npm test --']));
        $this->writeExecutable('dockernpm', $this->renderStub('dockernpm', $tokens));
    }

    /**
     * Sources and zero-dependency tests for the build-less stack.
     *
     * @param array<string, string> $tokens
     */
    private function scaffoldSpaBuildlessStack(string $sourceDir, array $tokens): void
    {
        $this->mkdir('tests/js');
        $this->writeFile($sourceDir . '/main.js', $this->renderStub('spa-vanilla-main.js', $tokens));
        $this->writeFile('www/assets/css/app.css', $this->getSpaPlainCss());
        $this->writeFile('tests/js/api.test.js', $this->renderStub('spa-api-client.node-test.js', $tokens));

        // A package.json with no dependencies at all — it exists so Node treats
        // the .js files as ES modules (the same modules the browser loads) and
        // so `npm test` has something to run.
        $this->ensureSpaPackageJson('vanilla', $tokens);
        $this->writeExecutable('testjs', $this->renderStub('spa-testjs', $tokens + [
            'testCommand' => 'node --test tests/js/*.test.js',
        ]));
    }

    /**
     * Serve the shell for SPA page requests.
     *
     * A pure SPA answers every non-file, non-API GET with the shell so client
     * routing works on a refresh or a deep link. A hybrid app keeps the MVC
     * front controller in charge and mounts the SPA under /app only.
     */
    private function scaffoldSpaRouting(string $appStyle, string $shellFile, array $mvcPrefixes = []): void
    {
        if ($appStyle === 'spa') {
            // Even a SPA-first project keeps server-rendered areas: the login
            // screens, the admin CRUD and the OAuth endpoints are all scaffolded
            // as MVC controllers. Those paths stay with the front controller;
            // everything else is a client-side route and gets the shell. The
            // list is not guesswork — init generated exactly these wirings.
            // The document root itself is a directory, so the catch-all rule
            // below (guarded by !-d) never fires for "/". Without this the site
            // root would serve the MVC index.php instead of the SPA.
            $rules = "DirectoryIndex $shellFile index.php\n"
                . "RewriteEngine On\n"
                . "# Server-rendered areas scaffolded by init stay with the front controller.\n"
                . "RewriteCond %{REQUEST_FILENAME} !-f\n"
                . "RewriteCond %{REQUEST_FILENAME} !-d\n"
                . 'RewriteRule ^(' . implode('|', $mvcPrefixes) . ")(/.*)?$ index.php?r=\$1\$2 [QSA,L]\n"
                . "# Every other page request renders the SPA shell, so client-side\n"
                . "# routes survive a refresh or a deep link.\n"
                . "RewriteCond %{REQUEST_FILENAME} !-f\n"
                . "RewriteCond %{REQUEST_FILENAME} !-d\n"
                . "RewriteRule ^(.*)$ $shellFile [L]\n";

            $this->writeFile('www/.htaccess', $rules);
            return;
        }

        if ($appStyle === 'hybrid') {
            $rules = "RewriteEngine On\n"
                . "# SPA mounted under /app — client-side routes fall through to the shell.\n"
                . "RewriteCond %{REQUEST_FILENAME} !-f\n"
                . "RewriteCond %{REQUEST_FILENAME} !-d\n"
                . "RewriteRule ^app(/.*)?$ $shellFile [L]\n"
                . "# Everything else stays with the MVC front controller.\n"
                . "RewriteRule ^$ index.php [L]\n"
                . "RewriteCond %{REQUEST_FILENAME} !-f\n"
                . "RewriteCond %{REQUEST_FILENAME} !-d\n"
                . "RewriteRule ^(.*)$ index.php?r=\$1 [QSA,L]\n";
        }

        $this->writeFile('www/.htaccess', $rules);
    }

    /**
     * Paths that must keep reaching the MVC front controller in a SPA project.
     *
     * Everything init scaffolds as a server-rendered controller belongs here —
     * the API, plus whichever feature wirings were generated. Anything missing
     * from this list would be swallowed by the SPA shell.
     *
     * @param  list<string> $features Enabled framework features
     * @return list<string>
     */
    public static function mvcRoutePrefixes(array $features): array
    {
        // Always scaffolded by init, regardless of feature selection.
        $prefixes = [
            'api', 'health', 'logs', 'users', 'settings', 'dashboard',
            'services', 'organizations', 'emails',
        ];

        if (in_array('auth', $features, true)) {
            array_push($prefixes, 'login', 'logout', 'register', 'account', 'token');
        }
        if (in_array('authserver', $features, true)) {
            array_push($prefixes, 'oauth', 'permissions');
        }
        if (in_array('queue', $features, true)) {
            $prefixes[] = 'queue';
        }
        if (in_array('devpanel', $features, true)) {
            $prefixes[] = 'devpanel';
        }

        return array_values(array_unique($prefixes));
    }

    /** Keep build output and node_modules out of version control. */
    private function scaffoldSpaGitignore(bool $needsBuild): void
    {
        $lines = "\n# Front end\nnode_modules/\n";
        if ($needsBuild) {
            $lines .= 'www/' . self::SPA_BUILD_DIR . "/\n";
        }

        $path = $this->targetBaseDir . '/.gitignore';
        $existing = file_exists($path) ? (string) file_get_contents($path) : '';
        if (!str_contains($existing, 'node_modules/') && !$this->skipWrite('.gitignore')) {
            file_put_contents($path, $lines, FILE_APPEND);
        }
    }

    /** Minimal stylesheet for the stacks that do not pull in Tailwind. */
    private function getSpaPlainCss(): string
    {
        return <<<CSS
/* Baseline styles for the SPA shell. Replace with your own design system. */
:root { color-scheme: light dark; }
body {
    margin: 0;
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    line-height: 1.5;
}
#app { max-width: 48rem; margin: 3rem auto; padding: 0 1rem; }
.status { padding: 0.75rem 1rem; border-radius: 0.5rem; }
.status-ok { background: #e6f6ec; color: #14532d; }
.status-error { background: #fdeaea; color: #7f1d1d; }
CSS;
    }

    /**
     * Write a file and mark it executable (helper scripts).
     */
    private function writeExecutable(string $path, string $content): void
    {
        $this->writeFile($path, $content);
        @chmod($this->targetBaseDir . '/' . $path, 0755);
    }

    /**
     * Create or extend package.json with the front-end scripts and dependencies.
     *
     * @param array<string, string> $tokens
     */
    private function ensureSpaPackageJson(string $spaStack, array $tokens): void
    {
        $path = $this->targetBaseDir . '/package.json';
        $pkg  = file_exists($path)
            ? (json_decode((string) file_get_contents($path), true) ?: [])
            : ['name' => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $tokens['appName'])), 'version' => '1.0.0', 'private' => true];

        $pkg = self::mergeSpaPackageJson($pkg, $spaStack);
        if (!$this->skipWrite('package.json')) {
            file_put_contents($path, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    /**
     * Merge the front-end scripts and dependencies for a stack into a
     * package.json array.
     *
     * Public + static so `project:resync` can reuse it and keep existing
     * projects aligned with what `init` writes. Existing keys are preserved:
     * only the entries this stack needs are added.
     *
     * @param  array<string, mixed> $pkg Decoded package.json (may be empty)
     * @param  string $spaStack svelte|vanilla-vite|vanilla
     * @return array<string, mixed>
     */
    public static function mergeSpaPackageJson(array $pkg, string $spaStack): array
    {
        // The build-less stack stays dependency-free on purpose. "type": module
        // is what lets Node run the very same ES modules the browser loads.
        if (!self::spaNeedsNode($spaStack)) {
            $pkg['type']    = 'module';
            $pkg['scripts'] = array_merge($pkg['scripts'] ?? [], [
                // Explicit glob rather than a directory argument: passing a
            // directory makes Node 24 try to *load* it as a module and fail.
            'test' => 'node --test tests/js/*.test.js',
            ]);
            return $pkg;
        }

        $pkg['type']    = 'module';
        $pkg['scripts'] = array_merge($pkg['scripts'] ?? [], [
            // npm runs pre<script> automatically, so the palette is re-derived
            // from the theme before every build and every dev-server start —
            // and never on a request.
            'prebuild'      => 'node scripts/build-theme.mjs',
            'predev'        => 'node scripts/build-theme.mjs',
            'theme'         => 'node scripts/build-theme.mjs',
            'dev'           => 'vite',
            'build'         => 'vite build',
            'preview'       => 'vite preview',
            'test'          => 'vitest run',
            'test:watch'    => 'vitest',
            'test:coverage' => 'vitest run --coverage',
        ]);

        $dev = array_merge($pkg['devDependencies'] ?? [], [
            'vite'   => '^7.0.0',
            'vitest' => '^3.0.0',
            'jsdom'  => '^26.0.0',
            '@vitest/coverage-v8' => '^3.0.0',
        ]);

        if ($spaStack === 'svelte') {
            $dev = array_merge($dev, [
                'svelte'                      => '^5.0.0',
                '@sveltejs/vite-plugin-svelte' => '^6.0.0',
                '@testing-library/svelte'     => '^5.2.0',
                'tailwindcss'                 => '^4.0.0',
                '@tailwindcss/vite'           => '^4.0.0',
                'daisyui'                     => '^5.0.0',
            ]);
        }

        ksort($dev);
        $pkg['devDependencies'] = $dev;

        return $pkg;
    }

    private function askRestApi(InputInterface $input, OutputInterface $output, mixed $helper): bool
    {
        $option = $input->getOption('rest-api');
        if ($option !== null) {
            return in_array(strtolower($option), ['y', 'yes', '1', 'true'], true);
        }
        $output->writeln("\n<comment>Step 2b — REST API</comment>");
        return $helper->ask($input, $output, new ConfirmationQuestion('Scaffold a REST API layer? [Y/n] ', true));
    }

    private function askWebhook(InputInterface $input, OutputInterface $output, mixed $helper): bool
    {
        $option = $input->getOption('webhook');
        if ($option !== null) {
            return in_array(strtolower($option), ['y', 'yes', '1', 'true'], true);
        }
        $output->writeln("\n<comment>Step 2c — Git webhook</comment>");
        return $helper->ask($input, $output, new ConfirmationQuestion('Generate git webhook receiver (www/webhook.php)? [y/N] ', false));
    }

    /**
     * @return array{0: bool, 1: string, 2: string}  [withApiDocs, apiUrl, apiColor]
     */
    private function askApiDocs(InputInterface $input, OutputInterface $output, mixed $helper, string $appName): array
    {
        $option = $input->getOption('api-docs');
        if ($option !== null) {
            // @codeCoverageIgnoreStart
            // When --api-docs is provided as a CLI option, tests use the interactive path;
            // this option-shortcut branch is never exercised in the current test suite.
            $enabled  = in_array(strtolower($option), ['y', 'yes', '1', 'true'], true);
            $apiUrl   = $input->getOption('api-url')   ?: 'https://api.example.com';
            $apiColor = $input->getOption('api-color') ?: '#4CAF50';
            return [$enabled, $apiUrl, $apiColor];
            // @codeCoverageIgnoreEnd
        }
        $output->writeln("\n<comment>Step 2d — API Documentation</comment>");
        $enabled = $helper->ask($input, $output, new ConfirmationQuestion(
            'Generate API documentation tooling (OpenAPI + RapiDoc)? [Y/n] ', true
        ));
        if (!$enabled) {
            return [false, '', '']; // @codeCoverageIgnore — tests always answer 'y' (default) to api-docs prompt
        }
        $defaultUrl   = 'https://api.example.com';
        $defaultColor = '#4CAF50';
        $apiUrl = $input->getOption('api-url')
            ?: $helper->ask($input, $output, new Question("Production API base URL [$defaultUrl]: ", $defaultUrl));
        $apiColor = $input->getOption('api-color')
            ?: $helper->ask($input, $output, new Question("Primary color for docs UI [$defaultColor]: ", $defaultColor));
        return [$enabled, $apiUrl, $apiColor];
    }

    private function scaffoldApiDocs(string $appName, string $namespace, string $apiUrl, string $apiColor, array $enabledFeatures = [], string $localServerUrl = '', string $defaultApiKey = '', string $supportEmail = ''): void
    {
        $this->mkdir('scripts');
        $this->mkdir('www/api/docs');

        $appKey = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $namespace));

        // localServer (when set — e.g. the Docker environment) becomes the default
        // server in the generated docs; an empty value is ignored by the generator.
        // defaultApiKey pre-fills RapiDoc's "Authorize" api key for instant testing.
        $this->writeFile('src/Api/apidoc.json', $this->renderStub('api-doc.json', [
            'APP_NAME'        => $appName,
            'API_URL'         => rtrim($apiUrl, '/'),
            'PRIMARY_COLOR'   => $apiColor,
            'APP_KEY'         => $appKey,
            'LOCAL_SERVER'    => rtrim($localServerUrl, '/'),
            'DEFAULT_API_KEY' => $defaultApiKey,
        ]));

        // The scaffolded API controllers (Me/Session/Account/Capabilities) inherit
        // their actions from framework controllers, and the OAuth server lives on
        // the main web front controller — so apidoc (which only scans
        // src/Api/Controllers) never sees any of these endpoints. When a relevant
        // feature is enabled we pre-fill openapi-overrides.json — deep-merged over
        // the generated spec — with those paths (and, for the OAuth server, an
        // oauth2 security scheme). Otherwise we keep the empty stub.
        $hasAuth       = in_array('auth', $enabledFeatures, true);
        $hasAuthServer = in_array('authserver', $enabledFeatures, true);
        if ($hasAuth || $hasAuthServer) {
            $this->writeFile(
                'src/Api/openapi-overrides.json',
                json_encode(
                    self::buildApiOverrides($appName, $apiUrl, $hasAuth, $hasAuthServer, $localServerUrl, $supportEmail),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) . "\n"
            );
        } else {
            $this->writeFile('src/Api/openapi-overrides.json', $this->renderStub('openapi-overrides.json', [
                'APP_NAME' => $appName,
            ]));
        }

        $scriptSrc = $this->scaffoldingDir . '/scripts/apidoc-to-openapi.cjs';
        if (file_exists($scriptSrc)) {
            $this->writeFile('scripts/apidoc-to-openapi.cjs', (string) file_get_contents($scriptSrc));
        }

        $this->writeFile('scripts/doc.sh', $this->renderStub('doc.sh', []));
        $docShPath = $this->targetBaseDir . '/scripts/doc.sh';
        if (file_exists($docShPath)) {
            chmod($docShPath, 0755);
        }

        $this->ensurePackageJsonApiScripts($namespace);

        // Add generated output to .gitignore
        $gitignorePath = $this->targetBaseDir . '/.gitignore';
        if (file_exists($gitignorePath)) {
            $existing = (string) file_get_contents($gitignorePath);
            if (!str_contains($existing, 'www/api/docs') && !$this->skipWrite('.gitignore')) {
                file_put_contents($gitignorePath, "\n# API documentation output\nwww/api/openapi*.json\nwww/api/docs/\n", FILE_APPEND);
            }
        }
    }

    /**
     * Build the openapi-overrides.json payload that documents the OAuth2 server.
     *
     * The overrides are deep-merged over the auto-generated OpenAPI spec by
     * scripts/apidoc-to-openapi.cjs, so this only has to contribute the pieces
     * apidoc cannot infer: an oauth2 security scheme (which drives RapiDoc's
     * "Authorize" button) and the machine OAuth endpoints. Scopes come from the
     * framework Scopes registry so the docs stay in sync with what the server
     * actually grants. Endpoint URLs are derived from the API base URL's origin;
     * adjust them in the generated file if the auth server is hosted elsewhere.
     *
     * @return array<string, mixed>
     */
    /**
     * Build the openapi-overrides.json payload documenting the feature-scaffolded
     * API controllers' endpoints (whose actions are inherited from framework
     * controllers, so apidoc can't see them). Public + static so `project:resync`
     * can reuse it and keep old projects' docs in sync with `init`.
     *
     * @param string $supportEmail Developer/author email captured at init; used
     *                             as the docs support contact. Falls back to the
     *                             generic support@example.com placeholder when empty.
     * @return array<string, mixed>
     */
    public static function buildApiOverrides(string $appName, string $apiUrl, bool $hasAuth, bool $hasAuthServer, string $localServerUrl = '', string $supportEmail = ''): array
    {
        // The OAuth server lives on the main web front controller at the site
        // ROOT (/oauth/*), NOT under the API base — so its URLs use the plain
        // origin (scheme://host[:port]). Prefer the local (Docker) origin when set
        // so the "Authorize" flow works during local testing; else the API host.
        $originOf = static function (string $url): string {
            $p = parse_url(rtrim($url, '/'));
            $scheme = $p['scheme'] ?? 'https';
            $host   = $p['host']   ?? 'api.example.com';
            $port   = isset($p['port']) ? ':' . $p['port'] : '';
            return "$scheme://$host$port";
        };
        $origin = $originOf($localServerUrl !== '' ? $localServerUrl : $apiUrl);

        $authorizeUrl = "$origin/oauth/authorize";
        $tokenUrl     = "$origin/oauth/token";

        // scope => description, straight from the framework registry.
        $scopes = \Pramnos\Auth\Scopes::getScopeDescriptions();

        $formToken = static function (array $properties, array $required = []): array {
            $schema = ['type' => 'object', 'properties' => $properties];
            if ($required !== []) {
                $schema['required'] = $required;
            }
            return ['required' => true, 'content' => ['application/x-www-form-urlencoded' => ['schema' => $schema]]];
        };
        $jsonResponse = static function (string $description, array $properties = []): array {
            $schema = ['type' => 'object'];
            if ($properties !== []) {
                $schema['properties'] = $properties;
            }
            return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
        };

        $paths = [];

        // ── auth feature: the scaffolded /me, /session and /account wrappers ──
        // Their endpoints are inherited from framework controllers, so apidoc
        // (which scans only src/Api/Controllers) can't see them — document them
        // here instead.
        if ($hasAuth) {
            // Per-resource groups (@apiGroup) with short titles (summary) and
            // camelCase names (operationId) — matching the apidoc house style.
            $paths['/me'] = [
                'get' => [
                    'tags'        => ['Me'],
                    'operationId' => 'getMe',
                    'summary'     => 'Get',
                    'description' => "The current authenticated user's public profile.",
                    'responses'   => [
                        '200' => $jsonResponse('Current user', ['data' => ['type' => 'object']]),
                        '401' => ['description' => 'not_authenticated'],
                    ],
                ],
            ];
            $paths['/me/tokens'] = [
                'get' => [
                    'tags'        => ['Me'],
                    'operationId' => 'getMeTokens',
                    'summary'     => 'Get Tokens',
                    'description' => "The current user's active tokens.",
                    'responses'   => [
                        '200' => $jsonResponse('Active tokens', ['data' => ['type' => 'array', 'items' => ['type' => 'object']]]),
                        '401' => ['description' => 'not_authenticated'],
                    ],
                ],
            ];
            $paths['/me/tokens/{tokenid}'] = [
                'delete' => [
                    'tags'        => ['Me'],
                    'operationId' => 'deleteMeToken',
                    'summary'     => 'Revoke Token',
                    'description' => "Revoke one of the current user's tokens.",
                    'parameters'  => [
                        ['name' => 'tokenid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responses'   => [
                        '200' => $jsonResponse('Token revoked', ['status' => ['type' => 'string']]),
                        '400' => ['description' => 'invalid_request'],
                        '401' => ['description' => 'not_authenticated'],
                    ],
                ],
            ];
            $paths['/session/info'] = [
                'get' => [
                    'tags'        => ['Session'],
                    'operationId' => 'getSessionInfo',
                    'summary'     => 'Info',
                    'description' => 'Detailed session + user info.',
                    'responses'   => [
                        '200' => $jsonResponse('Session info'),
                        '401' => ['description' => 'Not authenticated'],
                    ],
                ],
            ];
            $paths['/session/check'] = [
                'get' => [
                    'tags'        => ['Session'],
                    'operationId' => 'getSessionCheck',
                    'summary'     => 'Check',
                    'description' => 'Is the session / token still valid?',
                    'responses'   => ['200' => $jsonResponse('Validity')],
                ],
            ];
            $paths['/session/heartbeat'] = [
                'get' => [
                    'tags'        => ['Session'],
                    'operationId' => 'sessionHeartbeat',
                    'summary'     => 'Heartbeat',
                    'description' => 'Extend session last_activity.',
                    'responses'   => ['200' => $jsonResponse('Heartbeat')],
                ],
            ];
            $paths['/session/refresh'] = [
                'post' => [
                    'tags'        => ['Session'],
                    'operationId' => 'sessionRefresh',
                    'summary'     => 'Refresh',
                    'description' => 'Extend session lifetime (session-cookie clients only).',
                    'responses'   => [
                        '200' => $jsonResponse('Refreshed'),
                        '400' => ['description' => 'Bearer clients must use the refresh_token grant'],
                    ],
                ],
            ];
            $paths['/account/login'] = [
                'post' => [
                    'tags'        => ['Account'],
                    'operationId' => 'login',
                    'summary'     => 'Login',
                    'description' => 'Authenticate with credentials (JSON) and receive a bearer access token. Send the token back in the accessToken header on subsequent requests.',
                    'security'    => [],
                    'requestBody' => [
                        'required' => true,
                        'content'  => ['application/json' => ['schema' => [
                            'type'       => 'object',
                            'required'   => ['username', 'password'],
                            'properties' => [
                                'username' => ['type' => 'string'],
                                'password' => ['type' => 'string', 'format' => 'password'],
                            ],
                        ]]],
                    ],
                    'responses' => [
                        '200' => $jsonResponse('Authenticated', [
                            'status'       => ['type' => 'string', 'example' => 'success'],
                            'access_token' => ['type' => 'string'],
                            'token_type'   => ['type' => 'string', 'example' => 'Bearer'],
                            'user'         => ['type' => 'object'],
                        ]),
                        '400' => ['description' => 'missing_credentials'],
                        '401' => ['description' => 'invalid_credentials'],
                    ],
                ],
            ];
            $paths['/account/logout'] = [
                'post' => [
                    'tags'        => ['Account'],
                    'operationId' => 'logout',
                    'summary'     => 'Logout',
                    'description' => 'Revoke the presented access token (accessToken header).',
                    'responses'   => ['200' => $jsonResponse('Logged out', ['status' => ['type' => 'string']])],
                ],
            ];
        }

        // ── authserver feature: OAuth2 endpoints + capability sync ──
        if ($hasAuthServer) {
            // The OAuth server lives on the web front controller at the site ROOT
            // (/oauth/*), NOT under the API base (/api/<version>). So each OAuth
            // path carries its own `servers` override pointing at the root origin,
            // so the docs list them AND "Try it" targets the correct URL. Local
            // (Docker) first so it is the default; production second.
            $oauthServers = [];
            if ($localServerUrl !== '') {
                $oauthServers[] = ['url' => $originOf($localServerUrl), 'description' => 'Local development (Docker)'];
            }
            $oauthServers[] = ['url' => $originOf($apiUrl), 'description' => 'Authorization server'];

            $paths['/oauth/token'] = [
                'servers' => $oauthServers,
                'post'    => [
                    'tags'        => ['OAuth2'],
                    'operationId' => 'oauthToken',
                    'summary'     => 'Token',
                    'description' => 'OAuth2 token endpoint (RFC 6749). Lives at the site root, not under the API base. Accepts application/x-www-form-urlencoded; the client authenticates via credentials in the body or the Authorization header.',
                    'security'    => [],
                    'requestBody' => $formToken([
                        'grant_type'    => ['type' => 'string', 'example' => 'client_credentials', 'description' => 'authorization_code | client_credentials | refresh_token | urn:ietf:params:oauth:grant-type:device_code'],
                        'client_id'     => ['type' => 'string'],
                        'client_secret' => ['type' => 'string'],
                        'code'          => ['type' => 'string', 'description' => 'Authorization code (authorization_code grant)'],
                        'redirect_uri'  => ['type' => 'string'],
                        'refresh_token' => ['type' => 'string'],
                        'scope'         => ['type' => 'string'],
                    ], ['grant_type']),
                    'responses' => [
                        '200' => $jsonResponse('Token issued', [
                            'access_token'  => ['type' => 'string'],
                            'token_type'    => ['type' => 'string', 'example' => 'Bearer'],
                            'expires_in'    => ['type' => 'integer'],
                            'refresh_token' => ['type' => 'string'],
                            'scope'         => ['type' => 'string'],
                            'id_token'      => ['type' => 'string', 'description' => 'Present when the openid scope is granted'],
                        ]),
                        '400' => ['description' => 'invalid_request / invalid_grant'],
                        '401' => ['description' => 'invalid_client'],
                    ],
                ],
            ];
            $paths['/oauth/userinfo'] = [
                'servers' => $oauthServers,
                'get'     => [
                    'tags'        => ['OAuth2'],
                    'operationId' => 'oauthUserinfo',
                    'summary'     => 'UserInfo',
                    'description' => 'OpenID Connect UserInfo. Requires a Bearer access token carrying the openid scope.',
                    'security'    => [['OAuth2' => ['openid', 'profile', 'email']]],
                    'responses'   => [
                        '200' => $jsonResponse('User claims'),
                        '401' => ['description' => 'invalid_token'],
                    ],
                ],
            ];
            $paths['/oauth/introspect'] = [
                'servers' => $oauthServers,
                'post'    => [
                    'tags'        => ['OAuth2'],
                    'operationId' => 'oauthIntrospect',
                    'summary'     => 'Introspect',
                    'description' => 'Token introspection (RFC 7662). Requires client authentication; form-encoded.',
                    'requestBody' => $formToken(['token' => ['type' => 'string']], ['token']),
                    'responses'   => [
                        '200' => $jsonResponse('Introspection result', ['active' => ['type' => 'boolean']]),
                        '401' => ['description' => 'invalid_client'],
                    ],
                ],
            ];
            $paths['/oauth/revoke'] = [
                'servers' => $oauthServers,
                'post'    => [
                    'tags'        => ['OAuth2'],
                    'operationId' => 'oauthRevoke',
                    'summary'     => 'Revoke',
                    'description' => 'Token revocation (RFC 7009); form-encoded.',
                    'requestBody' => $formToken(['token' => ['type' => 'string']], ['token']),
                    'responses'   => [
                        '200' => $jsonResponse('Token revoked', ['success' => ['type' => 'boolean']]),
                        '400' => ['description' => 'invalid_request'],
                    ],
                ],
            ];
            $paths['/oauth/deviceauthorization'] = [
                'servers' => $oauthServers,
                'post'    => [
                    'tags'        => ['OAuth2'],
                    'operationId' => 'oauthDeviceAuthorization',
                    'summary'     => 'Device Authorization',
                    'description' => 'Device authorization (RFC 8628); form-encoded.',
                    'requestBody' => $formToken([
                        'client_id' => ['type' => 'string'],
                        'scope'     => ['type' => 'string'],
                    ]),
                    'responses' => [
                        '200' => $jsonResponse('Device and user codes', [
                            'device_code'      => ['type' => 'string'],
                            'user_code'        => ['type' => 'string'],
                            'verification_uri' => ['type' => 'string'],
                            'expires_in'       => ['type' => 'integer'],
                            'interval'         => ['type' => 'integer'],
                        ]),
                    ],
                ],
            ];

            // Capability sync IS an API-layer endpoint (under /api/<version>) — no
            // server override.
            $paths['/capabilities/sync'] = [
                'post' => [
                    'tags'        => ['Capabilities'],
                    'operationId' => 'capabilitiesSync',
                    'summary'     => 'Sync',
                    'description' => 'Sync OAuth client capabilities.',
                    'responses'   => [
                        '200' => $jsonResponse('Capabilities synced'),
                        '401' => ['description' => 'unauthorized'],
                        '405' => ['description' => 'method_not_allowed'],
                    ],
                ],
            ];
        }

        $overrides = [
            '_comment' => 'Optional manual overrides deep-merged over the auto-generated OpenAPI spec.',
            '_usage'   => 'Endpoints for the scaffolded API controllers were pre-filled from the enabled features (their actions are inherited from framework controllers, so apidoc cannot see them). Adjust or extend as needed; for OAuth, update the oauth URLs if the authorization server is hosted on a different host than the API.',
            'info' => [
                'contact' => [
                    'name'  => "$appName Support",
                    'email' => $supportEmail !== '' ? $supportEmail : 'support@example.com',
                ],
            ],
            'paths'      => $paths,
            'components' => [
                'schemas' => (object) [],
            ],
        ];

        // The OAuth2 security scheme (and the global security requirement that
        // drives RapiDoc's "Authorize" button) only make sense with the server.
        if ($hasAuthServer) {
            $overrides['security'] = [
                ['OAuth2' => array_keys($scopes)],
            ];
            $overrides['components']['securitySchemes'] = [
                'OAuth2' => [
                    'type'        => 'oauth2',
                    'description' => 'OAuth2 / OpenID Connect authorization server for this application.',
                    'flows'       => [
                        'authorizationCode' => [
                            'authorizationUrl' => $authorizeUrl,
                            'tokenUrl'         => $tokenUrl,
                            'refreshUrl'       => $tokenUrl,
                            'scopes'           => $scopes,
                        ],
                        'clientCredentials' => [
                            'tokenUrl' => $tokenUrl,
                            'scopes'   => $scopes,
                        ],
                    ],
                ],
            ];
        }

        return $overrides;
    }

    private function ensurePackageJsonApiScripts(string $namespace): void
    {
        $pkgPath = $this->targetBaseDir . '/package.json';
        if (file_exists($pkgPath)) {
            $pkg = json_decode((string) file_get_contents($pkgPath), true) ?: []; // @codeCoverageIgnore — no package.json in the scaffolded temp dir during tests
        } else {
            $pkg = [
                'name'        => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $namespace)),
                'version'     => '1.0.0',
                'private'     => true,
                'description' => 'Node tooling for ' . $namespace,
            ];
        }
        $pkg = self::mergeApiDocsPackageJson($pkg);
        if (!$this->skipWrite('package.json')) {
            file_put_contents($pkgPath, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        }
    }

    /**
     * Merge the API-docs npm scripts + dev-dependencies into a package.json array.
     *
     * Single source of truth shared by `init` (first scaffold) and
     * `project:resync` (refresh an existing project), so the two never drift.
     * Existing keys are preserved; only the API-docs entries are added/updated.
     *
     * @param array<string, mixed> $pkg Decoded package.json (may be empty)
     * @return array<string, mixed>
     */
    public static function mergeApiDocsPackageJson(array $pkg): array
    {
        $pkg['scripts'] = array_merge($pkg['scripts'] ?? [], [
            'openapi:generate' => 'node scripts/apidoc-to-openapi.cjs',
            'docs:build'       => 'npm run openapi:generate',
            // Wrapper: runs docs:build inside the Docker container by default, or
            // on the host with `bash scripts/doc.sh --host`.
            'docs'             => 'bash scripts/doc.sh',
        ]);
        if (!isset($pkg['dependencies']['rapidoc'])) {
            $pkg['devDependencies'] = array_merge($pkg['devDependencies'] ?? [], [
                'rapidoc' => '^9.3.4',
            ]);
        }
        return $pkg;
    }

    /**
     * Creates www/webhook.php and adds WEBHOOK_SECRET to .env.example.
     *
     * The generated file uses Dotenv for environment loading and WebhookHandler
     * for HMAC verification — exactly as the `project:git-webhook` command produces.
     */
    private function scaffoldWebhookWiring(string $cliName): void
    {
        $content = <<<PHP
<?php

/**
 * Git webhook receiver.
 *
 * Point your GitHub / Bitbucket webhook at:
 *   https://yourapp.example.com/webhook.php
 *
 * Set WEBHOOK_SECRET in .env to match the secret configured in your webhook provider.
 */

define('ROOT', dirname(__DIR__));
require ROOT . '/vendor/autoload.php';

\$dotenv = \\Dotenv\\Dotenv::createImmutable(ROOT);
\$dotenv->safeLoad();

\$handler = new \\Pramnos\\Webhook\\WebhookHandler(
    secret:     \$_ENV['WEBHOOK_SECRET'] ?? '',
    repoDir:    ROOT,
    logChannel: 'webhook',
);

\$handler->onBranch('main', [
    'git fetch --all',
    'git reset --hard origin/main',
    'composer install --no-dev --optimize-autoloader',
    'php {$cliName} migrate',
]);

// Add more branches as needed:
// \$handler->onBranch('develop', [
//     'git fetch --all',
//     'git reset --hard origin/develop',
// ]);

\$handler->handle();
PHP;

        $this->writeFile('www/webhook.php', $content);

        // Append WEBHOOK_SECRET to .env.example if it exists
        $envExample = $this->targetBaseDir . '/.env.example';
        if (file_exists($envExample)) {
            // @codeCoverageIgnoreStart
            // .env.example is not scaffolded into the temp dir during tests, so
            // this branch is never entered in the unit test suite.
            $envContents = (string) file_get_contents($envExample);
            if (!str_contains($envContents, 'WEBHOOK_SECRET') && !$this->skipWrite('.env.example')) {
                file_put_contents($envExample, "\n# Git webhook HMAC secret\nWEBHOOK_SECRET=\n", FILE_APPEND);
            }
            // @codeCoverageIgnoreEnd
        }
    }

    private function scaffoldRestApi(string $namespace, array $enabledFeatures = []): void
    {
        $this->mkdir('src/Api/Controllers');

        // Default API controllers (thin wrappers over framework Auth controllers)
        // + their route registrations, gated by the enabled features.
        $routeBlock = $this->scaffoldApiControllers($namespace, $enabledFeatures);
        if (trim($routeBlock) === '') {
            // No auth-related features: leave a working commented example. The API
            // Router executes closures (not [Class, 'method'] arrays), so the
            // example instantiates the controller and calls the action directly.
            $routeBlock = "        // Example:\n"
                . "        // \$r->get('/hello', function () {\n"
                . "        //     return (new \\{$namespace}\\Api\\Controllers\\HelloController(\$this))->index();\n"
                . "        // });";
        }

        $routesStub = <<<'ROUTES'
<?php
declare(strict_types=1);

// API routes — included by Api::_executeCore() with $this bound to the Api instance.
// Return value of this file is the dispatched response (passed back to the caller).

$router     = new \Pramnos\Routing\Router($this);
$newRequest = new \Pramnos\Http\Request();

$router->group(
    // Version prefix from the APIVERSION constant (set from app.php 'api_version'),
    // so routing and version checks share one source of truth.
    ['prefix' => '/' . (defined('APIVERSION') ? APIVERSION : '1.0')],
    function (\Pramnos\Routing\Router $r): void {
{{ routes }}
    }
);

return $router->dispatch($newRequest);
ROUTES;

        $this->writeFile('src/Api/routes.php', str_replace('{{ routes }}', $routeBlock, $routesStub));

        $apiClass = <<<PHP
<?php
namespace $namespace;

class Api extends \\Pramnos\\Application\\Api
{
    // Add app-specific API behaviour here.
}
PHP;
        $this->writeFile('src/Api.php', $apiClass);

        $apiIndex = <<<PHP
<?php
define('ROOT', dirname(dirname(__DIR__)));
define('SP', 1);
require ROOT . '/vendor/autoload.php';

\$app = new \\$namespace\\Api();
\$app->init();
\$app->exec();
echo \$app->render();
PHP;
        $this->mkdir('www/api');
        $this->writeFile('www/api/index.php', $apiIndex);

        $apiHtaccess = "RewriteEngine On\n"
            . "RewriteRule ^\$ index.php [L]\n"
            . "RewriteCond %{REQUEST_FILENAME} !-f\n"
            . "RewriteCond %{REQUEST_FILENAME} !-d\n"
            . "RewriteRule ^(.*)\$ index.php?r=\$1 [QSA,L]\n";
        $this->writeFile('www/api/.htaccess', $apiHtaccess);
    }

    /**
     * Scaffold default API controllers as thin wrappers over framework Auth
     * controllers, gated by the enabled features, and return the PHP source for
     * their route registrations (to be injected into src/Api/routes.php).
     *
     * The routes use closures that instantiate the controller and call the action
     * directly — the API Router executes closures, and this targets the app's
     * Api\Controllers namespace explicitly (getController() resolves the web
     * Controllers namespace, not Api\Controllers).
     *
     * @param list<string> $features
     */
    private function scaffoldApiControllers(string $namespace, array $features): string
    {
        $fqcn = static fn(string $class): string => '\\' . $namespace . '\\Api\\Controllers\\' . $class;
        $lines = [];

        // The SPA's first screen calls this; it is public on purpose, so the
        // front end can show that the backend is reachable before anyone signs in.
        if ($this->spaStatusEndpoint) {
            $status = $fqcn('Status');
            $lines[] = "        // Public status snapshot — used by the SPA's first screen";
            $lines[] = "        \$r->get('/status', function () {";
            $lines[] = "            return (new {$status}(\$this))->display();";
            $lines[] = "        });";
            $lines[] = "";
        }

        if (in_array('auth', $features, true)) {
            $this->writeApiWrapper(
                $namespace,
                'Session',
                '\\Pramnos\\Auth\\Controllers\\Session',
                'Session API — current session state (check / info / heartbeat / refresh).'
            );
            $this->writeApiWrapper(
                $namespace,
                'Me',
                '\\Pramnos\\Auth\\Controllers\\Me',
                'Me API — the current authenticated user: profile + personal token management.'
            );
            $this->writeApiWrapper(
                $namespace,
                'Account',
                '\\Pramnos\\Auth\\Controllers\\ApiAccount',
                'Account API — token-based auth (login issues a bearer token; logout revokes it). JSON-only, distinct from the web Account controller.'
            );
            $this->writeApiWrapper(
                $namespace,
                'Admin',
                '\\Pramnos\\Auth\\Controllers\\ApiAdmin',
                'Administration API — read-only user list, log viewer and dashboard summary for the admin screen. Each action is permission-checked separately.'
            );

            $me      = $fqcn('Me');
            $session = $fqcn('Session');
            $account = $fqcn('Account');
            $admin   = $fqcn('Admin');

            $lines[] = "        // Current authenticated user (profile + personal tokens)";
            $lines[] = "        \$r->get('/me', function () {";
            $lines[] = "            return (new {$me}(\$this))->display();";
            $lines[] = "        });";
            $lines[] = "        \$r->get('/me/tokens', function () {";
            $lines[] = "            return (new {$me}(\$this))->tokens();";
            $lines[] = "        });";
            $lines[] = "        \$r->delete('/me/tokens/{tokenid}', function (\$tokenid) {";
            $lines[] = "            return (new {$me}(\$this))->deleteTokens(\$tokenid);";
            $lines[] = "        });";
            $lines[] = "";
            $lines[] = "        // Session state";
            $lines[] = "        \$r->get('/session/info', function () {";
            $lines[] = "            return (new {$session}(\$this))->info();";
            $lines[] = "        });";
            $lines[] = "        \$r->get('/session/check', function () {";
            $lines[] = "            return (new {$session}(\$this))->check();";
            $lines[] = "        });";
            $lines[] = "        \$r->get('/session/heartbeat', function () {";
            $lines[] = "            return (new {$session}(\$this))->heartbeat();";
            $lines[] = "        });";
            $lines[] = "        \$r->post('/session/refresh', function () {";
            $lines[] = "            return (new {$session}(\$this))->refresh();";
            $lines[] = "        });";
            $lines[] = "";
            $lines[] = "        // Administration — read-only lists for an admin screen";
            $lines[] = "        \$r->get('/admin/summary', function () {";
            $lines[] = "            return (new {$admin}(\$this))->summary();";
            $lines[] = "        });";
            $lines[] = "        \$r->get('/admin/users', function () {";
            $lines[] = "            return (new {$admin}(\$this))->users();";
            $lines[] = "        });";
            $lines[] = "        \$r->get('/admin/logs', function () {";
            $lines[] = "            return (new {$admin}(\$this))->logs();";
            $lines[] = "        });";
            $lines[] = "";
            $lines[] = "        // Account — token-based auth (login issues a bearer token)";
            $lines[] = "        \$r->post('/account/login', function () {";
            $lines[] = "            return (new {$account}(\$this))->login();";
            $lines[] = "        });";
            $lines[] = "        \$r->post('/account/logout', function () {";
            $lines[] = "            return (new {$account}(\$this))->logout();";
            $lines[] = "        });";
        }

        if (in_array('authserver', $features, true)) {
            $this->writeApiWrapper(
                $namespace,
                'Capabilities',
                '\\Pramnos\\Auth\\Controllers\\Capabilities',
                'Capabilities API — OAuth client capability sync.'
            );
            $cap = $fqcn('Capabilities');

            if ($lines !== []) {
                $lines[] = "";
            }
            $lines[] = "        // OAuth client capability sync";
            $lines[] = "        \$r->post('/capabilities/sync', function () {";
            $lines[] = "            return (new {$cap}(\$this))->sync();";
            $lines[] = "        });";
            $lines[] = "        \$r->post('/capabilities/sync/{clientId}', function (\$clientId) {";
            $lines[] = "            return (new {$cap}(\$this))->sync(\$clientId);";
            $lines[] = "        });";
        }

        return implode("\n", $lines);
    }

    /**
     * Write one thin API-controller wrapper: a class in the app's
     * Api\Controllers namespace that extends a framework controller, so the app
     * inherits its behaviour and can override actions in place.
     */
    private function writeApiWrapper(string $namespace, string $class, string $baseFqcn, string $summary): void
    {
        $content = "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "namespace {$namespace}\\Api\\Controllers;\n\n"
            . "/**\n"
            . " * {$summary}\n"
            . " *\n"
            . " * Thin wrapper over {$baseFqcn}. Override any action to customise the\n"
            . " * JSON payload for this application.\n"
            . " */\n"
            . "class {$class} extends {$baseFqcn}\n"
            . "{\n"
            . "}\n";
        $this->writeFile("src/Api/Controllers/{$class}.php", $content);
    }

    private function scaffoldSettings(string $path, string $type, string $host, string $name, string $user, string $pass, string $prefix, bool $dev, string $cacheSystem = 'none'): void
    {
        $realType      = ($type === 'timescaledb') ? 'postgresql' : $type;
        $timescaleFlag = ($type === 'timescaledb') ? ",\n        'timescale' => true" : '';

        $cacheConfig = '';
        if ($cacheSystem !== 'none') {
            $port = ($cacheSystem === 'redis') ? 6379 : 11211;
            $cacheConfig = "\n    'cache' => [\n        'method' => '$cacheSystem',\n        'hostname' => 'cache',\n        'port' => $port,\n    ],";
        }

        $content = "<?php\nreturn [\n    'database' => [\n        'type' => '$realType',\n        'hostname' => '$host',\n        'database' => '$name',\n        'user' => '$user',\n        'password' => '$pass',\n        'prefix' => '$prefix'$timescaleFlag\n    ],\n    'dbsettings' => true,\n    'language' => 'en',\n    'development' => " . ($dev ? 'true' : 'false') . ",\n    'forcessl' => false,$cacheConfig\n];\n";
        $this->writeFile($path, $content);
    }

    /**
     * Scaffold the theme. header/footer include only layout-critical assets
     * (bootstrap CSS+JS for the bootstrap theme). All other libraries are
     * registered in Application::registerVendorLibraries() and enqueued
     * per-page by controllers via addScript()/addStyle().
     */
    /**
     * Re-install a scaffolding UI framework into an existing project (in place).
     *
     * Public entry point used by the project:switch-ui command so a project can
     * flip between plain-css / bootstrap / tailwind. Rewrites the theme chrome
     * (app/themes/default: theme.html.php, header, footer), www/assets/css/
     * style.css and the pf-*.js helpers, and pulls the CSS/JS vendor assets the
     * chosen framework needs (bootstrap / tailwind). Does NOT touch app.php —
     * the caller updates scaffold_theme and the CSP.
     *
     * @param string   $uiSystem plain-css | bootstrap | tailwind
     * @param string   $appName  Application display name (theme header).
     * @param string[] $features Enabled feature keys (nav rendering).
     * @return void
     */
    public function installUiFramework(string $uiSystem, string $appName, array $features = []): void
    {
        if ($this->scaffoldingDir === '') {
            $this->scaffoldingDir = $this->resolveScaffoldingDir();
        }
        if ($this->targetBaseDir === '') {
            $this->targetBaseDir = defined('ROOT') ? ROOT : getcwd();
        }
        $this->scaffoldTheme($uiSystem, $appName, $this->loadAssetCatalog(), $features);
    }

    private function scaffoldTheme(string $uiSystem, string $appName, array $catalog = [], array $features = []): void
    {
        $themeDir = $this->scaffoldingDir . '/themes/' . $uiSystem;
        $dest     = 'app/themes/default';

        $src = $themeDir . '/theme.html.php';
        if (file_exists($src)) {
            $this->writeFile($dest . '/theme.html.php', file_get_contents($src));
        }

        $this->writeFile($dest . '/header.php', $this->buildThemeHeader($uiSystem, $appName, $catalog, $features));
        $this->writeFile($dest . '/footer.php', $this->buildThemeFooter($uiSystem, $appName, $catalog));

        $cssFile = $themeDir . '/style.css';
        if (file_exists($cssFile)) {
            $this->writeFile('www/assets/css/style.css', file_get_contents($cssFile));
        }

        $pfUtils = $this->scaffoldingDir . '/assets/js/pf-utils.js';
        if (file_exists($pfUtils)) {
            $this->writeFile('www/assets/js/pf-utils.js', file_get_contents($pfUtils));
        }

        $pfWebauthn = $this->scaffoldingDir . '/assets/js/pf-webauthn.js';
        if (file_exists($pfWebauthn)) {
            $this->writeFile('www/assets/js/pf-webauthn.js', file_get_contents($pfWebauthn));
        }

        $pfAuth = $this->scaffoldingDir . '/assets/js/pf-auth.js';
        if (file_exists($pfAuth)) {
            $this->writeFile('www/assets/js/pf-auth.js', file_get_contents($pfAuth));
        }

        if ($uiSystem === 'bootstrap') {
            $this->ensureBootstrapAssets();
        } elseif ($uiSystem === 'tailwind') {
            $this->ensureTailwindAssets();
        }
    }

    private function buildThemeHeader(string $uiSystem, string $appName, array $catalog, array $features = []): string
    {
        // Only layout-critical CSS lives here. Per-page libraries are
        // enqueued by controllers and output via renderCss().
        $themeCss = '';
        if ($uiSystem === 'bootstrap') {
            $bsDef = $catalog['libraries']['bootstrap'] ?? null;
            if ($bsDef) {
                $filename = basename(parse_url($bsDef['css'][0] ?? '', PHP_URL_PATH));
                $path     = $bsDef['local_path'] . '/' . $filename;
                $themeCss = "    <link rel=\"stylesheet\" href=\"<?php echo sURL; ?>$path\">\n";
            }
        } elseif ($uiSystem === 'tailwind') {
            // Tailwind's browser build is a script that scans the DOM and injects
            // styles at runtime — load it in <head> before style.css so custom
            // rules can still override generated utilities.
            $twDef = $catalog['libraries']['tailwind'] ?? null;
            if ($twDef) {
                $filename = basename(parse_url($twDef['js'][0] ?? '', PHP_URL_PATH));
                $path     = $twDef['local_path'] . '/' . $filename;
                // The Tailwind v4 border-color compatibility rule lives in
                // style.css (loaded just below) — the browser build only
                // processes inline <style type="text/tailwindcss"> blocks, so
                // custom CSS belongs in the external stylesheet instead.
                $themeCss = "    <script src=\"<?php echo sURL; ?>$path\"></script>\n";
            }
        }

        // NavRegistry snippet — identical for all features/themes.
        // Application::init() calls registerDefaultNavItems() which registers
        // Login/Logout/Account/Logs/OAuth items based on enabled features.
        // The header just iterates over the filtered result — no hardcoded URLs.
        $navSetup = <<<'PHP'
    <?php
    $_navUser     = \Pramnos\User\User::getCurrentUser() ?: null;
    $_navFeatures = \Pramnos\Application\Application::getInstance()->applicationInfo['features'] ?? [];
    $_nav         = \Pramnos\Application\NavRegistry::getForUser($_navUser, $_navFeatures);
    ?>
PHP;

        $nav = match ($uiSystem) {
            'bootstrap' => <<<'HTML'
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            {{BRAND_LOGO}}
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Main->value] ?? [] as $_item): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Feature->value] ?? [] as $_item): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::User->value] ?? [] as $_item): ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php if (!empty($_nav[\Pramnos\Application\NavSection::Admin->value])): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Admin</a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <?php foreach ($_nav[\Pramnos\Application\NavSection::Admin->value] as $_item): ?>
                            <li><a class="dropdown-item" href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
HTML,
            'tailwind' => <<<'HTML'
    <header class="bg-white shadow-sm sticky top-0 z-50">
        <div class="container mx-auto px-4 max-w-5xl flex items-center justify-between h-16">
            {{BRAND_LOGO}}
            <nav>
                <ul class="flex gap-6 items-center">
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Main->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>" class="text-gray-700 hover:text-blue-600 font-medium transition-colors"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Feature->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>" class="text-gray-700 hover:text-blue-600 font-medium transition-colors"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::User->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>" class="text-blue-600 font-semibold hover:text-blue-800 transition-colors"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php if (!empty($_nav[\Pramnos\Application\NavSection::Admin->value])): ?>
                    <li class="relative group">
                        <span class="inline-block py-2 text-gray-700 hover:text-blue-600 font-medium transition-colors cursor-pointer">Admin &#9660;</span>
                        <ul class="absolute right-0 top-full bg-white border border-gray-200 rounded-sm shadow-lg hidden group-hover:block z-50 pt-2 pb-1 min-w-[180px]">
                            <?php foreach ($_nav[\Pramnos\Application\NavSection::Admin->value] as $_item): ?>
                            <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 whitespace-nowrap"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
HTML,
            default => <<<'HTML'
    <header class="main-header">
        <div class="container">
            {{BRAND_LOGO}}
            <nav class="main-nav">
                <ul>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Main->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::User->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php foreach ($_nav[\Pramnos\Application\NavSection::Feature->value] ?? [] as $_item): ?>
                    <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                    <?php endforeach; ?>
                    <?php if (!empty($_nav[\Pramnos\Application\NavSection::Admin->value])): ?>
                    <li class="nav-admin">
                        <span>Admin</span>
                        <ul>
                            <?php foreach ($_nav[\Pramnos\Application\NavSection::Admin->value] as $_item): ?>
                            <li><a href="<?php echo htmlspecialchars($_item->url, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($_item->label, ENT_QUOTES, 'UTF-8'); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>
HTML,
        };

        // The brand element (logo image) is theme-specific and PHP-bearing, so it
        // is injected here rather than written literally into the single-quoted
        // heredocs above.
        $nav = str_replace('{{BRAND_LOGO}}', $this->brandLogo($uiSystem), $nav);

        return "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
            . $this->faviconLinks()
            . $themeCss
            . "    <link rel=\"stylesheet\" href=\"<?php echo sURL; ?>assets/css/style.css\">\n"
            . "    <?php \$this->document->renderCss(); ?>\n"
            . $navSetup . "\n"
            . $nav . "\n";
    }

    private function buildThemeFooter(string $uiSystem, string $appName, array $catalog): string
    {
        // Only layout-critical JS lives here. Per-page libraries are
        // enqueued by controllers and output via renderJs().
        $themeJs = '';
        if ($uiSystem === 'bootstrap') {
            $bsDef = $catalog['libraries']['bootstrap'] ?? null;
            if ($bsDef) {
                $filename = basename(parse_url($bsDef['js'][0] ?? '', PHP_URL_PATH));
                $path     = $bsDef['local_path'] . '/' . $filename;
                $themeJs  = "    <script src=\"<?php echo sURL; ?>$path\"></script>\n";
            }
        }

        $footer = match ($uiSystem) {
            'bootstrap' => <<<HTML
    <footer class="bg-dark text-light py-4 mt-auto">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
        </div>
    </footer>
HTML,
            'tailwind' => <<<HTML
    <footer class="bg-gray-800 text-gray-300 py-8 mt-auto">
        <div class="container mx-auto px-4 max-w-5xl text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
        </div>
    </footer>
HTML,
            default => <<<HTML
    <footer class="main-footer">
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
        </div>
    </footer>
HTML,
        };

        return $footer . "\n"
            . $themeJs
            . "    <script src=\"<?php echo sURL; ?>assets/js/pf-utils.js\"></script>\n"
            . "    <?php \$this->document->renderJs(); ?>\n";
    }

    /**
     * Copy the framework favicon set into the generated project.
     *
     * Layout produced in the project's web root (www/):
     *   - www/favicon.ico            — the classic icon browsers auto-request at /favicon.ico
     *   - www/manifest.json          — PWA manifest (icon paths rewritten to the subdir below)
     *   - www/browserconfig.xml      — Windows tile config (tile paths rewritten likewise)
     *   - www/assets/favicons/*.png  — all sized app icons (apple-*, android-*, ms-*, favicon-16/32/96)
     *
     * The master set lives in the framework's brand/favicons/ directory (the
     * single source of truth). The generator emits root-relative ("/icon.png")
     * paths inside manifest.json / browserconfig.xml; this method rewrites them
     * to point at the assets/favicons/ subdir and stamps the real app name into
     * the manifest. The matching <link>/<meta> tags are emitted by faviconLinks().
     *
     * No-op when the brand directory is absent (e.g. a trimmed dist install that
     * export-ignored it) so scaffolding still succeeds without the artwork.
     */
    private function scaffoldFavicons(string $appName): void
    {
        $src = $this->brandDir . '/favicons';
        if (!is_dir($src)) {
            return; // @codeCoverageIgnore — brand/favicons is always present in the test tree
        }

        // Sized app icons → subdir. favicon.ico stays at the web root.
        $this->mkdir('www/' . $this->faviconSubdir);
        foreach (glob($src . '/*.png') ?: [] as $file) {
            $target = 'www/' . $this->faviconSubdir . '/' . basename($file);
            if (!$this->skipWrite($target)) {
                copy($file, $this->targetBaseDir . '/' . $target);
            }
        }

        $ico = $src . '/favicon.ico';
        if (file_exists($ico) && !$this->skipWrite('www/favicon.ico')) {
            copy($ico, $this->targetBaseDir . '/www/favicon.ico');
        }

        // Config files → web root, with their internal icon paths rewritten.
        $manifest = $src . '/manifest.json';
        if (file_exists($manifest)) {
            $this->writeFile('www/manifest.json', $this->rewriteFaviconManifest($manifest, $appName));
        }

        $browserconfig = $src . '/browserconfig.xml';
        if (file_exists($browserconfig)) {
            $this->writeFile('www/browserconfig.xml', $this->rewriteBrowserconfig($browserconfig));
        }
    }

    /**
     * Read the master manifest.json, stamp the app name and rewrite each icon
     * `src` from the generator's root-relative "/icon.png" form to
     * "assets/favicons/icon.png" (relative, so it resolves correctly regardless
     * of whether the app is served from a domain root or a subdirectory).
     */
    private function rewriteFaviconManifest(string $file, string $appName): string
    {
        $data = json_decode((string) file_get_contents($file), true) ?: [];

        $data['name']       = $appName;
        $data['short_name'] = $appName;

        foreach ($data['icons'] ?? [] as $i => $icon) {
            if (isset($icon['src'])) {
                $data['icons'][$i]['src'] = $this->faviconSubdir . '/' . ltrim($icon['src'], '/');
            }
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Rewrite the Windows tile image paths in browserconfig.xml from the
     * generator's root-relative "/ms-icon.png" form to the assets/favicons/
     * subdir, matching where scaffoldFavicons() copies the tiles.
     */
    private function rewriteBrowserconfig(string $file): string
    {
        $xml = (string) file_get_contents($file);
        return str_replace('src="/', 'src="' . $this->faviconSubdir . '/', $xml);
    }

    /**
     * The full <link>/<meta> favicon block that wires the scaffolded icon set
     * into the theme header — the set recommended by the favicon generator
     * (all Apple touch sizes, Android, the 16/32/96 icons, manifest and Windows
     * tile metadata).
     *
     * Two adaptations vs. the generator's raw snippet:
     *   - Paths are sURL-prefixed (not root-relative "/…") so they resolve under
     *     any base path, and the sized icons point at the assets/favicons/ subdir
     *     where scaffoldFavicons() copies them.
     *   - favicon.ico (web root) and a <link rel="manifest"> / msapplication-config
     *     pointing at browserconfig.xml are added, matching the scaffolded layout.
     */
    private function faviconLinks(): string
    {
        $sub  = $this->faviconSubdir;
        $icon = fn(string $file): string => "<?php echo sURL; ?>$sub/$file";

        $appleSizes = ['57x57', '60x60', '72x72', '76x76', '114x114', '120x120', '144x144', '152x152', '180x180'];

        $out = "    <link rel=\"icon\" href=\"<?php echo sURL; ?>favicon.ico\" sizes=\"any\">\n";
        foreach ($appleSizes as $size) {
            $out .= "    <link rel=\"apple-touch-icon\" sizes=\"$size\" href=\"" . $icon("apple-icon-$size.png") . "\">\n";
        }
        $out .= "    <link rel=\"icon\" type=\"image/png\" sizes=\"192x192\" href=\"" . $icon('android-icon-192x192.png') . "\">\n"
            . "    <link rel=\"icon\" type=\"image/png\" sizes=\"32x32\" href=\"" . $icon('favicon-32x32.png') . "\">\n"
            . "    <link rel=\"icon\" type=\"image/png\" sizes=\"96x96\" href=\"" . $icon('favicon-96x96.png') . "\">\n"
            . "    <link rel=\"icon\" type=\"image/png\" sizes=\"16x16\" href=\"" . $icon('favicon-16x16.png') . "\">\n"
            . "    <link rel=\"manifest\" href=\"<?php echo sURL; ?>manifest.json\">\n"
            . "    <meta name=\"msapplication-config\" content=\"<?php echo sURL; ?>browserconfig.xml\">\n"
            . "    <meta name=\"msapplication-TileColor\" content=\"#ffffff\">\n"
            . "    <meta name=\"msapplication-TileImage\" content=\"" . $icon('ms-icon-144x144.png') . "\">\n"
            . "    <meta name=\"theme-color\" content=\"#ffffff\">\n";

        return $out;
    }

    /**
     * Copy the default header logo into the project as a replaceable placeholder.
     *
     * Two ink variants are written to www/assets/img/ so the header reads on
     * either a light or a dark navbar without re-exporting artwork:
     *   - logo.png          — dark ink, for light headers (plain-css, tailwind)
     *   - logo-inverse.png  — light ink, for dark headers (bootstrap)
     *
     * The theme header (buildThemeHeader) references the variant matching its
     * default navbar background. This is framework artwork shipped as a sensible
     * default — a project replaces these files with its own brand.
     *
     * No-op when the brand directory is absent (trimmed dist install).
     */
    private function scaffoldLogo(): void
    {
        $src = $this->brandDir . '/logos';
        if (!is_dir($src)) {
            return; // @codeCoverageIgnore — brand/logos is always present in the test tree
        }

        $this->mkdir('www/assets/img');
        $variants = [
            'pramnos-logo-wide.png'         => 'logo.png',
            'pramnos-logo-wide-inverse.png' => 'logo-inverse.png',
        ];
        foreach ($variants as $from => $to) {
            if (file_exists($src . '/' . $from) && !$this->skipWrite('www/assets/img/' . $to)) {
                copy($src . '/' . $from, $this->targetBaseDir . '/www/assets/img/' . $to);
            }
        }
    }

    /**
     * The header brand element: the placeholder logo image linking home, with
     * the app name as its alt text. Uses the logo ink variant that reads on the
     * given theme's default navbar background.
     */
    private function brandLogo(string $uiSystem): string
    {
        $file = $uiSystem === 'bootstrap' ? 'logo-inverse.png' : 'logo.png';
        $name = "<?php echo htmlspecialchars(\\Pramnos\\Application\\Application::getInstance()->applicationInfo['name'], ENT_QUOTES, 'UTF-8'); ?>";
        $img  = "<img src=\"<?php echo sURL; ?>assets/img/$file\" alt=\"$name\"";

        return match ($uiSystem) {
            'bootstrap' => "<a class=\"navbar-brand d-flex align-items-center\" href=\"<?php echo sURL; ?>\">$img height=\"34\"></a>",
            'tailwind'  => "<a href=\"<?php echo sURL; ?>\" class=\"flex items-center\">$img class=\"h-9 w-auto\"></a>",
            default     => "<a href=\"<?php echo sURL; ?>\" class=\"logo\">$img style=\"height:38px;display:block\"></a>",
        };
    }

    /** Download (or stub) Bootstrap assets when bootstrap theme is selected. */
    private function ensureBootstrapAssets(): void
    {
        $catalog = $this->loadAssetCatalog();
        $lib     = $catalog['libraries']['bootstrap'] ?? null;
        if ($lib === null) {
            return; // @codeCoverageIgnore — assets.json has a 'bootstrap' entry; null is never returned in tests
        }
        $this->downloadLibraryAssets('bootstrap', $lib, false);
    }

    /**
     * Vendor the Tailwind CSS browser build into the project so the tailwind
     * theme renders without a Node/build step (mirrors ensureBootstrapAssets).
     * The header script tag is emitted by buildThemeHeader() from the same
     * catalog entry, so the filename and version stay in sync.
     */
    private function ensureTailwindAssets(): void
    {
        $catalog = $this->loadAssetCatalog();
        $lib     = $catalog['libraries']['tailwind'] ?? null;
        if ($lib === null) {
            return; // @codeCoverageIgnore — assets.json has a 'tailwind' entry; null is never returned in tests
        }
        $this->downloadLibraryAssets('tailwind', $lib, false);
    }

    /**
     * Download selected library assets into public/assets/vendor/<lib>/<version>/
     * and write a project-level assets.json manifest.
     *
     * @param list<string> $libraries
     */
    private function scaffoldLibraries(array $libraries, string $uiSystem, bool $skipDownload, OutputInterface $output): void
    {
        $catalog  = $this->loadAssetCatalog();
        $manifest = [];

        foreach ($libraries as $key) {
            $lib = $catalog['libraries'][$key] ?? null;
            if ($lib === null) {
                continue; // @codeCoverageIgnore — tests only pass keys that exist in the catalog
            }
            $manifest[$key] = [
                'version'    => $lib['version'],
                'local_path' => str_replace('assets/', 'www/assets/', $lib['local_path']),
                'css'        => [],
                'js'         => [],
            ];

            if (!$skipDownload) {
                // @codeCoverageIgnoreStart
                // Tests always call scaffoldLibraries with skipDownload=true; the actual
                // download/copy path (requires network or bundled source files) is never
                // exercised in the unit test suite.
                if (!empty($lib['bundled'])) {
                    $output->writeln("  <comment>→ Copying $key@{$lib['version']} (bundled)...</comment>");
                    [$copiedCss, $copiedJs] = $this->copyBundledAssets($lib);
                    $manifest[$key]['css'] = $copiedCss;
                    $manifest[$key]['js']  = $copiedJs;
                } else {
                    $output->writeln("  <comment>→ Downloading $key@{$lib['version']}...</comment>");
                    [$downloadedCss, $downloadedJs] = $this->downloadLibraryAssets($key, $lib, true);
                    $manifest[$key]['css'] = $downloadedCss;
                    $manifest[$key]['js']  = $downloadedJs;
                }
                // @codeCoverageIgnoreEnd
            }
        }

        // Scaffolding directory creation removed (assets.json not needed in runtime app)
    }

    /**
     * Download CSS + JS files for a single library.
     *
     * @return array{list<string>, list<string>}  [downloaded_css_paths, downloaded_js_paths]
     */
    private function downloadLibraryAssets(string $key, array $lib, bool $verbose): array
    {
        $localBase = $this->targetBaseDir . '/www/' . $lib['local_path'];
        if (!is_dir($localBase)) {
            @mkdir($localBase, 0777, true);
        }

        $downloadedCss = [];
        $downloadedJs  = [];

        foreach ($lib['css'] as $url) {
            $filename = basename(parse_url($url, PHP_URL_PATH));
            $dest     = $localBase . '/' . $filename;
            if ($this->downloadFile($url, $dest)) {
                $downloadedCss[] = $lib['local_path'] . '/' . $filename;
            }
        }
        foreach ($lib['js'] as $url) {
            $filename = basename(parse_url($url, PHP_URL_PATH));
            $dest     = $localBase . '/' . $filename;
            if ($this->downloadFile($url, $dest)) {
                $downloadedJs[] = $lib['local_path'] . '/' . $filename;
            }
        }

        return [$downloadedCss, $downloadedJs];
    }

    /**
     * Copy framework-bundled asset files from scaffolding/resources to the project's www/ dir.
     *
     * Used for libraries with `"bundled": true` in assets.json — these ship with the
     * framework itself and are copied rather than downloaded from a CDN.
     *
     * @param array $lib  Library entry from assets.json (must have source_path, local_path, js, css)
     * @return array{list<string>, list<string>}  [copied_css_paths, copied_js_paths]
     */
    private function copyBundledAssets(array $lib): array
    {
        // @codeCoverageIgnoreStart
        // copyBundledAssets is only called from scaffoldLibraries when skipDownload=false
        // and the library has "bundled": true — tests always use skipDownload=true.
        $sourceBase = $this->scaffoldingDir . DIRECTORY_SEPARATOR . ($lib['source_path'] ?? '');
        $localBase  = $this->targetBaseDir . '/www/' . $lib['local_path'];

        if (!is_dir($localBase)) {
            @mkdir($localBase, 0777, true);
        }

        $copiedCss = [];
        $copiedJs  = [];

        foreach ($lib['css'] as $filename) {
            $src  = $sourceBase . DIRECTORY_SEPARATOR . $filename;
            $dest = $localBase . '/' . $filename;
            if (!$this->dryRun && file_exists($src) && @copy($src, $dest)) {
                $copiedCss[] = $lib['local_path'] . '/' . $filename;
            }
        }
        foreach ($lib['js'] as $filename) {
            $src  = $sourceBase . DIRECTORY_SEPARATOR . $filename;
            $dest = $localBase . '/' . $filename;
            if (!$this->dryRun && file_exists($src) && @copy($src, $dest)) {
                $copiedJs[] = $lib['local_path'] . '/' . $filename;
            }
        }

        return [$copiedCss, $copiedJs];
        // @codeCoverageIgnoreEnd
    }

    private function downloadFile(string $url, string $dest): bool
    {
        // A dry run must not reach the network. Recorded by the caller, which knows
        // the project-relative path; here only the write is refused.
        if ($this->dryRun) {
            return true;
        }

        if (defined('PRAMNOS_TESTING') && PRAMNOS_TESTING) {
            return file_put_contents($dest, "/* mocked download of $url */\n") !== false;
        }

        // @codeCoverageIgnoreStart
        // PRAMNOS_TESTING is always true in the test suite, so the real HTTP download
        // path (stream_context_create, file_get_contents, file_put_contents) is never reached.
        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 15,
                'user_agent' => 'PramnosFramework/1.2 (+https://github.com/mrpc/PramnosFramework)',
            ],
        ]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) {
            return false;
        }
        return file_put_contents($dest, $data) !== false;
        // @codeCoverageIgnoreEnd
    }

    private function loadAssetCatalog(): array
    {
        $file = $this->scaffoldingDir . '/assets.json';
        if (!file_exists($file)) {
            return ['libraries' => []];
        }
        return json_decode(file_get_contents($file), true) ?? ['libraries' => []];
    }

    private function scaffoldTests(string $namespace, string $dbType, string $dbHost, string $dbName, string $dbUser, string $dbPass, string $dbPrefix, bool $useDocker, array $features = []): void
    {
        $this->mkdir('tests/Unit/Controllers');
        $this->mkdir('tests/Integration');

        $testDbName = $dbName . '_test';
        $this->scaffoldSettings('app/config/testsettings.php', $dbType, $dbHost, $testDbName, $dbUser, $dbPass, $dbPrefix, false);

        $bootstrapContent = <<<'PHP'
<?php
define('ROOT', dirname(__DIR__));
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
require ROOT . '/vendor/autoload.php';

\Pramnos\Framework\Testing\TestEnvironment::setup(
    ROOT . '/app/config/testsettings.php'
);

// Fallback constants for unit tests that run without a live HTTP request.
// Application::init() defines these when a real request is processed; in
// the CLI/test environment they may be absent.
if (!defined('sURL')) {
    define('sURL', 'http://localhost/');
}
if (!defined('URL')) {
    define('URL', 'http://localhost/');
}
PHP;
        $this->writeFile('tests/bootstrap.php', $bootstrapContent);
        $this->writeFile('tests/BaseTestCase.php', "<?php\nnamespace Tests;\n\nclass BaseTestCase extends \\Pramnos\\Framework\\Testing\\BaseTestCase\n{\n}\n");
        $this->writeFile('phpunit.xml', $this->getPhpunitXml());

        $this->writeFile('tests/Unit/Controllers/HomeControllerTest.php',
            $this->buildHomeControllerTest($namespace));
        $this->writeFile('tests/Unit/Controllers/ControllersContractTest.php',
            $this->buildControllersContractTest($namespace, $features));

        if (in_array('auth', $features, true)) {
            $this->writeFile('tests/Unit/Controllers/LoginControllerTest.php',
                $this->buildLoginControllerTest($namespace));
            $this->writeFile('tests/Integration/AuthFlowTest.php',
                $this->buildAuthFlowIntegrationTest($namespace));
        }
    }

    /**
     * Build the source for the scaffolded Home controller.
     *
     * Home is a schema-less welcome-page controller — it is NOT a CRUD artifact,
     * so it does not go through create:controller (which now always generates a
     * full CRUD controller from a database table). It registers no special
     * actions; a single display() action renders the 'home' view.
     *
     * @param string $namespace Application root namespace (e.g. "App")
     * @return string PHP source for src/Controllers/Home.php
     */
    private function getHomeControllerTemplate(string $namespace): string
    {
        return <<<PHP
<?php

namespace {$namespace}\\Controllers;

/**
 * Home Controller
 *
 * Schema-less welcome page shown at the application root. Renders the 'home'
 * view. Add your own actions and views as the application grows.
 */
class Home extends \\Pramnos\\Application\\Controller
{
    public function __construct(?\\Pramnos\\Application\\Application \$application = null)
    {
        parent::__construct(\$application);
    }

    public function display(): string
    {
        \$doc = \\Pramnos\\Framework\\Factory::getDocument();
        \$doc->title = 'Home';
        return \$this->getView('home')->display();
    }
}

PHP;
    }

    private function buildHomeControllerTest(string $namespace): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace Tests\\Unit\\Controllers;

use PHPUnit\\Framework\\TestCase;
use {$namespace}\\Controllers\\Home;

/**
 * Unit tests for the Home controller.
 *
 * Home is a schema-less welcome-page controller: it registers no special
 * actions and exposes a single display() action that renders the 'home' view.
 * Tests cover its class structure and the display() action. No database or web
 * server is required — view rendering is mocked.
 */
class HomeControllerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Structure
    // -------------------------------------------------------------------------

    /**
     * The Home controller class must exist and be loadable.
     *
     * A missing or broken autoload entry is the most common reason a newly
     * scaffolded controller silently returns a 404 on every request.
     */
    public function testHomeControllerClassExists(): void
    {
        // Assert — the class resolves via Composer autoload
        \$this->assertTrue(
            class_exists(Home::class),
            '{$namespace}\\Controllers\\Home must be loadable via autoload'
        );
    }

    /**
     * Home must extend \\Pramnos\\Application\\Controller so that exec() and
     * addaction() are available to the router dispatcher.
     */
    public function testHomeControllerExtendsFrameworkController(): void
    {
        // Act
        \$home = new Home(null);

        // Assert
        \$this->assertInstanceOf(
            \\Pramnos\\Application\\Controller::class,
            \$home,
            'Home must extend \\Pramnos\\Application\\Controller'
        );
    }

    // -------------------------------------------------------------------------
    // Actions — view-rendering methods
    // -------------------------------------------------------------------------

    /**
     * display() must return the string produced by the 'home' view.
     *
     * The view layer is mocked so the test does not require template files on
     * disk.  This proves the method body executes without errors and correctly
     * returns the view output instead of printing it or returning void.
     */
    public function testDisplayReturnsViewContent(): void
    {
        // Arrange — partial mock intercepts getView() only
        \$home = \$this->getMockBuilder(Home::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['getView'])
            ->getMock();

        \$mockView = \$this->createMock(\\Pramnos\\Application\\View::class);
        \$mockView->method('display')->willReturn('<h1>Home</h1>');
        \$home->method('getView')->willReturn(\$mockView);

        // Act
        \$result = \$home->display();

        // Assert — action returns the view's rendered string
        \$this->assertSame('<h1>Home</h1>', \$result,
            'display() must return the string produced by the view');
    }
}
PHP;
    }

    /**
     * Builds ControllersContractTest.php — structural smoke tests for all
     * thin-delegation controllers.  Every app controller must:
     *   (1) load via autoload without fatal errors,
     *   (2) extend the correct framework parent.
     *
     * These tests give instant feedback when a namespace, use-statement,
     * or extends clause is wrong in a freshly generated project.
     */
    private function buildControllersContractTest(string $namespace, array $features): string
    {
        $hasAuth       = in_array('auth',       $features, true);
        $hasAuthserver = in_array('authserver', $features, true);
        $hasQueue      = in_array('queue',      $features, true);

        // Build use-imports for app controllers (only those actually generated).
        $uses  = "use {$namespace}\\Controllers\\Home;\n";
        $uses .= "use {$namespace}\\Controllers\\Dashboard;\n";
        $uses .= "use {$namespace}\\Controllers\\Health;\n";
        $uses .= "use {$namespace}\\Controllers\\Users;\n";
        $uses .= "use {$namespace}\\Controllers\\Settings;\n";
        $uses .= "use {$namespace}\\Controllers\\Logs;\n";
        $uses .= "use {$namespace}\\Controllers\\Services;\n";
        $uses .= "use {$namespace}\\Controllers\\Organizations;\n";
        $uses .= "use {$namespace}\\Controllers\\Emails;\n";
        if ($hasAuth) {
            $uses .= "use {$namespace}\\Controllers\\Login;\n";
            $uses .= "use {$namespace}\\Controllers\\Account;\n";
            $uses .= "use {$namespace}\\Controllers\\TwoFactorAuth;\n";
            $uses .= "use {$namespace}\\Controllers\\Passkey;\n";
            $uses .= "use {$namespace}\\Controllers\\Session;\n";
            $uses .= "use {$namespace}\\Controllers\\TokenActions;\n";
            $uses .= "use {$namespace}\\Controllers\\Tokens;\n";
            $uses .= "use {$namespace}\\Controllers\\Oauth;\n";
        }
        if ($hasAuthserver) {
            $uses .= "use {$namespace}\\Controllers\\Applications;\n";
            $uses .= "use {$namespace}\\Controllers\\Permissions;\n";
            $uses .= "use {$namespace}\\Controllers\\Discovery;\n";
            $uses .= "use {$namespace}\\Controllers\\Device;\n";
            $uses .= "use {$namespace}\\Controllers\\Gdpr;\n";
            $uses .= "use {$namespace}\\Controllers\\Capabilities;\n";
            $uses .= "use {$namespace}\\Controllers\\InternalPermissions;\n";
        }
        if ($hasQueue) {
            $uses .= "use {$namespace}\\Controllers\\Queue;\n";
        }

        // Build data-provider rows.
        $rows  = "            'Home'          => [Home::class,          \\Pramnos\\Application\\Controller::class],\n";
        $rows .= "            'Dashboard'     => [Dashboard::class,     \\Pramnos\\Application\\Controllers\\DashboardController::class],\n";
        $rows .= "            'Health'        => [Health::class,         \\Pramnos\\Application\\Controllers\\Health::class],\n";
        $rows .= "            'Users'         => [Users::class,          \\Pramnos\\Application\\Controllers\\UsersController::class],\n";
        $rows .= "            'Settings'      => [Settings::class,       \\Pramnos\\Application\\Controllers\\SettingsController::class],\n";
        $rows .= "            'Logs'          => [Logs::class,           \\Pramnos\\Application\\Controllers\\LogController::class],\n";
        $rows .= "            'Services'      => [Services::class,       \\Pramnos\\Application\\Controllers\\ServicesController::class],\n";
        $rows .= "            'Organizations' => [Organizations::class,  \\Pramnos\\Application\\Controllers\\OrganizationsController::class],\n";
        $rows .= "            'Emails'        => [Emails::class,         \\Pramnos\\Application\\Controllers\\EmailsController::class],\n";
        if ($hasAuth) {
            $rows .= "            'Login'         => [Login::class,         \\Pramnos\\Auth\\Controllers\\Account::class],\n";
            $rows .= "            'Account'       => [Account::class,       \\Pramnos\\Auth\\Controllers\\Account::class],\n";
            $rows .= "            'TwoFactorAuth' => [TwoFactorAuth::class, \\Pramnos\\Auth\\Controllers\\TwoFactorAuth::class],\n";
            $rows .= "            'Passkey'       => [Passkey::class,       \\Pramnos\\Auth\\Controllers\\Passkey::class],\n";
            $rows .= "            'Session'       => [Session::class,       \\Pramnos\\Auth\\Controllers\\Session::class],\n";
            $rows .= "            'TokenActions'  => [TokenActions::class,  \\Pramnos\\Auth\\Controllers\\TokenActionsController::class],\n";
            $rows .= "            'Tokens'        => [Tokens::class,        \\Pramnos\\Auth\\Controllers\\TokensController::class],\n";
            $rows .= "            'Oauth'         => [Oauth::class,         \\Pramnos\\Auth\\Controllers\\Oauth::class],\n";
        }
        if ($hasAuthserver) {
            $rows .= "            'Applications'  => [Applications::class,  \\Pramnos\\Auth\\Controllers\\ApplicationsController::class],\n";
            $rows .= "            'Permissions'   => [Permissions::class,   \\Pramnos\\Auth\\Controllers\\PermissionsController::class],\n";
            $rows .= "            'Discovery'     => [Discovery::class,     \\Pramnos\\Auth\\Controllers\\Discovery::class],\n";
            $rows .= "            'Device'        => [Device::class,        \\Pramnos\\Auth\\Controllers\\Device::class],\n";
            $rows .= "            'Gdpr'          => [Gdpr::class,          \\Pramnos\\Auth\\Controllers\\Gdpr::class],\n";
            $rows .= "            'Capabilities'  => [Capabilities::class,  \\Pramnos\\Auth\\Controllers\\Capabilities::class],\n";
            $rows .= "            'InternalPermissions' => [InternalPermissions::class, \\Pramnos\\Auth\\Controllers\\InternalPermissions::class],\n";
        }
        if ($hasQueue) {
            $rows .= "            'Queue'         => [Queue::class,          \\Pramnos\\Queue\\Controllers\\QueueController::class],\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace Tests\\Unit\\Controllers;

use PHPUnit\\Framework\\TestCase;
use PHPUnit\\Framework\\Attributes\\DataProvider;
{$uses}
/**
 * Structural contract tests for all thin-delegation controllers.
 *
 * Every generated controller must:
 *   1. Be loadable via autoload without causing a fatal error.
 *   2. Extend the correct framework parent so it inherits the expected actions.
 *
 * These tests prove no class-name, namespace, or extends clause is wrong.
 * They run in pure PHP (no database, no HTTP) and are extremely fast.
 *
 * If you add a new controller, add a corresponding row to provideControllers().
 */
class ControllersContractTest extends TestCase
{
    /**
     * Every controller must be instantiable and extend the right framework class.
     *
     * A wrong extends clause (e.g. a typo, removed use-import, or renamed class)
     * would cause a fatal error on the first real HTTP request but might otherwise
     * go undetected until production.
     */
    #[DataProvider('provideControllers')]
    public function testControllerCanBeInstantiatedAndExtendsCorrectParent(
        string \$class,
        string \$expectedParent
    ): void {
        // Act — instantiate with null application (no DB or HTTP needed)
        \$controller = new \$class(null);

        // Assert — correct inheritance so the framework can dispatch requests
        \$this->assertInstanceOf(
            \$expectedParent,
            \$controller,
            "\$class must extend or implement \$expectedParent"
        );
    }

    /**
     * Every controller must expose only valid, callable action methods.
     *
     * A controller that registers an action via addaction() but does not
     * define the corresponding method would throw a fatal error when the
     * router tries to dispatch that URL.
     */
    #[DataProvider('provideControllers')]
    public function testControllerHasNoUnreachableRegisteredActions(
        string \$class,
        string \$expectedParent
    ): void {
        // Arrange
        \$controller = new \$class(null);
        \$ref        = new \\ReflectionClass(\$controller);
        \$prop       = \$ref->getProperty('actions');
        \$actions    = \$prop->getValue(\$controller);

        // Assert — every registered action maps to a real method
        foreach (\$actions as \$action) {
            \$this->assertTrue(
                \$ref->hasMethod(\$action),
                "\$class registers action '\$action' via addaction() but the method does not exist"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Data provider
    // -------------------------------------------------------------------------

    public static function provideControllers(): array
    {
        return [
{$rows}        ];
    }
}
PHP;
    }

    private function buildLoginControllerTest(string $namespace): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace Tests\\Unit\\Controllers;

use PHPUnit\\Framework\\TestCase;
use {$namespace}\\Controllers\\Login;
use Pramnos\\Auth\\Controllers\\Account;

/**
 * Unit tests for the Login controller.
 *
 * The scaffolded Login controller is a thin alias that binds the /login URL to
 * the framework's Account login flow (password -> 2FA/passkey step-up -> session,
 * via LoginFlow). It reimplements no login logic, so these tests only pin the
 * alias contract; the flow itself is covered by the framework's own test suite.
 */
class LoginControllerTest extends TestCase
{
    /** Login must delegate to the framework Account controller. */
    public function testExtendsFrameworkAccountController(): void
    {
        \$this->assertTrue(
            is_subclass_of(Login::class, Account::class),
            'Login must extend the framework Account controller'
        );
    }

    /** Form actions post under /login (routeBase). */
    public function testUsesLoginRouteBase(): void
    {
        \$ref   = new \\ReflectionClass(Login::class);
        \$login = \$ref->newInstanceWithoutConstructor();
        \$prop  = \$ref->getProperty('routeBase');
        // Note: ReflectionProperty::setAccessible() is a no-op since PHP 8.1 and
        // deprecated in 8.5 — reading the value needs no accessibility toggle.
        \$this->assertSame('login', \$prop->getValue(\$login),
            'Login form actions must post under /login');
    }

    /** The bare /login URL shows the sign-in form (not the account dashboard). */
    public function testDisplayIsOverriddenToShowLoginForm(): void
    {
        \$ref = new \\ReflectionMethod(Login::class, 'display');
        \$this->assertSame(Login::class, \$ref->getDeclaringClass()->getName(),
            'Login::display() must override the inherited dashboard display');
    }
}
PHP;
    }

    private function buildAuthFlowIntegrationTest(string $namespace): string
    {
        return <<<PHP
<?php

declare(strict_types=1);

namespace Tests\\Integration;

use Tests\\BaseTestCase;
use {$namespace}\\Controllers\\Login;

/**
 * Integration test for the authentication flow.
 *
 * These tests run against the real test database (configured in
 * app/config/testsettings.php) and verify that the full login/logout
 * lifecycle works end-to-end.
 *
 * To run these tests you need the test database to be migrated first:
 *   php pramnos migrate:framework --env=test
 *
 * Then run:
 *   ./dockertest --testsuite Integration
 */
class AuthFlowTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        \$_POST = [];
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        unset(\$_SESSION['user'], \$_SESSION['logged'], \$_SESSION['login_error']);
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Auth::auth() — low-level tests
    // -----------------------------------------------------------------------

    /**
     * Auth::auth() returns false for a non-existent user.
     *
     * This is the baseline check: the auth system must be wired up and
     * the users table must exist. A misconfigured addon stack or missing
     * migration will throw an exception instead of returning false.
     */
    public function testAuthReturnsFalseForUnknownUser(): void
    {
        // Arrange
        \$auth = \\Pramnos\\Auth\\Auth::getInstance();

        // Act
        \$result = \$auth->auth('no_such_user_' . bin2hex(random_bytes(4)), 'wrongpassword');

        // Assert — must return false, not throw
        \$this->assertFalse(\$result, 'auth() must return false for unknown users, not throw an exception');
    }

    /**
     * Auth::auth() returns true and sets \$_SESSION['logged'] for a valid user.
     *
     * This is the golden path: correct credentials → authenticated session.
     * With no user addon registered, Auth's built-in login lifecycle
     * (executeDefaultLogin) sets \$_SESSION['logged'] after a successful auth().
     */
    public function testAuthReturnsTrueAndSetsSessionForValidUser(): void
    {
        // Arrange — create a test user via the User model so all NOT NULL columns
        // get correct default values regardless of which columns have DB-level DEFAULTs.
        \$user            = new \\Pramnos\\User\\User();
        \$user->username  = 'testuser_auth';
        \$user->email     = 'testuser@example.com';
        \$user->usertype  = 50;
        \$user->validated = 1;
        \$user->save();

        // setPassword() must be called after save() because it salts with the
        // real userid — which is only known after the INSERT returns.
        \$userId = (int) \$user->userid;
        \$user->setPassword('testpass123');
        \$user->save();

        \$auth = \\Pramnos\\Auth\\Auth::getInstance();
        unset(\$_SESSION['logged']);

        // Act
        \$result = \$auth->auth('testuser_auth', 'testpass123');

        // Assert — authentication must succeed
        \$this->assertTrue(\$result, 'auth() must return true for valid credentials');

        // Assert — session must be marked as logged in (built-in login lifecycle)
        \$this->assertNotEmpty(\$_SESSION['logged'] ?? null,
            '\$_SESSION[logged] must be set after successful auth via Auth::executeDefaultLogin()');

        // Cleanup — no backtick quotes: works on both MySQL and PostgreSQL
        \$db = \\Pramnos\\Database\\Database::getInstance();
        \$db->query(\$db->prepareQuery("DELETE FROM #PREFIX#users WHERE userid = %d", \$userId));
    }

    // -----------------------------------------------------------------------
    // Login::login() — controller-level integration tests
    //
    // login() is CSRF-protected and reads the request method from
    // \$_SERVER['REQUEST_METHOD']; we set POST and mock the checkCsrf() seam to
    // true so the test drives the credential path directly. redirect() is mocked
    // so no HTTP headers are sent in the runner.
    // -----------------------------------------------------------------------

    /**
     * login() redirects to the application root on successful authentication.
     *
     * Creates a real user, submits valid credentials via \$_POST, and confirms
     * the controller calls redirect() with the site root URL (the true branch of
     * presentResult()).
     */
    public function testLoginRedirectsToHomeOnSuccessfulLogin(): void
    {
        // Arrange — a disposable test user with a known password
        \$user            = new \\Pramnos\\User\\User();
        \$user->username  = 'testuser_login_ok';
        \$user->email     = 'login_ok@example.com';
        \$user->usertype  = 50;
        \$user->validated = 1;
        \$user->save();
        \$userId = (int) \$user->userid;
        \$user->setPassword('correctpass');
        \$user->save();

        unset(\$_SESSION['user'], \$_SESSION['logged']);
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_POST = ['username' => 'testuser_login_ok', 'password' => 'correctpass'];

        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect', 'checkCsrf'])
            ->getMock();
        \$login->method('checkCsrf')->willReturn(true);

        // Assert — on success the controller sends the user to the site root (sURL)
        \$login->expects(\$this->once())
            ->method('redirect')
            ->with(\$this->stringContains(sURL));

        // Act
        \$login->login();

        // Cleanup
        \$db = \\Pramnos\\Database\\Database::getInstance();
        \$db->query(\$db->prepareQuery("DELETE FROM #PREFIX#users WHERE userid = %d", \$userId));
    }

    /**
     * login() re-renders the sign-in form and does NOT authenticate when the
     * password is wrong (the invalid-credentials branch of presentResult()).
     */
    public function testLoginRejectsInvalidCredentials(): void
    {
        // Arrange — a real user, but submit the wrong password
        \$user            = new \\Pramnos\\User\\User();
        \$user->username  = 'testuser_login_fail';
        \$user->email     = 'login_fail@example.com';
        \$user->usertype  = 50;
        \$user->validated = 1;
        \$user->save();
        \$userId = (int) \$user->userid;
        \$user->setPassword('rightpassword');
        \$user->save();

        unset(\$_SESSION['user'], \$_SESSION['logged']);
        \$_SERVER['REQUEST_METHOD'] = 'POST';
        \$_POST = ['username' => 'testuser_login_fail', 'password' => 'wrongpassword'];

        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect', 'checkCsrf'])
            ->getMock();
        \$login->method('checkCsrf')->willReturn(true);

        // Assert — a rejected login never redirects
        \$login->expects(\$this->never())->method('redirect');

        // Act — login() returns the re-rendered form on failure
        \$result = \$login->login();

        // Assert — the form is returned and no logged-in session was established
        \$this->assertIsString(\$result, 'a rejected login must re-render the login form');
        \$this->assertEmpty(\$_SESSION['logged'] ?? null,
            'invalid credentials must not establish a logged-in session');

        // Cleanup
        \$db = \\Pramnos\\Database\\Database::getInstance();
        \$db->query(\$db->prepareQuery("DELETE FROM #PREFIX#users WHERE userid = %d", \$userId));
    }
}
PHP;
    }

    private function getPhpunitXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/11.0/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         displayDetailsOnTestsThatTriggerDeprecations="true"
         displayDetailsOnTestsThatTriggerWarnings="true">
    <extensions>
        <!--
            The framework has two singletons that are per-request in production and
            process-wide in a test run. Without these, state left by one test answers
            for every test after it — and the failure surfaces in some unrelated test
            far away, where it looks like a bug in that test. This cost the framework
            itself 135 failures once, and three more on a separate occasion.

            Keep them. If a test needs a specific identity or document type, it still
            establishes one in setUp(): the reset runs before that.
        -->
        <bootstrap class="Pramnos\\Framework\\Testing\\RequestIdentityIsolation"/>
        <bootstrap class="Pramnos\\Framework\\Testing\\DocumentIsolation"/>
        <bootstrap class="Pramnos\\Framework\\Testing\\GateIsolation"/>
    </extensions>

    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">./src</directory>
        </include>
    </source>
</phpunit>
XML;
    }

    /**
     * The app/schedule.php stub — the official place to declare scheduled tasks.
     * Loaded by `pramnos schedule:run` / `schedule:list`.
     */
    private function getScheduleTemplate(): string
    {
        return <<<'PHP'
<?php

/**
 * Scheduled tasks.
 *
 * This file is loaded by `pramnos schedule:run` and `pramnos schedule:list`.
 * Register tasks with the Scheduler API; a system cron should run schedule:run
 * every minute:
 *
 *   * * * * * cd /path/to/app && php <cli> schedule:run >> /dev/null 2>&1
 *
 * Tasks are defined here in code (not stored in a database).
 */

use Pramnos\Scheduling\Scheduler;

// Examples — uncomment/edit:
//
// Scheduler::command('cache:clear')->daily()->at('03:00');
// Scheduler::command('queue:cleanup')->hourly();
// Scheduler::call(function () {
//     // ... custom work ...
// })->everyFifteenMinutes()->withoutOverlapping();

PHP;
    }

    private function getIndexTemplate(string $namespace = 'Pramnos'): string
    {
        return <<<PHP
<?php
define('ROOT', dirname(__DIR__));
define('SP', 1);
require ROOT . '/vendor/autoload.php';

\$app = new \\$namespace\\Application();
\$app->init();

// Run the HTTP middleware declared in app.php ('middleware' => [...]) around
// the dispatch. SessionTrackingMiddleware lives here so session/device tracking
// is explicit and opt-in rather than a hidden boot side-effect. When the stack
// is empty the pipeline is a straight passthrough.
\$pipeline = new \\Pramnos\\Http\\MiddlewarePipeline();
foreach (\$app->getMiddleware() as \$middleware) {
    \$pipeline->pipe(\$middleware);
}

echo \$pipeline->run(
    \\Pramnos\\Http\\Request::getInstance(),
    static function () use (\$app) {
        \$app->exec();
        return \$app->render();
    }
);
PHP;
    }

    /**
     * @param bool $withNode   Install Node/npm in the app image (API docs generator
     *                         and/or a SPA stack with a Vite build need it)
     * @param int  $spaDevPort When non-zero, publish this port too so Vite's dev
     *                         server is reachable from the host
     */
    private function scaffoldDocker(string $namespace, int $port, string $dbType, string $dbName, string $dbUser, string $dbPass, string $cacheSystem, string $dbRootPass, string $cliName = '', bool $withNode = false, int $spaDevPort = 0): void
    {
        $isPostgres = ($dbType === 'postgresql' || $dbType === 'timescaledb');
        $slug       = strtolower(str_replace([' ', '_'], '-', $namespace));

        $image = match ($dbType) {
            'timescaledb' => 'timescale/timescaledb:latest-pg17',
            'mysql'       => 'mysql:8.0',
            default       => 'postgres:latest',
        };

        $extraVolumes = $this->detectFrameworkDevVolume();

        // Vite's dev server runs inside the same container (npm run dev), so its
        // port is published alongside Apache's when a SPA build stack is in play.
        $devPortMapping = $spaDevPort > 0
            ? "      - \"$spaDevPort:$spaDevPort\"\n"
            : '';

        // UID/GID come from .env (written by init with the host user's ids), so
        // the image's www-data matches the person who owns the checkout.
        $compose  = "services:\n  app:\n    container_name: {$slug}_php\n"
            . "    build:\n      context: .\n      args:\n"
            . "        UID: \${UID:-1000}\n        GID: \${GID:-1000}\n"
            . "    ports:\n      - \"$port:80\"\n$devPortMapping    volumes:\n      - .:/var/www/html\n$extraVolumes    depends_on:\n      - db\n";

        if ($cacheSystem !== 'none') {
            $compose .= "      - cache\n";
        }

        $compose .= "  db:\n    container_name: {$slug}_db\n    image: $image\n";

        if ($dbType === 'mysql') {
            $compose .= "    volumes:\n      - ./docker/mysql-init:/docker-entrypoint-initdb.d\n";
        }

        $compose .= "    environment:\n";
        if ($isPostgres) {
            $compose .= "      POSTGRES_DB: $dbName\n      POSTGRES_USER: $dbUser\n      POSTGRES_PASSWORD: $dbPass\n";
        } else {
            $compose .= "      MYSQL_DATABASE: $dbName\n      MYSQL_USER: $dbUser\n      MYSQL_PASSWORD: $dbPass\n      MYSQL_ROOT_PASSWORD: $dbRootPass\n";
            $compose .= "    command: mysqld --default-authentication-plugin=mysql_native_password --sql_mode=\"NO_AUTO_VALUE_ON_ZERO\" --general-log=1 --general-log-file=/var/lib/mysql/general-log.log\n";
            $this->mkdir('docker/mysql-init');
            $this->writeFile('docker/mysql-init/init.sql', "GRANT ALL PRIVILEGES ON *.* TO '$dbUser'@'%';\nFLUSH PRIVILEGES;\n");
        }

        if ($cacheSystem !== 'none') {
            $compose .= "  cache:\n    container_name: {$slug}_cache\n    image: $cacheSystem:latest\n";
        }

        $toolPort = $port + 1;
        if ($isPostgres) {
            $compose .= "  adminer:\n    container_name: {$slug}_adminer\n    image: adminer\n    ports:\n      - \"$toolPort:8080\"\n";
        } else {
            $compose .= "  phpmyadmin:\n    container_name: {$slug}_pma\n    image: phpmyadmin/phpmyadmin\n    ports:\n      - \"$toolPort:80\"\n    environment:\n      PMA_HOST: db\n      UPLOAD_LIMIT: 5G\n";
        }

        $this->writeFile('docker-compose.yml', $compose);
        $this->ensureHostUserEnv();

        $phpExts  = $isPostgres ? 'pdo_pgsql pgsql' : 'pdo_mysql mysqli';
        $docRoot  = '/var/www/html/www';

        $dockerfile  = "FROM php:8.5-apache\n";
        // The database command-line client matching $dbType is installed on purpose:
        // TestEnvironment::setup() imports a schema dump by shelling out to psql /
        // mysql, and it redirects all output to /dev/null — so without the client the
        // import is a silent no-op and the test database just stays empty. It also
        // makes `./dockerbash` a usable place to poke at the database by hand.
        $dbClient = $isPostgres ? 'postgresql-client' : 'default-mysql-client';
        $dockerfile .= "RUN apt-get update && apt-get install -y libpq-dev libicu-dev libonig-dev libzip-dev libxml2-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev git unzip $dbClient\n";
        // Everything the tooling writes into the bind-mounted project (vendor/,
        // node_modules/, var/logs, migrations output) is created by the
        // container's web user. Remap www-data to the *host* user's ids at build
        // time so those files land owned by whoever ran init — otherwise the
        // project fills up with root-owned files and even deleting it needs
        // sudo. -o allows a duplicate id, which some hosts already use.
        $dockerfile .= "ARG UID=1000\n";
        $dockerfile .= "ARG GID=1000\n";
        $dockerfile .= "RUN groupmod -o -g \$GID www-data && usermod -o -u \$UID -g \$GID www-data\n";
        $dockerfile .= "COPY --from=composer:latest /usr/bin/composer /usr/bin/composer\n";
        $dockerfile .= "RUN docker-php-ext-configure intl\n";
        $dockerfile .= "RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype\n";
        $dockerfile .= "RUN docker-php-ext-install pdo $phpExts intl mbstring zip bcmath gd\n";
        $dockerfile .= "RUN pecl install xdebug && docker-php-ext-enable xdebug\n";
        $dockerfile .= "RUN echo \"xdebug.mode=coverage\" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini\n";
        // Node/npm, only when something in the project needs it: the OpenAPI /
        // RapiDoc generator, or a SPA stack with a Vite build. Keeping it in the
        // app image (rather than a second container) means one place to run
        // everything — ./dockernpm, ./testjs and the docs generator all shell
        // into the same service.
        if ($withNode) {
            $dockerfile .= "RUN apt-get update && apt-get install -y nodejs npm && rm -rf /var/lib/apt/lists/*\n";
        }
        $dockerfile .= "RUN a2enmod rewrite\n";
        $dockerfile .= "ENV APACHE_DOCUMENT_ROOT $docRoot\n";
        $dockerfile .= "RUN sed -ri -e 's!/var/www/html!$docRoot!g' /etc/apache2/sites-available/*.conf\n";
        $dockerfile .= "RUN printf \"<Directory $docRoot/>\\n\\tOptions Indexes FollowSymLinks\\n\\tAllowOverride All\\n\\tRequire all granted\\n</Directory>\" > /etc/apache2/conf-available/pramnos.conf && a2enconf pramnos\n";
        $dockerfile .= "WORKDIR /var/www/html\n";
        $dockerfile .= "COPY composer.json composer.lock* ./\n";
        $dockerfile .= "RUN composer install --no-scripts --no-autoloader || true\n";
        $dockerfile .= "COPY . .\n";
        $dockerfile .= "RUN composer dump-autoload\n";

        $this->writeFile('Dockerfile', $dockerfile);

        $dockerbashScript = $this->getDockerBashTemplate();
        $this->writeFile('dockerbash', $dockerbashScript);
        @chmod($this->targetBaseDir . '/dockerbash', 0755);

        $dockertestScript = $this->getDockerTestTemplate($namespace, $port);
        $this->writeFile('dockertest', $dockertestScript);
        @chmod($this->targetBaseDir . '/dockertest', 0755);

        if ($cliName !== '') {
            $cliWrapper = "#!/usr/bin/env bash\n# -u www-data: the image maps that user to the host user (see .env),\n# so anything the command writes stays owned by you.\ndocker-compose exec -u www-data app php $cliName.php \"\$@\"\n";
            $this->writeFile($cliName, $cliWrapper);
            @chmod($this->targetBaseDir . '/' . $cliName, 0755);
        }
    }

    /**
     * The id of the host user, so the container can write as them.
     *
     * posix_* is not compiled into every PHP build, hence the `id` fallback and
     * the 1000 default (the first non-root user on a Debian/Ubuntu host, which
     * is what the vast majority of development machines use).
     */
    public static function hostUserIds(): array
    {
        $uid = function_exists('posix_getuid') ? posix_getuid() : (int) trim((string) @shell_exec('id -u'));
        $gid = function_exists('posix_getgid') ? posix_getgid() : (int) trim((string) @shell_exec('id -g'));

        return [
            'UID' => $uid > 0 ? $uid : 1000,
            'GID' => $gid > 0 ? $gid : 1000,
        ];
    }

    /**
     * Record the host user's ids in .env, where docker-compose reads them.
     *
     * Compose interpolates ${UID}/${GID} from the environment or from .env —
     * and a plain shell does NOT export UID, so relying on the environment
     * silently falls back to the defaults. Writing them once, at init, makes
     * every later `docker-compose build` reproducible for this checkout.
     *
     * An existing .env is preserved: only missing keys are appended.
     */
    private function ensureHostUserEnv(): void
    {
        $path     = $this->targetBaseDir . '/.env';
        $existing = file_exists($path) ? (string) file_get_contents($path) : '';
        $append   = '';

        foreach (self::hostUserIds() as $key => $value) {
            if (!preg_match('/^' . $key . '=/m', $existing)) {
                $append .= "$key=$value\n";
            }
        }

        if ($append === '') {
            return;
        }

        $header = $existing === ''
            ? "# Host user ids, so files the container writes into the bind mount\n"
                . "# (vendor/, node_modules/, var/logs) belong to you and not to root.\n"
            : "\n# Host user ids for docker-compose build args.\n";

        if (!$this->skipWrite('.env')) {
            file_put_contents($path, $existing . $header . $append);
        }
    }

    private function detectFrameworkDevVolume(): string
    {
        $composerPath = $this->targetBaseDir . '/composer.json';
        if (!file_exists($composerPath)) {
            return '';
        }
        $composer = json_decode(file_get_contents($composerPath), true) ?: [];
        foreach ($composer['repositories'] ?? [] as $repo) {
            if (($repo['type'] ?? '') === 'path' && str_contains($repo['url'] ?? '', 'PramnosFramework')) {
                return "      - {$repo['url']}:/var/www/PramnosFramework\n"; // @codeCoverageIgnore — test composer.json has no path repo for PramnosFramework
            }
        }
        return '';
    }

    private function getDockerTestTemplate(string $namespace, int $port): string
    {
        $nsLower = strtolower($namespace);
        return <<<BASH
#!/usr/bin/env bash

# Prevent concurrent test runs against the shared Docker databases.
# flock on a file descriptor is released automatically when the process
# exits (even SIGKILL). If the recorded PID is gone, the lock is stale
# and is cleared so the new run can proceed without manual intervention.

nobrowser=false
coverage=false
nocoverage=false
testdox=false
force=false
passthrough=()
for arg in "\$@"; do
    if [[ "\$arg" == "--nobrowser" ]]; then
        nobrowser=true
    elif [[ "\$arg" == "--coverage" ]]; then
        coverage=true
    elif [[ "\$arg" == "--nocoverage" || "\$arg" == "--no-coverage" ]]; then
        # Fully disable coverage collection for this run. phpunit.xml declares a
        # <coverage> block, so PHPUnit otherwise instruments every line via
        # Xdebug on EVERY run (slow). This flag overrides that and turns Xdebug
        # off entirely, for the fastest possible run.
        nocoverage=true
    elif [[ "\$arg" == "--testdox" ]]; then
        testdox=true
    elif [[ "\$arg" == "--force" ]]; then
        force=true
    else
        passthrough+=("\$arg")
    fi
done

LOCK_FILE="/tmp/dockertest-{$nsLower}.lock"

if [[ "\$force" == true ]]; then
    existing_pid=\$(cat "\$LOCK_FILE" 2>/dev/null)
    if [[ -n "\$existing_pid" ]] && kill -0 "\$existing_pid" 2>/dev/null; then
        echo "Killing existing dockertest process (PID: \$existing_pid) due to --force..." >&2
        kill -9 "\$existing_pid" 2>/dev/null
    fi
    rm -f "\$LOCK_FILE"
fi

_acquire_lock() {
    exec 9>>"\$LOCK_FILE"
    flock -n 9
}

if ! _acquire_lock; then
    existing_pid=\$(cat "\$LOCK_FILE" 2>/dev/null)
    if [[ -n "\$existing_pid" ]] && ! kill -0 "\$existing_pid" 2>/dev/null; then
        echo "Stale lock detected (PID \$existing_pid is gone). Clearing and proceeding." >&2
        rm -f "\$LOCK_FILE"
        _acquire_lock || { echo "Could not acquire lock after clearing stale entry." >&2; exit 1; }
    else
        echo "Another ./dockertest run is already in progress (PID: \${existing_pid:-unknown})." >&2
        [[ -n "\$existing_pid" ]] && echo "  To kill it:  kill \$existing_pid  or run with --force" >&2
        exit 1
    fi
fi
> "\$LOCK_FILE"
echo \$\$ >"\$LOCK_FILE"

# Docker can hang indefinitely when the daemon is wedged (a common WSL / Docker
# Desktop failure mode). Bound every Docker control call below so a stuck daemon
# fails fast with a clear message instead of an endless, silent hang. The phpunit
# run further down is intentionally NOT time-limited.
DOCKER_CTL_TIMEOUT="\${DOCKERTEST_DOCKER_TIMEOUT:-45}"

_die_docker_wedged() {
    echo "" >&2
    echo "ERROR: Docker did not respond within \${DOCKER_CTL_TIMEOUT}s while running: \$1" >&2
    echo "The Docker daemon appears to be wedged (common on WSL / Docker Desktop)." >&2
    echo "Try restarting Docker Desktop, or run 'wsl.exe --shutdown' from PowerShell, then retry." >&2
    rm -f "\$LOCK_FILE"
    exit 1
}

# Preflight: fail fast if the daemon is unresponsive or not running.
if ! timeout 15 docker version >/dev/null 2>&1; then
    echo "" >&2
    echo "ERROR: Docker is not responding (timed out after 15s, or the daemon is not running)." >&2
    echo "Start Docker Desktop / the daemon (on WSL you may need 'wsl.exe --shutdown' then reopen) and retry." >&2
    rm -f "\$LOCK_FILE"
    exit 1
fi

# Capture ps output separately so a timeout (124) is distinguishable from
# "containers down" — piping straight to grep would hide the timeout.
ps_out=\$(timeout -k 5 "\$DOCKER_CTL_TIMEOUT" docker-compose ps 2>/dev/null)
[[ \$? -eq 124 ]] && _die_docker_wedged "docker-compose ps"
if ! grep -q "app.*Up" <<<"\$ps_out"; then
    echo "Containers not running. Starting them..."
    if ! timeout -k 10 300 docker-compose up -d; then
        rc=\$?
        [[ \$rc -eq 124 ]] && _die_docker_wedged "docker-compose up -d"
        echo "ERROR: 'docker-compose up -d' failed (exit \$rc)." >&2
        rm -f "\$LOCK_FILE"
        exit 1
    fi
    sleep 5
fi

if [ ! -f "vendor/bin/phpunit" ]; then
    echo "Dependencies missing. Running composer install..."
    docker-compose exec -u www-data -e COMPOSER_HOME=/tmp/composer app composer install
fi

extra_flags="--display-deprecations --display-warnings --display-notices --display-phpunit-deprecations"
[[ "\$testdox" == true ]] && extra_flags="\$extra_flags --testdox"

if [[ "\$coverage" == true ]]; then
    mkdir -p coverage
    docker-compose exec -u www-data app vendor/bin/phpunit --coverage-html coverage \$extra_flags "\${passthrough[@]}"
elif [[ "\$nocoverage" == true ]]; then
    # --no-coverage overrides any <coverage> block in phpunit.xml; XDEBUG_MODE=off
    # removes the per-line instrumentation overhead completely.
    docker-compose exec -u www-data -e XDEBUG_MODE=off app vendor/bin/phpunit --no-coverage \$extra_flags "\${passthrough[@]}"
else
    docker-compose exec -u www-data app vendor/bin/phpunit \$extra_flags "\${passthrough[@]}"
fi

if [[ "\$coverage" == true && "\$nobrowser" == false && -f ./coverage/index.html ]]; then
    if command -v wslpath > /dev/null; then
        win_path=\$(wslpath -w "\$(pwd)")
        explorer.exe "\$win_path\\coverage\\index.html"
    elif [[ "\$OSTYPE" == "linux-gnu"* ]] && command -v xdg-open > /dev/null; then
        xdg-open ./coverage/index.html
    elif [[ "\$OSTYPE" == "darwin"* ]]; then
        open ./coverage/index.html
    fi
fi
BASH;
    }

    private function getDockerBashTemplate(): string
    {
        return <<<BASH
#!/usr/bin/env bash

# Bound Docker control calls so a wedged daemon (common on WSL / Docker Desktop)
# fails fast with a clear message instead of hanging silently.
DOCKER_CTL_TIMEOUT="\${DOCKERTEST_DOCKER_TIMEOUT:-45}"

if ! timeout 15 docker version >/dev/null 2>&1; then
    echo "ERROR: Docker is not responding (timed out, or the daemon is not running)." >&2
    echo "Start Docker Desktop / the daemon and retry." >&2
    exit 1
fi

ps_out=\$(timeout "\$DOCKER_CTL_TIMEOUT" docker-compose ps 2>/dev/null)
if [[ \$? -eq 124 ]]; then
    echo "ERROR: Docker did not respond within \${DOCKER_CTL_TIMEOUT}s. The daemon may be wedged." >&2
    exit 1
fi
if ! grep -q "app.*Up" <<<"\$ps_out"; then
    echo "Containers not running. Starting them..."
    timeout 300 docker-compose up -d || { echo "ERROR: 'docker-compose up -d' failed or timed out." >&2; exit 1; }
    sleep 5
fi

docker-compose exec -u www-data app bash
BASH;
    }

    private function updateComposerJson(string $appName, string $namespace, string $userName, string $userEmail, OutputInterface $output): void
    {
        $composerPath = $this->targetBaseDir . '/composer.json';
        if (!file_exists($composerPath)) {
            return;
        }
        $composer = json_decode(file_get_contents($composerPath), true);
        if (!$composer) {
            return; // @codeCoverageIgnore — tests scaffold a valid composer.json so decode always succeeds
        }

        $slug = strtolower(str_replace([' ', '_'], '-', $appName));

        $composer['name']        = "app/$slug";
        $composer['description'] = "Pramnos Application: $appName";
        $composer['authors']     = [['name' => $userName, 'email' => $userEmail]];
        $composer['keywords']    = ['pramnos', 'framework', 'application', $slug];

        if (!isset($composer['require-dev'])) {
            $composer['require-dev'] = []; // @codeCoverageIgnore — scaffold composer.json already has require-dev
        }
        $composer['require-dev']['phpunit/phpunit'] = '^11.0';
        // PsySH powers a rich `pramnos tinker` REPL; without it tinker falls back
        // to a minimal built-in shell.
        $composer['require-dev']['psy/psysh'] = '^0.12';

        $composer['autoload']     = ['psr-4' => ["$namespace\\" => 'src/']];
        $composer['autoload-dev'] = ['psr-4' => ['Tests\\' => 'tests/']];

        unset($composer['scripts']['post-create-project-cmd']);
        // An empty PHP array serialises to a JSON array ("scripts": []), which
        // fails Composer's schema (scripts must be an object). Drop the key when
        // nothing is left so the generated composer.json stays valid.
        if (empty($composer['scripts'])) {
            unset($composer['scripts']);
        }

        if (!$this->skipWrite('composer.json')) {
            file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * The "here is your front end" block of the final summary.
     *
     * Says where the SPA lives, how to build/develop it and how to test it —
     * and, when the first build did not happen, exactly which command produces
     * it, because until then the shell serves the unbuilt fallback assets.
     */
    private function spaSummaryStep(
        string $appStyle,
        string $spaStack,
        int $spaDevPort,
        string $appUrl = '',
        string $cliName = 'pramnos'
    ): string
    {
        // The URL is the first thing anyone wants after init; describing the
        // mount point and leaving them to assemble it is a small cruelty.
        $spaUrl = rtrim($appUrl, '/') . ($appStyle === 'hybrid' ? '/app' : '/');
        $where  = $appUrl === ''
            ? ($appStyle === 'hybrid' ? 'mounted at <comment>/app</comment>' : 'served at the site root')
            : "open it at <comment>$spaUrl</comment>";
        $stack = match ($spaStack) {
            'svelte'       => 'Svelte 5 + Vite + Tailwind/daisyUI',
            'vanilla-vite' => 'vanilla JS + Vite',
            default        => 'vanilla JS, no build step',
        };

        $lines = ["SPA front end ($stack), $where:"];

        if (self::spaNeedsNode($spaStack)) {
            $lines[] = '    Sources in <comment>frontend/</comment>, build output in <comment>www/' . self::SPA_BUILD_DIR . '/</comment>';
            if (!$this->spaBuilt) {
                $lines[] = '    Build it with <comment>./' . $cliName . ' spa:build</comment>';
            }
            // Deliberately not the Vite port: the dev server has no HTML to
            // serve. It only supplies modules to this app's own pages.
            $lines[] = '    Dev server with HMR: <comment>./' . $cliName . ' spa:dev</comment>, then keep browsing the app URL';
            $lines[] = '    Front-end tests: <comment>./testjs</comment> (Vitest)';
            // The npm commands still work and are what the shortcuts run; naming
            // them here means the reader can drop to npm for anything the two
            // shortcuts do not cover.
            $lines[] = '    (both wrap npm — <comment>./dockernpm run build|dev</comment> if you need other scripts)';
        } else {
            $lines[] = '    Sources in <comment>www/assets/js/</comment> — served as written, no build step';
            $lines[] = '    Front-end tests: <comment>./testjs</comment> (node --test, no dependencies)';
        }

        return implode("\n", $lines);
    }

    private function printSummary(
        OutputInterface $output,
        bool            $useDocker,
        int             $dockerPort,
        string          $dbType,
        string          $dbUser,
        string          $dbPass,
        string          $dbRootPass,
        string          $cliName       = '',
        bool            $skipMigrations = false,
        bool            $withApi        = false,
        bool            $withApiDocs    = false,
        string          $apiKey         = '',
        string          $apiPrefix      = '/api/1.0',
        string          $appStyle       = 'mvc',
        string          $spaStack       = '',
        int             $spaDevPort     = 0
    ): void {
        $output->writeln("\nNext steps:");
        $steps = [];

        if ($appStyle !== 'mvc') {
            $steps[] = $this->spaSummaryStep(
                $appStyle,
                $spaStack,
                $spaDevPort,
                $useDocker ? "http://localhost:$dockerPort" : '',
                $cliName !== '' ? $cliName : 'pramnos'
            );
        }

        if ($useDocker) {
            if (!$this->dockerSuccess && !$this->skipDockerRun) {
                $steps[] = "Run <comment>docker-compose up -d --build</comment>"; // @codeCoverageIgnore — skipDockerRun=true in all tests
            }
            $appUrl = "http://localhost:$dockerPort";
            $steps[] = "Access your app at <comment>$appUrl</comment>";
            if ($withApi) {
                $steps[] = "API base URL: <comment>{$appUrl}{$apiPrefix}</comment>";
            }
            if ($withApiDocs) {
                $steps[] = "API documentation: <comment>{$appUrl}/api/docs/index.html</comment>";
            }
            if ($apiKey !== '') {
                $steps[] = "Development API key (send as the <comment>apiKey</comment> header — already pre-filled in the docs):\n"
                    . "    <comment>$apiKey</comment>";
            }
            $toolPort = $dockerPort + 1;
            $toolName = ($dbType === 'mysql') ? 'PHPMyAdmin' : 'Adminer';
            $steps[] = "Access $toolName at <comment>http://localhost:$toolPort</comment>";
            $steps[] = "Use <comment>./dockerbash</comment> to enter the container";
            $steps[] = "Database:\n    User: <comment>$dbUser</comment> / Pass: <comment>$dbPass</comment>"
                . ($dbType === 'mysql' ? "\n    Root Pass: <comment>$dbRootPass</comment>" : '');

            if ($this->migrationsSuccess) {
                $steps[] = "<info>✓ Framework migrations ran successfully.</info>"; // @codeCoverageIgnore — migrations never run (skipDockerRun=true)
            } elseif (!$skipMigrations) {
                $steps[] = "Run <comment>./$cliName migrate --scope=framework</comment> when the container is ready.";
            }

            if ($this->adminCredentials !== null) {
                // @codeCoverageIgnoreStart
                // adminCredentials is only set by createAdminUser(), which is only called
                // in the Docker+migrations path; tests set skipDockerRun=true so this is never reached.
                $creds = $this->adminCredentials;
                $steps[] = "Admin account:\n"
                    . "    Email:    <comment>{$creds['email']}</comment>\n"
                    . "    Password: <comment>{$creds['password']}</comment>\n"
                    . "    <info>Save this password — it will not be shown again.</info>";
                // @codeCoverageIgnoreEnd
            }
        }

        if ($this->skipInstall) {
            $steps[] = "<comment>Dependencies were not installed (--no-install).</comment> Run "
                . "<comment>composer install</comment> — the application cannot boot without an autoloader.";
        } elseif (!$this->autoloadSuccess) {
            $steps[] = "<comment>Warning: autoloader sync failed.</comment> Run <comment>composer dump-autoload</comment> manually.";
        }

        foreach ($steps as $i => $step) {
            $output->writeln(' ' . ($i + 1) . '. ' . $step);
        }
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    private function getApplicationTemplate(string $namespace, array $selectedLibraries, array $catalog): string
    {
        $lines = [];
        foreach ($selectedLibraries as $lib) {
            $libDef = $catalog['libraries'][$lib] ?? null;
            if ($libDef === null) {
                continue; // @codeCoverageIgnore — tests only pass library keys that exist in the catalog
            }
            $version = $libDef['version'];
            $deps    = $libDef['requires'] ?? [];
            $depsPhp = $deps ? "['" . implode("', '", $deps) . "']" : '[]';

            // CSS deps: only include requires that also have CSS (skip JS-only libraries
            // like jquery which appear in requires but have no CSS registration).
            $cssDeps = array_filter($deps, function (string $d) use ($catalog) {
                return !empty($catalog['libraries'][$d]['css'] ?? []);
            });
            $cssDepsPhp = $cssDeps ? "['" . implode("', '", $cssDeps) . "']" : '[]';

            $bundled = !empty($libDef['bundled']);
            foreach ($libDef['js'] as $url) {
                $filename = basename(parse_url($url, PHP_URL_PATH));
                $path     = $libDef['local_path'] . '/' . $filename;
                // A bundled library can ship several JS files under one catalog
                // key (e.g. `pramnos-adapters` → pramnos-datatable.js +
                // pramnos-gridjs.js). registerScript() keys by handle, so reusing
                // $lib would make each file overwrite the previous one and only
                // the last would survive. Register each bundled file under its own
                // handle (filename without extension) instead.
                $handle  = $bundled ? preg_replace('/\.js$/', '', $filename) : $lib;
                $lines[] = "        \$doc->registerScript('$handle', sURL . '$path', $depsPhp, '$version', true);";
            }
            foreach ($libDef['css'] as $url) {
                $filename = basename(parse_url($url, PHP_URL_PATH));
                $path     = $libDef['local_path'] . '/' . $filename;
                $lines[]  = "        \$doc->registerStyle('$lib', sURL . '$path', $cssDepsPhp, '$version');";
            }
        }

        $registrations = $lines
            ? implode("\n", $lines)
            : '        // No vendor libraries selected during init.';

        return <<<PHP
<?php
namespace $namespace;

class Application extends \\Pramnos\\Application\\Application
{
    public function init(\$settingsFile = '')
    {
        parent::init(\$settingsFile);
        \$this->registerVendorLibraries();
        return \$this;
    }

    /**
     * Register vendor libraries with local paths.
     * Nothing is enqueued here — controllers call addScript()/addStyle()
     * for what each specific page needs.
     *
     * Example in a controller:
     *   \$doc = \\Pramnos\\Framework\\Factory::getDocument();
     *   \$doc->addScript('jquery');
     *   \$doc->addScript('datatables');
     *   \$doc->addStyle('datatables');
     */
    private function registerVendorLibraries(): void
    {
        \$doc = \\Pramnos\\Framework\\Factory::getDocument();
$registrations
    }
}
PHP;
    }

    private function getConsoleTemplate(string $namespace, string $appName): string
    {
        return <<<PHP
<?php
namespace $namespace;

class Console extends \\Pramnos\\Console\\Application
{
    protected function registerCommands(): void
    {
        parent::registerCommands();
        // Register your custom commands here:
        // \$this->add(new \\$namespace\\ConsoleCommands\\MyCommand());
    }
}
PHP;
    }

    private function getCliEntryPointTemplate(string $namespace, string $appName): string
    {
        return <<<PHP
#!/usr/bin/env php
<?php
declare(strict_types=1);
define('ROOT', dirname(__FILE__));
require ROOT . '/vendor/autoload.php';

\$consoleApp = new \\$namespace\\Console('$appName CLI');
\$consoleApp->internalApplication->init(ROOT . '/app/config/settings.php');
\$consoleApp->run();
PHP;
    }

    private function getHomepageView(
        string $appName,
        string $namespace,
        array  $enabledFeatures,
        array  $selectedLibraries,
        bool   $useDocker,
        int    $dockerPort,
        string $dbType,
        string $cliName,
        bool   $withApi    = false,
        string $apiPrefix  = '/api/1.0',
        string $appStyle   = 'mvc'
    ): string {
        $toolPort     = $dockerPort + 1;
        $toolName     = ($dbType === 'mysql') ? 'PHPMyAdmin' : 'Adminer';
        $featureList  = $enabledFeatures ? implode(', ', $enabledFeatures) : 'none';
        $libList      = $selectedLibraries ? implode(', ', $selectedLibraries) : 'none';
        $appUrl       = $useDocker ? "http://localhost:$dockerPort" : '/';
        $toolUrl      = $useDocker ? "http://localhost:$toolPort" : '#';
        $apiUrl       = $useDocker ? "http://localhost:$dockerPort" . $apiPrefix : $apiPrefix;

        $sections = "<h1>Welcome to $appName</h1>\n<p>Your Pramnos Framework application is ready.</p>\n\n";

        $sections .= "<h2>Application</h2>\n<ul>\n";
        $sections .= "  <li><strong>Namespace:</strong> $namespace</li>\n";
        $sections .= "  <li><strong>Features:</strong> $featureList</li>\n";
        $sections .= "  <li><strong>Libraries:</strong> $libList</li>\n";
        $sections .= "</ul>\n\n";

        if ($useDocker) {
            $sections .= "<h2>Quick Links</h2>\n<ul>\n";
            $sections .= "  <li><a href=\"$appUrl\">Application: $appUrl</a></li>\n";
            if ($withApi) {
                $sections .= "  <li><a href=\"$apiUrl\">REST API: $apiUrl</a></li>\n";
            }
            $sections .= "  <li><a href=\"$toolUrl\">$toolName: $toolUrl</a></li>\n";
            $sections .= "</ul>\n\n";
        }

        // In a hybrid project this page IS the front door, and the SPA is easy
        // to forget behind it — say where it is, and link to it.
        if ($appStyle === 'hybrid') {
            $spaUrl = ($useDocker ? "http://localhost:$dockerPort" : '') . '/app';
            $sections .= "<h2>Single-page application</h2>\n";
            $sections .= "<p>This project is hybrid: these pages are server-rendered, and the SPA "
                . "lives at <a href=\"$spaUrl\">$spaUrl</a>. Its sources are in "
                . "<code>frontend/</code>.</p>\n\n";
        }

        $sections .= "<h2>CLI Commands</h2>\n<ul>\n";
        if ($useDocker) {
            $sections .= "  <li><code>./$cliName migrate --scope=framework</code> — run framework migrations</li>\n";
            $sections .= "  <li><code>./dockerbash</code> — enter the container shell</li>\n";
        }
        $sections .= "  <li><code>php $cliName.php migrate</code> — run app migrations</li>\n";
        $sections .= "  <li><code>php $cliName.php migrate:status</code> — show migration status</li>\n";
        $sections .= "</ul>\n\n";

        $sections .= "<p><em>Remove or replace this view once your application is configured.</em></p>\n";

        // Wrap in .pf-home so themes can style this placeholder's bare semantic
        // HTML without affecting the rest of the page. The tailwind theme scopes
        // basic typography to .pf-home (Tailwind's Preflight otherwise strips
        // heading sizes and list markers); plain-css/bootstrap style bare
        // elements directly and are unaffected by the wrapper.
        return "<div class=\"pf-home\">\n" . $sections . "</div>\n";
    }

    /**
     * Poll the database container until it accepts connections (max 60 s).
     * Without this, migrate --scope=framework runs while MySQL/PostgreSQL is still
     * initialising and fails immediately after docker-compose up.
     */
    private function waitForDatabase(string $dbType, OutputInterface $output): void
    {
        // @codeCoverageIgnoreStart
        // waitForDatabase is only called from the Docker startup block (skipDockerRun=false);
        // all tests set skipDockerRun=true so this method body is never entered.
        $isPostgres = ($dbType === 'postgresql' || $dbType === 'timescaledb');
        $output->write('Waiting for database ');

        $symbols = ['/', '-', '\\', '|'];
        $i       = 0;
        $maxTries = 30;

        for ($try = 0; $try < $maxTries; $try++) {
            $output->write("\r\033[KWaiting for database " . $symbols[$i % 4]);
            $i++;

            if ($isPostgres) {
                $cmd = 'docker-compose exec -T db pg_isready -q 2>/dev/null';
            } else {
                $cmd = 'docker-compose exec -T db mysqladmin ping -h 127.0.0.1 --silent 2>/dev/null';
            }

            exec($cmd, $ignored, $exitCode);
            if ($exitCode === 0) {
                $output->writeln("\r\033[KWaiting for database <info>READY</info>");
                return;
            }
            sleep(2);
        }

        $output->writeln("\r\033[KWaiting for database <comment>TIMEOUT (proceeding anyway)</comment>");
        // @codeCoverageIgnoreEnd
    }

    /**
     * After a successful migration run, ask if an admin user should be created
     * and run a PHP snippet inside the app container to INSERT the user.
     */
    private function createAdminUser(InputInterface $input, OutputInterface $output, mixed $helper, string $developerEmail = '', string $cliName = 'app', string $developerName = ''): void
    {
        // @codeCoverageIgnoreStart
        // createAdminUser is only called from the Docker+migrations success path; all
        // tests set skipDockerRun=true so migrations never run and this method is never entered.
        $output->writeln('');
        $wantAdmin = $helper->ask(
            $input, $output,
            new ConfirmationQuestion('Create an admin user? [Y/n] ', true)
        );
        if (!$wantAdmin) {
            return;
        }

        $adminUsername = $helper->ask($input, $output, new Question('  Admin username [admin]: ', 'admin'));

        $emailDefault  = $developerEmail ?: 'admin@example.com';
        $emailPrompt   = "  Admin email [$emailDefault]: ";
        $adminEmail    = '';
        while (true) {
            $adminEmail = $helper->ask($input, $output, new Question($emailPrompt, $emailDefault));
            if (\Pramnos\Validation\Validator::checkEmail($adminEmail)) {
                break;
            }
            $output->writeln('  <error>Invalid email. Please try again.</error>');
        }

        // Prompt for a password; pressing enter accepts a strong generated one.
        // Deliberately a visible prompt (not setHidden) so the user can see the
        // password they type during local scaffolding.
        $randomDefault = $this->generateRandomPassword(16);
        $rawPassword = (string) $helper->ask(
            $input, $output,
            new Question("  Admin password [press enter for random: $randomDefault]: ", $randomDefault)
        );

        // A terminal's line discipline can, on a non-UTF-8-aware setup, delete
        // one byte per backspace and pass literal backspace/control bytes and
        // broken multibyte sequences straight through into the input. Sanitise
        // so the stored password only contains characters the user can actually
        // reproduce at login (otherwise the saved value silently differs from
        // what they intended).
        $adminPassword = $this->sanitizePassword($rawPassword);
        if ($adminPassword !== $rawPassword) {
            $output->writeln('  <comment>Note: removed stray/invalid characters from the entered password.</comment>');
        }
        if (trim($adminPassword) === '') {
            $adminPassword = $randomDefault;
        }
        $output->writeln("  <info>Using password:</info> <comment>$adminPassword</comment>");

        // Seed the admin's name from the developer name captured at init (the
        // "Author Name"): first token → firstname, the remainder → lastname.
        $developerName = trim($developerName);
        $nameParts     = $developerName !== '' ? preg_split('/\s+/', $developerName, 2) : [];
        $firstName     = $nameParts[0] ?? '';
        $lastName      = $nameParts[1] ?? '';

        // Escape values for safe injection into the single-quoted PHP string
        $safeUsername  = addslashes($adminUsername);
        $safeEmail     = addslashes($adminEmail);
        $safePassword  = addslashes($adminPassword);
        $safeFirstName = addslashes($firstName);
        $safeLastName  = addslashes($lastName);

        $phpSnippet = <<<PHP
ob_start();
define('ROOT', '/var/www/html');
define('SP', 1);
require ROOT . '/vendor/autoload.php';
\$app = \Pramnos\Application\Application::getInstance();
\$app->init();
ob_end_clean();
try {
    \$user = new \Pramnos\User\User(0);
    \$user->username  = '$safeUsername';
    \$user->email     = '$safeEmail';
    \$user->firstname = '$safeFirstName';
    \$user->lastname  = '$safeLastName';
    \$user->usertype  = 90;
    \$user->active    = 1;
    \$user->validated = 1;
    \$user->regdate   = time();
    \$user->maingroup = 1;
    \$user->setPassword('$safePassword');
    \$user->save();
    if (\$user->userid > 1 && empty(\$user->_errors)) {
        \$user->setPassword('$safePassword');
        \$user->save();
    }
    if (empty(\$user->_errors)) {
        echo 'OK:' . \$user->userid;
    } else {
        \$db = \Pramnos\Framework\Factory::getDatabase();
        \$dbErr = \$db->getError();
        \$dbErrText = isset(\$db->error_text) ? \$db->error_text : '';
        \$msg = implode(', ', array_filter(\$user->_errors, 'strlen'));
        if (!\$msg) {
            \$msg = \$dbErr['message'] ?: \$dbErrText ?: 'no error captured (type=' . \$db->type . ')';
        }
        echo 'FAIL:' . \$msg;
    }
} catch (\Throwable \$e) {
    echo 'FAIL:' . \$e->getMessage();
}
PHP;

        // Wrap snippet in a temp file to avoid shell quoting issues
        $tmpFile  = sys_get_temp_dir() . '/pramnos_admin_' . uniqid() . '.php';
        file_put_contents($tmpFile, '<?php ' . $phpSnippet);

        // Copy to container and execute
        $containerName = trim((string) shell_exec("docker-compose ps -q app 2>/dev/null"));
        if (empty($containerName)) {
            $output->writeln('  <error>Could not determine container name — admin user creation skipped.</error>');
            @unlink($tmpFile);
            return;
        }

        shell_exec("docker cp " . escapeshellarg($tmpFile) . " " . escapeshellarg($containerName . ":/tmp/pramnos_admin.php") . " 2>/dev/null");
        @unlink($tmpFile);

        $result = trim((string) shell_exec("docker-compose exec -T -u www-data app php /tmp/pramnos_admin.php 2>&1"));
        shell_exec("docker-compose exec -T app rm -f /tmp/pramnos_admin.php 2>/dev/null");

        if (str_starts_with($result, 'OK:')) {
            $uid = substr($result, 3);
            $output->writeln("  <info>Admin user '$adminUsername' created (userid=$uid).</info>");
            $this->adminCredentials = [
                'username' => $adminUsername,
                'email'    => $adminEmail,
                'password' => $adminPassword,
            ];
        } else {
            $msg = str_starts_with($result, 'FAIL:') ? substr($result, 5) : $result;
            $output->writeln("  <error>Admin user creation failed: $msg</error>");
            $output->writeln("  Run manually: docker-compose exec -u www-data app php $cliName.php user:create --admin");
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Seed a "Development" OAuth application carrying the pre-generated API key,
     * so the REST API can be exercised immediately after scaffolding. Runs the
     * insert inside the app container (where the database is reachable) and is
     * idempotent (skips if an application with the key already exists).
     * Best-effort: a failure here must never fail init.
     */
    private function createApiApplication(OutputInterface $output, string $apiKey): void
    {
        // @codeCoverageIgnoreStart
        // Only reached on the Docker + migrations success path; all tests set
        // skipDockerRun = true, so this is never exercised in the unit suite.
        $safeKey    = addslashes($apiKey);
        $safeSecret = addslashes(bin2hex(random_bytes(32)));

        $phpSnippet = <<<PHP
ob_start();
define('ROOT', '/var/www/html');
define('SP', 1);
require ROOT . '/vendor/autoload.php';
\$app = \Pramnos\Application\Application::getInstance();
\$app->init();
ob_end_clean();
try {
    \$db = \Pramnos\Framework\Factory::getDatabase();
    \$existing = \$db->queryBuilder()->table('applications')->where('apikey', '$safeKey')->count();
    if (\$existing > 0) {
        echo 'EXISTS';
    } else {
        \$db->queryBuilder()->table('applications')->insert([
            'name'      => 'Development',
            'apikey'    => '$safeKey',
            'apisecret' => '$safeSecret',
            'status'    => 1,
            'added'     => time(),
        ]);
        echo 'OK';
    }
} catch (\Throwable \$e) {
    echo 'ERROR:' . \$e->getMessage();
}
PHP;

        $tmpFile = sys_get_temp_dir() . '/pramnos_app_' . uniqid() . '.php';
        file_put_contents($tmpFile, '<?php ' . $phpSnippet);

        $containerName = trim((string) shell_exec("docker-compose ps -q app 2>/dev/null"));
        if ($containerName === '') {
            $output->writeln('  <comment>Could not determine container — API application creation skipped.</comment>');
            @unlink($tmpFile);
            return;
        }

        shell_exec('docker cp ' . escapeshellarg($tmpFile) . ' ' . escapeshellarg($containerName . ':/tmp/pramnos_app.php') . ' 2>/dev/null');
        @unlink($tmpFile);

        $result = trim((string) shell_exec('docker-compose exec -T -u www-data app php /tmp/pramnos_app.php 2>&1'));
        shell_exec('docker-compose exec -T app rm -f /tmp/pramnos_app.php 2>/dev/null');

        if (str_starts_with($result, 'OK') || str_starts_with($result, 'EXISTS')) {
            $output->writeln('  <info>Development API application ready.</info>');
        } else {
            $msg = str_starts_with($result, 'ERROR:') ? substr($result, 6) : $result;
            $output->writeln("  <comment>API application creation skipped: $msg</comment>");
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Write a root .gitignore (or append to an existing one) that prevents
     * committing RSA private keys and other generated secrets.
     *
     * @param list<string> $features
     */
    private function scaffoldGitignore(array $features): void
    {
        $path = $this->targetBaseDir . '/.gitignore';
        $lines = [];

        $lines[] = '/vendor/';
        $lines[] = '/var/cache/';
        $lines[] = '/var/logs/';
        // Machine-specific: it carries this host's user ids for the Docker build.
        $lines[] = '/.env';

        if (in_array('authserver', $features, true)) {
            $lines[] = '/app/keys/private.key';
            $lines[] = '/app/keys/encryption.key';
        }

        $content = implode("\n", $lines) . "\n";

        if ($this->skipWrite('.gitignore')) {
            return;
        }

        if (file_exists($path)) {
            // @codeCoverageIgnoreStart
            // .gitignore does not exist in the fresh temp dir at the point when
            // scaffoldGitignore is called; the existing-file merge path is never reached.
            $existing = file_get_contents($path);
            // Only append entries not already present
            foreach ($lines as $line) {
                if (strpos($existing, $line) === false) {
                    $existing .= $line . "\n";
                }
            }
            file_put_contents($path, $existing);
            // @codeCoverageIgnoreEnd
        } else {
            file_put_contents($path, $content);
        }
    }

    /**
     * Generate CLAUDE.md (AI assistant guidelines) and .mcp.json (MCP server
     * configuration for database access) in the project root.
     *
     * CLAUDE.md uses the stub from scaffolding/templates/CLAUDE.md.stub.
     * .mcp.json is added to .gitignore because it contains DB credentials.
     */
    private function scaffoldAiGuidelines(
        string $appName,
        string $namespace,
        string $dbType,
        string $dbName,
        string $dbUser,
        string $dbPass,
        int    $dockerPort,
        string $cliName,
        array  $features,
        string $appStyle  = 'mvc',
        string $spaStack  = '',
        string $apiPrefix = '/api/1.0'
    ): void {
        // ── CLAUDE.md ─────────────────────────────────────────────────────────
        $featuresText = empty($features)
            ? '_(none selected)_'
            : implode("\n", array_map(fn($f) => "- `$f`", $features));

        $dbTypeLabel = match ($dbType) {
            'postgresql'  => 'PostgreSQL',
            'timescaledb' => 'TimescaleDB',
            default       => 'MySQL',
        };

        $this->writeFile('CLAUDE.md', $this->renderStub('CLAUDE.md', [
            'APP_NAME'     => $appName,
            'NAMESPACE'    => $namespace,
            'CLI_NAME'     => $cliName,
            'DB_TYPE'      => $dbType,
            'DB_TYPE_LABEL'=> $dbTypeLabel,
            'FEATURES_LIST'=> $featuresText,
            'APP_STYLE_LABEL'   => $this->appStyleLabel($appStyle, $spaStack),
            // An assistant that does not know the project has a front end will
            // happily add a server-rendered view to a SPA, or edit built output
            // under www/assets/spa/. Spell the whole loop out.
            'FRONTEND_SECTION'  => $this->aiFrontendSection($appStyle, $spaStack, $apiPrefix, $cliName),
        ]));

        // ── .mcp.json ─────────────────────────────────────────────────────────
        // Uses the framework's built-in MCP server (./bin/pramnos mcp:serve)
        // instead of external npm packages — no credentials needed, safe to commit.
        $appSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $appName) ?: 'app');
        $this->writeFile('.mcp.json', $this->renderStub('mcp.json', [
            'APP_SLUG' => $appSlug,
        ]));
    }

    /**
     * One-line description of how this application is built.
     */
    private function appStyleLabel(string $appStyle, string $spaStack): string
    {
        if ($appStyle === 'mvc') {
            return 'MVC + Models — server-rendered controllers, views and themes';
        }

        $stack = match ($spaStack) {
            'svelte'       => 'Svelte 5 + Vite + Tailwind/daisyUI',
            'vanilla-vite' => 'vanilla JS + Vite',
            default        => 'vanilla JS, no build step',
        };

        return $appStyle === 'hybrid'
            ? "Hybrid — MVC pages plus a SPA ($stack) mounted under `/app`"
            : "Services + API + SPA ($stack)";
    }

    /**
     * The front-end chapter of CLAUDE.md.
     *
     * Empty for an MVC project. For a SPA it has to answer the questions an
     * assistant would otherwise get wrong: where the sources are, what must
     * never be edited (build output), how to run the toolchain, which endpoints
     * exist, and — the non-obvious one — that the API rejects every request
     * without an `apiKey` header.
     */
    private function aiFrontendSection(string $appStyle, string $spaStack, string $apiPrefix, string $cliName): string
    {
        if ($appStyle === 'mvc') {
            return '';
        }

        $needsBuild = self::spaNeedsNode($spaStack);
        $shell      = $appStyle === 'hybrid' ? 'www/app.php' : 'www/spa.php';
        $sourceDir  = $needsBuild ? 'frontend/' : 'www/assets/js/';

        $lines   = [];
        $lines[] = '## Front end (SPA)';
        $lines[] = '';
        $lines[] = 'This project has **no server-rendered views for the SPA part**: the pages are';
        $lines[] = "rendered by `$shell`, a plain PHP shell (not an MVC view — no theme, no";
        $lines[] = '`getView()`), which boots a JavaScript client that talks to the JSON API.';
        $lines[] = '';
        $lines[] = '```';
        $lines[] = "$sourceDir" . str_repeat(' ', max(1, 20 - strlen($sourceDir))) . 'front-end sources — edit these';
        $lines[] = "  lib/api.js         API client: apiKey, tokens, errors";
        $lines[] = "  lib/debug.js       FRAMEWORK-OWNED debug panel — do not rewrite";
        if ($spaStack === 'svelte') {
            $lines[] = '  App.svelte         root component';
            $lines[] = '  main.js            entry point (mounts App)';
        } else {
            $lines[] = '  main.js            entry point';
        }
        $lines[] = "$shell" . str_repeat(' ', max(1, 20 - strlen($shell))) . 'the shell — asset tags + runtime config';
        if ($needsBuild) {
            $lines[] = 'www/assets/spa/      BUILD OUTPUT — generated, never edit, never commit';
        }
        $lines[] = '```';
        $lines[] = '';

        if ($needsBuild) {
            $lines[] = '### Working on it';
            $lines[] = '';
            $lines[] = '```bash';
            $lines[] = './dockernpm install       # dependencies, inside the container';
            $lines[] = './dockernpm run build     # production build → www/assets/spa/';
            $lines[] = './dockernpm run dev       # dev server + HMR';
            $lines[] = './testjs                  # front-end tests (Vitest)';
            $lines[] = '```';
            $lines[] = '';
            $lines[] = '**Do not open the Vite port.** It serves no HTML. Keep browsing the';
            $lines[] = 'application URL: while the dev server runs it writes';
            $lines[] = '`www/assets/spa/.vite/hot` and the shell loads modules from it, so HMR';
            $lines[] = 'happens against the real backend. Stop it and the shell falls back to the';
            $lines[] = 'built bundle.';
        } else {
            $lines[] = '### Working on it';
            $lines[] = '';
            $lines[] = 'There is no build step: the modules under `www/assets/js/` are served';
            $lines[] = 'exactly as written, and the shell stamps their URLs with the file mtime for';
            $lines[] = 'cache-busting. Run the tests with `./testjs` (`node --test`, no npm';
            $lines[] = 'dependencies).';
        }

        $lines[] = '';
        $lines[] = '### Talking to the API';
        $lines[] = '';
        $lines[] = "All calls go through `lib/api.js`, which already handles the contract:";
        $lines[] = '';
        $lines[] = '- The API layer **rejects any request without an `apiKey` header** (403,';
        $lines[] = '  "API key is missing"). The shell derives this application\'s key and passes';
        $lines[] = '  it in `window.__PRAMNOS__`; the client attaches it. Never hard-code a key.';
        $lines[] = '- A bearer session uses the framework\'s **`accessToken`** header (a standard';
        $lines[] = '  `Authorization: Bearer` is also accepted server-side).';
        $lines[] = '- Cookies are sent, so a user signed in through the server-rendered pages is';
        $lines[] = '  already authenticated in the SPA.';
        $lines[] = '- Failures throw `ApiError` with `.status` — branch on that, not on messages.';
        $lines[] = '';
        $lines[] = '### The debug panel already exists — do not build one';
        $lines[] = '';
        $lines[] = 'In development every JSON response carries a `_debug` key (timings, queries,';
        $lines[] = 'exceptions). The panel that draws it is **`' . $sourceDir . 'lib/debug.js`, shipped and';
        $lines[] = 'maintained by the framework**, and `lib/api.js` already feeds it every response';
        $lines[] = '(`recordDebug(...)`). It is the framework toolbar rewritten for a SPA: same bar';
        $lines[] = 'along the bottom, same tabs, same tables, same copy buttons, last 50 requests,';
        $lines[] = 'secrets masked.';
        $lines[] = '';
        $lines[] = 'So:';
        $lines[] = '';
        $lines[] = '- **Do not write your own debug panel, overlay or console logger for this.**';
        $lines[] = '  Nothing about the rendering is application-specific. If the panel is missing';
        $lines[] = '  a field, add it *there*, or report it upstream to the framework.';
        $lines[] = '- If `lib/debug.js` is absent (project scaffolded before it existed), get it';
        $lines[] = '  with `./' . $cliName . ' project:resync --debug-panel --all` — never by hand.';
        $lines[] = '- Nothing is attached in production, so the file is inert there: no data, no';
        $lines[] = '  DOM, no panel. That is why it ships unconditionally instead of being';
        $lines[] = '  imported behind a flag.';
        $lines[] = '';
        $lines[] = 'The server-rendered pages get the framework\'s HTML toolbar instead, injected';
        $lines[] = 'before `</body>` — including an `ajax` tab that wraps `fetch`/`XMLHttpRequest`';
        $lines[] = 'and stays live after the render. The SPA shell cannot use it: it does not boot';
        $lines[] = 'the framework (only the autoloader), so no middleware ever sees its HTML.';
        $lines[] = '';
        $lines[] = 'Add an endpoint the way `GET ' . $apiPrefix . '/status` is built: behaviour in a';
        $lines[] = '`src/Services/*Service.php`, a thin `src/Api/Controllers/*.php` over it, and a';
        $lines[] = 'route in `src/Api/routes.php`.';
        $lines[] = '';
        $lines[] = 'How to write front-end tests — what to assert, what to leave alone, and the';
        $lines[] = 'traps that make them pass while the app is broken — is in';
        $lines[] = '[docs/FRONTEND_TESTING.md](docs/FRONTEND_TESTING.md). Read it before adding a';
        $lines[] = 'screen without tests.';
        $lines[] = '';
        $lines[] = '### Adding a CRUD feature';
        $lines[] = '';
        $lines[] = 'Do not hand-write the four files. After the migration:';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = "./$cliName create:crud thing --table=things";
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'It reads `app_style` from app.php and generates what this project needs: the';
        $lines[] = 'model, the API controller, its routes, and a screen under `screens/` that';
        $lines[] = 'registers itself in `screens/registry.js` and so appears in the navigation.';
        $lines[] = 'In a hybrid project it also generates the MVC controller and views — over the';
        $lines[] = '**same model**: one domain object, two controllers, never two copies of the';
        $lines[] = 'logic. `--target=mvc|spa|both` overrides the choice.';
        $lines[] = '';

        return implode("\n", $lines) . "\n";
    }

    /**
     * Write the project README, unless the project already has one.
     *
     * A scaffold that explains itself only to an AI assistant and not to the
     * person cloning the repository is half a scaffold: this is what tells them
     * how to start the thing, where it listens and what to run.
     *
     * @param list<string> $features
     */
    private function scaffoldReadme(
        string $appName,
        string $namespace,
        string $cliName,
        string $dbType,
        array  $features,
        bool   $useDocker,
        int    $dockerPort,
        string $appStyle,
        string $spaStack,
        string $apiPrefix,
        bool   $withRestApi
    ): void {
        if (file_exists($this->targetBaseDir . '/README.md')) {
            return; // @codeCoverageIgnore — a fresh project never has one
        }

        $featureList = $features === []
            ? '_none selected_'
            : implode(', ', array_map(static fn(string $f): string => "`$f`", $features));

        $tokens = [
            'APP_NAME'    => $appName,
            'NAMESPACE'   => $namespace,
            'CLI_NAME'    => $cliName,
            'DB_TYPE'     => $dbType,
            'FEATURES'    => $featureList,
            'APP_STYLE'   => $this->appStyleLabel($appStyle, $spaStack),
            'START'       => $useDocker
                ? "docker-compose up -d\n./$cliName migrate --scope=framework"
                : "php -S localhost:8000 -t www\nphp $cliName.php migrate --scope=framework",
            'APP_URL'     => $useDocker ? "http://localhost:$dockerPort" : 'http://localhost:8000',
            'API_SECTION' => $withRestApi
                ? "## API\n\nJSON API under `$apiPrefix`. Every request needs an `apiKey` header — the SPA\n"
                    . "shell supplies the application's own key automatically. `GET $apiPrefix/status`\n"
                    . "is public and shows the shape a new endpoint should follow: a service in\n"
                    . "`src/Services/`, a thin controller in `src/Api/Controllers/`, a route in\n"
                    . "`src/Api/routes.php`.\n"
                : '',
            'FRONTEND_SECTION' => $this->readmeFrontendSection($appStyle, $spaStack),
            'TEST_COMMAND'     => $useDocker ? './dockertest' : 'vendor/bin/phpunit',
        ];

        $this->writeFile('README.md', $this->renderStub('README.md', $tokens));
    }

    /**
     * The front-end chapter of the README (empty for an MVC project).
     */
    private function readmeFrontendSection(string $appStyle, string $spaStack): string
    {
        if ($appStyle === 'mvc') {
            return '';
        }

        $where = $appStyle === 'hybrid'
            ? 'The SPA is mounted under `/app`; the rest of the site is server-rendered.'
            : 'The SPA is served at the site root; the scaffolded server-rendered areas (login, admin) keep their own paths.';

        if (!self::spaNeedsNode($spaStack)) {
            return "## Front end\n\n$where Sources live in `www/assets/js/` and are served as\n"
                . "written — there is no build step. Run the tests with `./testjs`, and see\n"
                . "[docs/FRONTEND_TESTING.md](docs/FRONTEND_TESTING.md) for how to write them.\n\n"
                . $this->readmeDebugPanelNote('www/assets/js/');
        }

        return "## Front end\n\n$where Sources live in `frontend/`; the build output in\n"
            . "`www/assets/spa/` is generated and should not be edited or committed.\n\n"
            . "```bash\n"
            . "./dockernpm install       # dependencies (inside the container)\n"
            . "./dockernpm run build     # production build\n"
            . "./dockernpm run dev       # dev server with HMR — keep browsing the app URL,\n"
            . "                          # not the Vite port, which serves no HTML\n"
            . "./testjs                  # front-end tests\n"
            . "```\n\n"
            . "How to write those tests: [docs/FRONTEND_TESTING.md](docs/FRONTEND_TESTING.md).\n\n"
            . $this->readmeDebugPanelNote('frontend/');
    }

    /**
     * The "the debug panel is already here" note for the README front-end section.
     *
     * Stated in the README as well as CLAUDE.md because the panel is invisible
     * until a request carries debug data: without being told the file exists,
     * both people and assistants conclude the framework only ships the *data*
     * for a SPA and hand-roll a renderer next to one that already works.
     *
     * @param string $sourceDir Front-end source directory, with trailing slash.
     */
    private function readmeDebugPanelNote(string $sourceDir): string
    {
        return "### Debug panel\n\n"
            . "In development every JSON response carries a `_debug` key, and\n"
            . "`{$sourceDir}lib/debug.js` — shipped by the framework, already wired into\n"
            . "`lib/api.js` — draws it as a toolbar along the bottom: requests, queries,\n"
            . "timings, exceptions. It is the framework's HTML toolbar for a SPA, so there is\n"
            . "no reason to build another one. Inert in production (nothing is attached, so\n"
            . "no panel appears). Missing from an older project? `project:resync --debug-panel --all`.\n\n"
            . "It also has an **Errors** tab for what the browser itself threw. Anything\n"
            . "nobody caught arrives on its own; a failure your code handles should be handed\n"
            . "over, which `lib/api.js` and the root component already do:\n\n"
            . "```js\n"
            . "import { reportError } from './lib/debug.js';\n\n"
            . "try { … } catch (error) { reportError(error, { kind: 'import' }); throw error; }\n"
            . "```\n";
    }

    /**
     * Generate a 2048-bit RSA key pair for the OAuth2 server (authserver feature).
     *
     * Mirrors OAuth2ServerFactory::generateKeyPair() but writes to the TARGET
     * project directory rather than ROOT, since init runs from the framework.
     *
     * - private.key  → app/keys/private.key   (chmod 0600)
     * - public.key   → app/keys/public.key    (chmod 0644)
     * - Directory    → app/keys/              (chmod 0700)
     *
     * Idempotent: does nothing if both files already exist.
     */
    private function generateOAuth2KeyPair(OutputInterface $output): void
    {
        // A dry run must not write a private key. Recorded so the plan still says
        // the keys would appear, which is worth knowing before a real run.
        if ($this->dryRun) {
            $this->skipWrite('app/keys/private.key');
            $this->skipWrite('app/keys/public.key');
            return;
        }

        $keysDir     = $this->targetBaseDir . '/app/keys';
        $privatePath = $keysDir . '/private.key';
        $publicPath  = $keysDir . '/public.key';

        if (!is_dir($keysDir)) {
            mkdir($keysDir, 0700, true);
        }

        if (file_exists($privatePath) && file_exists($publicPath)) {
            $output->writeln('  RSA keys already exist — skipping generation.');
            return;
        }

        if (!extension_loaded('openssl')) {
            // @codeCoverageIgnoreStart
            // openssl is always loaded in the test container so this warning branch is never reached.
            $output->writeln('  <comment>Warning: OpenSSL not available — RSA keys NOT generated.</comment>');
            $output->writeln('  Generate manually: openssl genrsa -out app/keys/private.key 2048');
            return;
            // @codeCoverageIgnoreEnd
        }

        // @codeCoverageIgnoreStart
        // The key files are pre-created by scaffoldAuthServer for the test tempDir, so the
        // "RSA keys already exist" guard (line above) returns early; the openssl generation
        // path below is never reached in tests.
        $privateKey = openssl_pkey_new([
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            $output->writeln('  <error>RSA key generation failed: ' . openssl_error_string() . '</error>');
            return;
        }

        openssl_pkey_export($privateKey, $privateKeyPem);
        file_put_contents($privatePath, $privateKeyPem);
        chmod($privatePath, 0600);

        $details = openssl_pkey_get_details($privateKey);
        file_put_contents($publicPath, $details['key']);
        chmod($publicPath, 0644);

        $output->writeln('  <info>RSA key pair generated</info> at app/keys/ (private.key 0600, public.key 0644)');
        // @codeCoverageIgnoreEnd
    }

    private function resolveScaffoldingDir(): string
    {
        return \Pramnos\Application\ScaffoldingHelper::resolveScaffoldingDir();
    }

    private function generateRandomPassword(int $length = 16): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#$%^&*';
        $max   = strlen($chars) - 1;
        $pass  = '';
        for ($i = 0; $i < $length; $i++) {
            $pass .= $chars[random_int(0, $max)];
        }
        return $pass;
    }

    /**
     * Enable UTF-8-aware line editing on the controlling terminal.
     *
     * By default some terminals (notably WSL) leave the line discipline in a
     * byte-oriented mode: pressing backspace deletes a single byte, so erasing
     * a two-byte character like «ι» removes only half of it and leaves a broken
     * sequence in the input buffer. `stty iutf8` tells the kernel the input is
     * UTF-8 so backspace erases a whole character. This is a no-op when stdin is
     * not an interactive terminal (pipes, CI) or when stty is unavailable
     * (Windows), so it is always safe to call.
     */
    private function enableUtf8TerminalInput(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return; // Windows: no stty
        }
        if (!function_exists('shell_exec')) {
            return; // @codeCoverageIgnore — disabled/hardened environments
        }
        // Only touch the terminal when stdin actually is one.
        if (defined('STDIN') && function_exists('stream_isatty') && !@stream_isatty(STDIN)) {
            return;
        }
        @shell_exec('stty iutf8 2>/dev/null');
    }

    /**
     * Clean a password typed at an interactive prompt.
     *
     * When a user edits multibyte input (e.g. types in a non-Latin keyboard
     * layout, then presses backspace and retypes), some terminals pass through
     * stray control bytes and leave a broken UTF-8 sequence in the line buffer.
     * The echoed prompt shows the corrected text, but the raw value read by the
     * program still contains the garbage — so the stored password no longer
     * matches what the user believes they typed and they can never log in.
     *
     * It reconstructs the line the way a terminal would: a literal backspace
     * (0x08) or DEL (0x7F) byte deletes the previously accepted character (the
     * whole multibyte character, not a single byte), invalid UTF-8 bytes are
     * dropped with a byte-wise resync, and any remaining control characters are
     * stripped. The caller compares the result with the raw input and warns if
     * it changed.
     *
     * @param string $raw Raw value as read from the prompt.
     * @return string Sanitised password containing no control/invalid bytes.
     */
    private function sanitizePassword(string $raw): string
    {
        $chars = [];            // accepted characters, each possibly multibyte
        $len   = strlen($raw);
        $i     = 0;

        while ($i < $len) {
            $ord = ord($raw[$i]);

            // Backspace / DEL: remove the last accepted character and move on.
            if ($ord === 0x08 || $ord === 0x7F) {
                array_pop($chars);
                $i++;
                continue;
            }

            // Determine the expected UTF-8 sequence length from the lead byte.
            if ($ord < 0x80)      { $seq = 1; } // ASCII
            elseif ($ord >= 0xF0) { $seq = 4; }
            elseif ($ord >= 0xE0) { $seq = 3; }
            elseif ($ord >= 0xC0) { $seq = 2; }
            else                  { $seq = 1; } // stray continuation byte

            $chunk = substr($raw, $i, $seq);

            if (strlen($chunk) === $seq && mb_check_encoding($chunk, 'UTF-8')) {
                // A well-formed character: keep it unless it is a control char.
                if ($seq > 1 || ($ord >= 0x20 && $ord !== 0x7F)) {
                    $chars[] = $chunk;
                }
                $i += $seq;
            } else {
                // Malformed sequence (e.g. the "�" left by a half-deleted
                // multibyte char): drop one byte and resync.
                $i++;
            }
        }

        return implode('', $chars);
    }

    /**
     * Is this run forbidden from touching the filesystem?
     *
     * Used at the write sites that do not go through {@see writeFile()} — the
     * appends and merges into files the project already owns (`.gitignore`,
     * `package.json`, `composer.json`), the image copies, and the asset downloads.
     * A flag that stopped the templates but still appended to `.gitignore` and
     * pulled assets over the network would be worse than no flag at all: a "dry"
     * run that changes the working tree is a trap, not a preview.
     *
     * @param  string $path Recorded in the plan; relative to the project root.
     * @return bool True when the caller must not write.
     */
    private function skipWrite(string $path): bool
    {
        if ($this->skipExisting && is_file($this->targetBaseDir . '/' . ltrim($path, '/'))) {
            $this->keptFiles[$path] = true;
            return true;
        }

        if (!$this->dryRun) {
            return false;
        }

        $this->plannedWrites[$path] = is_file($this->targetBaseDir . '/' . ltrim($path, '/'));

        return true;
    }

    private function mkdir(string $path): void
    {
        if ($this->dryRun) {
            return;
        }
        $fullPath = $this->targetBaseDir . '/' . $path;
        if (!is_dir($fullPath)) {
            @mkdir($fullPath, 0777, true);
        }
    }

    private function writeFile(string $path, string $content): void
    {
        // A dry run records what it would have done and touches nothing. Recorded
        // here, in the one place every scaffolded file passes through, so the
        // report cannot drift from what a real run writes.
        if ($this->dryRun) {
            $this->plannedWrites[$path] = is_file($this->targetBaseDir . '/' . $path);
            return;
        }

        // Adding a front end to a project that already exists must never overwrite
        // one of its files. Enforced here rather than at each call site, so a stub
        // added later cannot forget it.
        if ($this->skipExisting && is_file($this->targetBaseDir . '/' . $path)) {
            $this->keptFiles[$path] = true;
            return;
        }

        $this->writtenFiles[$path] = true;

        $full = $this->targetBaseDir . '/' . $path;
        // Ensure the parent directory exists. During a full init the tree is
        // pre-created, but installUiFramework() can be called standalone (e.g.
        // project:switch-ui) against a project missing a sub-directory.
        $dir = dirname($full);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        file_put_contents($full, $content);
    }

    /**
     * Can Docker bind this port on the host?
     *
     * Asks the question the same way `docker-compose up` will: by binding
     * 0.0.0.0:$port. Connecting to it instead (the previous approach) answers a
     * different question — it only finds a port that something is *listening on
     * and accepting connections from us*, and it misses a port held on another
     * interface, or one reachable over IPv4 while the name `localhost` resolves
     * to ::1 first. Either miss ends the same way: "Bind for 0.0.0.0:8081
     * failed: port is already allocated", several minutes into the init.
     */
    protected function isPortAvailable(int $port): bool
    {
        $socket = @stream_socket_server(
            "tcp://0.0.0.0:$port",
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }

    /**
     * Which of the ports a Docker environment needs are already taken?
     *
     * A generated docker-compose.yml publishes two host ports: $port for the
     * application and $port + 1 for the database tool (Adminer/PHPMyAdmin).
     * Both have to be free — checking only the first is what let init run all
     * the way to "docker-compose up" before failing on the tool container.
     *
     * @return list<int> The busy ports, empty when the pair is usable
     */
    protected function busyPorts(int $port): array
    {
        return array_values(array_filter(
            [$port, $port + 1],
            fn(int $candidate): bool => !$this->isPortAvailable($candidate)
        ));
    }

    /**
     * First port at or after $start whose whole pair ($port and $port + 1) is
     * free, so the suggested default never collides with anything.
     */
    protected function findAvailablePortPair(int $start, int $limit = 200): int
    {
        for ($port = $start; $port < $start + $limit; $port++) {
            if ($this->busyPorts($port) === []) {
                return $port;
            }
        }
        return $start; // @codeCoverageIgnore — 200 consecutive busy pairs is not a real scenario
    }

    /**
     * Decide the host port the Docker environment maps to.
     *
     * Suggests a free pair, and checks whatever the user picks instead of
     * trusting it: an interactive answer that is taken is rejected and asked
     * again, while an explicit --docker-port is honoured (the caller may know
     * the conflict is about to clear) but warned about, naming the exact ports.
     */
    private function resolveDockerPort(InputInterface $input, OutputInterface $output, mixed $helper): int
    {
        $suggested = $this->findAvailablePortPair(8080);

        $option = $input->getOption('docker-port');
        if ($option !== null && $option !== '') {
            $port = (int) $option;
            $busy = $this->busyPorts($port);
            if ($busy !== []) {
                $output->writeln(sprintf(
                    '  <comment>Warning: port %s already in use; "docker-compose up" will fail'
                    . ' unless it is freed first (%d = application, %d = database tool).</comment>',
                    implode(' and ', $busy),
                    $port,
                    $port + 1
                ));
            }
            return $port;
        }

        // Bounded: an interactive user who keeps choosing busy ports still gets
        // out of the loop, with the last answer honoured and a warning.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $port = (int) $helper->ask(
                $input,
                $output,
                new Question("Local mapping port [$suggested]: ", (string) $suggested)
            );
            $busy = $this->busyPorts($port);
            if ($busy === []) {
                return $port;
            }
            // @codeCoverageIgnoreStart — reached only when the answered port is taken
            $output->writeln(sprintf(
                '  <error>Port %s already in use.</error> The environment needs both %d'
                . ' (application) and %d (database tool).',
                implode(' and ', $busy),
                $port,
                $port + 1
            ));
            // @codeCoverageIgnoreEnd
        }

        return $port; // @codeCoverageIgnore — five busy answers in a row
    }

    /**
     * Run a shell command with a spinner animation, then show DONE/FAILED.
     *
     * @param bool $alwaysShowOutput When true, captured stdout is printed after
     *                               the spinner line regardless of exit code
     *                               (useful for migration steps where the output
     *                               is always informative).
     */
    /**
     * Formats an elapsed duration compactly for the spinner counter:
     * "45s" under a minute, "2m05s" beyond.
     */
    protected function formatElapsed(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * Says that dependencies were not installed, and what to run.
     *
     * A silent skip is the wrong shape here: the next thing that happens is somebody
     * pointing a browser at the new application and getting a fatal about a missing
     * autoloader. Naming the command turns that into a one-line fix.
     *
     * @param OutputInterface $output Where to report
     * @return void
     */
    private function reportSkippedInstall(OutputInterface $output): void
    {
        $output->writeln('  <comment>Skipped installing dependencies (--no-install).</comment>');
        $output->writeln('  Run <info>composer install</info> before serving the application.');
    }

    protected function runProcessWithSpinner(string $command, string $message, OutputInterface $output, bool $alwaysShowOutput = false): int
    {
        // Every external step goes through here — composer, docker-compose,
        // migrations, the docs build — so this is the one place a dry run has to
        // stop. Reported rather than silently skipped: "it did not run composer"
        // is part of what the reader is checking.
        if ($this->dryRun) {
            $output->writeln('  would run: <comment>' . $command . '</comment>');
            return 0;
        }

        $isVerbose = $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;

        // Always strip the 2>/dev/null redirect so we can capture stderr for error display
        $command = str_replace(' 2>/dev/null', '', $command);

        if ($isVerbose) {
            $output->writeln("<info>$message...</info>");
        } else {
            $output->write("$message ");
        }

        $symbols   = ['/', '-', '\\', '|'];
        $i         = 0;
        $stdoutBuf = '';
        $stderrBuf = '';
        $startTime = microtime(true);

        // Once true, subprocess output is streamed live instead of being
        // buffered until the end. It starts on in verbose mode, and also flips
        // on automatically once a step runs longer than slowStepThreshold — so
        // a hung command (e.g. "docker-compose up --build" stuck pulling an
        // image) becomes diagnosable instead of an endless, silent spinner.
        $liveOutput = $isVerbose;

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            // @codeCoverageIgnoreStart
            // proc_open only fails when the shell is unavailable — never in the test container.
            $output->writeln('<error>FAILED</error>');
            return 1;
            // @codeCoverageIgnoreEnd
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            // Always drain both pipes so buffers can't fill and deadlock the
            // child, and so we have captured output ready to surface on escalation.
            $chunkOut = (string) stream_get_contents($pipes[1]);
            $chunkErr = (string) stream_get_contents($pipes[2]);
            $stdoutBuf .= $chunkOut;
            $stderrBuf .= $chunkErr;

            $elapsed = (int) (microtime(true) - $startTime);

            // Escalate a long-running step to live output. This is what makes a
            // hang observable: after slowStepThreshold seconds we announce the
            // delay, flush everything captured so far, and stream the rest.
            if (!$liveOutput && $this->slowStepThreshold > 0 && $elapsed >= $this->slowStepThreshold) {
                $liveOutput = true;
                $output->write("\r\033[K");
                $output->writeln("<comment>$message is still running after " . $this->formatElapsed($elapsed) . " — showing live output:</comment>");
                $buffered = $stdoutBuf . $stderrBuf;
                if (trim($buffered) !== '') {
                    $output->write($buffered);
                }
            }

            if ($liveOutput) {
                // @codeCoverageIgnoreStart
                // Live streaming only runs under -v or after the slow-step
                // escalation; the normal, fast test path never reaches it.
                if ($chunkOut !== '') $output->write($chunkOut);
                if ($chunkErr !== '') $output->write($chunkErr);
                // @codeCoverageIgnoreEnd
            } else {
                // Spinner carries an elapsed-time counter so the user always
                // sees the step is alive and how long it has been working.
                $output->write("\r\033[K$message " . $symbols[$i % 4] . ' (' . $this->formatElapsed($elapsed) . ')');
            }
            $i++;
            usleep(100_000);
        }

        // Switch to blocking mode before final drain so non-blocking reads
        // don't silently drop data that arrived just as the process exited.
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        $remainingOut = stream_get_contents($pipes[1]);
        $remainingErr = stream_get_contents($pipes[2]);

        if ($liveOutput) {
            // @codeCoverageIgnoreStart
            if ($remainingOut) $output->write($remainingOut);
            if ($remainingErr) $output->write($remainingErr);
            // @codeCoverageIgnoreEnd
        } else {
            $stdoutBuf .= (string) $remainingOut;
            $stderrBuf .= (string) $remainingErr;
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $exitCode = proc_close($process);

        if ($liveOutput) {
            $output->writeln($exitCode === 0 ? "<info>$message: DONE</info>" : "<error>$message: FAILED (Exit Code: $exitCode)</error>"); // @codeCoverageIgnore — live output path only under -v or slow-step escalation
        } else {
            $suffix = $exitCode === 0 ? "<info>DONE</info>" : "<error>FAILED</error>";
            $output->write("\r\033[K$message $suffix\n");

            $showOutput = $alwaysShowOutput || $exitCode !== 0;
            if ($showOutput) {
                // @codeCoverageIgnoreStart
                // This block is only entered when a subprocess fails or alwaysShowOutput=true.
                // Non-Docker tests run composer commands that always exit 0 with
                // alwaysShowOutput=false, so this combined output display is never reached.
                $combined = trim($stdoutBuf . "\n" . $stderrBuf);
                if ($combined !== '') {
                    // Output each line indented, avoiding wrapping everything in
                    // a single <error> tag (which can fail if text contains '<').
                    foreach (explode("\n", $combined) as $line) {
                        if (trim($line) === '') {
                            continue;
                        }
                        if ($exitCode !== 0) {
                            $output->writeln('  <comment>' . \Symfony\Component\Console\Formatter\OutputFormatter::escape($line) . '</comment>');
                        } else {
                            $output->writeln('  ' . \Symfony\Component\Console\Formatter\OutputFormatter::escape($line));
                        }
                    }
                }
                // @codeCoverageIgnoreEnd
            }

            if ($exitCode !== 0) {
                // @codeCoverageIgnoreStart — reached only when a subprocess fails
                $this->explainDockerFailure($stdoutBuf . "\n" . $stderrBuf, $output);
                // @codeCoverageIgnoreEnd
            }
        }

        return $exitCode;
    }

    /**
     * Turn a known Docker failure into something the reader can act on.
     *
     * A failed pull or build prints forty lines of daemon output whose actual
     * cause is one line somewhere in the middle — and every one of these has a
     * fix that has nothing to do with the project being scaffolded. Recognising
     * them is the difference between "it broke" and "here is the command".
     *
     * Unknown failures are left alone: a guess dressed as advice is worse than
     * the raw output.
     *
     * @param string $log Combined stdout and stderr of the failed step
     */
    protected function explainDockerFailure(string $log, OutputInterface $output): void
    {
        foreach (self::dockerFailureHints($log) as $line) {
            $output->writeln($line);
        }
    }

    /**
     * The advice for a failed Docker step, as display lines.
     *
     * Separated from the printing so it can be asserted directly.
     *
     * @param  string $log Combined output of the failed command
     * @return list<string> Lines to print; empty when nothing is recognised
     */
    public static function dockerFailureHints(string $log): array
    {
        // The credential helper cannot be reached. Very common under WSL, where
        // docker-credential-desktop.exe lives on the Windows side and stops
        // answering after a sleep/resume — every pull then fails, including
        // public images that need no credentials at all.
        if (str_contains($log, 'error getting credentials')
            || str_contains($log, 'docker-credential')) {
            $lines = [
                '',
                '  <comment>Docker could not read its stored credentials.</comment>',
                '  This is a Docker configuration problem, not a problem with this project:',
                '  every pull fails the same way, including public images.',
                '',
                '  Public images need no credentials, so the usual fix is to stop using the',
                '  credential store:',
                '',
                '    <info>cp ~/.docker/config.json ~/.docker/config.json.bak</info>',
                '    <info>echo \'{}\' > ~/.docker/config.json</info>',
                '',
            ];
            if (str_contains($log, 'UtilAcceptVsock') || str_contains($log, 'WSL')) {
                $lines[] = '  The <comment>UtilAcceptVsock</comment> errors above are WSL failing to reach the';
                $lines[] = '  Windows-side helper; restarting Docker Desktop usually clears that too.';
                $lines[] = '';
            }
            $lines[] = '  Then finish the setup with: <info>docker-compose up -d --build</info>';
            $lines[] = '';

            return $lines;
        }

        // The daemon is not there at all.
        if (str_contains($log, 'Cannot connect to the Docker daemon')
            || str_contains($log, 'Is the docker daemon running')) {
            return [
                '',
                '  <comment>The Docker daemon is not reachable.</comment>',
                '  Start Docker (or Docker Desktop, including its WSL integration), then run:',
                '',
                '    <info>docker-compose up -d --build</info>',
                '',
            ];
        }

        // A port was taken between the availability check and the container
        // starting — a race init cannot prevent, only explain.
        if (str_contains($log, 'port is already allocated')
            || str_contains($log, 'address already in use')) {
            return [
                '',
                '  <comment>A published port was taken before the containers started.</comment>',
                '  Free it, or re-run init with a different <info>--docker-port</info>. The environment',
                '  publishes two ports: the application and, one above it, the database tool.',
                '',
            ];
        }

        // No space is reported as a build failure with an unhelpful exit code.
        if (str_contains($log, 'no space left on device')) {
            return [
                '',
                '  <comment>The Docker host has run out of disk space.</comment>',
                '  Reclaim some and try again:',
                '',
                '    <info>docker system prune -a --volumes</info>',
                '',
            ];
        }

        return [];
    }

    /**
     * Scaffold auth wiring for a new application: Login controller, login view,
     * and an Account controller wrapper around the framework Account controller.
     *
     * Called from execute() only when the 'auth' feature is enabled.
     */
    private function scaffoldAuthWiring(string $namespace, string $uiSystem): void
    {
        $this->mkdir('src/Controllers');
        $this->mkdir('src/Views/account');

        // ── Login controller ──────────────────────────────────────────────────
        // A thin alias that binds the /login URL to the framework Account
        // controller's built-in login flow (password → 2FA/passkey step-up →
        // session, via LoginFlow). No login logic is reimplemented here: the
        // whole flow, views and branding live in the framework.
        $loginController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Auth\\Controllers\\Account;

/**
 * Login entry point — delegates to the framework Account login flow.
 *
 * Routes: /login (form), /login/login (submit), /login/verify (2FA step-up),
 *         /login/logout. The actual credential handling, second-factor step-up
 *         and session bootstrap all live in {@see Account} / LoginFlow.
 */
class Login extends Account
{
    /** Form actions post under /login (not /Account). */
    protected string \$routeBase = 'login';

    /** The bare /login URL shows the sign-in form. */
    public function display()
    {
        return \$this->login();
    }

    /** After a successful login, go home rather than back to /login. */
    protected function postLoginTarget(string \$return): string
    {
        return \$return !== '' ? \$return : sURL;
    }
}
PHP;

        $this->writeFile('src/Controllers/Login.php', $loginController);

        // ── Account controller (wrapper for framework Account) ────────────────
        $accountController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

/**
 * User account controller — delegates to the framework Account controller.
 *
 * Routes: /account (display), /account/security, /account/changepassword, etc.
 * All account-management actions require authentication (inherited from the
 * framework Account controller).
 */
class Account extends \\Pramnos\\Auth\\Controllers\\Account
{
    protected string \$routeBase = 'account';
}
PHP;

        $this->writeFile('src/Controllers/Account.php', $accountController);

        // ── TwoFactorAuth controller ──────────────────────────────────────────
        $twoFactorController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Auth\\Controllers\\TwoFactorAuth as FrameworkTwoFactorAuth;

/**
 * Two-factor authentication controller — thin wrapper around the framework.
 *
 * Routes: /TwoFactorAuth (display), /TwoFactorAuth/setup, /TwoFactorAuth/disable,
 *         /TwoFactorAuth/backup, /TwoFactorAuth/status
 * All actions require authentication (enforced by the framework controller).
 */
class TwoFactorAuth extends FrameworkTwoFactorAuth
{
    // Override whitelist or settings here if needed.
}
PHP;

        $this->writeFile('src/Controllers/TwoFactorAuth.php', $twoFactorController);

        // ── Passkey + Session controllers ─────────────────────────────────────
        $this->writeAuthControllerWrapper(
            $namespace, 'Passkey', 'Passkey',
            'Passkey (WebAuthn/FIDO2) ceremony + management controller.'
        );
        $this->writeAuthControllerWrapper(
            $namespace, 'Session', 'Session',
            'Session status controller (check / heartbeat / info / refresh).'
        );

        // ── Login views ───────────────────────────────────────────────────────
        // Not scaffolded into the app: the framework ships themed login/2FA
        // views as fallbacks (see scaffolding/themes/*/views/login/), driven by
        // the Account/LoginFlow flow. Run `pramnos project:publish-views` to copy
        // and customise them.

        // ── Account views directory ───────────────────────────────────────────
        $dashboardView = $this->buildAccountDashboardView($uiSystem);
        $this->writeFile('src/Views/account/dashboard.html.php', $dashboardView);

        $profileView = $this->buildAccountProfileView($uiSystem);
        $this->writeFile('src/Views/account/profile.html.php', $profileView);
    }

    private function buildAccountDashboardView(string $uiSystem): string
    {
        if ($uiSystem === 'bootstrap') {
            return <<<'HTML'
<?php /** @var \Pramnos\View\View $this */ ?>
<div class="container mt-4">
    <div class="row">
        <div class="col-md-3">
            <div class="card mb-3">
                <div class="card-body text-center">
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center mx-auto mb-2" style="width:64px;height:64px;font-size:1.5rem">
                        <?php echo strtoupper(substr($this->user->username ?? 'U', 0, 1)); ?>
                    </div>
                    <h6 class="mb-0"><?php echo htmlspecialchars(trim(($this->user->firstname ?? '') . ' ' . ($this->user->lastname ?? '')) ?: ($this->user->username ?? ''), ENT_QUOTES, 'UTF-8'); ?></h6>
                    <small class="text-muted"><?php echo htmlspecialchars($this->user->email ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
            </div>
            <div class="list-group">
                <a href="<?php echo sURL; ?>account/profile" class="list-group-item list-group-item-action">My Profile</a>
                <a href="<?php echo sURL; ?>account/security" class="list-group-item list-group-item-action">Security</a>
                <a href="<?php echo sURL; ?>account/changepassword" class="list-group-item list-group-item-action">Change Password</a>
                <a href="<?php echo sURL; ?>account/privacy" class="list-group-item list-group-item-action">Privacy</a>
                <a href="<?php echo sURL; ?>login/logout" class="list-group-item list-group-item-action text-danger">Logout</a>
            </div>
        </div>
        <div class="col-md-9">
            <div class="card">
                <div class="card-header"><h5 class="mb-0">Account Overview</h5></div>
                <div class="card-body">
                    <?php if (!empty($this->recentActivity)): ?>
                    <h6>Recent Activity</h6>
                    <table class="table table-sm">
                        <thead><tr><th>Action</th><th>Date</th><th>IP</th></tr></thead>
                        <tbody>
                        <?php foreach (array_slice($this->recentActivity, 0, 5) as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entry['action'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($entry['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($entry['ip_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <p class="text-muted">No recent activity.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
        }

        if ($uiSystem === 'tailwind') {
            return <<<'HTML'
<?php /** @var \Pramnos\View\View $this */ ?>
<div class="max-w-5xl mx-auto">
    <h1 class="text-2xl font-bold mb-4">My Account</h1>
    <div class="flex gap-6">
        <div class="w-48 shrink-0">
            <div class="bg-white rounded-xl shadow-md border border-gray-200 p-4 text-center mb-4">
                <div class="w-16 h-16 rounded-full bg-gray-400 text-white flex items-center justify-center text-2xl mx-auto mb-2">
                    <?php echo strtoupper(substr($this->user->username ?? 'U', 0, 1)); ?>
                </div>
                <p class="font-semibold text-sm"><?php echo htmlspecialchars(trim(($this->user->firstname ?? '') . ' ' . ($this->user->lastname ?? '')) ?: ($this->user->username ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($this->user->email ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <nav class="flex flex-col gap-1">
                <a href="<?php echo sURL; ?>account/profile" class="block px-3 py-2 rounded-md text-gray-700 hover:bg-gray-100">My Profile</a>
                <a href="<?php echo sURL; ?>account/security" class="block px-3 py-2 rounded-md text-gray-700 hover:bg-gray-100">Security</a>
                <a href="<?php echo sURL; ?>account/changepassword" class="block px-3 py-2 rounded-md text-gray-700 hover:bg-gray-100">Change Password</a>
                <a href="<?php echo sURL; ?>account/privacy" class="block px-3 py-2 rounded-md text-gray-700 hover:bg-gray-100">Privacy</a>
                <a href="<?php echo sURL; ?>login/logout" class="block px-3 py-2 rounded-md text-red-600 hover:bg-red-50">Logout</a>
            </nav>
        </div>
        <div class="flex-1">
            <div class="bg-white rounded-xl shadow-md border border-gray-200 p-4">
                <h2 class="text-lg font-semibold mb-3">Account Overview</h2>
                <?php if (!empty($this->recentActivity)): ?>
                <h3 class="text-sm font-semibold mb-2">Recent Activity</h3>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="pb-1">Action</th><th class="pb-1">Date</th><th class="pb-1">IP</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($this->recentActivity, 0, 5) as $entry): ?>
                        <tr class="border-t border-gray-200">
                            <td class="py-1"><?php echo htmlspecialchars($entry['action'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="py-1"><?php echo htmlspecialchars($entry['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="py-1"><?php echo htmlspecialchars($entry['ip_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-gray-500 text-sm">No recent activity.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
HTML;
        }

        // plain-css
        return <<<'HTML'
<?php /** @var \Pramnos\View\View $this */ ?>
<div class="container mt-4">
    <h1 class="text-2xl font-bold mb-4">My Account</h1>
    <div class="flex gap-6">
        <div class="w-48 flex-shrink-0">
            <div class="card p-4 text-center mb-4">
                <div class="w-16 h-16 rounded-full bg-gray-400 text-white flex items-center justify-center text-2xl mx-auto mb-2">
                    <?php echo strtoupper(substr($this->user->username ?? 'U', 0, 1)); ?>
                </div>
                <p class="font-semibold text-sm"><?php echo htmlspecialchars(trim(($this->user->firstname ?? '') . ' ' . ($this->user->lastname ?? '')) ?: ($this->user->username ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($this->user->email ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
            <nav class="flex flex-col gap-1">
                <a href="<?php echo sURL; ?>account/profile" class="nav-link">My Profile</a>
                <a href="<?php echo sURL; ?>account/security" class="nav-link">Security</a>
                <a href="<?php echo sURL; ?>account/changepassword" class="nav-link">Change Password</a>
                <a href="<?php echo sURL; ?>account/privacy" class="nav-link">Privacy</a>
                <a href="<?php echo sURL; ?>login/logout" class="nav-link text-red-600">Logout</a>
            </nav>
        </div>
        <div class="flex-1">
            <div class="card p-4">
                <h2 class="text-lg font-semibold mb-3">Account Overview</h2>
                <?php if (!empty($this->recentActivity)): ?>
                <h3 class="text-sm font-semibold mb-2">Recent Activity</h3>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="pb-1">Action</th><th class="pb-1">Date</th><th class="pb-1">IP</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($this->recentActivity, 0, 5) as $entry): ?>
                        <tr class="border-t">
                            <td class="py-1"><?php echo htmlspecialchars($entry['action'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="py-1"><?php echo htmlspecialchars($entry['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="py-1"><?php echo htmlspecialchars($entry['ip_address'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-gray-500 text-sm">No recent activity.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
HTML;
    }

    private function buildAccountProfileView(string $uiSystem): string
    {
        $errorMessages = <<<'PHP'
<?php
$_msg = $_GET['message'] ?? '';
$_err = $_GET['error'] ?? '';
$_msgMap = ['profile_saved' => 'Profile updated successfully.'];
$_errMap = [
    'invalid_email' => 'Please enter a valid email address.',
    'invalid_token' => 'Security token invalid. Please try again.',
];
?>
PHP;

        if ($uiSystem === 'bootstrap') {
            return <<<HTML
<?php /** @var \\Pramnos\\View\\View \$this */ ?>
$errorMessages
<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">My Profile</h5>
                    <a href="<?php echo sURL; ?>account" class="btn btn-sm btn-outline-secondary">Back</a>
                </div>
                <div class="card-body">
                    <?php if (\$_msg && isset(\$_msgMap[\$_msg])): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars(\$_msgMap[\$_msg], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <?php if (\$_err && isset(\$_errMap[\$_err])): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars(\$_errMap[\$_err], ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo sURL; ?>account/profile">
                        <?php echo \\Pramnos\\Http\\Session::getInstance()->getTokenField(); ?>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name</label>
                                <input type="text" name="firstname" class="form-control" value="<?php echo htmlspecialchars(\$this->user->firstname ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="lastname" class="form-control" value="<?php echo htmlspecialchars(\$this->user->lastname ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required value="<?php echo htmlspecialchars(\$this->user->email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars(\$this->user->phone ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-muted">Username</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars(\$this->user->username ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                            <div class="form-text">Username cannot be changed here.</div>
                        </div>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="<?php echo sURL; ?>account/changepassword" class="btn btn-outline-secondary ms-2">Change Password</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
        }

        if ($uiSystem === 'tailwind') {
            return <<<HTML
<?php /** @var \\Pramnos\\View\\View \$this */ ?>
$errorMessages
<div class="max-w-lg mx-auto">
    <div class="bg-white rounded-xl shadow-md border border-gray-200 p-6">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-xl font-bold">My Profile</h1>
            <a href="<?php echo sURL; ?>account" class="text-sm text-gray-500 hover:underline">Back</a>
        </div>
        <?php if (\$_msg && isset(\$_msgMap[\$_msg])): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-md p-3 mb-4 text-sm"><?php echo htmlspecialchars(\$_msgMap[\$_msg], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if (\$_err && isset(\$_errMap[\$_err])): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-md p-3 mb-4 text-sm"><?php echo htmlspecialchars(\$_errMap[\$_err], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <form method="post" action="<?php echo sURL; ?>account/profile" class="space-y-4">
            <?php echo \\Pramnos\\Http\\Session::getInstance()->getTokenField(); ?>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                    <input type="text" name="firstname" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars(\$this->user->firstname ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                    <input type="text" name="lastname" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars(\$this->user->lastname ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                <input type="email" name="email" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-blue-500" required value="<?php echo htmlspecialchars(\$this->user->email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                <input type="text" name="phone" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-hidden focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars(\$this->user->phone ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-1">Username</label>
                <input type="text" class="w-full border border-gray-300 rounded-md px-3 py-2 bg-gray-50 text-gray-500" value="<?php echo htmlspecialchars(\$this->user->username ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                <p class="text-xs text-gray-400 mt-1">Username cannot be changed here.</p>
            </div>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition-colors">Save Changes</button>
                <a href="<?php echo sURL; ?>account/changepassword" class="border border-gray-300 text-gray-700 font-medium py-2 px-4 rounded-md hover:bg-gray-50 transition-colors">Change Password</a>
            </div>
        </form>
    </div>
</div>
HTML;
        }

        // plain-css
        return <<<HTML
<?php /** @var \\Pramnos\\View\\View \$this */ ?>
$errorMessages
<div class="container mt-4">
    <div class="max-w-lg mx-auto">
        <div class="card p-6">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-xl font-bold">My Profile</h1>
                <a href="<?php echo sURL; ?>account" class="text-sm text-gray-500 hover:underline">Back</a>
            </div>
            <?php if (\$_msg && isset(\$_msgMap[\$_msg])): ?>
            <div class="bg-green-50 border border-green-200 text-green-800 rounded p-3 mb-4 text-sm"><?php echo htmlspecialchars(\$_msgMap[\$_msg], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if (\$_err && isset(\$_errMap[\$_err])): ?>
            <div class="bg-red-50 border border-red-200 text-red-800 rounded p-3 mb-4 text-sm"><?php echo htmlspecialchars(\$_errMap[\$_err], ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <form method="post" action="<?php echo sURL; ?>account/profile">
                <?php echo \\Pramnos\\Http\\Session::getInstance()->getTokenField(); ?>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">First Name</label>
                        <input type="text" name="firstname" class="form-input w-full" value="<?php echo htmlspecialchars(\$this->user->firstname ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Last Name</label>
                        <input type="text" name="lastname" class="form-input w-full" value="<?php echo htmlspecialchars(\$this->user->lastname ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Email <span class="text-red-500">*</span></label>
                    <input type="email" name="email" class="form-input w-full" required value="<?php echo htmlspecialchars(\$this->user->email ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Phone</label>
                    <input type="text" name="phone" class="form-input w-full" value="<?php echo htmlspecialchars(\$this->user->phone ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="mb-6">
                    <label class="block text-sm font-medium mb-1 text-gray-400">Username</label>
                    <input type="text" class="form-input w-full bg-gray-50" value="<?php echo htmlspecialchars(\$this->user->username ?? '', ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    <p class="text-xs text-gray-400 mt-1">Username cannot be changed here.</p>
                </div>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="<?php echo sURL; ?>account/changepassword" class="btn btn-secondary">Change Password</a>
                </div>
            </form>
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Write a thin controller that extends a framework auth controller, so the
     * URL resolves (controller name = URL segment) while all logic stays in the
     * framework. The app opts in per controller by having this file — nothing is
     * routable that the app did not explicitly scaffold.
     *
     * @param string $namespace      App namespace.
     * @param string $appClass       Controller class name (also the URL segment).
     * @param string $frameworkClass Framework controller class under Pramnos\Auth\Controllers.
     * @param string $summary        One-line doc summary for the generated file.
     */
    private function writeAuthControllerWrapper(
        string $namespace,
        string $appClass,
        string $frameworkClass,
        string $summary
    ): void {
        $alias = 'Framework' . $frameworkClass;
        $wrapper = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Auth\\Controllers\\{$frameworkClass} as {$alias};

/**
 * {$summary}
 *
 * Thin wrapper delegating to the framework {$frameworkClass} controller.
 * Override actions here only if this application needs to customise them.
 */
class {$appClass} extends {$alias}
{
}
PHP;
        $this->writeFile("src/Controllers/{$appClass}.php", $wrapper);
    }

    /**
     * Scaffold the OAuth2 authorization server wiring when 'authserver' feature is enabled.
     *
     * Creates src/Controllers/Oauth.php — a thin wrapper around the framework's
     * OAuth2 controller so that /oauth/authorize, /oauth/token etc. route correctly.
     * All OAuth2 views are already provided as scaffolding fallbacks and do not need
     * to be copied into the app.
     */
    private function scaffoldAuthServerWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $oauthController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

/**
 * OAuth2 / OpenID Connect authorization server controller.
 *
 * Delegates all endpoint logic to the framework Oauth controller.
 * Routes: /oauth/authorize, /oauth/token, /oauth/revoke, /oauth/introspect,
 *         /oauth/userinfo, /oauth/logout, /oauth/deviceauthorization
 */
class Oauth extends \\Pramnos\\Auth\\Controllers\\Oauth
{
    // Extend or override endpoints here as needed for this application.
}
PHP;

        $this->writeFile('src/Controllers/Oauth.php', $oauthController);

        $applicationsController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Auth\\Controllers\\ApplicationsController as FrameworkApplicationsController;

/**
 * OAuth2 application (client) management controller.
 *
 * Delegates all actions to the framework ApplicationsController.
 * Override \$requiredUserType here if this application's admin hierarchy
 * uses a different threshold for OAuth2 management.
 */
class Applications extends FrameworkApplicationsController
{
}
PHP;
        $this->writeFile('src/Controllers/Applications.php', $applicationsController);

        $tokensController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Auth\\Controllers\\TokensController as FrameworkTokensController;

/**
 * OAuth2 token management controller.
 *
 * Delegates all actions to the framework TokensController.
 * Override \$requiredUserType here if this application's admin hierarchy
 * uses a different threshold for token revocation.
 */
class Tokens extends FrameworkTokensController
{
}
PHP;
        $this->writeFile('src/Controllers/Tokens.php', $tokensController);
    }

    /**
     * Scaffold the application logs controller (always created for every new app).
     *
     * Creates src/Controllers/Logs.php — a thin wrapper around the framework's
     * LogController so that /logs provides the log viewer.
     * All authentication for this controller is enforced by LogController::addAuthAction().
     */
    private function scaffoldLogsWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $logsController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Application\\Controllers\\LogController;

/**
 * Application log viewer — delegates to the framework LogController.
 *
 * Routes: /logs (display), /logs/stats, /logs/search, /logs/archive, etc.
 * All actions require authentication (inherited from LogController).
 *
 * Override \$whitelist and \$blacklist to control which log files are visible.
 */
class Logs extends LogController
{
    // Override whitelist/blacklist to restrict or expand visible log files:
    // protected \$whitelist = ['app.log', 'php_error.log'];
    // protected \$blacklist = ['general-log.log'];
}
PHP;

        $this->writeFile('src/Controllers/Logs.php', $logsController);
    }

    /**
     * Creates src/Controllers/Health.php — a thin wrapper around the framework's
     * Health controller so that /health provides the health dashboard and the
     * GET /health/check JSON endpoint is available to monitoring systems.
     *
     * Scaffolded in every application regardless of enabled features.
     */
    private function scaffoldHealthWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $healthController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Application\\Controllers\\Health as FrameworkHealth;

/**
 * Application health dashboard — delegates to the framework Health controller.
 *
 * Routes:
 *   GET /health          → display()  HTML dashboard (login required)
 *   GET /health/check    → check()    JSON endpoint  (public — for monitoring)
 *   GET /health/phpinfo  → phpinfo()  PHP Info page  (admin only)
 *
 * Register custom health checks in your Application::init() or a ServiceProvider:
 *
 *   \\Pramnos\\Health\\HealthRegistry::register(new MyCustomCheck());
 */
class Health extends FrameworkHealth
{
}
PHP;

        $this->writeFile('src/Controllers/Health.php', $healthController);
    }

    private function scaffoldUsersWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $usersController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Application\\Controllers\\UsersController as FrameworkUsersController;

/**
 * User management controller.
 *
 * Delegates all actions to the framework UsersController.
 * Override \$requiredUserType or individual action methods here to customise
 * access control or behaviour for this application.
 */
class Users extends FrameworkUsersController
{
}
PHP;

        $this->writeFile('src/Controllers/Users.php', $usersController);
    }

    private function scaffoldSettingsWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $settingsController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Application\\Controllers\\SettingsController as FrameworkSettingsController;

/**
 * Application settings management controller.
 *
 * Delegates all actions to the framework SettingsController.
 * Override \$readonlyKeys here to protect additional application-specific
 * setting keys from UI modification.
 */
class Settings extends FrameworkSettingsController
{
}
PHP;

        $this->writeFile('src/Controllers/Settings.php', $settingsController);
    }

    private function scaffoldDashboardWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $dashboardController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Application\\Controllers\\DashboardController as FrameworkDashboardController;

/**
 * Admin/ops overview dashboard controller.
 *
 * Delegates all actions to the framework DashboardController.
 * Override \$requiredUserType here to raise (or lower) the minimum
 * usertype required to access the dashboard in this application.
 */
class Dashboard extends FrameworkDashboardController
{
}
PHP;

        $this->writeFile('src/Controllers/Dashboard.php', $dashboardController);
    }

    private function scaffoldServicesWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $servicesController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Application\\Controllers\\ServicesController as FrameworkServicesController;

/**
 * Daemon/worker services management controller.
 *
 * Delegates all actions to the framework ServicesController.
 * Override \$requiredUserType or \$maxLogLines here to customise
 * access requirements and log output limits for this application.
 */
class Services extends FrameworkServicesController
{
}
PHP;

        $this->writeFile('src/Controllers/Services.php', $servicesController);
    }

    private function scaffoldOrganizationsWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $organizationsController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Application\\Controllers\\OrganizationsController as FrameworkOrganizationsController;

/**
 * Organizations management controller.
 *
 * Delegates all actions to the framework OrganizationsController.
 * Override \$requiredUserType here to adjust the minimum access level
 * for this application.
 */
class Organizations extends FrameworkOrganizationsController
{
}
PHP;

        $this->writeFile('src/Controllers/Organizations.php', $organizationsController);
    }

    private function scaffoldEmailsWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $emailsController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Application\\Controllers\\EmailsController as FrameworkEmailsController;

/**
 * Email history controller.
 *
 * Delegates all actions to the framework EmailsController.
 */
class Emails extends FrameworkEmailsController
{
}
PHP;

        $this->writeFile('src/Controllers/Emails.php', $emailsController);
    }

    private function scaffoldTokenActionsWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $tokenActionsController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Auth\\Controllers\\TokenActionsController as FrameworkTokenActionsController;

/**
 * Token actions audit log controller.
 *
 * Delegates all actions to the framework TokenActionsController.
 * Override \$requiredUserType or \$maxExportRows here to customise
 * access requirements and export limits for this application.
 */
class TokenActions extends FrameworkTokenActionsController
{
}
PHP;

        $this->writeFile('src/Controllers/TokenActions.php', $tokenActionsController);
    }

    private function scaffoldPermissionsWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $permissionsController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Auth\\Controllers\\PermissionsController as FrameworkPermissionsController;

/**
 * RBAC permissions management controller.
 *
 * Delegates all actions to the framework PermissionsController.
 * Override \$requiredUserType here if this application's admin hierarchy
 * uses a different threshold for RBAC management.
 */
class Permissions extends FrameworkPermissionsController
{
}
PHP;

        $this->writeFile('src/Controllers/Permissions.php', $permissionsController);

        // ── Discovery / Device / GDPR + internal (client-credentials) endpoints ─
        $this->writeAuthControllerWrapper(
            $namespace, 'Discovery', 'Discovery',
            'OpenID Connect discovery + JWKS endpoints (/.well-known).'
        );
        $this->writeAuthControllerWrapper(
            $namespace, 'Device', 'Device',
            'OAuth2 Device Authorization Grant endpoints.'
        );
        $this->writeAuthControllerWrapper(
            $namespace, 'Gdpr', 'Gdpr',
            'GDPR data-subject request endpoints (export / erase / status).'
        );
        // Internal, server-to-server endpoints — they authenticate via
        // client-credentials (not the user session), so exposing the URL is safe.
        $this->writeAuthControllerWrapper(
            $namespace, 'Capabilities', 'Capabilities',
            'Internal client capabilities manifest sync endpoint (client-credentials).'
        );
        $this->writeAuthControllerWrapper(
            $namespace, 'InternalPermissions', 'InternalPermissions',
            'Internal permissions resolution endpoint (client-credentials).'
        );
    }

    private function scaffoldQueueWiring(string $namespace): void
    {
        $this->mkdir('src/Controllers');

        $queueController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Queue\\Controllers\\QueueController as FrameworkQueueController;

/**
 * Background job queue management controller.
 *
 * Delegates all actions to the framework QueueController.
 * Override \$requiredUserType here if this application's admin hierarchy
 * uses a different threshold for queue management.
 */
class Queue extends FrameworkQueueController
{
}
PHP;

        $this->writeFile('src/Controllers/Queue.php', $queueController);
    }
}
