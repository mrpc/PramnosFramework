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
 *  2. Framework features (auth, authserver, queue, messaging)
 *  3. UI system (plain-css, bootstrap, tailwind)
 *  4. Extra libraries (local asset download into public/assets/vendor/)
 *  5. Extra resources (favicon set, base CSS reset, print stylesheet)
 *  6. Docker startup → composer install → migrate --scope=framework → summary
 */
class Init extends Command
{
    /** Target directory for scaffolding. */
    public string $targetBaseDir = '';

    /** When true, docker-compose up is skipped (test mode). */
    public bool $skipDockerRun = false;

    /** Path to the scaffolding/ directory inside the framework package. */
    public string $scaffoldingDir = '';

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
        $this->addOption('features',      null, InputOption::VALUE_OPTIONAL, 'Comma-separated feature list (auth,authserver,queue,messaging)');
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
        $this->addOption('rest-api',      null, InputOption::VALUE_OPTIONAL, 'Scaffold REST API layer (y/n)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->targetBaseDir === '') {
            $this->targetBaseDir = defined('ROOT') ? ROOT : getcwd();
        }

        if ($this->scaffoldingDir === '') {
            $this->scaffoldingDir = $this->resolveScaffoldingDir();
        }

        $helper = $this->getHelper('question');

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

        // ── Step 2: Framework features ────────────────────────────────────────
        $enabledFeatures = $this->askFeatures($input, $output, $helper);
        $withRestApi     = $this->askRestApi($input, $output, $helper);

        // ── Step 3: UI system ─────────────────────────────────────────────────
        $uiSystem = $this->askUiSystem($input, $output, $helper);

        // ── Step 4: Extra libraries ───────────────────────────────────────────
        $selectedLibraries = $this->askLibraries($input, $output, $helper, $uiSystem);

        // ── Docker setup ──────────────────────────────────────────────────────
        $dockerOption = $input->getOption('docker');
        if ($dockerOption !== null) {
            $useDocker = in_array(strtolower($dockerOption), ['y', 'yes', '1', 'true']);
        } else {
            $useDocker = $helper->ask($input, $output, new ConfirmationQuestion('Setup Docker environment? [Y/n] ', true));
        }

        $dockerPort  = 8080;
        $cacheSystem = 'none';

        if ($useDocker) {
            while (!$this->isPortAvailable($dockerPort)) {
                $dockerPort++;
            }
            $dockerPort = (int) ($input->getOption('docker-port')
                ?: $helper->ask($input, $output, new Question("Local mapping port [$dockerPort]: ", (string) $dockerPort)));

            $cacheSystemOption = $input->getOption('cache-system');
            $cacheSystem = $cacheSystemOption !== null
                ? $cacheSystemOption
                : $helper->ask($input, $output, new ChoiceQuestion('Cache System [none]: ', ['none', 'redis', 'memcached'], 0));
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
            $output->writeln('<error>Invalid email address. Please try again.</error>');
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

        $this->scaffoldSettings('app/config/settings.php', $dbType, $dbHost, $dbName, $dbUser, $dbPass, $dbPrefix, true);
        $this->scaffoldAppConfig('app/app.php', $appName, $namespace, $enabledFeatures, $uiSystem, $withRestApi);
        $this->writeFile('app/language/en.php', "<?php\n\$lang = [\n    'CHARSET' => 'UTF-8',\n    'LangShort' => 'en'\n];\nreturn \$lang;\n");
        $this->writeFile('www/index.php', $this->getIndexTemplate());
        $this->writeFile('www/.htaccess', "RewriteEngine On\nRewriteRule ^$ index.php [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^(.*)$ index.php?url=$1 [QSA,L]\n");
        $catalog = $this->loadAssetCatalog();
        $this->writeFile('src/Application.php', $this->getApplicationTemplate($namespace, $selectedLibraries, $catalog));
        $this->writeFile('src/Console.php', $this->getConsoleTemplate($namespace, $appName));
        $this->writeFile("$cliName.php", $this->getCliEntryPointTemplate($namespace, $appName));
        $this->writeFile(
            'src/Controllers/Home.php',
            $this->renderStub('controller', [
                'namespace' => "$namespace\\Controllers",
                'class'     => 'Home',
                'view'      => 'home',
            ])
        );
        $this->writeFile('src/Views/home/home.html.php', $this->getHomepageView(
            $appName, $namespace, $enabledFeatures, $selectedLibraries, $useDocker, $dockerPort, $dbType, $cliName
        ));

        $this->scaffoldTheme($uiSystem, $appName, $catalog);

        if (!empty($selectedLibraries)) {
            $skipDownload = (bool) $input->getOption('no-download');
            $this->scaffoldLibraries($selectedLibraries, $uiSystem, $skipDownload, $output);
        }

        if ($useDocker) {
            $this->scaffoldDocker($namespace, $dockerPort, $dbType, $dbName, $dbUser, $dbPass, $cacheSystem, $dbRootPass, $cliName);
        }

        $this->scaffoldTests($namespace, $dbType, $dbHost, $dbName, $dbUser, $dbPass, $dbPrefix, $useDocker);
        $this->scaffoldGitignore($enabledFeatures);

        if ($withRestApi) {
            $this->scaffoldRestApi($namespace);
        }

        $this->scaffoldAiGuidelines($appName, $namespace, $dbType, $dbName, $dbUser, $dbPass, $dockerPort, $cliName, $enabledFeatures);

        if (in_array('authserver', $enabledFeatures, true)) {
            $this->generateOAuth2KeyPair($output);
        }

        $this->updateComposerJson($appName, $namespace, $userName, $userEmail, $output);

        $output->writeln("\n<info>Project initialized successfully!</info>");

        // ── Step 6: Docker startup + migrations ───────────────────────────────
        if ($useDocker && !$this->skipDockerRun) {
            $this->dockerSuccess = ($this->runProcessWithSpinner(
                'docker-compose up -d --build 2>/dev/null', 'Starting Docker environment', $output
            ) === 0);

            if ($this->dockerSuccess) {
                $this->waitForDatabase($dbType, $output);

                $syncStatus         = $this->runProcessWithSpinner('docker-compose exec -T app composer update --no-interaction 2>/dev/null',      'Syncing dependencies (in container)',     $output);
                $syncAutoloadStatus = $this->runProcessWithSpinner('docker-compose exec -T app composer dump-autoload --no-interaction 2>/dev/null', 'Regenerating autoloader (in container)',  $output);

                if ($syncStatus !== 0 || $syncAutoloadStatus !== 0) {
                    $this->autoloadSuccess = false;
                }

                if ($this->autoloadSuccess && !$input->getOption('no-migrations')) {
                    $migStatus = $this->runProcessWithSpinner(
                        "docker-compose exec -T app php $cliName.php migrate --scope=framework 2>/dev/null",
                        'Running framework migrations',
                        $output
                    );
                    $this->migrationsSuccess = ($migStatus === 0);

                    if ($this->migrationsSuccess && in_array('auth', $enabledFeatures, true)) {
                        $this->createAdminUser($input, $output, $helper, $userEmail, $cliName);
                    } elseif (!$this->migrationsSuccess) {
                        $output->writeln('  <comment>Admin user creation skipped — migrations did not complete successfully.</comment>');
                        $output->writeln("  Run manually after fixing migrations: docker-compose exec app php $cliName.php migrate --scope=framework");
                    }
                }
            }
        } elseif (!$useDocker) {
            $syncStatus         = $this->runProcessWithSpinner('composer update --no-interaction --ignore-platform-reqs 2>/dev/null', 'Syncing dependencies',      $output);
            $syncAutoloadStatus = $this->runProcessWithSpinner('composer dump-autoload --no-interaction 2>/dev/null',                 'Regenerating autoloader',   $output);

            if ($syncStatus !== 0 || $syncAutoloadStatus !== 0) {
                $this->autoloadSuccess = false;
            }
        }

        $this->printSummary($output, $useDocker, $dockerPort, $dbType, $dbUser, $dbPass, $dbRootPass, $cliName, (bool) $input->getOption('no-migrations'), $withRestApi);

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
     * @return list<string>
     */
    private function askLibraries(InputInterface $input, OutputInterface $output, mixed $helper, string $uiSystem): array
    {
        $libOption = $input->getOption('libraries');
        if ($libOption !== null) {
            return $libOption === '' ? [] : array_filter(array_map('trim', explode(',', $libOption)));
        }

        $output->writeln("\n<comment>Step 4 — Extra libraries</comment>");
        $wantLibraries = $helper->ask($input, $output, new ConfirmationQuestion('Configure extra libraries? [Y/n] ', true));
        if (!$wantLibraries) {
            return [];
        }

        $catalog = $this->loadAssetCatalog();
        if (empty($catalog['libraries'])) {
            return [];
        }

        $output->writeln("Select which libraries to include (assets downloaded locally):");

        // Libraries we use across the framework and urbanwater — default yes
        $defaultEnabled = ['jquery', 'datatables', 'select2', 'leaflet', 'chartjs', 'ckeditor'];

        $skipAlways = ['bootstrap']; // bundled with bootstrap theme automatically
        $selected   = [];

        foreach ($catalog['libraries'] as $key => $lib) {
            if (in_array($key, $skipAlways, true)) {
                continue;
            }
            $requiredUi = $lib['requires_ui'] ?? [];
            if (!empty($requiredUi) && !in_array($uiSystem, $requiredUi, true)) {
                continue;
            }
            $requires = $lib['requires'] ?? [];
            if (!empty($requires)) {
                $missingDeps = array_diff($requires, $selected);
                if (!empty($missingDeps)) {
                    continue;
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
    }

    private function scaffoldAppConfig(
        string $path,
        string $appName,
        string $namespace,
        array  $features,
        string $scaffoldTheme = '',
        bool   $withApi = false,
        string $apiPrefix = '/api/v1'
    ): void {
        $featuresPhp = empty($features)
            ? "    'features' => [],\n"
            : "    'features' => ['" . implode("', '", $features) . "'],\n";

        $scaffoldLine = $scaffoldTheme !== ''
            ? "    'scaffold_theme' => '$scaffoldTheme',\n"
            : '';

        $apiSection = $withApi
            ? "    'api' => [\n        'prefix'       => '$apiPrefix',\n        'cors_origins' => ['*'],\n        'version'      => 'v1',\n    ],\n"
            : '';

        $content = "<?php\nreturn [\n    'name' => '$appName',\n    'namespace' => '$namespace',\n    'theme' => 'default',\n{$scaffoldLine}{$featuresPhp}{$apiSection}    'csp' => [\n        'script-src' => [],\n        'style-src'  => []\n    ]\n];\n";
        $this->writeFile($path, $content);
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

    private function scaffoldRestApi(string $namespace): void
    {
        $this->mkdir('src/Api/Controllers');

        $stub = <<<'ROUTES'
<?php
declare(strict_types=1);

// API routes — loaded by the API application entry point.
// Authentication is handled by ApiAuthMiddleware configured in the Api application.

/** @var \Pramnos\Routing\Router $router */

$router->group(
    ['prefix' => '/v1'],
    function (\Pramnos\Routing\Router $r): void {
        // $r->get('/hello', [{{ namespace }}\Api\Controllers\HelloController::class, 'index']);
    }
);
ROUTES;

        $this->writeFile('src/Api/routes.php', str_replace('{{ namespace }}', $namespace, $stub));
    }

    private function scaffoldSettings(string $path, string $type, string $host, string $name, string $user, string $pass, string $prefix, bool $dev): void
    {
        $realType      = ($type === 'timescaledb') ? 'postgresql' : $type;
        $timescaleFlag = ($type === 'timescaledb') ? ",\n        'timescale' => true" : '';

        $content = "<?php\nreturn [\n    'database' => [\n        'type' => '$realType',\n        'hostname' => '$host',\n        'database' => '$name',\n        'user' => '$user',\n        'password' => '$pass',\n        'prefix' => '$prefix'$timescaleFlag\n    ],\n    'dbsettings' => false,\n    'language' => 'en',\n    'development' => " . ($dev ? 'true' : 'false') . ",\n    'forcessl' => false\n];\n";
        $this->writeFile($path, $content);
    }

    /**
     * Scaffold the theme. header/footer include only layout-critical assets
     * (bootstrap CSS+JS for the bootstrap theme). All other libraries are
     * registered in Application::registerVendorLibraries() and enqueued
     * per-page by controllers via addScript()/addStyle().
     */
    private function scaffoldTheme(string $uiSystem, string $appName, array $catalog = []): void
    {
        $themeDir = $this->scaffoldingDir . '/themes/' . $uiSystem;
        $dest     = 'app/themes/default';

        $src = $themeDir . '/theme.html.php';
        if (file_exists($src)) {
            $this->writeFile($dest . '/theme.html.php', file_get_contents($src));
        }

        $this->writeFile($dest . '/header.php', $this->buildThemeHeader($uiSystem, $appName, $catalog));
        $this->writeFile($dest . '/footer.php', $this->buildThemeFooter($uiSystem, $appName, $catalog));

        $cssFile = $themeDir . '/style.css';
        if (file_exists($cssFile)) {
            $this->writeFile('www/assets/css/style.css', file_get_contents($cssFile));
        }

        if ($uiSystem === 'bootstrap') {
            $this->ensureBootstrapAssets();
        }
    }

    private function buildThemeHeader(string $uiSystem, string $appName, array $catalog): string
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
        }

        $nav = match ($uiSystem) {
            'bootstrap' => <<<HTML
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="<?php echo sURL; ?>">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="<?php echo sURL; ?>">Home</a></li>
                </ul>
            </div>
        </div>
    </nav>
HTML,
            default => <<<HTML
    <header class="main-header">
        <div class="container">
            <a href="<?php echo sURL; ?>" class="logo">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
            <nav class="main-nav">
                <ul>
                    <li><a href="<?php echo sURL; ?>">Home</a></li>
                </ul>
            </nav>
        </div>
    </header>
HTML,
        };

        return "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
            . $themeCss
            . "    <link rel=\"stylesheet\" href=\"<?php echo sURL; ?>assets/css/style.css\">\n"
            . "    <?php \$this->document->renderCss(); ?>\n"
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
            <p class="mb-1">&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
            <p class="mb-0 text-muted small">Powered by <a href="https://github.com/mrpc/PramnosFramework" target="_blank" class="text-secondary">PramnosFramework</a></p>
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
            . "    <?php \$this->document->renderJs(); ?>\n";
    }

    /** Download (or stub) Bootstrap assets when bootstrap theme is selected. */
    private function ensureBootstrapAssets(): void
    {
        $catalog = $this->loadAssetCatalog();
        $lib     = $catalog['libraries']['bootstrap'] ?? null;
        if ($lib === null) {
            return;
        }
        $this->downloadLibraryAssets('bootstrap', $lib, false);
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
                continue;
            }
            $manifest[$key] = [
                'version'    => $lib['version'],
                'local_path' => str_replace('assets/', 'www/assets/', $lib['local_path']),
                'css'        => [],
                'js'         => [],
            ];

            if (!$skipDownload) {
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
            if (file_exists($src) && @copy($src, $dest)) {
                $copiedCss[] = $lib['local_path'] . '/' . $filename;
            }
        }
        foreach ($lib['js'] as $filename) {
            $src  = $sourceBase . DIRECTORY_SEPARATOR . $filename;
            $dest = $localBase . '/' . $filename;
            if (file_exists($src) && @copy($src, $dest)) {
                $copiedJs[] = $lib['local_path'] . '/' . $filename;
            }
        }

        return [$copiedCss, $copiedJs];
    }

    private function downloadFile(string $url, string $dest): bool
    {
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
    }

    private function loadAssetCatalog(): array
    {
        $file = $this->scaffoldingDir . '/assets.json';
        if (!file_exists($file)) {
            return ['libraries' => []];
        }
        return json_decode(file_get_contents($file), true) ?? ['libraries' => []];
    }

    private function scaffoldTests(string $namespace, string $dbType, string $dbHost, string $dbName, string $dbUser, string $dbPass, string $dbPrefix, bool $useDocker): void
    {
        $this->mkdir('tests/Unit');
        $this->mkdir('tests/Integration');

        $testDbName = $dbName . '_test';
        $this->scaffoldSettings('app/config/testsettings.php', $dbType, $dbHost, $testDbName, $dbUser, $dbPass, $dbPrefix, true);

        $this->writeFile('tests/bootstrap.php', "<?php\ndefine('ROOT', dirname(__DIR__));\nrequire ROOT . '/vendor/autoload.php';\n\n\\Pramnos\\Framework\\Testing\\TestEnvironment::setup(\n    ROOT . '/app/config/testsettings.php'\n);\n");
        $this->writeFile('tests/BaseTestCase.php', "<?php\nnamespace Tests;\n\nclass BaseTestCase extends \\Pramnos\\Framework\\Testing\\BaseTestCase\n{\n}\n");
        $this->writeFile('phpunit.xml', $this->getPhpunitXml());
        $this->writeFile('tests/Unit/ExampleTest.php', $this->renderStub('test', ['class' => 'Example']));
    }

    private function getPhpunitXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php" colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML;
    }

    private function getIndexTemplate(): string
    {
        return <<<PHP
<?php
require __DIR__ . '/../vendor/autoload.php';

define('ROOT', dirname(__DIR__));
define('SP', 1);

\$app = \Pramnos\Application\Application::getInstance();
\$app->init();
echo \$app->exec();
echo \$app->render();
PHP;
    }

    private function scaffoldDocker(string $namespace, int $port, string $dbType, string $dbName, string $dbUser, string $dbPass, string $cacheSystem, string $dbRootPass, string $cliName = ''): void
    {
        $isPostgres = ($dbType === 'postgresql' || $dbType === 'timescaledb');
        $slug       = strtolower(str_replace([' ', '_'], '-', $namespace));

        $image = match ($dbType) {
            'timescaledb' => 'timescale/timescaledb:latest-pg17',
            'mysql'       => 'mysql:8.0',
            default       => 'postgres:latest',
        };

        $extraVolumes = $this->detectFrameworkDevVolume();

        $compose  = "services:\n  app:\n    container_name: {$slug}_php\n    build: .\n    ports:\n      - \"$port:80\"\n    volumes:\n      - .:/var/www/html\n$extraVolumes    depends_on:\n      - db\n";

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

        $phpExts  = $isPostgres ? 'pdo_pgsql pgsql' : 'pdo_mysql mysqli';
        $docRoot  = '/var/www/html/www';

        $dockerfile  = "FROM php:8.5-apache\n";
        $dockerfile .= "RUN apt-get update && apt-get install -y libpq-dev libicu-dev libonig-dev libzip-dev libxml2-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev git unzip\n";
        $dockerfile .= "COPY --from=composer:latest /usr/bin/composer /usr/bin/composer\n";
        $dockerfile .= "RUN docker-php-ext-configure intl\n";
        $dockerfile .= "RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype\n";
        $dockerfile .= "RUN docker-php-ext-install pdo $phpExts intl mbstring zip bcmath gd\n";
        $dockerfile .= "RUN pecl install xdebug && docker-php-ext-enable xdebug\n";
        $dockerfile .= "RUN echo \"xdebug.mode=coverage\" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini\n";
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
            $cliWrapper = "#!/usr/bin/env bash\ndocker-compose exec app php $cliName.php \"\$@\"\n";
            $this->writeFile($cliName, $cliWrapper);
            @chmod($this->targetBaseDir . '/' . $cliName, 0755);
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
                return "      - {$repo['url']}:/var/www/PramnosFramework\n";
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
LOCK_FILE="/tmp/dockertest-{$nsLower}.lock"

_acquire_lock() {
    exec 9>"\$LOCK_FILE"
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
        [[ -n "\$existing_pid" ]] && echo "  To kill it:  kill \$existing_pid" >&2
        exit 1
    fi
fi
echo \$\$ >"\$LOCK_FILE"

nobrowser=false
coverage=false
testdox=false
passthrough=()
for arg in "\$@"; do
    if [[ "\$arg" == "--nobrowser" ]]; then
        nobrowser=true
    elif [[ "\$arg" == "--coverage" ]]; then
        coverage=true
    elif [[ "\$arg" == "--testdox" ]]; then
        testdox=true
    else
        passthrough+=("\$arg")
    fi
done

if ! docker-compose ps | grep -q "app.*Up"; then
    echo "Containers not running. Starting them..."
    docker-compose up -d
    sleep 5
fi

if [ ! -f "vendor/bin/phpunit" ]; then
    echo "Dependencies missing. Running composer install..."
    docker-compose exec app composer install
fi

extra_flags="--display-deprecations --display-warnings --display-notices --display-phpunit-deprecations"
[[ "\$testdox" == true ]] && extra_flags="\$extra_flags --testdox"

if [[ "\$coverage" == true ]]; then
    mkdir -p coverage
    docker-compose exec app vendor/bin/phpunit --coverage-html coverage \$extra_flags "\${passthrough[@]}"
else
    docker-compose exec app vendor/bin/phpunit \$extra_flags "\${passthrough[@]}"
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

if ! docker-compose ps | grep -q "app.*Up"; then
    echo "Containers not running. Starting them..."
    docker-compose up -d
    sleep 5
fi

docker-compose exec app bash
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
            return;
        }

        $slug = strtolower(str_replace([' ', '_'], '-', $appName));

        $composer['name']        = "app/$slug";
        $composer['description'] = "Pramnos Application: $appName";
        $composer['authors']     = [['name' => $userName, 'email' => $userEmail]];
        $composer['keywords']    = ['pramnos', 'framework', 'application', $slug];

        if (!isset($composer['require-dev'])) {
            $composer['require-dev'] = [];
        }
        $composer['require-dev']['phpunit/phpunit'] = '^11.0';

        $composer['autoload']     = ['psr-4' => ["$namespace\\" => 'src/']];
        $composer['autoload-dev'] = ['psr-4' => ['Tests\\' => 'tests/']];

        unset($composer['scripts']['post-create-project-cmd']);

        file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        string          $apiPrefix      = '/api/v1'
    ): void {
        $output->writeln("\nNext steps:");
        $steps = [];

        if ($useDocker) {
            if (!$this->dockerSuccess && !$this->skipDockerRun) {
                $steps[] = "Run <comment>docker-compose up -d --build</comment>";
            }
            $appUrl = "http://localhost:$dockerPort";
            $steps[] = "Access your app at <comment>$appUrl</comment>";
            if ($withApi) {
                $steps[] = "API base URL: <comment>{$appUrl}{$apiPrefix}</comment>";
            }
            $toolPort = $dockerPort + 1;
            $toolName = ($dbType === 'mysql') ? 'PHPMyAdmin' : 'Adminer';
            $steps[] = "Access $toolName at <comment>http://localhost:$toolPort</comment>";
            $steps[] = "Use <comment>./dockerbash</comment> to enter the container";
            $steps[] = "Database:\n    User: <comment>$dbUser</comment> / Pass: <comment>$dbPass</comment>"
                . ($dbType === 'mysql' ? "\n    Root Pass: <comment>$dbRootPass</comment>" : '');

            if ($this->migrationsSuccess) {
                $steps[] = "<info>✓ Framework migrations ran successfully.</info>";
            } elseif (!$skipMigrations) {
                $steps[] = "Run <comment>./$cliName migrate --scope=framework</comment> when the container is ready.";
            }

            if ($this->adminCredentials !== null) {
                $creds = $this->adminCredentials;
                $steps[] = "Admin account:\n"
                    . "    Email:    <comment>{$creds['email']}</comment>\n"
                    . "    Password: <comment>{$creds['password']}</comment>\n"
                    . "    <info>Save this password — it will not be shown again.</info>";
            }
        }

        if (!$this->autoloadSuccess) {
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
                continue;
            }
            $version = $libDef['version'];
            $deps    = $libDef['requires'] ?? [];
            $depsPhp = $deps ? "['" . implode("', '", $deps) . "']" : '[]';

            foreach ($libDef['js'] as $url) {
                $filename = basename(parse_url($url, PHP_URL_PATH));
                $path     = $libDef['local_path'] . '/' . $filename;
                $lines[]  = "        \$doc->registerScript('$lib', sURL . '$path', $depsPhp, '$version', true);";
            }
            foreach ($libDef['css'] as $url) {
                $filename = basename(parse_url($url, PHP_URL_PATH));
                $path     = $libDef['local_path'] . '/' . $filename;
                $lines[]  = "        \$doc->registerStyle('$lib', sURL . '$path', $depsPhp, '$version');";
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
        string $cliName
    ): string {
        $toolPort     = $dockerPort + 1;
        $toolName     = ($dbType === 'mysql') ? 'PHPMyAdmin' : 'Adminer';
        $featureList  = $enabledFeatures ? implode(', ', $enabledFeatures) : 'none';
        $libList      = $selectedLibraries ? implode(', ', $selectedLibraries) : 'none';
        $appUrl       = $useDocker ? "http://localhost:$dockerPort" : '/';
        $toolUrl      = $useDocker ? "http://localhost:$toolPort" : '#';

        $sections = "<h1>Welcome to $appName</h1>\n<p>Your Pramnos Framework application is ready.</p>\n\n";

        $sections .= "<h2>Application</h2>\n<ul>\n";
        $sections .= "  <li><strong>Namespace:</strong> $namespace</li>\n";
        $sections .= "  <li><strong>Features:</strong> $featureList</li>\n";
        $sections .= "  <li><strong>Libraries:</strong> $libList</li>\n";
        $sections .= "</ul>\n\n";

        if ($useDocker) {
            $sections .= "<h2>Quick Links</h2>\n<ul>\n";
            $sections .= "  <li><a href=\"$appUrl\">Application: $appUrl</a></li>\n";
            $sections .= "  <li><a href=\"$toolUrl\">$toolName: $toolUrl</a></li>\n";
            $sections .= "</ul>\n\n";
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

        return $sections;
    }

    /**
     * Poll the database container until it accepts connections (max 60 s).
     * Without this, migrate --scope=framework runs while MySQL/PostgreSQL is still
     * initialising and fails immediately after docker-compose up.
     */
    private function waitForDatabase(string $dbType, OutputInterface $output): void
    {
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
    }

    /**
     * After a successful migration run, ask if an admin user should be created
     * and run a PHP snippet inside the app container to INSERT the user.
     */
    private function createAdminUser(InputInterface $input, OutputInterface $output, mixed $helper, string $developerEmail = '', string $cliName = 'app'): void
    {
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

        // Generate a strong random password and display it — no prompt needed
        $adminPassword = $this->generateRandomPassword(16);
        $output->writeln("  <info>Generated password:</info> <comment>$adminPassword</comment>");

        // Escape values for safe injection into the single-quoted PHP string
        $safeUsername = addslashes($adminUsername);
        $safeEmail    = addslashes($adminEmail);
        $safePassword = addslashes($adminPassword);

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
    \$user->usertype  = 10;
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

        $result = trim((string) shell_exec("docker-compose exec -T app php /tmp/pramnos_admin.php 2>&1"));
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
            $output->writeln("  Run manually: docker-compose exec app php $cliName.php user:create --admin");
        }
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

        if (in_array('authserver', $features, true)) {
            $lines[] = '/app/keys/private.key';
            $lines[] = '/app/keys/encryption.key';
        }

        $content = implode("\n", $lines) . "\n";

        if (file_exists($path)) {
            $existing = file_get_contents($path);
            // Only append entries not already present
            foreach ($lines as $line) {
                if (strpos($existing, $line) === false) {
                    $existing .= $line . "\n";
                }
            }
            file_put_contents($path, $existing);
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
        array  $features
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
        ]));

        // ── .mcp.json ─────────────────────────────────────────────────────────
        $isPostgres = in_array($dbType, ['postgresql', 'timescaledb'], true);
        if ($isPostgres) {
            $dsn        = "postgresql://{$dbUser}:{$dbPass}@localhost:5432/{$dbName}";
            $mcpPackage = '@modelcontextprotocol/server-postgres';
            $mcpName    = 'postgres';
        } else {
            $dsn        = "mysql://{$dbUser}:{$dbPass}@localhost:3306/{$dbName}";
            $mcpPackage = '@modelcontextprotocol/server-mysql';
            $mcpName    = 'mysql';
        }

        $this->writeFile('.mcp.json', $this->renderStub('mcp.json', [
            'DB_MCP_NAME'    => $mcpName,
            'DB_MCP_PACKAGE' => $mcpPackage,
            'DB_MCP_DSN'     => $dsn,
        ]));

        // .mcp.json contains credentials — exclude from git
        $gitignorePath = $this->targetBaseDir . '/.gitignore';
        if (file_exists($gitignorePath)) {
            $gi = file_get_contents($gitignorePath);
            if (strpos($gi, '.mcp.json') === false) {
                file_put_contents($gitignorePath, $gi . ".mcp.json\n");
            }
        }
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
            $output->writeln('  <comment>Warning: OpenSSL not available — RSA keys NOT generated.</comment>');
            $output->writeln('  Generate manually: openssl genrsa -out app/keys/private.key 2048');
            return;
        }

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

    private function mkdir(string $path): void
    {
        $fullPath = $this->targetBaseDir . '/' . $path;
        if (!is_dir($fullPath)) {
            @mkdir($fullPath, 0777, true);
        }
    }

    private function writeFile(string $path, string $content): void
    {
        file_put_contents($this->targetBaseDir . '/' . $path, $content);
    }

    private function isPortAvailable(int $port): bool
    {
        $connection = @fsockopen('localhost', $port, $errno, $errstr, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            return false;
        }
        return true;
    }

    private function runProcessWithSpinner(string $command, string $message, OutputInterface $output): int
    {
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

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $output->writeln('<error>FAILED</error>');
            return 1;
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if ($isVerbose) {
                $out = stream_get_contents($pipes[1]);
                $err = stream_get_contents($pipes[2]);
                if ($out) $output->write($out);
                if ($err) $output->write($err);
            } else {
                $stdoutBuf .= (string) stream_get_contents($pipes[1]);
                $stderrBuf .= (string) stream_get_contents($pipes[2]);
                $output->write("\r\033[K$message " . $symbols[$i % 4]);
            }
            $i++;
            usleep(100_000);
        }

        $remainingOut = stream_get_contents($pipes[1]);
        $remainingErr = stream_get_contents($pipes[2]);

        if ($isVerbose) {
            if ($remainingOut) $output->write($remainingOut);
            if ($remainingErr) $output->write($remainingErr);
        } else {
            $stdoutBuf .= (string) $remainingOut;
            $stderrBuf .= (string) $remainingErr;
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        $exitCode = proc_close($process);

        if ($isVerbose) {
            $output->writeln($exitCode === 0 ? "<info>$message: DONE</info>" : "<error>$message: FAILED (Exit Code: $exitCode)</error>");
        } else {
            $suffix = $exitCode === 0 ? "<info>DONE</info>" : "<error>FAILED</error>";
            $output->write("\r\033[K$message $suffix\n");
            if ($exitCode !== 0) {
                $combined = trim($stdoutBuf . "\n" . $stderrBuf);
                if ($combined !== '') {
                    $output->writeln('<error>' . $combined . '</error>');
                }
            }
        }

        return $exitCode;
    }
}
