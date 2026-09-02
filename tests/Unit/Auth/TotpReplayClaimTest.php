<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\TwoFactorAuthService;
use Pramnos\Cache\Cache;

/**
 * Making a TOTP code single-use, and the three ways that protection stands down.
 *
 * A six-digit code is valid for its 30-second window plus the drift the verifier accepts either
 * side — about 90 seconds — and within that window it verifies every time it is presented. So
 * anybody who can see one submission can replay it: a proxy, a shared screen, a phishing page that
 * forwards what it was given. `claimCode()` is what makes the first presentation the only one.
 *
 * It had never executed. The call site had, seven times over — and always with the feature off,
 * because `SecurityPolicy::cachesTotpReplays()` is not enabled by default. Which is the first thing
 * worth recording: **a TOTP code is replayable unless an installation turns this on.**
 *
 * The rest is what happens when it *is* on, and the answer is that it fails open in three separate
 * places: a cache with no atomic counter, a counter that answers `false`, and any exception at all.
 * The reasoning is in the method — refusing every login while Redis is down is a larger failure
 * than a 90-second replay window — and it is a deliberate trade rather than an oversight. It does
 * mean the protection is best-effort, which is not what "single-use" sounds like.
 */
#[CoversClass(TwoFactorAuthService::class)]
class TotpReplayClaimTest extends TestCase
{
    /**
     * A service whose replay store is the given adapter, and nothing shared.
     *
     * The first version of this swapped the adapter on `Cache::getInstance('auth')`, which is
     * process-wide: it left the shared cache in a state every later test inherited and cost the
     * suite ten seconds. `authCache()` exists so this can own its store instead.
     */
    private function serviceUsing(?object $adapter): object
    {
        $cache = new \Pramnos\Cache\Cache();
        (new \ReflectionProperty(\Pramnos\Cache\Cache::class, 'adapter'))->setValue($cache, $adapter);

        $database = $this->getMockBuilder(\Pramnos\Database\Database::class)
            ->disableOriginalConstructor()
            ->getMock();

        return new class ($database, $cache) extends TwoFactorAuthService {
            public function __construct($database, private readonly Cache $cache)
            {
                parent::__construct($database);
            }

            protected function authCache(): Cache
            {
                return $this->cache;
            }
        };
    }

    /** Claims a code, through the private method the verifier uses. */
    private function claimWith(object $service, int $userId, string $code): bool
    {
        return (new \ReflectionMethod(TwoFactorAuthService::class, 'claimCode'))
            ->invoke($service, $userId, $code);
    }

    /**
     * The first presentation of a code is claimed; the second is refused.
     *
     * The whole feature, in one test. A counting store answers `1` to the first `increment()` and
     * `2` to the second, and only `1` means "nobody has used this".
     */
    public function testTheFirstPresentationWinsAndTheSecondIsRefused(): void
    {
        // Arrange
        $service = $this->serviceUsing(new CountingAdapter());

        // Act + Assert
        $this->assertTrue($this->claimWith($service, 42, '123456'), 'the first use of a code was refused');
        $this->assertFalse($this->claimWith($service, 42, '123456'), 'the code was accepted twice');
    }

    /**
     * The claim is per account, so two people holding the same digits do not collide.
     *
     * Six digits and a 30-second window: two accounts producing `123456` at the same moment is
     * unlikely but entirely possible, and a key without the account in it would sign one of them
     * out of their own login.
     */
    public function testTheClaimIsPerAccount(): void
    {
        // Arrange
        $service = $this->serviceUsing(new CountingAdapter());

        // Act + Assert
        $this->assertTrue($this->claimWith($service, 42, '123456'));
        $this->assertTrue($this->claimWith($service, 43, '123456'), 'another account was blocked by the same digits');
    }

    /**
     * A different code from the same account is a different claim.
     *
     * The counterpart: a key made only of the account would refuse the second factor of anybody
     * signing in twice in a minute — which is what a failed first attempt looks like.
     */
    public function testADifferentCodeIsADifferentClaim(): void
    {
        // Arrange
        $service = $this->serviceUsing(new CountingAdapter());

        // Act + Assert
        $this->assertTrue($this->claimWith($service, 42, '123456'));
        $this->assertTrue($this->claimWith($service, 42, '654321'));
    }

    /**
     * The code is never the key — its hash is.
     *
     * A cache dump, a `MONITOR` on Redis or a slow-log entry would otherwise contain live second
     * factors for the ninety seconds they are worth something.
     */
    public function testTheStoredKeyDoesNotContainTheCode(): void
    {
        // Arrange
        $adapter = new CountingAdapter();
        $service = $this->serviceUsing($adapter);

        // Act
        $this->claimWith($service, 42, '123456');

        // Assert
        $this->assertNotEmpty($adapter->keys);
        foreach ($adapter->keys as $key) {
            $this->assertStringNotContainsString('123456', $key, 'the live code is in the cache key');
        }
        $this->assertStringContainsString(hash('sha256', '123456'), $adapter->keys[0]);
    }

    /**
     * The claim expires with the window the code is valid in.
     *
     * 90 seconds: the current 30-second window plus the drift accepted either side. Shorter and a
     * code becomes replayable while it would still verify; much longer and nothing breaks, but the
     * store keeps entries that cannot matter.
     */
    public function testTheClaimLivesAsLongAsTheCodeCould(): void
    {
        // Arrange
        $adapter = new CountingAdapter();
        $service = $this->serviceUsing($adapter);

        // Act
        $this->claimWith($service, 42, '123456');

        // Assert
        $this->assertSame(90, $adapter->ttls[0] ?? null);
    }

    /**
     * A cache that cannot count atomically stands down rather than guessing.
     *
     * A read-modify-write would lose claims exactly when it matters — two requests read `0`, both
     * write `1`, and both are the first — so a store without a real counter is no protection at
     * all, and pretending otherwise would be worse than admitting it.
     */
    public function testACacheWithoutAnAtomicCounterStandsDown(): void
    {
        // Arrange
        $service = $this->serviceUsing(new NonCountingAdapter());

        // Act + Assert — the same code twice, both allowed
        $this->assertTrue($this->claimWith($service, 42, '123456'));
        $this->assertTrue($this->claimWith($service, 42, '123456'), 'the second use should also be allowed');
    }

    /**
     * A counter that answers `false` stands down too.
     *
     * `false` is what every adapter here returns when the server is unreachable mid-request. The
     * trade is stated in the method: refusing every login while Redis is down is a larger failure
     * than a 90-second replay window.
     */
    public function testACounterThatCannotAnswerStandsDown(): void
    {
        // Arrange
        $service = $this->serviceUsing(new FailingCounterAdapter());

        // Act + Assert
        $this->assertTrue($this->claimWith($service, 42, '123456'));
        $this->assertTrue($this->claimWith($service, 42, '123456'));
    }

    /**
     * And a counter that raises stands down, with a line in the log.
     *
     * The outermost of the three, catching anything the other two do not — a serialisation error,
     * a connection reset mid-call. Same trade, and the same reason it is a trade rather than a
     * bug.
     */
    public function testACounterThatRaisesStandsDown(): void
    {
        // Arrange
        $service = $this->serviceUsing(new RaisingAdapter());

        // Act + Assert
        $this->assertTrue($this->claimWith($service, 42, '123456'));
    }

    /**
     * With no cache at all, nothing is claimed and nothing is refused.
     *
     * The state a fresh installation is in before a cache is configured, and it must not be a
     * sign-in outage.
     */
    public function testWithNoCacheNothingIsRefused(): void
    {
        // Arrange
        $service = $this->serviceUsing(null);

        // Act + Assert
        $this->assertTrue($this->claimWith($service, 42, '123456'));
        $this->assertTrue($this->claimWith($service, 42, '123456'));
    }
}

/** A store that counts, which is the only kind this protection can use. */
class CountingAdapter
{
    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $keys = [];

    /** @var list<int> */
    public array $ttls = [];

    public function supportsAtomicCounter(): bool
    {
        return true;
    }

    public function increment($key, $by = 1, $ttl = null, $slidingExpiry = false)
    {
        // `Cache::increment($id, $ttl)` calls `$adapter->increment($name, 1, $ttl)` — the TTL is
        // the third argument, and the second is the amount, which is always 1 here.
        $this->keys[] = (string) $key;
        $this->ttls[] = (int) $ttl;

        $this->counts[$key] = ($this->counts[$key] ?? 0) + 1;

        return $this->counts[$key];
    }
}

/** A store with no atomic counter — a file cache, say. */
class NonCountingAdapter extends CountingAdapter
{
    public function supportsAtomicCounter(): bool
    {
        return false;
    }
}

/** A counting store that cannot reach its server. */
class FailingCounterAdapter extends CountingAdapter
{
    public function increment($key, $by = 1, $ttl = null, $slidingExpiry = false)
    {
        return false;
    }
}

/** A counting store that raises. */
class RaisingAdapter extends CountingAdapter
{
    public function increment($key, $by = 1, $ttl = null, $slidingExpiry = false)
    {
        throw new \RuntimeException('the connection went away');
    }
}
