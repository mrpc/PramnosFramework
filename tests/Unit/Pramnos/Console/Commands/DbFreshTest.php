<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DbFresh;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the db:fresh console command.
 *
 * db:fresh is destructive — it drops every table and re-runs all migrations.
 * As with db:wipe, the testable-without-a-database invariant is the safety
 * guard: in non-interactive mode the command must refuse to run unless --force
 * is given, exiting non-zero and without wiping or migrating anything.
 *
 * The guard runs before the command resolves the database connection or
 * delegates to db:wipe / migrate, so a plain Symfony Application suffices here.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(DbFresh::class)]
class DbFreshTest extends TestCase
{
    private CommandTester $tester;
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    /**
     * Attach a fresh DbFresh to a minimal Symfony Console Application so
     * getApplication() is non-null and CommandTester can drive it.
     */
    protected function setUp(): void
    {
        // Symfony's DumpCompletionCommand reads $_SERVER['PHP_SELF'] in configure();
        // ensure it is set to prevent "Undefined array key" warnings in PHP 8.4.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }

        $app = new Application('test', '1.0');
        $app->add(new DbFresh());
        $app->setAutoExit(false);

        $this->tester = new CommandTester($app->find('db:fresh'));
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        } else {
            $_SERVER['PHP_SELF'] = $this->originalPhpSelf;
        }
    }

    /**
     * The core safety invariant: run non-interactively without --force. The
     * command must refuse with a non-zero exit code and point the user at
     * --force, never proceeding to wipe or migrate.
     */
    public function testRefusesNonInteractiveWithoutForce(): void
    {
        // Act
        $exitCode = $this->tester->execute([], ['interactive' => false]);

        // Assert — non-zero exit: the destructive op was blocked.
        $this->assertSame(Command::FAILURE, $exitCode);

        // Assert — the refusal message names --force as the remedy.
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('--force', $output);
        $this->assertStringContainsString('Refusing', $output);

        // Assert — it never attempted the destructive steps.
        $this->assertStringNotContainsString('Wiping database', $output);
        $this->assertStringNotContainsString('Running migrations', $output);
    }

    /**
     * With --force the guard must be bypassed. Outside the real Pramnos console
     * application, execution proceeds past the guard and stops at the
     * console-application check. Absence of the "Refusing" message proves the
     * guard let the command through.
     */
    public function testForceBypassesGuard(): void
    {
        // Act
        $exitCode = $this->tester->execute(['--force' => true], ['interactive' => false]);
        $output   = $this->tester->getDisplay();

        // Assert — past the guard (no refusal message).
        $this->assertStringNotContainsString('Refusing', $output);

        // Assert — stopped at the console-application guard instead.
        $this->assertStringContainsString('Pramnos console application', $output);
        $this->assertSame(Command::FAILURE, $exitCode);
    }
}
