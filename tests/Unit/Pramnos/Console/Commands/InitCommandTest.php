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

    /**
     * Setup a unique temporary directory for each test.
     */
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pramnos_init_test_internal_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    /**
     * Clean up the temporary directory after each test.
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
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
        $commandTester = new CommandTester($command);

        // Simulate interactive inputs
        $commandTester->setInputs([
            'Test App',     // App Name
            'TestNamespace', // Namespace
            'n',            // Setup Docker? (n)
            '0',            // DB Type (mysql)
            'localhost',    // Host
            'testdb',       // DB Name
            'root',         // User
            '',             // Pass
            ''              // Prefix
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
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'Docker App',
            'DockerApp',
            'y',            // Setup Docker (y)
            '8081',         // Port
            '1',            // Redis
            '1',            // PostgreSQL
            'localhost',
            'dockerdb',
            'user',
            'pass',
            ''              // Prefix
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
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'Minimal App',
            'MinApp',
            'n',            // No Docker
            '0',            // MySQL
            'localhost',
            'mindb',
            'root',
            '',
            ''              // Prefix
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
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'PG Mem App',
            'PGMemApp',
            'y',            // Setup Docker
            '8085',         // Port
            '2',            // Memcached
            '1',            // PostgreSQL
            'localhost',
            'pgmemdb',
            'pguser',
            'pgpass',
            'pg_'           // Prefix
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
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'No Cache App',
            'NoCacheApp',
            'y',            // Setup Docker
            '8086',         // Port
            '0',            // No Cache
            '0',            // MySQL
            'localhost',
            'nocachedb',
            'root',
            '',
            ''              // Prefix
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
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            '',             // App Name (ENTER -> my-auto-app)
            '',             // Namespace (ENTER -> MyAutoApp)
            'n',            // Setup Docker (n)
            '',             // DB Type (ENTER -> now TimescaleDB/postgresql)
            'localhost',    // Host
            '',             // DB Name (ENTER -> my_auto_app_db)
            '',             // DB User (ENTER -> my_auto_app_user)
            'mypass',       // Pass
            ''              // Prefix
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
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'Timescale App',
            'TimescaleApp',
            'y',            // Setup Docker (y)
            '8088',         // Port
            '1',            // Redis (Index 1 in ['none', 'redis', 'memcached'])
            '2',            // TimescaleDB (Index 2 in ['mysql', 'postgresql', 'timescaledb'])
            'localhost',
            'timescaledb',
            'user',
            '',             // Empty password (should use random default)
            ''              // Prefix
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
        $commandTester = new CommandTester($command);

        $commandTester->setInputs([
            'Theme App',
            'ThemeApp',
            'n',            // No Docker
            '0',            // MySQL
            'localhost',
            'themedb',
            'root',
            '',
            ''              // Prefix
        ]);

        $commandTester->execute([]);

        $themeDir = $this->tempDir . '/app/themes/default';
        $this->assertDirectoryExists($themeDir);
        $this->assertFileExists($themeDir . '/theme.html.php');
        $this->assertFileExists($themeDir . '/header.php');
        $this->assertFileExists($themeDir . '/footer.php');
        $this->assertFileExists($themeDir . '/style.css');

        $themeHtml = file_get_contents($themeDir . '/theme.html.php');
        $this->assertStringContainsString('[MODULE]', $themeHtml);
        $this->assertStringContainsString('get_Header()', $themeHtml);
        $this->assertStringContainsString('get_Footer()', $themeHtml);

        $header = file_get_contents($themeDir . '/header.php');
        $this->assertStringContainsString('<title><?php echo $appName; ?></title>', $header);
        $this->assertStringContainsString('fonts.googleapis.com', $header);

        $style = file_get_contents($themeDir . '/style.css');
        $this->assertStringContainsString(':root', $style);
        $this->assertStringContainsString('--primary-color', $style);
    }

    /**
     * Test the internal port availability check.
     */
    public function test_is_port_available()
    {
        $command = new Init();
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isPortAvailable');
        $method->setAccessible(true);

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
