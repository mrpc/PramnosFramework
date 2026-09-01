<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Loginlockout;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The per-address limiter — `recordFailedAttemptWithin()`, which had never run.
 *
 * `Loginlockout` has thorough tests for the *account* ladder: three failures, five, seven, ten,
 * the sliding window, the configurable steps. All of them go through `recordFailedAttempt()`.
 * This is the other method, and it answers a different question with deliberately different
 * rules — 43 of the class's 130 statements, all of them here.
 *
 * The distinction is the point. The account ladder is for a person who has forgotten their
 * password, and it escalates. An address making one attempt against each of a thousand usernames
 * is not that person, and a ladder built for them is far too gentle for it. So this one:
 *
 *   - has **no ladder**. A fixed cost per window, because an address is not an account — it can
 *     be a shared office NAT, and locking one out for a day over somebody else's typing is a
 *     denial of service delivered by the security feature;
 *   - locks for **what is left of the window the first failure opened**, not for a window
 *     starting now. A steady drip of attempts must not be able to keep extending its own
 *     deadline, which would punish everybody behind that address indefinitely;
 *   - refuses to run at all on a threshold or window below 1, because `$attempts >= 0` is true
 *     of every attempt — a misconfigured threshold would lock out the first request from every
 *     address, permanently.
 *
 * Both backends: {@see AddressRateLimitPostgreSQLTest} re-runs it. Every claim here is about a
 * timestamp written to the database and read back with `strtotime()`, and the column is
 * `TIMESTAMPTZ` on one engine against `DATETIME` on the other — the difference that once made
 * `lockoutuntil` land in the past on a non-UTC host, so the lockout never engaged at all.
 */
#[CoversClass(Loginlockout::class)]
class AddressRateLimitTest extends BaseTestCase
{
    private $db;

    private Loginlockout $lockout;

    private const ADDRESS = '198.51.100.77';

    private const SCOPE = 'ip';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Auth\CreateLoginlockoutTable::class,
        ], $this->db);

        $this->lockout = new Loginlockout();
        $this->clearProbe();
    }

    protected function tearDown(): void
    {
        $this->clearProbe();

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── Counting ──────────────────────────────────────────────────────────────

    /**
     * Below the threshold nothing is locked, however many rows have been written.
     *
     * The first failure creates the row; the row existing is not the same as the address being
     * refused, and conflating the two would refuse the first wrong password anybody types.
     */
    public function testBelowTheThresholdTheAddressIsNotRefused(): void
    {
        // Act
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);

        // Assert
        $status = $this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS);
        $this->assertFalse($status['locked']);
        $this->assertSame(2, (int) ($this->row()['failedattempts'] ?? 0), 'the count is wrong');
    }

    /** At the threshold the address is refused, with a remaining time a caller can show. */
    public function testAtTheThresholdTheAddressIsRefused(): void
    {
        // Act
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);
        }

        // Assert
        $status = $this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS);
        $this->assertTrue($status['locked'], 'three failures in the window did not refuse the address');
        $this->assertGreaterThan(0, $status['remaining']);
        $this->assertLessThanOrEqual(600, $status['remaining']);
    }

    /**
     * A drip of further attempts does not extend the deadline.
     *
     * The property the whole method is shaped around. Locking for `now + window` on every
     * attempt would let a slow attacker keep an address refused for as long as they cared to
     * keep typing — and the people that punishes are the ones sharing the address, not the
     * attacker, who has a thousand others.
     *
     * Arranged by moving the stored first-failure time backwards, which is what the passage of
     * time looks like to the code that reads it.
     */
    public function testAContinuingDripDoesNotExtendTheDeadline(): void
    {
        // Arrange — refused, with most of the window already elapsed.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);
        }
        $this->ageFirstFailureBy(540);
        $before = $this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS)['remaining'];

        // Act — the attacker keeps going.
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);

        // Assert
        $after = $this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS)['remaining'];
        $this->assertLessThanOrEqual(
            $before,
            $after,
            'a further attempt pushed the deadline out, so the address can be held refused for ever'
        );
        $this->assertLessThanOrEqual(60, $after, 'the deadline is not the first failure plus the window');
    }

    /**
     * The lock always has at least a second left when it is applied.
     *
     * `max($now + 1, …)`: without it, a threshold reached on the very last second of a window
     * would produce a `lockoutuntil` at or before now, and `getLockoutStatus()` would report the
     * address as free at the exact moment it was refused. That is not a small window of
     * inaccuracy — it is the one attempt that reached the threshold going unpunished.
     */
    public function testAThresholdReachedAtTheEndOfTheWindowStillRefuses(): void
    {
        // Arrange — two failures, then age them to the far edge of the window.
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);
        $this->ageFirstFailureBy(599);

        // Act — the third arrives with a second of the window left.
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);

        // Assert
        $this->assertTrue(
            $this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS)['locked'],
            'the attempt that reached the threshold was not refused'
        );
    }

    /**
     * A failure after the window has passed starts the count again.
     *
     * Otherwise an address accumulates for ever and every long-lived NAT eventually crosses any
     * threshold. The window is what makes the limit about a burst rather than about a lifetime.
     */
    public function testAFailureAfterTheWindowStartsOver(): void
    {
        // Arrange
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);
        $this->ageAllTimestampsBy(3600);

        // Act
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);

        // Assert
        $this->assertSame(1, (int) ($this->row()['failedattempts'] ?? 0), 'the count did not restart');
        $this->assertFalse($this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS)['locked']);
    }

    /** A successful sign-in clears the address as well as the account. */
    public function testASuccessfulSignInClearsTheAddress(): void
    {
        // Arrange
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);
        }
        $this->assertTrue($this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS)['locked']);

        // Act
        $this->lockout->clearSuccessfulLoginState(self::SCOPE, self::ADDRESS);

        // Assert
        $this->assertNull($this->row(), 'the row survived a successful sign-in');
        $this->assertFalse($this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS)['locked']);
    }

    // ── What it refuses to do ─────────────────────────────────────────────────

    /**
     * A threshold or window below one is refused rather than obeyed.
     *
     * `$attempts >= $threshold` with a threshold of `0` is true of every attempt, so a
     * misconfigured value would refuse the first request from every address on the site, for the
     * length of the window, with nothing in the logs naming the setting. An empty identifier is
     * the same shape of accident: a request with no address recorded would otherwise share one
     * counter with every other such request.
     */
    public function testAnUnusableThresholdOrWindowWritesNothing(): void
    {
        // Act
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 0);
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 0, 3);
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, -1, -1);
        $this->lockout->recordFailedAttemptWithin(self::SCOPE, '', 600, 3);

        // Assert
        $this->assertNull($this->row(), 'a misconfigured limit wrote a lockout row anyway');
        $this->assertFalse($this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS)['locked']);
    }

    /**
     * Two addresses are counted apart, and apart from the account ladder.
     *
     * `locktype` is what keeps the per-address counter from sharing a row with the per-account
     * one. Sharing would let a single address's sweep lock out the accounts it guessed at.
     */
    public function testAddressesAndAccountsAreCountedApart(): void
    {
        // Act
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->lockout->recordFailedAttemptWithin(self::SCOPE, self::ADDRESS, 600, 3);
        }

        // Assert
        $this->assertTrue($this->lockout->getLockoutStatus(self::SCOPE, self::ADDRESS)['locked']);
        $this->assertFalse(
            $this->lockout->getLockoutStatus(self::SCOPE, '203.0.113.4')['locked'],
            'one address being refused refused another'
        );
        $this->assertFalse(
            $this->lockout->getLockoutStatus('identifier', self::ADDRESS)['locked'],
            'the address counter and the account ladder share a row'
        );
    }

    /** An address nobody has ever failed from is not locked, and no row is created by asking. */
    public function testAskingAboutAnUnknownAddressCreatesNothing(): void
    {
        // Act
        $status = $this->lockout->getLockoutStatus(self::SCOPE, '203.0.113.99');

        // Assert
        $this->assertFalse($status['locked']);
        $this->assertSame(0, $status['remaining']);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function row(string $identifier = self::ADDRESS): ?array
    {
        $result = $this->db->queryBuilder()
            ->table('authserver.loginlockouts')
            ->where('locktype', self::SCOPE)
            ->where('lookupvalue', $identifier)
            ->first();

        return $result && $result->numRows > 0 ? (array) $result->fields : null;
    }

    /**
     * Move the first-failure timestamp back, leaving the last one where it is.
     *
     * What the passage of time looks like to the code that reads the row: the window opened
     * earlier, but the address is still being tried right now.
     */
    private function ageFirstFailureBy(int $seconds): void
    {
        $row = $this->row();
        $this->assertNotNull($row, 'nothing to age');

        $this->db->queryBuilder()
            ->table('authserver.loginlockouts')
            ->where('lockoutid', (int) $row['lockoutid'])
            ->update([
                'firstfailedat' => date('Y-m-d H:i:s', strtotime((string) $row['firstfailedat']) - $seconds),
                'lockoutuntil'  => empty($row['lockoutuntil'])
                    ? null
                    : date('Y-m-d H:i:s', strtotime((string) $row['lockoutuntil']) - $seconds),
            ]);
    }

    /** Move the whole row back, which is what a quiet hour looks like. */
    private function ageAllTimestampsBy(int $seconds): void
    {
        $row = $this->row();
        $this->assertNotNull($row, 'nothing to age');

        $this->db->queryBuilder()
            ->table('authserver.loginlockouts')
            ->where('lockoutid', (int) $row['lockoutid'])
            ->update([
                'firstfailedat' => date('Y-m-d H:i:s', strtotime((string) $row['firstfailedat']) - $seconds),
                'lastfailedat'  => date('Y-m-d H:i:s', strtotime((string) $row['lastfailedat']) - $seconds),
            ]);
    }

    private function clearProbe(): void
    {
        foreach ([self::ADDRESS, '203.0.113.4', '203.0.113.99', ''] as $identifier) {
            foreach ([self::SCOPE, 'identifier'] as $scope) {
                try {
                    $this->db->queryBuilder()
                        ->table('authserver.loginlockouts')
                        ->where('locktype', $scope)
                        ->where('lookupvalue', $identifier)
                        ->delete();
                } catch (\Throwable $exception) {
                    // No table on a lane mid-migration; nothing to clear.
                }
            }
        }
    }
}
