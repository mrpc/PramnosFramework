<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\MakeCommandBase;
use Pramnos\Console\Commands\Serve;
use Pramnos\Console\Commands\MigrateLogs;
use Symfony\Component\Console\Application as SymfonyApp;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Characterization tests for Console Commands.
 *
 * Locks the pure-logic contracts:
 *   - MakeCommandBase::getProperClassName() and getModelTableName() naming helpers
 *   - Serve command defaults (port/host)
 *   - MigrateLogs command configuration
 */
#[CoversClass(MakeCommandBase::class)]
#[CoversClass(Serve::class)]
#[CoversClass(MigrateLogs::class)]
class CommandsCharacterizationTest extends TestCase
{
    /** @var string|null Original value of $_SERVER['PHP_SELF'] before each test */
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] at configure()
        // time; without it, PHP emits an "Undefined array key" warning plus a
        // deprecation for basename(null). Ensure it is always set.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    // -----------------------------------------------------------------------
    // MakeCommandBase::getProperClassName — singular/plural naming contract
    // -----------------------------------------------------------------------

    /**
     * A singular noun stays singular and is ucfirst'd.
     * E.g. "user" → "User" when forceSingular=true.
     */
    public function testGetProperClassNameSingularInputStaysSingular(): void
    {
        // Act
        $result = MakeCommandBase::getProperClassName('user', true);

        // Assert
        $this->assertSame('User', $result);
    }

    /**
     * A plural noun is singularized when forceSingular=true.
     * E.g. "users" → "User".
     */
    public function testGetProperClassNamePluralIsSingularizedWhenForced(): void
    {
        // Act
        $result = MakeCommandBase::getProperClassName('users', true);

        // Assert – should become "User"
        $this->assertSame('User', $result);
    }

    /**
     * A singular noun is pluralized when forceSingular=false.
     * E.g. "article" → "Articles".
     */
    public function testGetProperClassNameSingularIsPluralized(): void
    {
        // Act
        $result = MakeCommandBase::getProperClassName('article', false);

        // Assert
        $this->assertSame('Articles', $result);
    }

    /**
     * A plural noun stays plural when forceSingular=false.
     * E.g. "articles" → "Articles".
     */
    public function testGetProperClassNamePluralStaysPluralWhenNotForced(): void
    {
        // Act
        $result = MakeCommandBase::getProperClassName('articles', false);

        // Assert
        $this->assertSame('Articles', $result);
    }

    #[DataProvider('classNameProvider')]
    public function testGetProperClassNameVariousCases(
        string $input, bool $forceSingular, string $expected
    ): void {
        $this->assertSame($expected, MakeCommandBase::getProperClassName($input, $forceSingular));
    }

    /** @return array<string,array{0:string,1:bool,2:string}> */
    public static function classNameProvider(): array
    {
        return [
            'comment singular'  => ['comment',  true,  'Comment'],
            'comments plural→s' => ['comments', true,  'Comment'],
            'post singular→pl'  => ['post',      false, 'Posts'],
            'posts plural stays'=> ['posts',     false, 'Posts'],
        ];
    }

    // -----------------------------------------------------------------------
    // MakeCommandBase::getModelTableName — table naming contract
    // -----------------------------------------------------------------------

    /**
     * A singular noun is pluralized and prefixed with #PREFIX# placeholder.
     * E.g. "user" → "#PREFIX#users".
     */
    public function testGetModelTableNameSingularIsPluralized(): void
    {
        // Act
        $result = MakeCommandBase::getModelTableName('user');

        // Assert
        $this->assertSame('#PREFIX#users', $result);
    }

    /**
     * A plural noun stays plural and is prefixed with #PREFIX#.
     * E.g. "articles" → "#PREFIX#articles".
     */
    public function testGetModelTableNamePluralStaysPlural(): void
    {
        // Act
        $result = MakeCommandBase::getModelTableName('articles');

        // Assert
        $this->assertSame('#PREFIX#articles', $result);
    }

    /**
     * Input is lowercased before the prefix is applied.
     * E.g. "Order" → "#PREFIX#orders".
     */
    public function testGetModelTableNameInputIsLowercased(): void
    {
        // Act
        $result = MakeCommandBase::getModelTableName('Order');

        // Assert
        $this->assertSame('#PREFIX#orders', $result);
    }

    // -----------------------------------------------------------------------
    // Serve command defaults
    // -----------------------------------------------------------------------

    /**
     * Serve command is registered with name 'serve'.
     */
    public function testServeCommandIsNamedServe(): void
    {
        // Act
        $cmd = new Serve();

        // Assert
        $this->assertSame('serve', $cmd->getName());
    }

    /**
     * Serve command has --port and --host options.
     */
    public function testServeCommandHasPortAndHostOptions(): void
    {
        // Arrange
        $cmd = new Serve();
        $definition = $cmd->getDefinition();

        // Assert
        $this->assertTrue($definition->hasOption('port'));
        $this->assertTrue($definition->hasOption('host'));
    }

    // -----------------------------------------------------------------------
    // MigrateLogs command configuration
    // -----------------------------------------------------------------------

    /**
     * MigrateLogs command is named 'migratelogs'.
     */
    public function testMigrateLogsCommandIsNamedMigratelogs(): void
    {
        // Act
        $cmd = new MigrateLogs();

        // Assert
        $this->assertSame('migratelogs', $cmd->getName());
    }

    /**
     * MigrateLogs requires a 'path' argument and has --all and --no-backup options.
     */
    public function testMigrateLogsCommandConfiguration(): void
    {
        // Arrange
        $cmd = new MigrateLogs();
        $definition = $cmd->getDefinition();

        // Assert
        $this->assertTrue($definition->hasArgument('path'));
        $this->assertTrue($definition->getArgument('path')->isRequired());
        $this->assertTrue($definition->hasOption('all'));
        $this->assertTrue($definition->hasOption('no-backup'));
    }

    /**
     * MigrateLogs returns error (exit code 1) for a non-existent path,
     * without crashing the process.
     */
    public function testMigrateLogsCommandReturnsErrorForNonExistentPath(): void
    {
        // Arrange
        $app = new SymfonyApp();
        $app->add(new MigrateLogs());
        $cmd = $app->find('migratelogs');
        $tester = new CommandTester($cmd);

        // Act
        $exitCode = $tester->execute(['path' => '/tmp/nonexistent_pramnos_test_' . uniqid()]);

        // Assert – must return 1 (error), not throw
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }
}
