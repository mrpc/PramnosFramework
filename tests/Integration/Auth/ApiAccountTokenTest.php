<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\ApiAccount;
use Pramnos\Database\Database;

/**
 * The controller with its request surface stubbed, over a real database.
 */
class TokenRevokingApiAccount extends ApiAccount
{
    public function __construct()
    {
        // No parent constructor: the token write needs a database, not an
        // application, a webhook service or a router.
    }

    /** Expose the revocation for the test. */
    public function revoke(string $token): void
    {
        $this->revokeToken($token);
    }
}

/**
 * Integration test for the token revocation behind `POST /account/logout`.
 *
 * WHAT: does logging out actually deactivate the row a bearer token points at?
 * WHY:  `revokeToken()` carried `@codeCoverageIgnore — thin DB write; exercised
 *       via integration, not unit tests`, and no such test existed. The
 *       annotation excused the method from coverage on the strength of a claim
 *       nothing backed.
 *
 * It matters beyond bookkeeping: API tokens have no expiry by default on
 * existing installations, so revocation is the only thing that ends a session.
 * A logout that silently failed to write would leave a token valid for ever,
 * and the client would show the user as signed out.
 */
class ApiAccountTokenTest extends TestCase
{
    /** @var Database The test's own connection */
    private Database $db;

    /** @var bool Whether this test created the table and must drop it */
    private bool $createdTable = false;

    /** A token value no fixture would collide with. */
    private const TOKEN = 'integration-revoke-probe-token';

    /** @var int The user this test's tokens belong to */
    private int $userId = 0;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!\defined('CONFIG')) {
            \define('CONFIG', 'tests' . \DS . 'fixtures' . \DS . 'app');
        }

        \Pramnos\Application\Settings::loadSettings(
            \ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php'
        );

        $this->db = \Pramnos\Framework\Factory::getDatabase();
        if (!$this->db->connected) {
            try {
                $this->db->connect();
            } catch (\Throwable $e) {
                $this->markTestSkipped('Database not reachable: ' . $e->getMessage());
            }
        }

        $this->ensureTable();
        $this->clearProbe();
        $this->ensureUser();
    }

    protected function tearDown(): void
    {
        try {
            $this->clearProbe();
            if ($this->createdTable) {
                $this->db->schema()->dropTableIfExists('usertokens');
            }
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
    }

    /**
     * The token store, created only when this installation lacks it.
     */
    private function ensureTable(): void
    {
        if ($this->db->schema()->hasTable('usertokens')) {
            return;
        }

        $this->createdTable = true;
        \Pramnos\User\User::setupDb();

        if (!$this->db->schema()->hasTable('usertokens')) {
            $this->markTestSkipped('No usertokens table available');
        }
    }

    /**
     * A real user for the tokens to belong to.
     *
     * `usertokens.userid` carries a foreign key to `users`, so an invented id
     * cannot be inserted — the constraint is the schema doing its job.
     */
    private function ensureUser(): void
    {
        \Pramnos\User\User::setupDb();

        $existing = $this->db->queryBuilder()
            ->table('users')
            ->where('username', 'revoke_probe')
            ->first();

        if ($existing && $existing->numRows > 0) {
            $this->userId = (int) $existing->fields['userid'];

            return;
        }

        $this->db->queryBuilder()->table('users')->insert([
            'username' => 'revoke_probe',
            'email'    => 'revoke_probe@example.test',
            'password' => 'x',
            'active'   => 1,
        ]);

        $this->userId = (int) $this->db->getInsertId();
    }

    /** Remove only this test's row. */
    private function clearProbe(): void
    {
        $this->db->queryBuilder()
            ->table('usertokens')
            ->where('token', self::TOKEN)
            ->delete();
    }

    /** The stored status of this test's token, or null when there is no row. */
    private function storedStatus(): ?int
    {
        $row = $this->db->queryBuilder()
            ->table('usertokens')
            ->where('token', self::TOKEN)
            ->first();

        if (!$row || $row->numRows == 0) {
            return null;
        }

        return (int) $row->fields['status'];
    }

    // ── Tests ────────────────────────────────────────────────────────────────

    /**
     * Revoking marks the row as removed, so it stops authenticating.
     *
     * Status 2 is "removed" in this schema; `User::loadByToken()` only accepts
     * status 1, so this is what actually ends the session.
     */
    public function testRevokingDeactivatesTheToken(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid'    => $this->userId,
            'token'     => self::TOKEN,
            'tokentype' => 'auth',
            'status'    => 1,
            'created'   => time(),
            'expires'   => 0,
        ]);
        $this->assertSame(1, $this->storedStatus(), 'precondition: the token is active');

        // Act
        (new TokenRevokingApiAccount())->revoke(self::TOKEN);

        // Assert
        $this->assertSame(2, $this->storedStatus(), 'a revoked token must not stay active');
    }

    /**
     * A revoked token no longer identifies its user.
     *
     * The status column is the mechanism; this is the consequence, and the
     * consequence is the point — a logout that wrote the row but left the token
     * usable would pass the assertion above and fail the user.
     */
    public function testARevokedTokenNoLongerLoadsItsUser(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('usertokens')->insert([
            'userid'    => $this->userId,
            'token'     => self::TOKEN,
            'tokentype' => 'auth',
            'status'    => 1,
            'created'   => time(),
            'expires'   => 0,
        ]);

        // Act
        (new TokenRevokingApiAccount())->revoke(self::TOKEN);

        // Assert
        $user = new \Pramnos\User\User();
        $user->loadByToken(self::TOKEN, 'auth', false);
        $this->assertLessThan(
            2,
            (int) $user->userid,
            'a revoked token must not resolve to a signed-in user'
        );
    }

    /**
     * Revoking a token nobody issued is not an error.
     *
     * Clients log out with whatever they hold, including a token the server has
     * already forgotten. That has to be a no-op, not a failure.
     */
    public function testRevokingAnUnknownTokenIsHarmless(): void
    {
        // Act
        (new TokenRevokingApiAccount())->revoke('a-token-that-was-never-issued');

        // Assert — reaching this line is the contract
        $this->assertNull($this->storedStatus());
    }
}
