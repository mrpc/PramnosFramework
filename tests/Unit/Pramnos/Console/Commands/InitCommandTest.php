<?php
namespace Tests\Unit\Pramnos\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class InitCommandTest extends TestCase
{
    private $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/pramnos_init_test_internal_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

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
            '0',            // DB Type (mysql)
            'localhost',    // Host
            'testdb',       // DB Name
            'root',         // User
            '',             // Pass
            '',             // Prefix
            'n'             // Setup Docker?
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
            '1',            // PostgreSQL
            'localhost',
            'dockerdb',
            'user',
            'pass',
            '',
            'y',            // Setup Docker
            '8081',         // Port
            '1'             // Redis
        ]);

        $commandTester->execute([]);

        $this->assertFileExists($this->tempDir . '/docker-compose.yml');
        $this->assertFileExists($this->tempDir . '/Dockerfile');
        $this->assertFileExists($this->tempDir . '/dockerbash');
        
        $composeContent = file_get_contents($this->tempDir . '/docker-compose.yml');
        $this->assertStringContainsString('image: postgres:latest', $composeContent);
        $this->assertStringContainsString('image: redis:latest', $composeContent);
    }

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
            '0',            // MySQL
            'localhost',
            'mindb',
            'root',
            '',
            '',
            'n'             // No Docker
        ]);

        $commandTester->execute([]);

        $this->assertFileExists($this->tempDir . '/app/app.php');
        $this->assertFileDoesNotExist($this->tempDir . '/docker-compose.yml');
        $this->assertFileExists($this->tempDir . '/phpunit.xml');
    }

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
            '1',            // PostgreSQL
            'localhost',
            'pgmemdb',
            'pguser',
            'pgpass',
            'pg_',
            'y',            // Setup Docker
            '8085',         // Port
            '2'             // Memcached
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
            '0',            // MySQL
            'localhost',
            'nocachedb',
            'root',
            '',
            '',
            'y',            // Setup Docker
            '8086',         // Port
            '0'             // No Cache
        ]);

        $commandTester->execute([]);

        $composeContent = file_get_contents($this->tempDir . '/docker-compose.yml');
        $this->assertStringNotContainsString('redis', $composeContent);
        $this->assertStringNotContainsString('memcached', $composeContent);
        $this->assertFileExists($this->tempDir . '/phpunit.xml');
    }

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
            '0',            // DB Type (mysql)
            'localhost',    // Host
            '',             // DB Name (ENTER -> my_auto_app_db)
            '',             // DB User (ENTER -> my_auto_app_user)
            'mypass',       // Pass
            ''              // Prefix
        ]);

        $commandTester->execute([]);

        $settings = include($specificDir . '/app/config/settings.php');
        $this->assertEquals('my_auto_app_db', $settings['database']['database']);
        $this->assertEquals('my_auto_app_user', $settings['database']['user']);
        
        $appConfig = include($specificDir . '/app/app.php');
        $this->assertEquals('MyAutoApp', $appConfig['namespace']);
        
        $homeContent = file_get_contents($specificDir . '/src/Views/home/home.html.php');
        $this->assertStringContainsString('Welcome to my-auto-app', $homeContent);
    }

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
            '2',            // TimescaleDB (Index 2 in ['mysql', 'postgresql', 'timescaledb'])
            'localhost',
            'timescaledb',
            'user',
            '',             // Empty password (should use random default)
            '',
            'y',            // Setup Docker
            '8088',         // Port
            '1'             // Redis (Index 1 in ['none', 'redis', 'memcached'])
        ]);

        $commandTester->execute([]);

        $composeContent = file_get_contents($this->tempDir . '/docker-compose.yml');
        $this->assertStringContainsString('image: timescale/timescaledb:latest-pg17', $composeContent);
        $this->assertStringContainsString('image: redis:latest', $composeContent);
        
        $dockerfileContent = file_get_contents($this->tempDir . '/Dockerfile');
        $this->assertStringContainsString('pdo_pgsql pgsql', $dockerfileContent);
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
