<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\AuthTokenCleanup;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\User\Token;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `auth:token-cleanup` retiring real rows in a real table.
 *
 * The arms of `execute()` are covered by unit tests against a seam; this is the one run that proves
 * the seam has something behind it. `cleanupAllAuthTokens()` had a test too — against a recording
 * fake that asserts the shape of the SQL — so what nobody had ever checked was whether the
 * statement, sent to a database, retires the right rows and leaves the others alone.
 *
 * That matters more than the usual "does the query run", because the predicate is a conjunction:
 * `created < cutoff` **and** `lastused < cutoff`. A token issued a year ago and used this morning
 * must survive, and `lastused` defaults to `0` — so a token issued a year ago and never used at all
 * must not, which is the row a `lastused >= cutoff` mistake would keep forever.
 *
 * Runs on every backend: {@see TokenCleanupCommandPostgreSQLTest} re-runs it against
 * PostgreSQL/TimescaleDB, where `status` is a `SMALLINT` rather than MySQL's `TINYINT` and the
 * comparison is against a different integer type.
 *
 * The rows are inserted and deleted rather than the table being owned: `usertokens` is shared, it
 * carries a foreign key from `tokenactions`, and a class that drops it makes every later class that
 * needs it pay to rebuild it.
 */
#[CoversClass(AuthTokenCleanup::class)]
#[CoversClass(\Pramnos\User\User::class)]
class TokenCleanupCommandTest extends BaseTestCase
{
    private $db;

    /** A marker in `notes`, so teardown removes this class's rows and nothing else. */
    private const MARKER = 'token-cleanup-integration';

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
            \Pramnos\Framework\Migrations\Auth\CreateUsertokensTable::class,
        ], $this->db);

        $this->deleteOwnRows();
    }

    protected function tearDown(): void
    {
        $this->deleteOwnRows();
        parent::tearDown();
    }

    /** The settings fixture, so the PostgreSQL lane can point at its own. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** A quoted, escaped literal — `prepareInput()` escapes and does not add the quotes. */
    private function q(string $value): string
    {
        return "'" . $this->db->prepareInput($value) . "'";
    }

    private function deleteOwnRows(): void
    {
        $this->db->query(
            'DELETE FROM ' . $this->db->prefix . 'usertokens WHERE notes = '
            . $this->q(self::MARKER)
        );
    }

    /**
     * An owner for the tokens, because `usertokens.userid` is a foreign key.
     *
     * Whichever account the fixtures happen to have; this test is about the timestamps, not about
     * who holds the token.
     */
    private function anyUserId(): int
    {
        $row = $this->db->query('SELECT MIN(userid) AS userid FROM ' . $this->db->prefix . 'users');
        $id  = (int) ($row->fields['userid'] ?? 0);

        if ($id === 0) {
            $this->markTestSkipped('No user in the fixtures to own a token.');
        }

        return $id;
    }

    /**
     * Inserts one token and returns its id.
     *
     * @param int    $created  Unix timestamp the token was issued
     * @param int    $lastused Unix timestamp it was last presented, or 0 for never
     * @param string $type     One of the `Token::TYPE_*` values
     */
    private function insertToken(int $created, int $lastused, string $type = Token::TYPE_WEB_SESSION): int
    {
        $userId = $this->anyUserId();
        $value  = self::MARKER . '-' . $created . '-' . $lastused . '-' . $type;

        $this->db->query(
            'INSERT INTO ' . $this->db->prefix . 'usertokens '
            . '(userid, tokentype, token, token_lookup, created, lastused, status, notes, '
            . 'deviceinfo, scope) VALUES ('
            . $userId . ', '
            . $this->q($type) . ', '
            . $this->q($value) . ', '
            . $this->q(hash('sha256', $value)) . ', '
            . $created . ', '
            . $lastused . ', 1, '
            . $this->q(self::MARKER) . ", '', '')"
        );

        $row = $this->db->query(
            'SELECT tokenid FROM ' . $this->db->prefix . 'usertokens WHERE token_lookup = '
            . $this->q(hash('sha256', $value))
        );

        return (int) $row->fields['tokenid'];
    }

    /** The `status` a token has now. */
    private function statusOf(int $tokenId): int
    {
        $row = $this->db->query(
            'SELECT status FROM ' . $this->db->prefix . 'usertokens WHERE tokenid = ' . $tokenId
        );

        return (int) $row->fields['status'];
    }

    /**
     * A token idle past the window is retired; one used recently is not.
     *
     * Both halves in one test, because the assertion that matters is the *pair*: a cleanup that
     * retires everything passes the first half on its own.
     */
    public function testItRetiresTheIdleTokenAndLeavesTheActiveOne(): void
    {
        // Arrange
        $longAgo = time() - (60 * 86400);
        $idle    = $this->insertToken($longAgo, $longAgo);
        $active  = $this->insertToken($longAgo, time() - 60);

        // Act
        $tester = new CommandTester($this->command());
        $tester->execute(['--days' => '30']);

        // Assert
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame(2, $this->statusOf($idle), 'an idle token was not retired');
        $this->assertSame(1, $this->statusOf($active), 'a token used an hour ago was retired');
    }

    /**
     * A token issued long ago and never presented is retired.
     *
     * `lastused` defaults to `0`, which is *less* than any cutoff — so this row is caught by the
     * predicate as written and would be kept forever by the obvious mistake of treating an unused
     * token as fresh.
     */
    public function testATokenThatWasNeverUsedIsRetired(): void
    {
        // Arrange
        $neverUsed = $this->insertToken(time() - (60 * 86400), 0);

        // Act
        (new CommandTester($this->command()))->execute(['--days' => '30']);

        // Assert
        $this->assertSame(2, $this->statusOf($neverUsed));
    }

    /**
     * `--days` moves the window, so patience keeps a token the default would retire.
     *
     * The option is the only reason this command takes one; a run that ignored it and always used
     * 30 days would pass every other test here.
     */
    public function testAWiderWindowKeepsATokenThatTheDefaultWouldRetire(): void
    {
        // Arrange — idle for 60 days: inside 90, outside 30
        $token = $this->insertToken(time() - (60 * 86400), time() - (60 * 86400));

        // Act
        (new CommandTester($this->command()))->execute(['--days' => '90']);

        // Assert
        $this->assertSame(1, $this->statusOf($token), '--days=90 should not touch a 60-day-old token');

        // Act — and the default does
        (new CommandTester($this->command()))->execute([]);

        // Assert
        $this->assertSame(2, $this->statusOf($token));
    }

    /**
     * A token type outside the retired set is left alone.
     *
     * `password_reset` and `email_verify` tokens carry their own expiry and are consumed once;
     * retiring them on an idle-days rule would be a second, wrong lifetime for them.
     */
    public function testATokenTypeOutsideTheRetiredSetSurvives(): void
    {
        // Arrange
        $longAgo = time() - (60 * 86400);
        $reset   = $this->insertToken($longAgo, $longAgo, 'password_reset');
        $session = $this->insertToken($longAgo, $longAgo);

        // Act
        (new CommandTester($this->command()))->execute(['--days' => '30']);

        // Assert
        $this->assertSame(1, $this->statusOf($reset), 'a password-reset token is not a session token');
        $this->assertSame(2, $this->statusOf($session), 'the session token in the same run was not retired');
    }

    /** The command, named as the console would name it. */
    private function command(): AuthTokenCleanup
    {
        $command = new AuthTokenCleanup();
        $application = new \Symfony\Component\Console\Application();
        $application->add($command);

        return $command;
    }
}
