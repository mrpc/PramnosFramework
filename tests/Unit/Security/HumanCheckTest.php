<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Pramnos\Security\HumanCheck;

/**
 * An in-memory cache with an atomic counter, standing in for Redis.
 *
 * Single use is the property that makes the whole check worth anything, and it
 * is the cache that enforces it, so the tests need a cache they can reason
 * about rather than whatever the environment provides.
 */
class CountingHumanCheckCache extends Cache
{
    /** @var array<string, int> Counters by key. */
    public array $counters = [];

    /** Constructed without the parent's adapter machinery. */
    public function __construct()
    {
    }

    /** This stand-in counts atomically. */
    public function supportsAtomicCounter(): bool
    {
        return true;
    }

    /** Increment and return in one step. */
    public function increment($id, int $ttl)
    {
        $this->counters[$id] = ($this->counters[$id] ?? 0) + 1;

        return $this->counters[$id];
    }
}

/**
 * A cache with no atomic counter — the Array and File adapters' shape.
 */
class PlainHumanCheckCache extends Cache
{
    /** @var array<string, string> Stored values by key. */
    public array $values = [];

    /** Constructed without the parent's adapter machinery. */
    public function __construct()
    {
    }

    /** No atomic counter, so the load-and-save fallback is used. */
    public function supportsAtomicCounter(): bool
    {
        return false;
    }

    /** @return string|false */
    public function load($id, $category = null, $timeout = null)
    {
        return $this->values[$id] ?? false;
    }

    /** @return bool */
    public function save($data = '', $id = null)
    {
        $this->values[(string) $id] = (string) $data;

        return true;
    }
}

/**
 * The proof-of-work human check.
 *
 * WHAT: does a challenge cost what it claims, refuse forgery, expire, and — the
 *       one that matters most — refuse to be spent twice?
 * WHY:  this is a security control on a public, unauthenticated write, and it
 *       is being built specifically *because* a CAPTCHA would be a control that
 *       appears to work and does not. It would be a poor joke to replace one
 *       with another. Each test below corresponds to a way the check could look
 *       like it was working while being free to bypass:
 *
 *       - unsigned difficulty  → the client asks for zero work
 *       - no expiry check      → one challenge is minted and used for ever
 *       - no single-use check  → one solve buys unlimited submissions
 *       - wrong hash boundary  → the advertised cost is not the real one
 */
class HumanCheckTest extends TestCase
{
    /**
     * Solve a challenge honestly, the way the JS worker does.
     *
     * Kept deliberately literal — searching a counter and testing each digest —
     * so the test proves the server accepts what an honest client actually
     * produces, rather than what a clever shortcut in the test produces.
     */
    private function solve(HumanCheck $check, string $challenge): string
    {
        $parts   = explode('.', $challenge);
        $payload = implode('.', array_slice($parts, 0, 3));
        $bits    = (int) $parts[1];

        for ($nonce = 0; $nonce < 5000000; $nonce++) {
            $candidate = base_convert((string) $nonce, 10, 36);
            if ($check->meetsDifficulty($payload, $candidate, $bits)) {
                return $candidate;
            }
        }

        $this->fail('no solution found — the difficulty is misconfigured for a test');
    }

    /**
     * A cheap check, so the tests solve in milliseconds rather than seconds.
     *
     * The difficulty floor is 4 bits, which is 16 expected hashes.
     */
    private function check(?Cache $cache = null, int $ms = 1, int $ttl = 600): HumanCheck
    {
        return new HumanCheck($ms, $ttl, 'test-secret-key', $cache ?? new CountingHumanCheckCache());
    }

    // ── The happy path ───────────────────────────────────────────────────────

    /**
     * An honestly solved challenge is accepted.
     */
    public function testAnHonestSolutionIsAccepted(): void
    {
        // Arrange
        $check     = $this->check();
        $challenge = $check->challenge();

        // Act
        $solution = $this->solve($check, $challenge['challenge']);

        // Assert
        $this->assertTrue($check->verify($challenge['challenge'], $solution));
    }

    /**
     * A challenge announces its own difficulty and expiry to the client.
     *
     * The client cannot solve what it cannot see; these fields are the contract
     * with the worker.
     */
    public function testAChallengeCarriesWhatTheClientNeeds(): void
    {
        // Act
        $challenge = $this->check()->challenge();

        // Assert
        $this->assertArrayHasKey('challenge', $challenge);
        $this->assertGreaterThanOrEqual(4, $challenge['difficulty']);
        $this->assertGreaterThan(time(), $challenge['expires']);
        $this->assertSame('sha256-leading-zero-bits', $challenge['algorithm']);
    }

    /**
     * Two challenges are never the same.
     *
     * Identical challenges would share a single-use record, so the second
     * visitor of any pair would be refused.
     */
    public function testChallengesAreUnique(): void
    {
        // Arrange
        $check = $this->check();

        // Act
        $first  = $check->challenge()['challenge'];
        $second = $check->challenge()['challenge'];

        // Assert
        $this->assertNotSame($first, $second);
    }

    // ── The bypasses ─────────────────────────────────────────────────────────

    /**
     * A solved challenge cannot be spent twice.
     *
     * The single most important test here. Without it, one solve buys unlimited
     * submissions and the check costs an attacker one unit of work in total,
     * which is indistinguishable from no check at all.
     */
    public function testASolvedChallengeCannotBeReplayed(): void
    {
        // Arrange
        $check     = $this->check();
        $challenge = $check->challenge();
        $solution  = $this->solve($check, $challenge['challenge']);

        // Act
        $first  = $check->verify($challenge['challenge'], $solution);
        $second = $check->verify($challenge['challenge'], $solution);

        // Assert
        $this->assertTrue($first, 'the first use is legitimate');
        $this->assertFalse($second, 'the replay must be refused');
    }

    /**
     * Replay is refused on caches without an atomic counter too.
     *
     * The fallback closes the replay window even though it cannot close the
     * race, and a caller on the File adapter should still not be replayable at
     * leisure.
     */
    public function testReplayIsRefusedOnANonAtomicCache(): void
    {
        // Arrange
        $check     = $this->check(new PlainHumanCheckCache());
        $challenge = $check->challenge();
        $solution  = $this->solve($check, $challenge['challenge']);

        // Act & Assert
        $this->assertTrue($check->verify($challenge['challenge'], $solution));
        $this->assertFalse($check->verify($challenge['challenge'], $solution));
    }

    /**
     * A client cannot lower its own difficulty.
     *
     * The difficulty travels in the challenge, so without a signature over it
     * an attacker rewrites it to the minimum — or to something the empty
     * solution satisfies — and the work disappears. The signature is checked
     * before anything else for exactly this reason.
     */
    public function testEditingTheDifficultyInvalidatesTheChallenge(): void
    {
        // Arrange
        $check     = $this->check(null, 300);
        $challenge = $check->challenge()['challenge'];
        $parts     = explode('.', $challenge);

        // Act — ask for one bit of work instead of sixteen
        $parts[1] = '1';
        $tampered = implode('.', $parts);
        $payload  = implode('.', array_slice($parts, 0, 3));

        // Find a nonce that satisfies the *reduced* difficulty, as an attacker
        // would, so the test fails for the right reason.
        $solution = '';
        for ($nonce = 0; $nonce < 1000; $nonce++) {
            $candidate = (string) $nonce;
            if ($check->meetsDifficulty($payload, $candidate, 1)) {
                $solution = $candidate;
                break;
            }
        }
        $this->assertNotSame('', $solution, 'precondition: the cheap solution exists');

        // Assert
        $this->assertFalse($check->verify($tampered, $solution));
    }

    /**
     * A challenge signed with another key is refused.
     *
     * Otherwise anyone able to guess the format mints their own challenges and
     * the server does no work-checking at all.
     */
    public function testAChallengeFromAnotherSecretIsRefused(): void
    {
        // Arrange
        $theirs    = new HumanCheck(1, 600, 'a-different-secret', new CountingHumanCheckCache());
        $ours      = $this->check();
        $challenge = $theirs->challenge()['challenge'];
        $solution  = $this->solve($theirs, $challenge);

        // Assert
        $this->assertFalse($ours->verify($challenge, $solution));
    }

    /**
     * An expired challenge is refused even when correctly solved.
     *
     * Without this a single challenge is minted once and reused indefinitely,
     * and the expiry printed in the response is decoration.
     */
    public function testAnExpiredChallengeIsRefused(): void
    {
        // Arrange — a challenge that expired a second ago
        $check     = $this->check(null, 1, -1);
        $challenge = $check->challenge();
        $solution  = $this->solve($check, $challenge['challenge']);

        // Assert
        $this->assertFalse($check->verify($challenge['challenge'], $solution));
    }

    /**
     * A wrong solution is refused, and does not spend the challenge.
     *
     * A client whose answer was wrong should be able to try again with the
     * challenge it already holds — spending it on failure would turn a bug in
     * the worker into a lockout.
     */
    public function testAWrongSolutionIsRefusedWithoutSpendingTheChallenge(): void
    {
        // Arrange
        $check     = $this->check();
        $challenge = $check->challenge();

        // Act
        $rejected = $check->verify($challenge['challenge'], 'definitely-not-a-solution');
        $solution = $this->solve($check, $challenge['challenge']);

        // Assert
        $this->assertFalse($rejected);
        $this->assertTrue($check->verify($challenge['challenge'], $solution), 'a retry must still work');
    }

    /**
     * Malformed input is refused rather than reaching the hash.
     *
     * @param string $challenge
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedChallenges')]
    public function testMalformedChallengesAreRefused(string $challenge): void
    {
        // Assert
        $this->assertFalse($this->check()->verify($challenge, 'x'));
    }

    /**
     * Shapes a client might send that are not challenges.
     *
     * @return array<string, array{string}>
     */
    public static function malformedChallenges(): array
    {
        return [
            'empty'          => [''],
            'no separators'  => ['nonsense'],
            'too few fields' => ['a.b.c'],
            'too many'       => ['a.b.c.d.e'],
        ];
    }

    /**
     * An empty or absurd solution is refused.
     *
     * The empty string is the first thing an attacker tries, and an unbounded
     * one is how they turn a verification into a memory cost.
     */
    public function testEmptyAndOversizedSolutionsAreRefused(): void
    {
        // Arrange
        $check     = $this->check();
        $challenge = $check->challenge()['challenge'];

        // Assert
        $this->assertFalse($check->verify($challenge, ''));
        $this->assertFalse($check->verify($challenge, str_repeat('a', 65)));
    }

    // ── The cost ─────────────────────────────────────────────────────────────

    /**
     * Difficulty tracks the millisecond budget, and stays within its bounds.
     *
     * The unit is what a caller reasons about — "about 300ms on a mid-range
     * phone" — so it has to actually move the difficulty, and it has to be
     * clamped so that a mistyped configuration cannot ask a phone for hours of
     * work.
     */
    public function testDifficultyFollowsTheTimeBudgetWithinBounds(): void
    {
        // Assert — more time means more bits
        $this->assertGreaterThan(
            (new HumanCheck(100, 600, 'k'))->bits(),
            (new HumanCheck(1000, 600, 'k'))->bits()
        );

        // A sane default is a handful of bits, not a lock-up
        $this->assertGreaterThanOrEqual(4, (new HumanCheck(300, 600, 'k'))->bits());
        $this->assertLessThanOrEqual(26, (new HumanCheck(300, 600, 'k'))->bits());

        // Clamped at both ends
        $this->assertSame(4, (new HumanCheck(0, 600, 'k'))->bits());
        $this->assertSame(26, (new HumanCheck(PHP_INT_MAX, 600, 'k'))->bits());
    }

    /**
     * The difficulty boundary is counted in bits, exactly.
     *
     * If this is off by one the advertised cost is either half or double what
     * was asked for. The two cases below bracket the boundary inside a byte,
     * which is where an implementation counting whole bytes would be wrong.
     */
    public function testTheDifficultyBoundaryIsCountedInBits(): void
    {
        // Arrange — a digest whose first byte is 0x0F: four leading zero bits
        $check = $this->check();
        $found = null;
        for ($i = 0; $i < 5000000; $i++) {
            $digest = hash('sha256', 'p:' . $i, true);
            if (ord($digest[0]) === 0x0F) {
                $found = (string) $i;
                break;
            }
        }
        $this->assertNotNull($found, 'precondition: a 0x0F-prefixed digest exists');

        // Assert — exactly four bits, not five, and not a whole zero byte
        $this->assertTrue($check->meetsDifficulty('p', $found, 4));
        $this->assertFalse($check->meetsDifficulty('p', $found, 5));
        $this->assertFalse($check->meetsDifficulty('p', $found, 8));
    }

    /**
     * Without an explicit secret the application's security salt is used.
     *
     * That is how this will actually be constructed — `new HumanCheck()` — so
     * the default key path has to work end to end, not merely not crash.
     */
    public function testTheApplicationSecuritySaltIsUsedByDefault(): void
    {
        // Arrange
        $original = \Pramnos\Application\Settings::getSetting('securitySalt');
        \Pramnos\Application\Settings::setSetting('securitySalt', 'a-configured-salt', false);

        try {
            $check     = new HumanCheck(1, 600, null, new CountingHumanCheckCache());
            $challenge = $check->challenge();

            // Act
            $solution = $this->solve($check, $challenge['challenge']);

            // Assert — a challenge minted with the salt verifies against it
            $this->assertTrue($check->verify($challenge['challenge'], $solution));

            // …and a check holding a different key rejects it, which proves the
            // salt was actually the key rather than something ignored.
            $other = new HumanCheck(1, 600, 'something-else', new CountingHumanCheckCache());
            $this->assertFalse($other->verify($challenge['challenge'], $solution));
        } finally {
            \Pramnos\Application\Settings::setSetting(
                'securitySalt',
                is_string($original) ? $original : '',
                false
            );
        }
    }

    /**
     * With no security salt configured the check fails closed.
     *
     * The fallback is a per-process random key, so challenges do not verify
     * across processes and submissions are refused. An unconfigured
     * installation rejecting everything is recoverable; one accepting forged
     * challenges is not.
     */
    public function testWithoutASaltTheKeyIsNotPredictable(): void
    {
        // Arrange
        $original = \Pramnos\Application\Settings::getSetting('securitySalt');
        \Pramnos\Application\Settings::setSetting('securitySalt', '', false);

        try {
            // Act — two instances, each falling back to its own random key
            $first  = new HumanCheck(1, 600, null, new CountingHumanCheckCache());
            $second = new HumanCheck(1, 600, null, new CountingHumanCheckCache());

            $challenge = $first->challenge();
            $solution  = $this->solve($first, $challenge['challenge']);

            // Assert
            $this->assertTrue($first->verify($challenge['challenge'], $solution));
            $this->assertFalse(
                $second->verify($challenge['challenge'], $solution),
                'an unconfigured installation must not share a guessable key'
            );
        } finally {
            \Pramnos\Application\Settings::setSetting(
                'securitySalt',
                is_string($original) ? $original : '',
                false
            );
        }
    }

    /**
     * Zero difficulty is not a free pass.
     *
     * A caller that computed a difficulty of zero has misconfigured something;
     * accepting everything silently would be the failure mode this class exists
     * to avoid.
     */
    public function testZeroDifficultyAcceptsNothing(): void
    {
        // Assert
        $this->assertFalse($this->check()->meetsDifficulty('p', 'x', 0));
    }
}
