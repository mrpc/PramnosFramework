<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\AuthUnlock;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `auth:unlock` — 78 of 138 statements never executed, on a command whose most important line is
 * a **refusal**.
 *
 * It exists for the developer who has mistyped a fixture password three times and now cannot test
 * the login flow they are working on. Which makes it, by construction, a command that weakens a
 * brute-force defence — so the interesting assertions are the limits:
 *
 *   - **`--all` refuses outside development.** "Clear every lockout on the server" is exactly what
 *     somebody working through a password list would want, and a command that offers it on a live
 *     installation is a hole with a friendly name.
 *   - **It clears the counter and nothing else.** A wrong password is still a wrong password
 *     afterwards; this is not a way past authentication.
 *   - An unknown scope is refused **with the list of valid ones**, because a typo that silently
 *     unlocked nothing would read as "that account was not locked".
 *
 * Runs on every backend: {@see AuthUnlockCommandPostgreSQLTest} re-runs it against
 * PostgreSQL/TimescaleDB. `authserver.loginlockouts` is created by hand-written DDL per engine in
 * its migration, and the timestamps are `TIMESTAMPTZ` on one and `DATETIME` on the other — so
 * "how much longer is this locked" is a claim about two different comparisons.
 */
#[CoversClass(AuthUnlock::class)]
class AuthUnlockCommandTest extends BaseTestCase
{
    private $db;

    private mixed $savedDebug = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        $app = Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }
        $app->database = $this->db;

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateLoginlockoutTable::class,
        ], $this->db);

        $this->clear();

        // `--all` reads this, and it decides whether the command's one refusal fires.
        $this->savedDebug = getenv('APP_DEBUG');
    }

    protected function tearDown(): void
    {
        $this->clear();

        if ($this->savedDebug === false) {
            putenv('APP_DEBUG');
            unset($_ENV['APP_DEBUG']);
        } else {
            putenv('APP_DEBUG=' . $this->savedDebug);
            $_ENV['APP_DEBUG'] = $this->savedDebug;
        }

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    private function tester(): CommandTester
    {
        $console = new ConsoleApplication();
        $console->add(new AuthUnlock());

        return new CommandTester($console->find('auth:unlock'));
    }

    // ── The refusal that matters ──────────────────────────────────────────────

    /**
     * `--all` refuses outside development, and says what to do instead.
     *
     * The one line in this command that is a security control. "Clear every lockout on the
     * server" is what somebody working through a password list would ask for, and the message
     * has to point at the narrow alternative — a refusal with no next step is a refusal somebody
     * works around.
     */
    public function testAllRefusesOutsideDevelopment(): void
    {
        // Arrange
        $this->development(false);
        $this->lock('admin', 'identifier', 300);
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--all' => true]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(1, $code, '--all succeeded on a non-development installation');
        $this->assertStringContainsString('only runs in development', $text);
        $this->assertStringContainsString('auth:unlock', $text, 'no narrower alternative offered');
        $this->assertSame(1, $this->lockoutCount(), 'the lockout was cleared anyway');
    }

    /** And in development it clears everything. */
    public function testAllClearsEverythingInDevelopment(): void
    {
        // Arrange
        $this->development(true);
        $this->lock('admin', 'identifier', 300);
        $this->lock('10.0.0.5', 'ip', 60);
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--all' => true]);

        // Assert
        $this->assertSame(0, $code, $tester->getDisplay());
        $this->assertSame(0, $this->lockoutCount());
    }

    // ── Unlocking one ─────────────────────────────────────────────────────────

    /**
     * One identifier is unlocked, and the others are left alone.
     *
     * The narrow operation the command exists for. Clearing more than was asked is the same
     * mistake as `--all`, arriving quietly.
     */
    public function testOneIdentifierIsUnlockedAndTheRestAreNot(): void
    {
        // Arrange
        $this->lock('admin', 'identifier', 300);
        $this->lock('someone-else', 'identifier', 300);
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['identifier' => 'admin']);

        // Assert
        $this->assertSame(0, $code, $tester->getDisplay());
        $this->assertSame(0, $this->lockoutCount('admin'));
        $this->assertSame(
            1,
            $this->lockoutCount('someone-else'),
            "another account's lockout was cleared"
        );
    }

    /** A scope narrows it further: the same identifier locked two ways loses only one. */
    public function testAScopeNarrowsTheUnlock(): void
    {
        // Arrange — the same value locked as an identifier and as an IP.
        $this->lock('10.0.0.5', 'identifier', 300);
        $this->lock('10.0.0.5', 'ip', 300);
        $tester = $this->tester();

        // Act
        $tester->execute(['identifier' => '10.0.0.5', '--scope' => 'ip']);

        // Assert
        $this->assertSame(1, $this->lockoutCount('10.0.0.5', 'identifier'));
        $this->assertSame(0, $this->lockoutCount('10.0.0.5', 'ip'));
    }

    /**
     * An unknown scope is refused, and the valid ones are named.
     *
     * A typo that silently unlocked nothing would read as "that account was not locked" — the
     * operator would move on believing the lockout had gone.
     */
    public function testAnUnknownScopeIsRefusedWithTheValidOnes(): void
    {
        // Arrange
        $this->lock('admin', 'identifier', 300);
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['identifier' => 'admin', '--scope' => 'nonsense']);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown scope', $text);
        $this->assertStringContainsString('identifier', $text, 'the valid scopes are not listed');
        $this->assertSame(1, $this->lockoutCount('admin'), 'a typo cleared a lockout');
    }

    /** No identifier and no flag is refused, with the three things that would work. */
    public function testNoIdentifierIsRefusedWithGuidance(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $code = $tester->execute([]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--list', $text);
        $this->assertStringContainsString('--all', $text);
    }

    /**
     * Unlocking something that is not locked is not an error.
     *
     * It is the answer to "is this account locked?" asked in the imperative, and the state
     * afterwards is the state the operator wanted either way.
     */
    public function testUnlockingSomethingNotLockedIsNotAnError(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['identifier' => 'nobody-is-locked-here']);

        // Assert
        $this->assertSame(0, $code, $tester->getDisplay());
    }

    // ── Listing ───────────────────────────────────────────────────────────────

    /**
     * `--list` says who is locked and for how long, and clears nothing.
     *
     * The duration is the point: "locked" without it does not tell an operator whether to wait or
     * to act, which is the only decision they have.
     */
    public function testListReportsWhoIsLockedAndForHowLongWithoutClearing(): void
    {
        // Arrange
        $this->lock('admin', 'identifier', 3700);
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--list' => true]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString('admin', $text);
        $this->assertMatchesRegularExpression(
            '~\d+h \d+m|\d+m \d+s|\d+s~',
            $text,
            'the remaining time is not reported in a form somebody reads'
        );
        $this->assertSame(1, $this->lockoutCount('admin'), '--list cleared a lockout');
    }

    /**
     * An expired lockout is not listed.
     *
     * The row stays — it is the failure history the progressive backoff counts — but a lockout
     * that has run out is not something an operator can act on, and listing it would send them
     * to unlock an account that is already usable.
     */
    public function testAnExpiredLockoutIsNotListed(): void
    {
        // Arrange
        $this->lock('expired-one', 'identifier', -60);
        $tester = $this->tester();

        // Act
        $tester->execute(['--list' => true]);
        $text = $tester->getDisplay();

        // Assert
        $this->assertStringNotContainsString('expired-one', $text);
    }

    /** With nothing locked, `--list` says so rather than printing an empty table. */
    public function testListSaysWhenNothingIsLocked(): void
    {
        // Arrange
        $tester = $this->tester();

        // Act
        $code = $tester->execute(['--list' => true]);

        // Assert
        $this->assertSame(0, $code);
        $this->assertNotSame('', trim($tester->getDisplay()));
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** `APP_DEBUG`, which is what `--all` consults. */
    private function development(bool $yes): void
    {
        putenv('APP_DEBUG=' . ($yes ? '1' : '0'));
        $_ENV['APP_DEBUG'] = $yes ? '1' : '0';
    }

    /**
     * A lockout row, locked for `$seconds` from now — negative for one that has run out.
     */
    private function lock(string $value, string $scope, int $seconds): void
    {
        $until = $this->db->type === 'postgresql'
            ? date('Y-m-d H:i:sO', time() + $seconds)
            : date('Y-m-d H:i:s', time() + $seconds);

        $this->db->queryBuilder()->table('authserver.loginlockouts')->insert([
            'locktype'       => $scope,
            'lookupvalue'    => $value,
            'displayvalue'   => $value,
            'failedattempts' => 5,
            'lockoutuntil'   => $until,
        ]);
    }

    private function lockoutCount(?string $value = null, ?string $scope = null): int
    {
        $query = $this->db->queryBuilder()->table('authserver.loginlockouts');

        if ($value !== null) {
            $query->where('lookupvalue', $value);
        }
        if ($scope !== null) {
            $query->where('locktype', $scope);
        }

        return (int) $query->count();
    }

    private function clear(): void
    {
        try {
            $this->db->queryBuilder()->table('authserver.loginlockouts')->delete();
        } catch (\Throwable $exception) {
            // No table yet on a lane mid-migration; nothing to clear.
        }
    }
}
