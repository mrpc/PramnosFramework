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
            'n',               // Step 2: Enable auth?
            'n',               // Step 2: Enable authserver?
            'n',               // Step 2: Enable queue?
            'n',               // Step 2: Enable messaging?
            'n',               // Step 2: Enable devpanel?
            'n',               // Step 2b: REST API?
            'n',               // Step 2c: webhook?
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

        $commandTester->execute([]);

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
            'n',                 // Step 2: auth
            'n',                 // Step 2: authserver
            'n',                 // Step 2: queue
            'n',                 // Step 2: messaging
            'n',                 // Step 2: devpanel
            'n',                 // Step 2b: REST API?
            'n',                 // Step 2c: webhook?
            '',                  // Step 3: UI (plain-css)
            'n',                 // Step 4: libraries
            'y',                 // Setup Docker (y)
            '8081',              // Port
            '1',                 // Redis
            '1',                 // PostgreSQL
            'localhost',
            'dockerdb',
            'user',
            'pass',
            '',                  // Prefix
            'Docker Author',
            'docker@example.com'
        ]);

        $commandTester->execute([]);

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
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
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

        $commandTester->execute([]);

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
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
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

        $commandTester->execute([]);

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
        $composer = json_decode(file_get_contents($this->tempDir . '/composer.json'), true);
        $this->assertEquals('app/pg-mem-app', $composer['name']);
        $this->assertEquals('PGMemApp\\', array_key_first($composer['autoload']['psr-4']));
        $this->assertArrayNotHasKey('post-create-project-cmd', $composer['scripts'] ?? []);
        
        // Verify Authors
        $this->assertEquals('Complex Author', $composer['authors'][0]['name']);
        $this->assertEquals('complex@example.com', $composer['authors'][0]['email']);
        
        // Verify PHPUnit requirement
        $this->assertArrayHasKey('phpunit/phpunit', $composer['require-dev']);
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
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
            '',             // Step 3: UI (plain-css)
            'n',            // Step 4: libraries
            'y',            // Setup Docker
            '8086',         // Port
            '0',            // No Cache
            '0',            // MySQL
            'localhost',
            'nocachedb',
            'root',
            '',
            '',             // Prefix
            'No Cache Author',
            'nocache@example.com'
        ]);

        $commandTester->execute([]);

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
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
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

        $commandTester->execute([]);

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
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
            '',             // Step 3: UI (plain-css)
            'n',            // Step 4: libraries
            'y',            // Setup Docker (y)
            '8088',         // Port
            '1',            // Redis
            '2',            // TimescaleDB
            'localhost',
            'timescaledb',
            'user',
            '',             // Empty password
            '',             // Prefix
            'Timescale Author',
            'timescale@example.com'
        ]);

        $commandTester->execute([]);

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
            'n',            // Step 2: auth
            'n',            // Step 2: authserver
            'n',            // Step 2: queue
            'n',            // Step 2: messaging
            'n',            // Step 2: devpanel
            'n',            // Step 2b: REST API?
            'n',            // Step 2c: webhook?
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

        $commandTester->execute([]);

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
            'n', 'n', 'n', 'n', 'n',   // features
            'n',                   // REST API?
            'n',                   // webhook?
            '',                    // UI plain-css
            'n',                   // no libraries
            'y', '8090', '0',      // Docker, port, no cache
            '0',                   // MySQL
            'localhost', 'clidb', 'root', '', '',
            'Author', 'author@example.com',
        ]);

        // Act
        $commandTester->execute([]);

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
        $this->assertStringContainsString('docker-compose exec app php mycliapp.php', $wrapper);

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
            '--namespace'      => 'LibApp',
            '--docker'         => 'n',
            '--db-type'        => 'mysql',
            '--features'       => '',
            '--ui-system'      => 'plain-css',
            '--libraries'      => 'jquery,datatables',
            '--no-download'    => true,
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
            'n', 'n', 'n', 'n', 'n',
            'n',            // REST API?
            'n',            // webhook?
            '', 'n',
            'y', '8091', '0', '0',
            'localhost', 'migratedb', 'root', '', '',
            'Author', 'author@example.com',
        ]);

        // Act
        $commandTester->execute([]);
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
            'n', 'n', 'n', 'n', 'n',
            'n',            // REST API?
            'n',            // webhook?
            '', 'n',
            'n', '0',
            'localhost', 'cdndb', 'root', '', '',
            'Author', 'author@example.com',
        ]);

        // Act
        $commandTester->execute([]);

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
            '--namespace' => 'CliNamespace',
            '--docker' => 'n',
            '--db-type' => 'postgresql',
            '--db-name' => 'cli_db',
            '--no-interaction' => true
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());

        // Verify CliApp and CliNamespace
        $settingsContent = file_get_contents($this->tempDir . '/app/config/settings.php');
        $this->assertStringContainsString("'type' => 'postgresql'", $settingsContent);
        $this->assertStringContainsString("'database' => 'cli_db'", $settingsContent);

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
