<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Pramnos\Console\Application as ConsoleApplication;
use Pramnos\Console\Commands\Create;
use Pramnos\Console\Commands\Make\MakeController;

class CreateAliasTest extends TestCase
{
    protected function setUp(): void
    {
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
        if (!defined('INCLUDES')) {
            define('INCLUDES', 'src');
        }
    }

    /**
     * The legacy `pramnos create controller` alias must:
     *  - exit 0 (success)
     *  - print a deprecation warning so developers know to migrate to create:controller
     *  - delegate to create:controller and produce the same "Controller created" success line
     */
    public function testExecuteAliasPrintsWarningAndRunsCommand(): void
    {
        $consoleApp = new class extends ConsoleApplication {
            protected function registerCommands(): void {}
        };

        $consoleApp->internalApplication = new class {
            public string $appName = '';
            public array $applicationInfo = ['namespace' => 'TestApp'];
            public $database = null;
            public function init(): void {}
        };

        $consoleApp->add(new MakeController());
        $aliasCommand = new Create();
        $consoleApp->add($aliasCommand);

        $tester = new CommandTester($consoleApp->find('create'));
        // Using a random name so it doesn't collide
        $name = 'AliasZzzCtrl' . bin2hex(random_bytes(4));
        
        $exit = $tester->execute(['entity' => 'controller', 'name' => $name]);
        $this->assertSame(0, $exit);
        
        $display = $tester->getDisplay();
        $this->assertStringContainsString("Warning: 'pramnos create controller' is deprecated", $display);
        $this->assertStringContainsString("Controller created", $display);
        
        // Cleanup generated files.
        // getProperClassName(name, false) lowercases then pluralizes, so the actual
        // file name differs from the original mixed-case $name — use the transformed
        // class name for an exact match instead of a glob that would miss.
        $className  = \Pramnos\Console\Commands\MakeCommandBase::getProperClassName($name, false);
        $root       = defined('ROOT') ? ROOT : getcwd();
        $ctrlDir    = $root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Controllers';
        $featureDir = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature';

        @unlink($ctrlDir    . DIRECTORY_SEPARATOR . $className . '.php');
        @unlink($featureDir . DIRECTORY_SEPARATOR . $className . 'Test.php');
    }
}
