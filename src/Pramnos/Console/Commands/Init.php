<?php
namespace Pramnos\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Initialize a new Pramnos Application project.
 * 
 * This command scaffolds the entire directory structure and necessary boilerplate
 * files to start a new application. It supports optional Docker environment
 * setup and Unit Testing infrastructure.
 */
class Init extends Command
{
    /**
     * Target directory for scaffolding.
     * @var string
     */
    public $targetBaseDir;

    /**
     * Configure the command metadata.
     */
    protected function configure()
    {
        $this->setName('init');
        $this->setDescription('Initialize a new Pramnos project structure');
    }

    /**
     * Execute the command logic.
     * 
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (empty($this->targetBaseDir)) {
            $this->targetBaseDir = defined('ROOT') ? ROOT : getcwd();
        }

        $helper = $this->getHelper('question');

        $output->writeln([
            '',
            ' <info>╔══════════════════════════════════════════════╗</info>',
            ' <info>║       Pramnos Framework Initialization       ║</info>',
            ' <info>╚══════════════════════════════════════════════╝</info>',
            '',
        ]);

        // 1. Basic Metadata
        $defaultAppName = basename($this->targetBaseDir);
        $appName = $helper->ask($input, $output, new Question("Application Name [$defaultAppName]: ", $defaultAppName));
        
        // Default Namespace: CamelCase of app name
        $defaultNamespace = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $appName)));
        $namespace = $helper->ask($input, $output, new Question("Namespace [$defaultNamespace]: ", $defaultNamespace));

        // 2. Database Config
        $randomPass = bin2hex(random_bytes(10));
        $dbType = $helper->ask($input, $output, new ChoiceQuestion('Database Type: ', ['mysql', 'postgresql', 'timescaledb'], 3));
        $dbHost = $helper->ask($input, $output, new Question('Database Host [localhost]: ', 'localhost'));
        
        $dbSuffix = strtolower(str_replace(['-', ' '], '_', $appName));
        $dbNameDefault = $dbSuffix . '_db';
        $dbUserDefault = $dbSuffix . '_user';
        
        $dbName = $helper->ask($input, $output, new Question("Database Name [$dbNameDefault]: ", $dbNameDefault));
        $dbUser = $helper->ask($input, $output, new Question("Database User [$dbUserDefault]: ", $dbUserDefault));
        $dbPass = $helper->ask($input, $output, new Question("Database Password [$randomPass]: ", $randomPass));
        $dbPrefix = $helper->ask($input, $output, new Question('Database Table Prefix [optional]: ', ''));
 
        // 3. Docker setup
        $useDocker = $helper->ask($input, $output, new ConfirmationQuestion('Setup Docker environment? [y/N] ', true));
        $dockerPort = 8080;
        $cacheSystem = 'none';
 
        if ($useDocker) {
            $dockerPort = $helper->ask($input, $output, new Question('Local mapping port [8080]: ', 8080));
            $cacheSystem = $helper->ask($input, $output, new ChoiceQuestion('Cache System: ', ['none', 'redis', 'memcached'], 2));
        }

        // 4. Tests setup - Always Y as requested
        $useTests = true;

        $output->writeln("\n<info>Scaffolding project structure...</info>");

        // --- Scaffold Directories ---
        $this->mkdir('www');
        $this->mkdir('src/Controllers');
        $this->mkdir('src/Models');
        $this->mkdir('src/Views/home');
        $this->mkdir('app/config');
        $this->mkdir('app/Migrations');
        $this->mkdir('app/themes/default');
        $this->mkdir('var/cache');
        $this->mkdir('var/logs');

        // --- Scaffold Files ---
        
        // app/config/settings.php
        $this->scaffoldSettings('app/config/settings.php', $dbType, $dbHost, $dbName, $dbUser, $dbPass, $dbPrefix, true);
        
        // app/app.php
        $this->writeFile('app/app.php', "<?php\nreturn [\n    'namespace' => '$namespace',\n    'theme' => 'default',\n    'csp' => [\n        'script-src' => [],\n        'style-src' => []\n    ]\n];\n");

        // www/index.php
        $this->writeFile('www/index.php', $this->getIndexTemplate());

        // .htaccess
        $this->writeFile('.htaccess', "RewriteEngine On\nRewriteRule ^$ www/index.php [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^(.*)$ www/index.php?url=$1 [QSA,L]\n");

        // src/Application.php
        $this->writeFile('src/Application.php', "<?php\nnamespace $namespace;\n\nclass Application extends \\Pramnos\\Application\\Application\n{\n}\n");

        // src/Controllers/HomeController.php
        $this->writeFile('src/Controllers/HomeController.php', "<?php\nnamespace $namespace\\Controllers;\n\nuse Pramnos\\Application\\Controller;\n\nclass Home extends Controller\n{\n    public function display()\n    {\n        return \$this->render('home/home.html');\n    }\n}\n");

        // src/Views/home/home.html.php
        $this->writeFile('src/Views/home/home.html.php', "<h1>Welcome to $appName</h1>\n<p>Your Pramnos project is ready.</p>\n");

        // --- Docker ---
        if ($useDocker) {
            $this->scaffoldDocker($namespace, $dockerPort, $dbType, $dbName, $dbUser, $dbPass, $cacheSystem);
        }

        // --- Tests ---
        if ($useTests) {
            $this->scaffoldTests($namespace, $dbType, $dbHost, $dbName, $dbUser, $dbPass, $dbPrefix, $useDocker);
        }

        $output->writeln("\n<info>Project initialized successfully!</info>");
        $output->writeln("Next steps:");
        if ($useDocker) {
            $output->writeln(" 1. Run <comment>docker-compose up -d</comment>");
            $output->writeln(" 2. Access your app at <comment>http://localhost:$dockerPort</comment>");
            $output->writeln(" 3. Use <comment>./dockerbash</comment> to enter the container");
        } else {
            $output->writeln(" 1. Configure your web server to point to the <comment>www/</comment> directory.");
            $output->writeln(" 2. Run <comment>php bin/pramnos serve</comment> (if implemented).");
        }

        return 0;
    }

    /**
     * Create a directory if it doesn't exist.
     * 
     * @param string $path Relative path from ROOT
     */
    private function mkdir($path)
    {
        $fullPath = $this->targetBaseDir . '/' . $path;
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0777, true);
        }
    }

    /**
     * Write content to a file in the project.
     * 
     * @param string $path Relative path from targetBaseDir
     * @param string $content File content
     */
    private function writeFile($path, $content)
    {
        file_put_contents($this->targetBaseDir . '/' . $path, $content);
    }

    /**
     * Scaffold the settings.php file.
     * 
     * @param string $path File path
     * @param string $type Database type
     * @param string $host Database host
     * @param string $name Database name
     * @param string $user Database user
     * @param string $pass Database password
     * @param string $prefix Database table prefix
     * @param bool   $dev Enable development mode
     */
    private function scaffoldSettings($path, $type, $host, $name, $user, $pass, $prefix, $dev)
    {
        $content = "<?php\nreturn [\n    'database' => [\n        'type' => '$type',\n        'hostname' => '$host',\n        'database' => '$name',\n        'user' => '$user',\n        'password' => '$pass',\n        'prefix' => '$prefix'\n    ],\n    'development' => " . ($dev ? 'true' : 'false') . ",\n    'forcessl' => false\n];\n";
        $this->writeFile($path, $content);
    }

    /**
     * Get the template for www/index.php.
     * 
     * @return string
     */
    private function getIndexTemplate()
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

    /**
     * Scaffold Docker environment files.
     * 
     * @param string $namespace App namespace used for container names
     * @param int    $port Local port mapping
     * @param string $dbType Database type
     * @param string $dbName Database name
     * @param string $dbUser Database user
     * @param string $dbPass Database password
     * @param string $cacheSystem Cache system (none, redis, memcached)
     */
    private function scaffoldDocker($namespace, $port, $dbType, $dbName, $dbUser, $dbPass, $cacheSystem)
    {
        $image = 'postgres:latest';
        if ($dbType === 'timescaledb') {
            $image = 'timescale/timescaledb:latest-pg17';
        } elseif ($dbType === 'mysql') {
            $image = 'mysql:8.0';
        }
        
        $dbService = ($dbType === 'mysql') ? 'mysql' : 'postgres';
        $isPostgres = ($dbType === 'postgresql' || $dbType === 'timescaledb');
        
        $compose = "services:\n  app:\n    build: .\n    ports:\n      - \"$port:80\"\n    volumes:\n      - .:/var/www/html/" . strtolower($namespace) . "\n    depends_on:\n      - db\n";
        
        if ($cacheSystem !== 'none') {
            $compose .= "      - cache\n";
        }

        $compose .= "  db:\n    image: $image\n    environment:\n";
        if ($isPostgres) {
            $compose .= "      POSTGRES_DB: $dbName\n      POSTGRES_USER: $dbUser\n      POSTGRES_PASSWORD: $dbPass\n";
        } else {
            $compose .= "      MYSQL_DATABASE: $dbName\n      MYSQL_USER: $dbUser\n      MYSQL_PASSWORD: $dbPass\n      MYSQL_ROOT_PASSWORD: $dbPass\n";
        }

        if ($cacheSystem !== 'none') {
            $compose .= "  cache:\n    image: $cacheSystem:latest\n";
        }

        $this->writeFile('docker-compose.yml', $compose);
        
        $phpExts = $isPostgres ? 'pdo_pgsql pgsql' : 'pdo_mysql mysqli';
        $dockerfile = "FROM php:8.4-apache\nRUN apt-get update && apt-get install -y libpq-dev libicu-dev git unzip\nRUN docker-php-ext-install pdo $phpExts intl\nRUN a2enmod rewrite\nWORKDIR /var/www/html/" . strtolower($namespace) . "\nCOPY . .\n";
        $this->writeFile('Dockerfile', $dockerfile);

        $this->writeFile('dockerbash', $this->getDockerBashTemplate());
        chmod($this->targetBaseDir . '/dockerbash', 0755);

        $this->writeFile('dockertest', $this->getDockerTestTemplate($namespace, $port));
        chmod($this->targetBaseDir . '/dockertest', 0755);
    }

    /**
     * Get the template for the dockertest bash script.
     * 
     * @param string $namespace
     * @param int    $port
     * @return string
     */
    private function getDockerTestTemplate($namespace, $port)
    {
        $nsLower = strtolower($namespace);
        return <<<BASH
#!/usr/bin/env bash

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

# Check if containers are running
if ! docker-compose ps | grep -q "app.*Up"; then
    echo "Containers not running. Starting them..."
    docker-compose up -d
    echo "Waiting for services to be ready..."
    sleep 5
fi

# Check if database is initialized by accessing the app (optional but recommended)
echo "Ensuring application is reachable..."
curl -s http://localhost:$port/$nsLower/www/ > /dev/null 2>&1

# Run tests
extra_flags="--display-deprecations --display-warnings --display-notices --display-phpunit-notices"
[[ "\$testdox" == true ]] && extra_flags="\$extra_flags --testdox"

if [[ "\$coverage" == true ]]; then
    # Ensure coverage directory exists
    mkdir -p coverage
    docker-compose exec app vendor/bin/phpunit --coverage-html coverage \$extra_flags "\${passthrough[@]}"
else
    docker-compose exec app vendor/bin/phpunit \$extra_flags "\${passthrough[@]}"
fi

# Open coverage report if generated and not suppressed
if [[ "\$coverage" == true && "\$nobrowser" == false && -f ./coverage/index.html ]]; then
    echo "Opening coverage report..."
    if [[ "\$OSTYPE" == "msys" || "\$OSTYPE" == "cygwin" || "\$OSTYPE" == "linux-gnu"* && "\$(uname -r)" == *"Microsoft"* || -n "\$WSL_DISTRO_NAME" ]]; then
        # Check if we are in WSL
        if command -v wslpath > /dev/null; then
            win_path=\$(wslpath -w "\$(pwd)")
            explorer.exe "\$win_path\coverage\index.html"
        else
            explorer.exe "coverage\index.html"
        fi
    elif [[ "\$OSTYPE" == "linux-gnu"* ]]; then
        if command -v xdg-open > /dev/null; then
            xdg-open ./coverage/index.html
        fi
    elif [[ "\$OSTYPE" == "darwin"* ]]; then
        open ./coverage/index.html
    fi
fi
BASH;
    }

    /**
     * Get the template for the dockerbash script.
     * 
     * @return string
     */
    private function getDockerBashTemplate()
    {
        return <<<BASH
#!/usr/bin/env bash

# Check if containers are running
if ! docker-compose ps | grep -q "app.*Up"; then
    echo "Containers not running. Starting them..."
    docker-compose up -d
    echo "Waiting for services to be ready..."
    sleep 5
fi

docker-compose exec app bash
BASH;
    }

    /**
     * Scaffold the testing infrastructure.
     * 
     * @param string $namespace App namespace
     * @param string $dbType Database type
     * @param string $dbHost Database host
     * @param string $dbName Database name
     * @param string $dbUser Database user
     * @param string $dbPass Database password
     * @param string $dbPrefix Database prefix
     * @param bool   $useDocker Whether Docker is being used
     */
    private function scaffoldTests($namespace, $dbType, $dbHost, $dbName, $dbUser, $dbPass, $dbPrefix, $useDocker)
    {
        $this->mkdir('tests/Unit');
        $this->mkdir('tests/Integration');
        
        $testDbName = $dbName . 'tests';
        $this->scaffoldSettings('app/config/testsettings.php', $dbType, $dbHost, $testDbName, $dbUser, $dbPass, $dbPrefix, true);

        $bootstrap = "<?php\ndefine('ROOT', dirname(__DIR__));\nrequire ROOT . '/vendor/autoload.php';\n\n\Pramnos\Framework\Testing\TestEnvironment::setup(\n    ROOT . '/app/config/testsettings.php'\n);\n";
        $this->writeFile('tests/bootstrap.php', $bootstrap);

        $baseTest = "<?php\nnamespace Tests;\n\nclass BaseTestCase extends \\Pramnos\\Framework\\Testing\\BaseTestCase\n{\n}\n";
        $this->writeFile('tests/BaseTestCase.php', $baseTest);

        $phpunitXml = <<<XML
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
        $this->writeFile('phpunit.xml', $phpunitXml);

        $exampleTest = "<?php\nnamespace Tests\Unit;\n\nuse Tests\BaseTestCase;\n\nclass ExampleTest extends BaseTestCase\n{\n    public function test_it_works()\n    {\n        \$this->assertTrue(true);\n    }\n}\n";
        $this->writeFile('tests/Unit/ExampleTest.php', $exampleTest);
    }
}
