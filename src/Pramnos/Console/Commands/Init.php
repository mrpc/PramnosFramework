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
     * Whether the automated composer dump-autoload succeeded.
     * @var bool
     */
    private $autoloadSuccess = true;

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

        // 2. Docker setup
        $useDocker = $helper->ask($input, $output, new ConfirmationQuestion('Setup Docker environment? [y/N] ', true));
        $dockerPort = 8080;
        $cacheSystem = 'none';

        if ($useDocker) {
            // Suggest the first available port starting from 8080
            while (!$this->isPortAvailable($dockerPort)) {
                $dockerPort++;
            }

            $dockerPort = $helper->ask($input, $output, new Question("Local mapping port [$dockerPort]: ", $dockerPort));
            $cacheSystem = $helper->ask($input, $output, new ChoiceQuestion('Cache System: ', ['none', 'redis', 'memcached'], 1));
        }

        // 3. Database Config
        $randomPass = bin2hex(random_bytes(10));
        $dbTypeChoices = ['mysql', 'postgresql', 'timescaledb'];
        $dbType = $helper->ask($input, $output, new ChoiceQuestion('Database Type: ', $dbTypeChoices, 2));
        
        $defaultDbHost = $useDocker ? 'db' : 'localhost';
        $dbHost = $helper->ask($input, $output, new Question("Database Host [$defaultDbHost]: ", $defaultDbHost));
        
        $dbSuffix = strtolower(str_replace(['-', ' '], '_', $appName));
        $dbNameDefault = $dbSuffix . '_db';
        $dbUserDefault = $dbSuffix . '_user';
        
        $dbName = $helper->ask($input, $output, new Question("Database Name [$dbNameDefault]: ", $dbNameDefault));
        $dbUser = $helper->ask($input, $output, new Question("Database User [$dbUserDefault]: ", $dbUserDefault));
        $dbPass = $helper->ask($input, $output, new Question("Database Password [$randomPass]: ", $randomPass));
        $dbPrefix = $helper->ask($input, $output, new Question('Database Table Prefix [optional]: ', ''));

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

        // www/.htaccess
        $this->writeFile('www/.htaccess', "RewriteEngine On\nRewriteRule ^$ index.php [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule ^(.*)$ index.php?url=$1 [QSA,L]\n");

        // src/Application.php
        $this->writeFile('src/Application.php', "<?php\nnamespace $namespace;\n\nclass Application extends \\Pramnos\\Application\\Application\n{\n}\n");

        // src/Controllers/Home.php
        $this->writeFile('src/Controllers/Home.php', "<?php\nnamespace $namespace\\Controllers;\n\nuse Pramnos\\Application\\Controller;\n\nclass Home extends Controller\n{\n    public function display()\n    {\n        \$view = \$this->getView('home');\n        return \$view->display('home');\n    }\n}\n");

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

        // --- Finalize Metadata ---
        $this->updateComposerJson($appName, $namespace);

        $output->writeln("\n<info>Project initialized successfully!</info>");
        $output->writeln("Next steps:");
        if ($useDocker) {
            $output->writeln(" 1. Run <comment>docker-compose up -d</comment>");
            $output->writeln(" 2. Access your app at <comment>http://localhost:$dockerPort</comment>");
            $output->writeln(" 3. Use <comment>./dockerbash</comment> to enter the container");
            if (!$this->autoloadSuccess) {
                $output->writeln(" 4. <warning>Warning: Autoloader sync failed.</warning> Run <comment>composer dump-autoload</comment> manually.");
            }
        } else {
            if (!$this->autoloadSuccess) {
                $output->writeln(" 1. <warning>Warning: Autoloader sync failed.</warning> Run <comment>composer dump-autoload</comment> manually.");
            }
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
        $realType = ($type === 'timescaledb') ? 'postgresql' : $type;
        $timescaleFlag = ($type === 'timescaledb') ? ",\n        'timescale' => true" : "";
        
        $content = "<?php\nreturn [\n    'database' => [\n        'type' => '$realType',\n        'hostname' => '$host',\n        'database' => '$name',\n        'user' => '$user',\n        'password' => '$pass',\n        'prefix' => '$prefix'$timescaleFlag\n    ],\n    'development' => " . ($dev ? 'true' : 'false') . ",\n    'forcessl' => false\n];\n";
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
        $slug = strtolower(str_replace([' ', '_'], '-', $namespace));
        
        // Detect if framework is local (symlinked via path repository)
        $extraVolumes = "";
        $composerPath = $this->targetBaseDir . '/composer.json';
        if (file_exists($composerPath)) {
            $composer = json_decode(file_get_contents($composerPath), true);
            foreach ($composer['repositories'] ?? [] as $repo) {
                if (($repo['type'] ?? '') === 'path' && (strpos($repo['url'] ?? '', 'PramnosFramework') !== false)) {
                    // Extract the framework path and map it to /var/www/PramnosFramework
                    // Most likely it's ../PramnosFramework
                    $fwPath = $repo['url'];
                    $extraVolumes = "      - $fwPath:/var/www/PramnosFramework\n";
                    break;
                }
            }
        }

        $compose = "services:\n  app:\n    container_name: {$slug}_php\n    build: .\n    ports:\n      - \"$port:80\"\n    volumes:\n      - .:/var/www/html\n$extraVolumes    depends_on:\n      - db\n";
        
        if ($cacheSystem !== 'none') {
            $compose .= "      - cache\n";
        }

        $compose .= "  db:\n    container_name: {$slug}_db\n    image: $image\n    environment:\n";
        if ($isPostgres) {
            $compose .= "      POSTGRES_DB: $dbName\n      POSTGRES_USER: $dbUser\n      POSTGRES_PASSWORD: $dbPass\n";
        } else {
            $compose .= "      MYSQL_DATABASE: $dbName\n      MYSQL_USER: $dbUser\n      MYSQL_PASSWORD: $dbPass\n      MYSQL_ROOT_PASSWORD: $dbPass\n";
        }

        if ($cacheSystem !== 'none') {
            $compose .= "  cache:\n    container_name: {$slug}_cache\n    image: $cacheSystem:latest\n";
        }

        $this->writeFile('docker-compose.yml', $compose);
        
        $phpExts = $isPostgres ? 'pdo_pgsql pgsql' : 'pdo_mysql mysqli';
        $docRoot = "/var/www/html/www";
        
        $dockerfile = "FROM php:8.4-apache\n";
        $dockerfile .= "RUN apt-get update && apt-get install -y libpq-dev libicu-dev git unzip\n";
        $dockerfile .= "RUN docker-php-ext-install pdo $phpExts intl\n";
        $dockerfile .= "RUN a2enmod rewrite\n";
        $dockerfile .= "ENV APACHE_DOCUMENT_ROOT $docRoot\n";
        $dockerfile .= "RUN sed -ri -e 's!/var/www/html!$docRoot!g' /etc/apache2/sites-available/*.conf\n";
        $dockerfile .= "RUN printf \"<Directory $docRoot/>\\n\\tOptions Indexes FollowSymLinks\\n\\tAllowOverride All\\n\\tRequire all granted\\n</Directory>\" > /etc/apache2/conf-available/pramnos.conf && a2enconf pramnos\n";
        $dockerfile .= "WORKDIR /var/www/html\n";
        $dockerfile .= "COPY . .\n";
        
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

    /**
     * Update the project's composer.json with actual project metadata.
     * 
     * @param string $appName
     * @param string $namespace
     */
    private function updateComposerJson($appName, $namespace)
    {
        $composerPath = $this->targetBaseDir . '/composer.json';
        if (!file_exists($composerPath)) {
            return;
        }

        $composer = json_decode(file_get_contents($composerPath), true);
        if (!$composer) {
            return;
        }

        // Slugify app name for package name
        $slug = strtolower(str_replace([' ', '_'], '-', $appName));
        
        $composer['name'] = "app/$slug";
        $composer['description'] = "Pramnos Application: $appName";
        
        // Update autoloading
        if (!isset($composer['autoload'])) {
            $composer['autoload'] = ['psr-4' => []];
        }
        
        $composer['autoload']['psr-4'] = [
            "$namespace\\" => "src/"
        ];

        // Remove the initialization script from the project
        if (isset($composer['scripts']['post-create-project-cmd'])) {
            unset($composer['scripts']['post-create-project-cmd']);
        }
        
        // Clean up keywords and potentially other template-only fields
        $composer['keywords'] = ['pramnos', 'framework', 'application', $slug];

        file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Automatically run composer dump-autoload to sync the new namespace
        echo "\n<info>Regenerating autoloader...</info>\n";
        $output = [];
        $resultCode = 0;
        @exec('composer dump-autoload 2>&1', $output, $resultCode);
        
        if ($resultCode !== 0) {
            $this->autoloadSuccess = false;
        }
    }

    /**
     * Check if a port is available on localhost.
     * 
     * @param int $port
     * @return bool
     */
    private function isPortAvailable($port)
    {
        $connection = @fsockopen('localhost', $port, $errno, $errstr, 0.1);
        if (is_resource($connection)) {
            fclose($connection);
            return false;
        }
        return true;
    }
}
