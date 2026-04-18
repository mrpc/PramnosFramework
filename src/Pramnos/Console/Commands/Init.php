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
        $this->addOption('app-name', null, InputOption::VALUE_OPTIONAL, 'The name of the application');
        $this->addOption('namespace', null, InputOption::VALUE_OPTIONAL, 'The PHP namespace for the application');
        $this->addOption('docker', null, InputOption::VALUE_OPTIONAL, 'Whether to setup Docker environment (y/n)');
        $this->addOption('docker-port', null, InputOption::VALUE_OPTIONAL, 'Local port for Docker mapping');
        $this->addOption('cache-system', null, InputOption::VALUE_OPTIONAL, 'Cache system (none, redis, memcached)');
        $this->addOption('db-type', null, InputOption::VALUE_OPTIONAL, 'Database type (mysql, postgresql, timescaledb)');
        $this->addOption('db-host', null, InputOption::VALUE_OPTIONAL, 'Database host');
        $this->addOption('db-name', null, InputOption::VALUE_OPTIONAL, 'Database name');
        $this->addOption('db-user', null, InputOption::VALUE_OPTIONAL, 'Database user');
        $this->addOption('db-pass', null, InputOption::VALUE_OPTIONAL, 'Database password');
        $this->addOption('db-prefix', null, InputOption::VALUE_OPTIONAL, 'Database table prefix');
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
        $appName = $input->getOption('app-name') ?: $helper->ask($input, $output, new Question("Application Name [$defaultAppName]: ", $defaultAppName));
        
        // Default Namespace: CamelCase of app name
        $defaultNamespace = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $appName)));
        $namespace = $input->getOption('namespace') ?: $helper->ask($input, $output, new Question("Namespace [$defaultNamespace]: ", $defaultNamespace));

        // 2. Docker setup
        $dockerOption = $input->getOption('docker');
        if ($dockerOption !== null) {
            $useDocker = in_array(strtolower($dockerOption), ['y', 'yes', '1', 'true']);
        } else {
            $useDocker = $helper->ask($input, $output, new ConfirmationQuestion('Setup Docker environment? [y/N] ', true));
        }
        
        $dockerPort = 8080;
        $cacheSystem = 'none';

        if ($useDocker) {
            // Suggest the first available port starting from 8080
            while (!$this->isPortAvailable($dockerPort)) {
                $dockerPort++;
            }

            $dockerPort = $input->getOption('docker-port') ?: $helper->ask($input, $output, new Question("Local mapping port [$dockerPort]: ", $dockerPort));
            
            $cacheSystemOption = $input->getOption('cache-system');
            if ($cacheSystemOption !== null) {
                $cacheSystem = $cacheSystemOption;
            } else {
                $cacheSystem = $helper->ask($input, $output, new ChoiceQuestion('Cache System: ', ['none', 'redis', 'memcached'], 1));
            }
        }

        // 3. Database Config
        $randomPass = bin2hex(random_bytes(10));
        $dbTypeChoices = ['mysql', 'postgresql', 'timescaledb'];
        
        $dbTypeOption = $input->getOption('db-type');
        if ($dbTypeOption !== null) {
            $dbType = $dbTypeOption;
        } else {
            $dbType = $helper->ask($input, $output, new ChoiceQuestion('Database Type: ', $dbTypeChoices, 0)); // Changed default to 0 (mysql) for better compatibility
        }
        
        $defaultDbHost = $useDocker ? 'db' : 'localhost';
        $dbHost = $input->getOption('db-host') ?: $helper->ask($input, $output, new Question("Database Host [$defaultDbHost]: ", $defaultDbHost));
        
        $dbSuffix = strtolower(str_replace(['-', ' '], '_', $appName));
        $dbNameDefault = $dbSuffix . '_db';
        $dbUserDefault = $dbSuffix . '_user';
        
        $dbName = $input->getOption('db-name') ?: $helper->ask($input, $output, new Question("Database Name [$dbNameDefault]: ", $dbNameDefault));
        $dbUser = $input->getOption('db-user') ?: $helper->ask($input, $output, new Question("Database User [$dbUserDefault]: ", $dbUserDefault));
        $dbPass = $input->getOption('db-pass') ?: $helper->ask($input, $output, new Question("Database Password [$randomPass]: ", $randomPass));
        
        $dbPrefixOption = $input->getOption('db-prefix');
        if ($dbPrefixOption !== null) {
            $dbPrefix = $dbPrefixOption;
        } else {
            $dbPrefix = $helper->ask($input, $output, new Question('Database Table Prefix [optional]: ', ''));
        }

        // 4. Tests setup - Always Y as requested
        $useTests = true;

        $output->writeln("\n<info>Scaffolding project structure...</info>");

        // --- Scaffold Directories ---
        // Create the basic directory structure for a Pramnos application
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

        // --- Theme ---
        $this->scaffoldTheme($appName);

        // --- Docker ---
        if ($useDocker) {
            $this->scaffoldDocker($namespace, $dockerPort, $dbType, $dbName, $dbUser, $dbPass, $cacheSystem);
        }

        // --- Tests ---
        if ($useTests) {
            $this->scaffoldTests($namespace, $dbType, $dbHost, $dbName, $dbUser, $dbPass, $dbPrefix, $useDocker);
        }

        // --- Finalize Metadata ---
        $this->updateComposerJson($appName, $namespace, $output);

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
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    private function updateComposerJson($appName, $namespace, $output)
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
        $output->writeln("\n<info>Regenerating autoloader...</info>");
        $execOutput = [];
        $resultCode = 0;
        @exec('composer dump-autoload 2>&1', $execOutput, $resultCode);
        
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

    /**
     * Scaffold the default theme.
     * 
     * @param string $appName
     */
    private function scaffoldTheme($appName)
    {
        $themeDir = 'app/themes/default';
        
        // theme.html.php
        $this->writeFile($themeDir . '/theme.html.php', $this->getThemeHtmlTemplate());
        
        // header.php
        $this->writeFile($themeDir . '/header.php', $this->getThemeHeaderTemplate($appName));
        
        // footer.php
        $this->writeFile($themeDir . '/footer.php', $this->getThemeFooterTemplate());
        
        // style.css
        $this->writeFile($themeDir . '/style.css', $this->getThemeCssTemplate());
    }

    /**
     * Get the HTML wrapper template for the default theme.
     * 
     * @return string
     */
    private function getThemeHtmlTemplate()
    {
        return <<<'PHP'
<?php $this->get_Header(); ?>
<main class="main-content">
    <div class="container">
        [MODULE]
    </div>
</main>
<?php $this->get_Footer(); ?>
PHP;
    }

    /**
     * Get the Header template for the default theme.
     * 
     * @param string $appName
     * @return string
     */
    private function getThemeHeaderTemplate($appName)
    {
        return <<<PHP
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo \$appName; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <?php \$this->document->renderCss(); ?>
</head>
<body>
    <header class="main-header">
        <div class="container">
            <a href="<?php echo sURL; ?>" class="logo">
                $appName
            </a>
            <nav class="main-nav">
                <ul>
                    <li><a href="<?php echo sURL; ?>">Home</a></li>
                </ul>
            </nav>
        </div>
    </header>
PHP;
    }

    /**
     * Get the Footer template for the default theme.
     * 
     * @return string
     */
    private function getThemeFooterTemplate()
    {
        return <<<'PHP'
    <footer class="main-footer">
        <div class="container">
            <div class="footer-content">
                <p>&copy; <?php echo date('Y'); ?> <?php echo \Pramnos\Application\Application::getInstance()->applicationInfo['name']; ?>. All rights reserved.</p>
                <p class="powered">Powered by <a href="https://github.com/mrpc/PramnosFramework" target="_blank">PramnosFramework</a></p>
            </div>
        </div>
    </footer>
    <?php $this->document->renderJs(); ?>
</body>
</html>
PHP;
    }

    /**
     * Get the CSS template for the default theme.
     * 
     * @return string
     */
    private function getThemeCssTemplate()
    {
        return <<<'CSS'
:root {
    --primary-color: #2563eb;
    --primary-hover: #1d4ed8;
    --bg-gradient: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    --text-main: #1e293b;
    --text-muted: #64748b;
    --white: #ffffff;
    --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
    --glass-bg: rgba(255, 255, 255, 0.8);
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', sans-serif;
    color: var(--text-main);
    background: var(--bg-gradient);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    line-height: 1.6;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 2rem;
}

.main-header {
    background: var(--white);
    padding: 1.5rem 0;
    box-shadow: var(--shadow);
    position: sticky;
    top: 0;
    z-index: 100;
}

.main-header .container {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.logo {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary-color);
    text-decoration: none;
    letter-spacing: -0.025em;
}

.main-nav ul {
    list-style: none;
    display: flex;
    gap: 2rem;
}

.main-nav a {
    text-decoration: none;
    color: var(--text-main);
    font-weight: 500;
    transition: color 0.2s ease;
}

.main-nav a:hover {
    color: var(--primary-color);
}

.main-content {
    flex: 1;
    padding: 4rem 0;
}

.main-content .container {
    background: var(--glass-bg);
    backdrop-filter: blur(10px);
    padding: 3rem;
    border-radius: 1rem;
    box-shadow: var(--shadow);
    border: 1px solid rgba(255, 255, 255, 0.3);
}

.main-footer {
    background: var(--white);
    padding: 3rem 0;
    margin-top: auto;
    border-top: 1px solid #e2e8f0;
}

.footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: var(--text-muted);
    font-size: 0.875rem;
}

.footer-content a {
    color: var(--primary-color);
    text-decoration: none;
}

.footer-content a:hover {
    text-decoration: underline;
}

h1 {
    font-size: 3rem;
    font-weight: 800;
    margin-bottom: 1.5rem;
    color: var(--text-main);
    letter-spacing: -0.05em;
}

p {
    margin-bottom: 1rem;
    font-size: 1.125rem;
}

@media (max-width: 768px) {
    .main-content {
        padding: 2rem 0;
    }
    .main-content .container {
        padding: 1.5rem;
    }
    h1 {
        font-size: 2rem;
    }
}
CSS;
    }
}
