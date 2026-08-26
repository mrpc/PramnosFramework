<?php
namespace Tests\Unit\Pramnos\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the Init command.
 * 
 * Verifies that the framework can be correctly scaffolded with various 
 * configurations (Docker, Databases, Cache systems, etc.).
 */
class InitCommandTest extends TestCase
{
    private $tempDir;

    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    /**
     * Setup a unique temporary directory for each test.
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pramnos_init_test_internal_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    /**
     * Clean up the temporary directory after each test.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    /**
     * Test the basic project structure scaffolding (Interactive mode, No Docker).
     */
    public function test_it_scaffolds_project_structure()
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        // Redirection to temp directory
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        // Simulate interactive inputs
        $commandTester->setInputs([
            'Test App',        // App Name
            'TestNamespace',   // Namespace
            '',             // Step 1b: application style (ENTER -> mvc)
            'n',               // Step 2: Enable auth?
            'n',               // Step 2: Enable authserver?
            'n',               // Step 2: Enable queue?
            'n',               // Step 2: Enable messaging?
            'n',               // Step 2: Enable devpanel?
            'n',               // Step 2b: REST API?
            'n',               // Step 2c: webhook?
            'n',               // Step 2e: service worker? (default N)
            '',                // Step 3: UI system (Enter = plain-css default)
            'n',               // Step 4: Configure libraries?
            'n',               // Setup Docker? (n)
            '0',               // DB Type (mysql)
            'localhost',       // Host
            'testdb',          // DB Name
            'root',            // User
            '',                // Pass
            '',                // Prefix
            'Test Author',     // Author Name
            'test@example.com' // Author Email
        ]);

        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Project initialized successfully', $output);

        // Verify directories
        $this->assertDirectoryExists($this->tempDir . '/www');
        $this->assertDirectoryExists($this->tempDir . '/app/config');

        // Verify files
        $this->assertFileExists($this->tempDir . '/app/app.php');
        $this->assertFileExists($this->tempDir . '/www/index.php');
    }

    /**
     * Test scaffolding with Docker environment and PostgreSQL database.
     */
    public function test_it_scaffolds_docker_and_postgres()
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'Docker App',
            'DockerApp',
            '',             // Step 1b: application style (ENTER -> mvc)
            'n',                 // Step 2: auth
            'n',                 // Step 2: authserver
            'n',                 // Step 2: queue
            'n',                 // Step 2: messaging
            'n',                 // Step 2: devpanel
            'n',                 // Step 2b: REST API?
            'n',                 // Step 2c: webhook?
            'n',                 // Step 2e: service worker? (default N)
            '',                  // Step 3: UI (plain-css)
            'n',                 // Step 4: libraries
            'y',                 // Setup Docker (y)
            '8081',              // Port
            '0',                 // Redis (index 0 in ['redis','none','memcached'])
            '1',                 // PostgreSQL
            'localhost',
            'dockerdb',
            'user',
            'pass',
            '',                  // Prefix
            'Docker Author',
            'docker@example.com'
        ]);

        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        $this->assertFileExists($this->tempDir . '/docker-compose.yml');
        $this->assertFileExists($this->tempDir . '/Dockerfile');
        $this->assertFileExists($this->tempDir . '/dockerbash');
        
        $composeContent = file_get_contents($this->tempDir . '/docker-compose.yml');
        $this->assertStringContainsString('image: postgres:latest', $composeContent);
        $this->assertStringContainsString('image: redis:latest', $composeContent);
    }

    /**
     * Test scaffolding of a minimalist project (No Docker, MySQL).
     */
    public function test_it_scaffolds_minimalist_project()
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'Minimal App',
            'MinApp',
            '',             // Step 1b: application style (ENTER -> mvc)
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
            'n',            // Step 2e: service worker? (default N)
            '',             // Step 3: UI (plain-css)
            'n',            // Step 4: libraries
            'n',            // No Docker
            '0',            // MySQL
            'localhost',
            'mindb',
            'root',
            '',
            '',             // Prefix
            'Minimal Author',
            'min@example.com'
        ]);

        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        $this->assertFileExists($this->tempDir . '/app/app.php');
        $this->assertFileDoesNotExist($this->tempDir . '/docker-compose.yml');
        $this->assertFileExists($this->tempDir . '/phpunit.xml');
    }

    /**
     * Test complex configuration: PostgreSQL, Memcached, and Docker.
     * Also verifies that composer.json is correctly updated.
     */
    public function test_it_handles_postgres_memcached_docker()
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'PG Mem App',
            'PGMemApp',
            '',             // Step 1b: application style (ENTER -> mvc)
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
            'n',            // Step 2e: service worker? (default N)
            '',             // Step 3: UI (plain-css)
            'n',            // Step 4: libraries
            'y',            // Setup Docker
            '8085',         // Port
            '2',            // Memcached
            '1',            // PostgreSQL
            'localhost',
            'pgmemdb',
            'pguser',
            'pgpass',
            'pg_',          // Prefix
            'Complex Author',
            'complex@example.com'
        ]);

        // Pre-create a dummy composer.json to test modification
        file_put_contents($this->tempDir . '/composer.json', json_encode([
            'name' => 'mrpc/pramnos-application',
            'autoload' => ['psr-4' => ['PramnosSkeleton\\' => 'src/']],
            'scripts' => ['post-create-project-cmd' => ['@php vendor/bin/pramnos init']]
        ]));

        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        $composeContent = file_get_contents($this->tempDir . '/docker-compose.yml');
        $this->assertStringContainsString('image: postgres:latest', $composeContent);
        $this->assertStringContainsString('image: memcached:latest', $composeContent);
        $this->assertStringContainsString('8085:80', $composeContent);
        $this->assertStringContainsString('container_name: pgmemapp_php', $composeContent);
        $this->assertStringContainsString('container_name: pgmemapp_db', $composeContent);
        $this->assertStringContainsString('- .:/var/www/html', $composeContent);
        
        $settings = include($this->tempDir . '/app/config/settings.php');
        $this->assertEquals('postgresql', $settings['database']['type']);
        $this->assertEquals('pg_', $settings['database']['prefix']);

        // Verify .htaccess placement and content
        $this->assertFileExists($this->tempDir . '/www/.htaccess');
        $this->assertStringContainsString('RewriteRule ^$ index.php [L]', file_get_contents($this->tempDir . '/www/.htaccess'));

        // Verify Dockerfile DocumentRoot fix
        $dockerfileContent = file_get_contents($this->tempDir . '/Dockerfile');
        $this->assertStringContainsString('AllowOverride All', $dockerfileContent);
        $this->assertStringContainsString('ENV APACHE_DOCUMENT_ROOT /var/www/html/www', $dockerfileContent);

        // Verify composer.json was updated
        $composerRaw = file_get_contents($this->tempDir . '/composer.json');
        $composer = json_decode($composerRaw, true);
        $this->assertEquals('app/pg-mem-app', $composer['name']);
        $this->assertEquals('PGMemApp\\', array_key_first($composer['autoload']['psr-4']));
        $this->assertArrayNotHasKey('post-create-project-cmd', $composer['scripts'] ?? []);
        // Regression: removing the only script must not leave "scripts": [] — an
        // empty JSON array fails Composer's schema (scripts must be an object),
        // which broke `composer install`/`dump-autoload` during docker build.
        $this->assertStringNotContainsString('"scripts": []', $composerRaw);
        if (array_key_exists('scripts', $composer)) {
            $this->assertIsObject(json_decode($composerRaw)->scripts, 'scripts must serialise as a JSON object');
        }
        
        // Verify Authors
        $this->assertEquals('Complex Author', $composer['authors'][0]['name']);
        $this->assertEquals('complex@example.com', $composer['authors'][0]['email']);
        
        // Verify PHPUnit requirement
        $this->assertArrayHasKey('phpunit/phpunit', $composer['require-dev']);

        // The DOM libraries TestResponse's selector assertions need. They are
        // dev dependencies of the framework, and a dependency's dev dependencies
        // are not installed downstream — so without them here, three documented
        // assertions threw a missing-class error in every scaffolded project.
        $this->assertArrayHasKey('symfony/dom-crawler', $composer['require-dev']);
        $this->assertArrayHasKey('symfony/css-selector', $composer['require-dev']);
    }

    /**
     * Test configuration with Docker but no cache system.
     */
    public function test_it_handles_no_cache_no_tests()
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'No Cache App',
            'NoCacheApp',
            '',             // Step 1b: application style (ENTER -> mvc)
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
            'n',            // Step 2e: service worker? (default N)
            '',             // Step 3: UI (plain-css)
            'n',            // Step 4: libraries
            'y',            // Setup Docker
            '8086',         // Port
            '1',            // No Cache (index 1 in ['redis','none','memcached'])
            '0',            // MySQL
            'localhost',
            'nocachedb',
            'root',
            '',
            '',             // Prefix
            'No Cache Author',
            'nocache@example.com'
        ]);

        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        $composeContent = file_get_contents($this->tempDir . '/docker-compose.yml');
        $this->assertStringNotContainsString('redis', $composeContent);
        $this->assertStringNotContainsString('memcached', $composeContent);
        $this->assertFileExists($this->tempDir . '/phpunit.xml');
    }

    /**
     * Test that the command correctly uses automatic defaults when ENTER is pressed.
     */
    public function test_it_uses_automatic_defaults()
    {
        // Set up a specific directory with a known name to test defaults
        $specificDir = $this->tempDir . '/my-auto-app';
        mkdir($specificDir, 0777, true);

        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $specificDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            '',             // App Name (ENTER -> my-auto-app)
            '',             // Namespace (ENTER -> MyAutoApp)
            '',             // Step 1b: application style (ENTER -> mvc)
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
            'n',            // Step 2e: service worker? (default N)
            '',             // Step 3: UI (plain-css)
            'n',            // Step 4: libraries
            'n',            // Setup Docker (n)
            '',             // DB Type (ENTER -> TimescaleDB default)
            'localhost',    // Host
            '',             // DB Name (ENTER -> my_auto_app_db)
            '',             // DB User (ENTER -> my_auto_app_user)
            'mypass',       // Pass
            '',             // Prefix
            '',             // Author Name (ENTER)
            ''              // Author Email (ENTER)
        ]);

        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        $settings = include($specificDir . '/app/config/settings.php');
        $this->assertEquals('postgresql', $settings['database']['type']);
        $this->assertTrue($settings['database']['timescale']);
        $this->assertEquals('my_auto_app_db', $settings['database']['database']);
        $this->assertEquals('my_auto_app_user', $settings['database']['user']);
        
        $appConfig = include($specificDir . '/app/app.php');
        $this->assertEquals('MyAutoApp', $appConfig['namespace']);
        
        $homeContent = file_get_contents($specificDir . '/src/Views/home/home.html.php');
        $this->assertStringContainsString('Welcome to my-auto-app', $homeContent);

        // Verify Application Name in app/app.php
        $appConfig = include($specificDir . '/app/app.php');
        $this->assertEquals('my-auto-app', $appConfig['name']);

        // Verify Language Scaffolding
        $this->assertFileExists($specificDir . '/app/language/en.php');
        $langConfig = include($specificDir . '/app/language/en.php');
        $this->assertEquals('UTF-8', $langConfig['CHARSET']);
    }

    /**
     * Test scaffolding with TimescaleDB and Redis.
     */
    public function test_it_scaffolds_timescaledb_and_redis()
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'Timescale App',
            'TimescaleApp',
            '',             // Step 1b: application style (ENTER -> mvc)
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
            'n',            // Step 2e: service worker? (default N)
            '',             // Step 3: UI (plain-css)
            'n',            // Step 4: libraries
            'y',            // Setup Docker (y)
            '8088',         // Port
            '0',            // Redis (index 0 in ['redis','none','memcached'])
            '2',            // TimescaleDB
            'localhost',
            'timescaledb',
            'user',
            '',             // Empty password
            '',             // Prefix
            'Timescale Author',
            'timescale@example.com'
        ]);

        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        $composeContent = file_get_contents($this->tempDir . '/docker-compose.yml');
        $this->assertStringContainsString('image: timescale/timescaledb:latest-pg17', $composeContent);
        $this->assertStringContainsString('image: redis:latest', $composeContent);

        $dockerfileContent = file_get_contents($this->tempDir . '/Dockerfile');
        $this->assertStringContainsString('pdo_pgsql pgsql', $dockerfileContent);
    }

    /**
     * Test that the default theme is correctly scaffolded.
     */
    public function test_it_scaffolds_default_theme()
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'Theme App',
            'ThemeApp',
            '',             // Step 1b: application style (ENTER -> mvc)
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
            'n',            // Step 2e: service worker? (default N)
            '',             // Step 3: UI (plain-css)
            'n',            // Step 4: libraries
            'n',            // No Docker
            '0',            // MySQL
            'localhost',
            'themedb',
            'root',
            '',
            '',             // Prefix
            'Theme Author',
            'theme@example.com'
        ]);

        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        $themeDir = $this->tempDir . '/app/themes/default';
        $this->assertDirectoryExists($themeDir);
        $this->assertFileExists($themeDir . '/theme.html.php');
        $this->assertFileExists($themeDir . '/header.php');
        $this->assertFileExists($themeDir . '/footer.php');
        $this->assertFileExists($this->tempDir . '/www/assets/css/style.css');

        $themeHtml = file_get_contents($themeDir . '/theme.html.php');
        $this->assertStringContainsString('[MODULE]', $themeHtml);
        $this->assertStringContainsString('get_Header()', $themeHtml);
        $this->assertStringContainsString('get_Footer()', $themeHtml);

        $header = file_get_contents($themeDir . '/header.php');
        $this->assertStringContainsString('applicationInfo[\'name\']', $header);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $header);
        // No CDN references — all assets must be served locally
        $this->assertStringNotContainsString('fonts.googleapis.com', $header);

        $style = file_get_contents($this->tempDir . '/www/assets/css/style.css');
        $this->assertStringContainsString(':root', $style);
        $this->assertStringContainsString('--primary-color', $style);
    }

    /**
     * Test that the CLI entry-point files are scaffolded when Docker is enabled.
     *
     * The init command must produce:
     *  - {cliName}.php  — PHP entry point that defines ROOT and runs the app Console
     *  - {cliName}      — bash wrapper calling docker-compose exec app php {cliName}.php
     *  - src/Console.php — app Console class extending Pramnos\Console\Application
     *
     * Without these files the app has no CLI interface (migrate, queue, etc. are unusable).
     */
    public function test_it_scaffolds_app_cli_files(): void
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        // Arrange — namespace 'MyCLIApp' → cliName 'mycliapp'
        $commandTester->setInputs([
            'My CLI App', 'MyCLIApp',
            '',             // Step 1b: application style (ENTER -> mvc)
            'n', 'n', 'n', 'n', 'n',   // features
            'n',                   // REST API?
            'n',                   // webhook?
            'n',                   // Step 2e: service worker? (default N)
            '',                    // UI plain-css
            'n',                   // no libraries
            'y', '8090', '0',      // Docker, port, no cache
            '0',                   // MySQL
            'localhost', 'clidb', 'root', '', '',
            'Author', 'author@example.com',
        ]);

        // Act
        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        // Assert — PHP CLI entry point
        $this->assertFileExists($this->tempDir . '/mycliapp.php');
        $cliEntry = file_get_contents($this->tempDir . '/mycliapp.php');
        $this->assertStringContainsString("define('ROOT'", $cliEntry);
        $this->assertStringContainsString('MyCLIApp\\Console', $cliEntry);
        // init() must be called so migrate and other commands have a DB connection
        $this->assertStringContainsString('internalApplication->init(', $cliEntry);
        $this->assertStringContainsString("app/config/settings.php", $cliEntry);

        // Assert — bash wrapper delegates to docker-compose exec
        $this->assertFileExists($this->tempDir . '/mycliapp');
        $wrapper = file_get_contents($this->tempDir . '/mycliapp');
        // -u www-data: the image maps that user to the host user, so files the
        // command writes (migration logs, caches) stay owned by the developer.
        $this->assertStringContainsString('docker-compose exec -u www-data app php mycliapp.php', $wrapper);

        // Assert — app Console class extending the framework
        $this->assertFileExists($this->tempDir . '/src/Console.php');
        $console = file_get_contents($this->tempDir . '/src/Console.php');
        $this->assertStringContainsString('class Console extends \\Pramnos\\Console\\Application', $console);
        $this->assertStringContainsString('registerCommands', $console);
    }

    /**
     * Test that the generated Application::init() signature matches the parent.
     *
     * The parent declares init($settingsFile = '').  PHP 8 raises a fatal error
     * ("Declaration must be compatible") when a child overrides a method with an
     * incompatible signature.  This was the first regression seen in production:
     *   Fatal error: Declaration of TestApp\Application::init() must be compatible
     *   with Pramnos\Application\Application::init($settingsFile = '')
     */
    public function test_application_php_has_correct_init_signature(): void
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        // Arrange
        $commandTester->execute([
            '--app-name'       => 'Sig App',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'      => 'SigApp',
            '--docker'         => 'n',
            '--db-type'        => 'mysql',
            '--features'       => '',
            '--ui-system'      => 'plain-css',
            '--libraries'      => '',
            '--no-interaction' => true,
        ]);

        // Act
        $this->assertFileExists($this->tempDir . '/src/Application.php');
        $content = file_get_contents($this->tempDir . '/src/Application.php');

        // Assert — must match parent signature exactly
        $this->assertStringContainsString("public function init(\$settingsFile = '')", $content,
            'init() must declare $settingsFile = \'\' to match parent signature');
        $this->assertStringContainsString("parent::init(\$settingsFile)", $content);
        $this->assertStringContainsString('registerVendorLibraries', $content);
    }

    /**
     * Test that selected vendor libraries are registered via registerScript/registerStyle
     * with local vendor paths — never CDN URLs.
     *
     * Libraries must be registered-but-not-enqueued: controllers decide what each
     * page needs by calling addScript('jquery') / addStyle('datatables') etc.
     */
    public function test_application_php_registers_vendor_libraries(): void
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        // Arrange — select jquery + datatables (datatables depends on jquery)
        $commandTester->execute([
            '--app-name'       => 'Lib App',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace'      => 'LibApp',
            '--docker'         => 'n',
            '--db-type'        => 'mysql',
            '--features'       => '',
            '--ui-system'      => 'plain-css',
            '--libraries'      => 'jquery,datatables',
            '--no-interaction' => true,
        ]);

        // Act
        $this->assertFileExists($this->tempDir . '/src/Application.php');
        $content = file_get_contents($this->tempDir . '/src/Application.php');

        // Assert — jquery JS registered with local path
        $this->assertStringContainsString("registerScript('jquery'", $content);
        $this->assertStringContainsString("assets/vendor/jquery/", $content);

        // Assert — datatables JS + CSS registered
        $this->assertStringContainsString("registerScript('datatables'", $content);
        $this->assertStringContainsString("registerStyle('datatables'", $content);
        $this->assertStringContainsString("assets/vendor/datatables/", $content);

        // Assert — selecting DataTables auto-pulls the Pramnos REST adapter, and
        // the bundled pramnos-adapters library is registered under per-file
        // handles (not collapsed onto one) so pramnos-datatable.js survives. This
        // is the handle the scaffolded CRUD controller enqueues.
        $this->assertStringContainsString("registerScript('pramnos-datatable'", $content,
            'DataTables selection must register the pramnos-datatable adapter handle');
        $this->assertStringContainsString("registerScript('pramnos-gridjs'", $content,
            'the second bundled adapter file must keep its own handle');
        $this->assertStringContainsString("assets/vendor/pramnos/", $content);

        // Assert — no CDN references; runtime must not reach out to external hosts
        foreach (['cdn.', 'jsdelivr', 'cdnjs', 'unpkg'] as $cdn) {
            $this->assertStringNotContainsString($cdn, $content,
                "Application.php must not reference CDN ($cdn found)");
        }
    }

    /**
     * Test that the post-init summary shows the correct migrate command.
     *
     * The Symfony Console application registers the command as 'migrate' with a
     * --scope option.  'migrate:framework' does not exist and causes:
     *   Command "migrate:framework" is not defined.
     */
    public function test_summary_shows_correct_migrate_command(): void
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true; // Docker enabled but skipped → shows manual step
        $commandTester = new CommandTester($command);

        // Arrange — Docker enabled so the migrate fallback line appears in summary
        $commandTester->setInputs([
            'Migrate App', 'MigrateApp',
            '',             // Step 1b: application style (ENTER -> mvc)
            'n', 'n', 'n', 'n', 'n',
            'n',            // REST API?
            'n',            // webhook?
            'n',            // Step 2e: service worker? (default N)
            '', 'n',
            'y', '8091', '0', '0',
            'localhost', 'migratedb', 'root', '', '',
            'Author', 'author@example.com',
        ]);

        // Act
        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);
        $output = $commandTester->getDisplay();

        // Assert — correct command name in summary
        $this->assertStringContainsString('migrate --scope=framework', $output);
        $this->assertStringNotContainsString('migrate:framework', $output);
    }

    /**
     * Test that theme header.php and footer.php contain no CDN references.
     *
     * Every library is downloaded locally during init and served from
     * www/assets/vendor/.  CDN references at runtime are a security risk
     * (supply-chain compromise) and break air-gapped deployments.
     */
    public function test_theme_files_have_no_cdn_references(): void
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        // Arrange
        $commandTester->setInputs([
            'CDN Test App', 'CDNTestApp',
            '',             // Step 1b: application style (ENTER -> mvc)
            'n', 'n', 'n', 'n', 'n',
            'n',            // REST API?
            'n',            // webhook?
            'n',            // Step 2e: service worker? (default N)
            '', 'n',
            'n', '0',
            'localhost', 'cdndb', 'root', '', '',
            'Author', 'author@example.com',
        ]);

        // Act
        // The prompt sequence is the subject here, not composer: --no-install is a
        // flag, so it changes nothing about the questions asked or answered.
        $commandTester->execute(['--no-install' => true, '--no-download' => true]);

        // Assert — no CDN references in any theme file
        $themeDir  = $this->tempDir . '/app/themes/default';
        $cdnTokens = ['cdn.', 'jsdelivr', 'cdnjs', 'unpkg.com', 'googleapis', 'gstatic'];

        foreach (['header.php', 'footer.php'] as $file) {
            $content = file_get_contents($themeDir . '/' . $file);
            foreach ($cdnTokens as $token) {
                $this->assertStringNotContainsString($token, $content,
                    "Theme $file must not reference CDN ('$token' found)");
            }
        }
    }

    /**
     * Test the internal port availability check.
     */
    public function test_is_port_available()
    {
        $command = new Init();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isPortAvailable');

        // Test with a port that is likely to be boolean (true or false depending on env)
        $result = $method->invoke($command, 8080);
        $this->assertIsBool($result);
        
        // Test with a very unlikely port
        $resultHigh = $method->invoke($command, 55555);
        $this->assertIsBool($resultHigh);
    }

    /**
     * Test that the command correctly handles and prioritizes CLI options.
     * 
     * This test ensures that when options like --app-name are provided, 
     * no interactive questions are asked for those fields.
     */
    public function test_it_accepts_cli_options()
    {
        $application = new Application();
        $application->add(new Init());

        $command = $application->find('init');
        $command->targetBaseDir = $this->tempDir;
        $command->skipDockerRun = true;
        $commandTester = new CommandTester($command);

        // Run with options and NO input sequence
        $commandTester->execute([
            '--app-name' => 'CliApp',
            // Scaffolding is the subject here; installing dependencies and
            // fetching assets over the network are not. See the test suite
            // performance guide: they were 85% of this class's runtime.
            '--no-install'  => true,
            '--no-download' => true,
            '--namespace' => 'CliNamespace',
            '--docker' => 'n',
            '--db-type' => 'postgresql',
            '--db-name' => 'cli_db',
            '--no-interaction' => true
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());

        // Verify CliApp and CliNamespace
        // The settings file reads the environment now; the values it falls back to are
        // the scaffolded ones, and the credentials are not in it at all. Asserted on the
        // shape rather than on a literal because a literal password here is precisely
        // what this stopped writing.
        $settingsContent = file_get_contents($this->tempDir . '/app/config/settings.php');
        $this->assertStringContainsString("envvar('APP_DB_TYPE', 'postgresql')", $settingsContent);
        $this->assertStringContainsString("envvar('APP_DB_NAME', 'cli_db')", $settingsContent);

        $appContent = file_get_contents($this->tempDir . '/app/app.php');
        $this->assertStringContainsString("'namespace' => 'CliNamespace'", $appContent);

        $viewContent = file_get_contents($this->tempDir . '/src/Views/home/home.html.php');
        $this->assertStringContainsString('Welcome to CliApp', $viewContent);
    }

    /**
     * Helper method to recursively remove a directory.
     * 
     * @param string $path
     * @return bool
     */
    private function removeDirectory($path)
    {
        if (!is_dir($path)) return;
        $files = array_diff(scandir($path), array('.', '..'));
        foreach ($files as $file) {
            (is_dir("$path/$file")) ? $this->removeDirectory("$path/$file") : unlink("$path/$file");
        }
        return rmdir($path);
    }
}
