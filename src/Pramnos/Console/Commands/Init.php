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
        $this->addOption('rest-api',      null, InputOption::VALUE_OPTIONAL, 'Scaffold REST API layer (y/n)');
        $this->addOption('api-docs',      null, InputOption::VALUE_OPTIONAL, 'Generate API documentation tooling (apidoc → OpenAPI) (y/n)');
        $this->addOption('api-url',       null, InputOption::VALUE_OPTIONAL, 'Production API base URL for documentation');
        $this->addOption('api-color',     null, InputOption::VALUE_OPTIONAL, 'Primary color for API docs UI (hex, e.g. #4CAF50)');
        $this->addOption('webhook',       null, InputOption::VALUE_OPTIONAL, 'Generate www/webhook.php git webhook receiver (y/n)');
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
        $withWebhook     = $this->askWebhook($input, $output, $helper);
        $withApiDocs     = false;
        $apiUrl          = 'https://api.example.com';
        $apiColor        = '#4CAF50';
        if ($withRestApi) {
            [$withApiDocs, $apiUrl, $apiColor] = $this->askApiDocs($input, $output, $helper, $appName);
        }

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
        $this->writeFile('www/index.php', $this->getIndexTemplate($namespace));
        $this->writeFile('www/.htaccess', "RewriteEngine On\nRewriteRule ^$ index.php [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^(.*)$ index.php?r=\$1 [QSA,L]\n");
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
            $appName, $namespace, $enabledFeatures, $selectedLibraries, $useDocker, $dockerPort, $dbType, $cliName,
            $withRestApi
        ));

        $this->scaffoldTheme($uiSystem, $appName, $catalog, $enabledFeatures);

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

        if ($useDocker) {
            $this->scaffoldDocker($namespace, $dockerPort, $dbType, $dbName, $dbUser, $dbPass, $cacheSystem, $dbRootPass, $cliName);
        }

        $this->scaffoldTests($namespace, $dbType, $dbHost, $dbName, $dbUser, $dbPass, $dbPrefix, $useDocker, $enabledFeatures);
        $this->scaffoldGitignore($enabledFeatures);

        if ($withRestApi) {
            $this->scaffoldRestApi($namespace);
            if ($withApiDocs) {
                $this->scaffoldApiDocs($appName, $namespace, $apiUrl, $apiColor);
            }
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

        // Register the auth addon pair when the auth feature is enabled:
        //   - UserDatabase: handles password verification (type=auth)
        //   - User:         sets $_SESSION['logged'], uid, username after a successful
        //                   login and clears cookies on logout (type=user)
        // Without UserDatabase, Auth::auth() always returns false.
        // Without User, auth succeeds but $_SESSION['logged'] is never set, so the
        // app behaves as if the user is not logged in after every login attempt.
        $addonsSection = in_array('auth', $features, true)
            ? "    'addons' => [\n"
              . "        ['addon' => 'Pramnos\\\\Addon\\\\Auth\\\\UserDatabase', 'type' => 'auth'],\n"
              . "        ['addon' => 'Pramnos\\\\Addon\\\\User\\\\User', 'type' => 'user'],\n"
              . "    ],\n"
            : '';

        $content = "<?php\nreturn [\n    'name' => '$appName',\n    'namespace' => '$namespace',\n    'theme' => 'default',\n{$scaffoldLine}{$featuresPhp}{$addonsSection}{$apiSection}    'csp' => [\n        'script-src' => [],\n        'style-src'  => []\n    ]\n];\n";
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
            $enabled  = in_array(strtolower($option), ['y', 'yes', '1', 'true'], true);
            $apiUrl   = $input->getOption('api-url')   ?: 'https://api.example.com';
            $apiColor = $input->getOption('api-color') ?: '#4CAF50';
            return [$enabled, $apiUrl, $apiColor];
        }
        $output->writeln("\n<comment>Step 2d — API Documentation</comment>");
        $enabled = $helper->ask($input, $output, new ConfirmationQuestion(
            'Generate API documentation tooling (apidoc → OpenAPI + RapiDoc)? [Y/n] ', true
        ));
        if (!$enabled) {
            return [false, '', ''];
        }
        $defaultUrl   = 'https://api.example.com';
        $defaultColor = '#4CAF50';
        $apiUrl = $input->getOption('api-url')
            ?: $helper->ask($input, $output, new Question("Production API base URL [$defaultUrl]: ", $defaultUrl));
        $apiColor = $input->getOption('api-color')
            ?: $helper->ask($input, $output, new Question("Primary color for docs UI [$defaultColor]: ", $defaultColor));
        return [$enabled, $apiUrl, $apiColor];
    }

    private function scaffoldApiDocs(string $appName, string $namespace, string $apiUrl, string $apiColor): void
    {
        $this->mkdir('scripts');
        $this->mkdir('www/api/docs');
        $this->mkdir('www/api/docs/old');

        $appKey = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $namespace));

        $this->writeFile('src/Api/apidoc.json', $this->renderStub('api-doc.json', [
            'APP_NAME'      => $appName,
            'API_URL'       => rtrim($apiUrl, '/'),
            'PRIMARY_COLOR' => $apiColor,
            'APP_KEY'       => $appKey,
        ]));

        $this->writeFile('src/Api/openapi-overrides.json', $this->renderStub('openapi-overrides.json', [
            'APP_NAME' => $appName,
        ]));

        $scriptSrc = $this->scaffoldingDir . '/scripts/apidoc-to-openapi.js';
        if (file_exists($scriptSrc)) {
            $this->writeFile('scripts/apidoc-to-openapi.js', (string) file_get_contents($scriptSrc));
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
            if (!str_contains($existing, 'www/api/docs')) {
                file_put_contents($gitignorePath, "\n# API documentation output\nwww/api/openapi*.json\nwww/api/docs/\n", FILE_APPEND);
            }
        }
    }

    private function ensurePackageJsonApiScripts(string $namespace): void
    {
        $pkgPath = $this->targetBaseDir . '/package.json';
        if (file_exists($pkgPath)) {
            $pkg = json_decode((string) file_get_contents($pkgPath), true) ?: [];
        } else {
            $pkg = [
                'name'        => strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $namespace)),
                'version'     => '1.0.0',
                'private'     => true,
                'description' => 'Node tooling for ' . $namespace,
            ];
        }
        $pkg['scripts'] = array_merge($pkg['scripts'] ?? [], [
            'apidoc'   => 'node scripts/apidoc-to-openapi.js',
            'docs'     => 'bash scripts/doc.sh',
        ]);
        if (!isset($pkg['dependencies']['rapidoc'])) {
            $pkg['devDependencies'] = array_merge($pkg['devDependencies'] ?? [], [
                'rapidoc' => '^9.3.4',
            ]);
        }
        file_put_contents($pkgPath, json_encode($pkg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Creates www/webhook.php and adds WEBHOOK_SECRET to .env.example.
     *
     * The generated file uses Dotenv for environment loading and WebhookHandler
     * for HMAC verification — exactly as the `make:webhook` command produces.
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
            $envContents = (string) file_get_contents($envExample);
            if (!str_contains($envContents, 'WEBHOOK_SECRET')) {
                file_put_contents($envExample, "\n# Git webhook HMAC secret\nWEBHOOK_SECRET=\n", FILE_APPEND);
            }
        }
    }

    private function scaffoldRestApi(string $namespace): void
    {
        $this->mkdir('src/Api/Controllers');

        $routesStub = <<<'ROUTES'
<?php
declare(strict_types=1);

// API routes — included by Api::_executeCore() with $this bound to the Api instance.
// Return value of this file is the dispatched response (passed back to the caller).

$router     = new \Pramnos\Routing\Router($this);
$newRequest = new \Pramnos\Http\Request();

$router->group(
    ['prefix' => '/v1'],
    function (\Pramnos\Routing\Router $r): void {
        // Example: $r->get('/hello', [{{ namespace }}\Api\Controllers\HelloController::class, 'index']);
    }
);

return $router->dispatch($newRequest);
ROUTES;

        $this->writeFile('src/Api/routes.php', str_replace('{{ namespace }}', $namespace, $routesStub));

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

    private function scaffoldSettings(string $path, string $type, string $host, string $name, string $user, string $pass, string $prefix, bool $dev): void
    {
        $realType      = ($type === 'timescaledb') ? 'postgresql' : $type;
        $timescaleFlag = ($type === 'timescaledb') ? ",\n        'timescale' => true" : '';

        $content = "<?php\nreturn [\n    'database' => [\n        'type' => '$realType',\n        'hostname' => '$host',\n        'database' => '$name',\n        'user' => '$user',\n        'password' => '$pass',\n        'prefix' => '$prefix'$timescaleFlag\n    ],\n    'dbsettings' => true,\n    'language' => 'en',\n    'development' => " . ($dev ? 'true' : 'false') . ",\n    'forcessl' => false\n];\n";
        $this->writeFile($path, $content);
    }

    /**
     * Scaffold the theme. header/footer include only layout-critical assets
     * (bootstrap CSS+JS for the bootstrap theme). All other libraries are
     * registered in Application::registerVendorLibraries() and enqueued
     * per-page by controllers via addScript()/addStyle().
     */
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

        if ($uiSystem === 'bootstrap') {
            $this->ensureBootstrapAssets();
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
            <a class="navbar-brand fw-bold" href="<?php echo sURL; ?>">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
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
            default => <<<'HTML'
    <header class="main-header">
        <div class="container">
            <a href="<?php echo sURL; ?>" class="logo">
                <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>
            </a>
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

        return "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n"
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
            . "    <script src=\"<?php echo sURL; ?>assets/js/pf-utils.js\"></script>\n"
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

    private function scaffoldTests(string $namespace, string $dbType, string $dbHost, string $dbName, string $dbUser, string $dbPass, string $dbPrefix, bool $useDocker, array $features = []): void
    {
        $this->mkdir('tests/Unit/Controllers');
        $this->mkdir('tests/Integration');

        $testDbName = $dbName . '_test';
        $this->scaffoldSettings('app/config/testsettings.php', $dbType, $dbHost, $testDbName, $dbUser, $dbPass, $dbPrefix, true);

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
 * Tests cover the full surface of the scaffolded Home controller:
 * class structure, constructor contract, and every action method.
 * No database or web server is required — view rendering is mocked.
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

    /**
     * The constructor must register edit, save, and delete as auth-required
     * actions via addAuthAction().
     *
     * Without this registration, unauthenticated users could reach those
     * routes — a silent access-control failure.
     */
    public function testHomeConstructorRegistersAuthActions(): void
    {
        // Arrange
        \$home = new Home(null);
        \$ref  = new \\ReflectionClass(\$home);

        // Act — read the protected auth-actions list (property name: actions_auth)
        \$prop       = \$ref->getProperty('actions_auth');
        \$authActions = \$prop->getValue(\$home);

        // Assert — all three write-actions require authentication
        \$this->assertContains('edit',   \$authActions,
            "'edit' must require auth — modifies content");
        \$this->assertContains('save',   \$authActions,
            "'save' must require auth — persists changes");
        \$this->assertContains('delete', \$authActions,
            "'delete' must require auth — destructive operation");
    }

    // -------------------------------------------------------------------------
    // Actions — view-rendering methods
    // -------------------------------------------------------------------------

    /**
     * display() must return the string produced by the view.
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

    /**
     * show() must pass the 'show' template name to the view and return its output.
     */
    public function testShowReturnsViewContent(): void
    {
        // Arrange
        \$home = \$this->getMockBuilder(Home::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['getView'])
            ->getMock();

        \$mockView = \$this->createMock(\\Pramnos\\Application\\View::class);
        \$mockView->method('display')->willReturn('<section>show</section>');
        \$home->method('getView')->willReturn(\$mockView);

        // Act
        \$result = \$home->show();

        // Assert
        \$this->assertSame('<section>show</section>', \$result,
            'show() must return the string produced by the view');
    }

    /**
     * edit() requires auth (registered in constructor) and must return the
     * rendered edit form from the view.
     */
    public function testEditReturnsViewContent(): void
    {
        // Arrange
        \$home = \$this->getMockBuilder(Home::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['getView'])
            ->getMock();

        \$mockView = \$this->createMock(\\Pramnos\\Application\\View::class);
        \$mockView->method('display')->willReturn('<form>edit</form>');
        \$home->method('getView')->willReturn(\$mockView);

        // Act
        \$result = \$home->edit();

        // Assert
        \$this->assertSame('<form>edit</form>', \$result,
            'edit() must return the rendered form string');
    }

    // -------------------------------------------------------------------------
    // Actions — redirect methods
    // -------------------------------------------------------------------------

    /**
     * save() must redirect back to the home page after processing.
     *
     * redirect() is mocked to capture the call without triggering an HTTP
     * header (which would crash in CLI) or requiring a live Application.
     */
    public function testSaveRedirectsToHomePage(): void
    {
        // Arrange
        \$home = \$this->getMockBuilder(Home::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect'])
            ->getMock();

        // Assert — redirect is called exactly once with a URL ending in 'home'
        \$home->expects(\$this->once())
            ->method('redirect')
            ->with(\$this->stringContains('home'));

        // Act
        \$home->save();
    }

    /**
     * delete() must redirect back to the home page after processing.
     */
    public function testDeleteRedirectsToHomePage(): void
    {
        // Arrange
        \$home = \$this->getMockBuilder(Home::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect'])
            ->getMock();

        \$home->expects(\$this->once())
            ->method('redirect')
            ->with(\$this->stringContains('home'));

        // Act
        \$home->delete();
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
            $uses .= "use {$namespace}\\Controllers\\TokenActions;\n";
            $uses .= "use {$namespace}\\Controllers\\Tokens;\n";
            $uses .= "use {$namespace}\\Controllers\\Oauth;\n";
        }
        if ($hasAuthserver) {
            $uses .= "use {$namespace}\\Controllers\\Applications;\n";
            $uses .= "use {$namespace}\\Controllers\\Permissions;\n";
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
            $rows .= "            'Login'         => [Login::class,         \\Pramnos\\Application\\Controller::class],\n";
            $rows .= "            'Account'       => [Account::class,       \\Pramnos\\Auth\\Controllers\\Dashboard::class],\n";
            $rows .= "            'TwoFactorAuth' => [TwoFactorAuth::class, \\Pramnos\\Auth\\Controllers\\TwoFactorAuth::class],\n";
            $rows .= "            'TokenActions'  => [TokenActions::class,  \\Pramnos\\Auth\\Controllers\\TokenActionsController::class],\n";
            $rows .= "            'Tokens'        => [Tokens::class,        \\Pramnos\\Auth\\Controllers\\TokensController::class],\n";
            $rows .= "            'Oauth'         => [Oauth::class,         \\Pramnos\\Auth\\Controllers\\Oauth::class],\n";
        }
        if ($hasAuthserver) {
            $rows .= "            'Applications'  => [Applications::class,  \\Pramnos\\Auth\\Controllers\\ApplicationsController::class],\n";
            $rows .= "            'Permissions'   => [Permissions::class,   \\Pramnos\\Auth\\Controllers\\PermissionsController::class],\n";
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

/**
 * Unit tests for the Login controller.
 *
 * Covers the full surface of the scaffolded Login controller:
 * class structure, action registration, and every method branch.
 *
 * CSRF setup note:
 *   The real dologin() validates the session CSRF token before checking
 *   credentials.  Tests that need to reach the credentials branch call
 *   setupValidCsrfToken() which syncs the Session singleton's internal
 *   _token with \$_SESSION['token'] and places the correct HMAC fingerprint
 *   in \$_POST.  This mirrors what the HTML form hidden-field provides in
 *   a real browser request.
 *
 * Auth success / failure note:
 *   Auth::auth() requires a live database connection; those branches are
 *   covered in tests/Integration/AuthFlowTest.php.  The unit tests here
 *   only verify that dologin() calls redirect() with the correct target
 *   and that \$_SESSION['login_error'] is set or unset appropriately.
 */
class LoginControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset(\$_SESSION['login_error']);
        \$_POST = [];
    }

    protected function tearDown(): void
    {
        \$tokenName = \$_SESSION['token'] ?? null;
        if (\$tokenName !== null) {
            unset(\$_POST[\$tokenName]);
        }
        \$_POST = [];
        unset(\$_SESSION['login_error']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Initialise the Session singleton and place the correct CSRF fingerprint
     * in \$_POST so that Session::checkToken('post') returns true.
     */
    protected function setupValidCsrfToken(): void
    {
        \$session = \\Pramnos\\Http\\Session::getInstance();
        \$session->start();

        \$tokenName = \$_SESSION['token'];
        \$ua        = \$_SERVER['HTTP_USER_AGENT'] ?? 'none';
        \$_POST[\$tokenName] = hash_hmac('sha256', \$ua . '', \$tokenName);
    }

    // =========================================================================
    // Structure
    // =========================================================================

    /**
     * Login extends \\Pramnos\\Application\\Controller and exposes the three
     * public action methods.  Missing methods cause silent URL failures.
     */
    public function testLoginControllerHasRequiredActions(): void
    {
        // Act
        \$login = new Login(null);

        // Assert — class hierarchy
        \$this->assertInstanceOf(
            \\Pramnos\\Application\\Controller::class,
            \$login,
            'Login must extend \\Pramnos\\Application\\Controller'
        );

        // Assert — methods exist (the router requires these)
        \$this->assertTrue(method_exists(\$login, 'display'),  'Login::display() must exist');
        \$this->assertTrue(method_exists(\$login, 'dologin'),  'Login::dologin() must exist');
        \$this->assertTrue(method_exists(\$login, 'logout'),   'Login::logout() must exist');
    }

    /**
     * The constructor must register 'dologin' and 'logout' via addaction()
     * so that Controller::exec() can dispatch POST requests.
     *
     * Without this registration the controller only exposes display() and
     * silently falls back to it for every URL.
     */
    public function testLoginControllerRegistersActionsInConstructor(): void
    {
        // Arrange
        \$login = new Login(null);

        // Act — read the protected actions list via reflection
        \$ref     = new \\ReflectionClass(\$login);
        \$prop    = \$ref->getProperty('actions');
        \$actions = \$prop->getValue(\$login);

        // Assert
        \$this->assertContains('dologin', \$actions,
            "dologin must be in actions so exec() can dispatch POST /login/dologin");
        \$this->assertContains('logout',  \$actions,
            "logout must be in actions so exec() can dispatch /login/logout");
    }

    // =========================================================================
    // display()
    // =========================================================================

    /**
     * display() must return the string produced by the view.
     *
     * The view is mocked so no template files are required on disk.
     */
    public function testDisplayReturnsViewContent(): void
    {
        // Arrange
        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['getView'])
            ->getMock();

        \$mockView = \$this->createMock(\\Pramnos\\Application\\View::class);
        \$mockView->method('display')->willReturn('<form>login</form>');
        \$login->method('getView')->willReturn(\$mockView);

        // Act
        \$result = \$login->display();

        // Assert
        \$this->assertSame('<form>login</form>', \$result,
            'display() must return the string produced by the view');
    }

    /**
     * display() must pass the stored login error from the session to the view
     * and clear it immediately, so the error is shown once and then gone.
     */
    public function testDisplayPassesAndClearsSessionError(): void
    {
        // Arrange — simulate a previous failed dologin() having stored an error
        \$_SESSION['login_error'] = 'Bad credentials';

        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['getView'])
            ->getMock();

        \$mockView = \$this->createMock(\\Pramnos\\Application\\View::class);
        \$mockView->method('display')->willReturn('');
        \$login->method('getView')->willReturn(\$mockView);

        // Act
        \$login->display();

        // Assert — the session error has been cleared after display() ran
        \$this->assertArrayNotHasKey('login_error', \$_SESSION,
            'display() must unset \$_SESSION[login_error] after passing it to the view');
    }

    // =========================================================================
    // dologin() — CSRF branch
    // =========================================================================

    /**
     * dologin() must redirect back to /login and set a CSRF error in the session
     * when the POST does not contain a valid CSRF token.
     *
     * This is the FIRST guard in dologin() and must fire even when credentials
     * are present and valid — a missing token indicates a forged or replayed request.
     */
    public function testDologinRedirectsOnCsrfFailure(): void
    {
        // Arrange — valid credentials BUT no CSRF token in POST
        \$_POST = ['username' => 'admin', 'password' => 'secret'];

        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect'])
            ->getMock();

        \$login->expects(\$this->once())
            ->method('redirect')
            ->with(\$this->stringContains('login'));

        // Act
        \$login->dologin();

        // Assert — CSRF error was stored for the view to display
        \$this->assertNotEmpty(
            \$_SESSION['login_error'] ?? '',
            'dologin() must store a CSRF error message in the session on token failure'
        );
    }

    // =========================================================================
    // dologin() — empty credentials branch
    // =========================================================================

    /**
     * dologin() must redirect back to /login and set a session error when
     * the submitted username is empty (CSRF token is valid).
     */
    public function testDologinRedirectsOnEmptyUsername(): void
    {
        // Arrange — CSRF passes, but empty username
        \$this->setupValidCsrfToken();
        \$_POST['username'] = '';
        \$_POST['password'] = 'anything';

        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect'])
            ->getMock();

        \$login->expects(\$this->once())
            ->method('redirect')
            ->with(\$this->stringContains('login'));

        // Act
        \$login->dologin();

        // Assert
        \$this->assertNotEmpty(
            \$_SESSION['login_error'] ?? '',
            'dologin() must set \$_SESSION[login_error] when username is empty'
        );
    }

    /**
     * dologin() must redirect and set a session error when the password is empty
     * and the CSRF token is valid.
     */
    public function testDologinRedirectsOnEmptyPassword(): void
    {
        // Arrange — CSRF passes, but empty password
        \$this->setupValidCsrfToken();
        \$_POST['username'] = 'someone';
        \$_POST['password'] = '';

        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect'])
            ->getMock();

        \$login->expects(\$this->once())
            ->method('redirect')
            ->with(\$this->stringContains('login'));

        // Act
        \$login->dologin();

        // Assert
        \$this->assertNotEmpty(
            \$_SESSION['login_error'] ?? '',
            'dologin() must set an error message when password is empty'
        );
    }

    // =========================================================================
    // dologin() — auth branches (subclass stubs; real auth covered in Integration)
    // =========================================================================

    /**
     * dologin() must redirect to sURL (homepage) on a successful authentication.
     *
     * Uses a subclass that short-circuits the auth call so no live DB is needed.
     */
    public function testDologinRedirectsToHomepageOnAuthSuccess(): void
    {
        // Arrange — CSRF passes, credentials present
        \$this->setupValidCsrfToken();
        \$_POST['username'] = 'testuser';
        \$_POST['password'] = 'testpass';

        \$login = new class(null) extends Login {
            public bool   \$shouldRedirect = false;
            public string \$redirectTarget = '';

            public function redirect(\$url = null, \$quit = true, \$code = '302'): void
            {
                \$this->shouldRedirect = true;
                \$this->redirectTarget = (string) \$url;
            }

            public function dologin(): void
            {
                if (!\\Pramnos\\Http\\Session::getInstance()->checkToken('post')) {
                    \$_SESSION['login_error'] = 'Invalid or expired form token. Please try again.';
                    \$this->redirect(sURL . 'login');
                    return;
                }
                \$username = trim((string) (\$_POST['username'] ?? ''));
                \$password  = (string) (\$_POST['password'] ?? '');
                if (\$username === '' || \$password === '') {
                    \$_SESSION['login_error'] = 'Please enter your username and password.';
                    \$this->redirect(sURL . 'login');
                    return;
                }
                // Simulate Auth::auth() returning true
                \$this->redirect(sURL);
            }
        };

        // Act
        \$login->dologin();

        // Assert
        \$this->assertTrue(\$login->shouldRedirect, 'dologin() must call redirect() on auth success');
        \$this->assertStringNotContainsString('login', \$login->redirectTarget,
            'On auth success dologin() must redirect to sURL (not back to login page)');
        \$this->assertArrayNotHasKey('login_error', \$_SESSION,
            'On auth success dologin() must NOT set a session error');
    }

    /**
     * dologin() must redirect back to /login and set a session error when
     * authentication fails (wrong credentials).
     */
    public function testDologinRedirectsToLoginOnAuthFailure(): void
    {
        // Arrange — CSRF passes, credentials present
        \$this->setupValidCsrfToken();
        \$_POST['username'] = 'testuser';
        \$_POST['password'] = 'wrongpass';

        \$login = new class(null) extends Login {
            public bool   \$shouldRedirect = false;
            public string \$redirectTarget = '';

            public function redirect(\$url = null, \$quit = true, \$code = '302'): void
            {
                \$this->shouldRedirect = true;
                \$this->redirectTarget = (string) \$url;
            }

            public function dologin(): void
            {
                if (!\\Pramnos\\Http\\Session::getInstance()->checkToken('post')) {
                    \$_SESSION['login_error'] = 'Invalid or expired form token. Please try again.';
                    \$this->redirect(sURL . 'login');
                    return;
                }
                \$username = trim((string) (\$_POST['username'] ?? ''));
                \$password  = (string) (\$_POST['password'] ?? '');
                if (\$username === '' || \$password === '') {
                    \$_SESSION['login_error'] = 'Please enter your username and password.';
                    \$this->redirect(sURL . 'login');
                    return;
                }
                // Simulate Auth::auth() returning false
                \$_SESSION['login_error'] = 'Invalid username or password.';
                \$this->redirect(sURL . 'login');
            }
        };

        // Act
        \$login->dologin();

        // Assert
        \$this->assertTrue(\$login->shouldRedirect, 'dologin() must call redirect() on auth failure');
        \$this->assertStringContainsString('login', \$login->redirectTarget,
            'On auth failure dologin() must redirect back to the login page');
        \$this->assertNotEmpty(
            \$_SESSION['login_error'] ?? '',
            'On auth failure dologin() must set \$_SESSION[login_error]'
        );
    }

    // =========================================================================
    // logout()
    // =========================================================================

    /**
     * logout() must call redirect() to send the user away after clearing
     * the session.
     *
     * The redirect target is the application root (sURL), not the login page.
     * Auth::logout() is exercised here but does not require real DB state.
     */
    public function testLogoutCallsRedirect(): void
    {
        // Arrange
        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect'])
            ->getMock();

        \$login->expects(\$this->once())
            ->method('redirect');

        // Act — Auth::getInstance()->logout() clears the session; redirect() is mocked
        \$login->logout();
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
        unset(\$_SESSION['login_error'], \$_SESSION['logged']);
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
     * The User addon must be registered (type=user) — without it, auth() returns
     * true from UserDatabase but \$_SESSION['logged'] is never set.
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

        // Assert — session must be marked as logged in (requires User addon to be registered)
        \$this->assertNotEmpty(\$_SESSION['logged'] ?? null,
            '\$_SESSION[logged] must be set after successful auth — check that Addon\\\\User\\\\User is registered in app.php');

        // Cleanup — no backtick quotes: works on both MySQL and PostgreSQL
        \$db = \\Pramnos\\Database\\Database::getInstance();
        \$db->query(\$db->prepareQuery("DELETE FROM #PREFIX#users WHERE userid = %d", \$userId));
    }

    // -----------------------------------------------------------------------
    // Login::dologin() — controller-level integration tests
    // -----------------------------------------------------------------------

    /**
     * dologin() redirects to the application root on successful authentication.
     *
     * Creates a real user in the test database, submits valid credentials via
     * \$_POST, and confirms the controller calls redirect() with the site root URL.
     * redirect() is mocked to avoid HTTP headers being sent in the test runner.
     * This covers the true-branch of the auth check in dologin().
     */
    public function testDologinRedirectsToHomeOnSuccessfulLogin(): void
    {
        // Arrange — create a disposable test user with a known password
        \$user            = new \\Pramnos\\User\\User();
        \$user->username  = 'testuser_dologin_ok';
        \$user->email     = 'dologin_ok@example.com';
        \$user->usertype  = 50;
        \$user->validated = 1;
        \$user->save();
        \$userId = (int) \$user->userid;
        \$user->setPassword('correctpass');
        \$user->save();

        \$_POST = ['username' => 'testuser_dologin_ok', 'password' => 'correctpass'];

        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect'])
            ->getMock();

        // Assert — on success the controller sends the user to the site root (sURL)
        \$login->expects(\$this->once())
            ->method('redirect')
            ->with(\$this->stringContains(sURL));

        // Act
        \$login->dologin();

        // Cleanup
        \$db = \\Pramnos\\Database\\Database::getInstance();
        \$db->query(\$db->prepareQuery("DELETE FROM #PREFIX#users WHERE userid = %d", \$userId));
    }

    /**
     * dologin() redirects back to /login and sets a session error when
     * credentials are rejected by Auth::auth().
     *
     * This covers the else-branch in dologin() — wrong password, locked account,
     * or any other failure the Auth layer reports via lastResponse.
     */
    public function testDologinRedirectsOnInvalidCredentials(): void
    {
        // Arrange — create a real user but submit the wrong password
        \$user            = new \\Pramnos\\User\\User();
        \$user->username  = 'testuser_dologin_fail';
        \$user->email     = 'dologin_fail@example.com';
        \$user->usertype  = 50;
        \$user->validated = 1;
        \$user->save();
        \$userId = (int) \$user->userid;
        \$user->setPassword('rightpassword');
        \$user->save();

        \$_POST = ['username' => 'testuser_dologin_fail', 'password' => 'wrongpassword'];

        \$login = \$this->getMockBuilder(Login::class)
            ->setConstructorArgs([null])
            ->onlyMethods(['redirect'])
            ->getMock();

        // Assert — failure must redirect back to the login page
        \$login->expects(\$this->once())
            ->method('redirect')
            ->with(\$this->stringContains('login'));

        // Act
        \$login->dologin();

        // Assert — error message is stored in the session for the view to display
        \$this->assertNotEmpty(\$_SESSION['login_error'] ?? null,
            '\$_SESSION[login_error] must be set when credentials are rejected by Auth');

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

    private function getIndexTemplate(string $namespace = 'Pramnos'): string
    {
        return <<<PHP
<?php
define('ROOT', dirname(__DIR__));
define('SP', 1);
require ROOT . '/vendor/autoload.php';

\$app = new \\$namespace\\Application();
\$app->init();
\$app->exec();
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
        string $cliName,
        bool   $withApi    = false,
        string $apiPrefix  = '/api/v1'
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
        // Uses the framework's built-in MCP server (./bin/pramnos mcp:serve)
        // instead of external npm packages — no credentials needed, safe to commit.
        $appSlug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $appName) ?: 'app');
        $this->writeFile('.mcp.json', $this->renderStub('mcp.json', [
            'APP_SLUG' => $appSlug,
        ]));
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

    /**
     * Scaffold auth wiring for a new application: Login controller, login view,
     * and an Account controller wrapper around the framework Dashboard.
     *
     * Called from execute() only when the 'auth' feature is enabled.
     */
    private function scaffoldAuthWiring(string $namespace, string $uiSystem): void
    {
        $this->mkdir('src/Controllers');
        $this->mkdir('src/Views/login');
        $this->mkdir('src/Views/account');

        // ── Login controller ──────────────────────────────────────────────────
        $loginController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

use Pramnos\\Application\\Controller;
use Pramnos\\Auth\\Auth;

/**
 * Handles user login, logout, and login form display.
 */
class Login extends Controller
{
    public function __construct(?\\Pramnos\\Application\\Application \$application = null)
    {
        \$this->addaction(['dologin', 'logout']);
        parent::__construct(\$application);
    }

    /** Show the login form. */
    public function display()
    {
        \$doc = \\Pramnos\\Framework\\Factory::getDocument();
        \$doc->title = 'Login';

        \$view = \$this->getView('login');
        \$view->error = \$_SESSION['login_error'] ?? '';
        unset(\$_SESSION['login_error']);
        return \$view->display();
    }

    /** Process login form submission (POST). */
    public function dologin(): void
    {
        if (!\Pramnos\Http\Session::getInstance()->checkToken('post')) {
            \$_SESSION['login_error'] = 'Invalid or expired form token. Please try again.';
            \$this->redirect(sURL . 'login');
            return;
        }

        \$username = trim((string) (\$_POST['username'] ?? ''));
        \$password  = (string) (\$_POST['password'] ?? '');
        \$remember  = !empty(\$_POST['remember']);

        if (\$username === '' || \$password === '') {
            \$_SESSION['login_error'] = 'Please enter your username and password.';
            \$this->redirect(sURL . 'login');
            return;
        }

        \$auth = Auth::getInstance();
        if (\$auth->auth(\$username, \$password, \$remember)) {
            \$this->redirect(sURL);
        } else {
            \$response = \$auth->lastResponse;
            \$_SESSION['login_error'] = \$response['message'] ?? 'Invalid username or password.';
            \$this->redirect(sURL . 'login');
        }
    }

    /** Log out the current user and redirect to the homepage. */
    public function logout(): void
    {
        Auth::getInstance()->logout();
        \$this->redirect(sURL);
    }
}
PHP;

        $this->writeFile('src/Controllers/Login.php', $loginController);

        // ── Account controller (wrapper for framework Dashboard) ──────────────
        $accountController = <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Controllers;

/**
 * User account dashboard — delegates to the framework Dashboard controller.
 *
 * Routes: /account (display), /account/security, /account/changepassword, etc.
 * All actions require authentication (inherited from framework Dashboard).
 */
class Account extends \\Pramnos\\Auth\\Controllers\\Dashboard
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

        // ── Login view ────────────────────────────────────────────────────────
        $loginView = $uiSystem === 'bootstrap'
            ? $this->buildBootstrapLoginView()
            : $this->buildPlainLoginView();

        $this->writeFile('src/Views/login/login.html.php', $loginView);

        // ── Account views directory ───────────────────────────────────────────
        // The framework Dashboard controller resolves its views from the app's
        // view path (via getView('dashboard')). Scaffold a minimal placeholder.
        $dashboardView = <<<'HTML'
<?php /** @var \Pramnos\View\View $this */ ?>
<div class="container mt-4">
    <h1>My Account</h1>
    <p>Welcome, <?php echo htmlspecialchars($this->user->username ?? 'User', ENT_QUOTES, 'UTF-8'); ?>.</p>
    <ul>
        <li><a href="<?php echo sURL; ?>account/security">Security</a></li>
        <li><a href="<?php echo sURL; ?>account/changepassword">Change Password</a></li>
        <li><a href="<?php echo sURL; ?>login/logout">Logout</a></li>
    </ul>
</div>
HTML;

        $this->writeFile('src/Views/account/dashboard.html.php', $dashboardView);
    }

    private function buildBootstrapLoginView(): string
    {
        return <<<'HTML'
<?php /** @var \Pramnos\View\View $this */ ?>
<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <h2 class="card-title mb-4 text-center">Login</h2>
                    <?php if (!empty($this->error)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($this->error, ENT_QUOTES, 'UTF-8'); ?></div>
                    <?php endif; ?>
                    <form method="post" action="<?php echo sURL; ?>login/dologin">
                        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username or Email</label>
                            <input type="text" class="form-control" id="username" name="username" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1">
                            <label class="form-check-label" for="remember">Remember me</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Login</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
HTML;
    }

    private function buildPlainLoginView(): string
    {
        return <<<'HTML'
<?php /** @var \Pramnos\Application\View $this */ ?>
<div class="form-card">
    <h2>Login</h2>
    <?php if (!empty($this->error)): ?>
    <p class="error"><?php echo htmlspecialchars($this->error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <form method="post" action="<?php echo sURL; ?>login/dologin">
        <?php echo \Pramnos\Http\Session::getInstance()->getTokenField(); ?>
        <div class="form-group">
            <label for="username">Username or Email</label>
            <input type="text" id="username" name="username" required autofocus>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required>
        </div>
        <div class="form-group-check">
            <input type="checkbox" id="remember" name="remember" value="1">
            <label for="remember">Remember me</label>
        </div>
        <button type="submit" class="btn btn-full">Login</button>
    </form>
</div>
HTML;
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
