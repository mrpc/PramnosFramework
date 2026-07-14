<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DbWipe;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Unit tests for the db:wipe console command.
 *
 * db:wipe is destructive — it drops every table in the current database. The
 * one invariant we can (and must) verify without a live database is the safety
 * guard: in non-interactive mode the command must refuse to run unless --force
 * is given, exiting non-zero and without ever attempting to touch the database.
 *
 * The guard is deliberately evaluated before the command resolves the database
 * connection, so these tests attach the command to a plain Symfony Application
 * (not the real Pramnos console application) and still exercise the guard.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(DbWipe::class)]
class DbWipeTest extends TestCase
{
    private CommandTester $tester;
    /** @var string|null Original $_SERVER['PHP_SELF'] value */
    private ?string $originalPhpSelf = null;

    /**
     * Attach a fresh DbWipe to a minimal Symfony Console Application so
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
        $app->add(new DbWipe());
        $app->setAutoExit(false);

        $this->tester = new CommandTester($app->find('db:wipe'));
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
     * The core safety invariant: run non-interactively (CommandTester's default)
     * without --force. The command must refuse with a non-zero exit code and a
     * message telling the user to pass --force. It must never reach the
     * database-resolution step (which would otherwise print a different error).
     */
    public function testRefusesNonInteractiveWithoutForce(): void
    {
        // Arrange — CommandTester executes non-interactively by default.

        // Act
        $exitCode = $this->tester->execute([], ['interactive' => false]);

        // Assert — non-zero exit: the destructive op was blocked.
        $this->assertSame(Command::FAILURE, $exitCode);

        // Assert — the refusal message names --force as the remedy.
        $output = $this->tester->getDisplay();
        $this->assertStringContainsString('--force', $output);
        $this->assertStringContainsString('Refusing', $output);
    }

    /**
     * With --force the guard must be bypassed. Since these tests run outside the
     * real Pramnos console application, execution proceeds past the guard and
     * then stops at the console-application check. We assert the guard did NOT
     * short-circuit by confirming the "Refusing" message is absent — proving
     * --force lets the command move past the safety gate.
     */
    public function testForceBypassesGuard(): void
    {
        // Act
        $exitCode = $this->tester->execute(['--force' => true], ['interactive' => false]);
        $output   = $this->tester->getDisplay();

        // Assert — we got past the guard (no refusal message).
        $this->assertStringNotContainsString('Refusing', $output);

        // Assert — instead we hit the console-application guard, proving the
        // safety guard let us through rather than aborting early.
        $this->assertStringContainsString('Pramnos console application', $output);
        $this->assertSame(Command::FAILURE, $exitCode);
    }
}
