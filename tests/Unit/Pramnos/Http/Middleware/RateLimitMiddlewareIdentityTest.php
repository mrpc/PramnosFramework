<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Http\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;
use Pramnos\Http\Middleware\RateLimitMiddleware;
use Pramnos\Http\Request;
use Pramnos\Http\TooManyRequestsException;

/**
 * `RateLimitMiddleware` counting something other than the address.
 *
 * An address is the wrong unit for an API: two keys behind one NAT — a mobile carrier, an
 * office — share one budget, and one key used from many addresses has none. `$identify` names
 * the identity to count and, optionally, that identity's own limit, which is how a key's tier
 * becomes part of what it buys without one middleware instance per tier.
 */
#[CoversClass(RateLimitMiddleware::class)]
class RateLimitMiddlewareIdentityTest extends TestCase
{
    /** The API key the "request" carries; what the identify callable reads. */
    private string $key = '';

    protected function setUp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $this->key = '';
    }

    /** A limiter counting by `$this->key`, falling back to the address when it is empty. */
    private function byKey(int $max, ?Cache $cache = null, ?\Closure $identify = null): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            $max,
            60,
            'rl:',
            $cache ?? new Cache(null, null, 'array'),
            $identify ?? fn (Request $r): string => $this->key
        );
    }

    /** Whether one more request is admitted. */
    private function admits(RateLimitMiddleware $limiter): bool
    {
        try {
            $limiter->handle($this->createMock(Request::class), fn () => 'ok');

            return true;
        } catch (TooManyRequestsException) {
            return false;
        }
    }

    /**
     * Two keys behind one address each get the whole budget.
     *
     * The NAT case: with the address as the bucket, the second key is refused for traffic the
     * first one sent.
     */
    public function testTwoKeysBehindOneAddressDoNotShareABudget(): void
    {
        // Arrange
        $limiter = $this->byKey(2);
        $this->key = 'key-a';
        $this->admits($limiter);
        $this->admits($limiter);

        // Act
        $this->key = 'key-b';
        $admitted = $this->admits($limiter);

        // Assert
        $this->assertTrue($admitted, 'the second key was charged for the first key\'s requests');
    }

    /**
     * One key used from many addresses has one budget.
     *
     * The other half: with the address as the bucket, spreading a key across addresses
     * multiplies its allowance.
     */
    public function testOneKeyFromManyAddressesSharesOneBudget(): void
    {
        // Arrange
        $limiter = $this->byKey(2);
        $this->key = 'key-a';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
        $this->admits($limiter);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.2';
        $this->admits($limiter);

        // Act
        $_SERVER['REMOTE_ADDR'] = '198.51.100.3';
        $admitted = $this->admits($limiter);

        // Assert
        $this->assertFalse($admitted, 'a new address reset the key\'s budget');
    }

    /**
     * No identity means the address, so an anonymous request is still limited.
     *
     * Null and the empty string both fall back — a request without a key must not be a request
     * without a limit.
     */
    public function testNoIdentityFallsBackToTheAddress(): void
    {
        // Arrange
        $limiter = $this->byKey(1);
        $this->admits($limiter);

        // Act
        $second = $this->admits($limiter);
        $viaNull = $this->admits($this->byKey(1, null, static fn (): ?string => null));

        // Assert
        $this->assertFalse($second, 'an empty identity was not limited by address');
        $this->assertTrue($viaNull, 'a null identity on a fresh limiter was refused');
    }

    /**
     * An identity equal to an address string does not share that address's bucket.
     *
     * Identities and addresses are namespaced apart, so a key literally named `198.51.100.7`
     * cannot be charged for — or spend — the anonymous traffic from that address.
     */
    public function testAnIdentityDoesNotCollideWithTheAddressBucket(): void
    {
        // Arrange — the anonymous budget from this address is spent
        $cache = new Cache(null, null, 'array');
        $limiter = $this->byKey(1, $cache);
        $this->admits($limiter);

        // Act
        $this->key = '198.51.100.7';
        $admitted = $this->admits($limiter);

        // Assert
        $this->assertTrue($admitted, 'an identity shared the address bucket of the same spelling');
    }

    /**
     * An identity may carry its own limit, and it applies to that identity only.
     *
     * A key's tier is a property of the key. The copy that carries the tier must not leak its
     * limit back into the middleware, or the next key would be counted at the last tier seen.
     */
    public function testATierLimitAppliesToItsIdentityOnly(): void
    {
        // Arrange — "gold" gets 3, everybody else the default 1
        $limiter = $this->byKey(1, null, fn (Request $r): array|string => $this->key === 'gold'
            ? ['gold', 3, 60]
            : $this->key);

        // Act
        $this->key = 'gold';
        $gold = [$this->admits($limiter), $this->admits($limiter), $this->admits($limiter), $this->admits($limiter)];
        $this->key = 'basic';
        $basic = [$this->admits($limiter), $this->admits($limiter)];

        // Assert
        $this->assertSame([true, true, true, false], $gold, 'the tier limit was not the one applied');
        $this->assertSame([true, false], $basic, 'the tier limit leaked into another identity');
    }

    /**
     * The tier limit is honoured on the atomic path too, window and all.
     *
     * Redis and Memcached count on the server; the counter's TTL is the window, so a tier's
     * `perSeconds` has to reach the key and the TTL, not only the comparison.
     */
    public function testATierLimitIsHonouredOnTheAtomicPath(): void
    {
        // Arrange
        $cache = new class extends Cache {
            /** @var array<string, int> */
            public array $counters = [];
            /** @var array<string, int> */
            public array $ttls = [];

            /** No adapter needed; the counter is this object. */
            public function __construct()
            {
            }

            /** Counts atomically, which is what selects the fixed-window path. */
            public function supportsAtomicCounter(): bool
            {
                return true;
            }

            /** Increment, recording the TTL the counter was created with. */
            public function increment($id, int $ttl)
            {
                $this->ttls[$id] ??= $ttl;

                return $this->counters[$id] = ($this->counters[$id] ?? 0) + 1;
            }
        };
        $limiter = $this->byKey(1, $cache, static fn (): array => ['gold', 2, 300]);

        // Act
        $results = [$this->admits($limiter), $this->admits($limiter), $this->admits($limiter)];

        // Assert
        $this->assertSame([true, true, false], $results);
        $this->assertSame([301], array_values($cache->ttls), 'the counter did not live for the tier\'s window');
    }

    /**
     * A malformed answer is refused rather than quietly counted by address.
     *
     * A limiter that fell back on a typo would look configured and count the wrong thing, which
     * is the failure nobody notices until the flood.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function malformedAnswers(): array
    {
        return [
            'an int'              => [42],
            'two elements'        => [['gold', 3]],
            'a zero limit'        => [['gold', 0, 60]],
            'a zero window'       => [['gold', 3, 0]],
            'an empty identity'   => [['', 3, 60]],
            'a string limit'      => [['gold', '3', 60]],
        ];
    }

    /**
     * Each malformed shape throws, naming the shapes that are accepted.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedAnswers')]
    public function testAMalformedAnswerIsRefused(mixed $answer): void
    {
        // Arrange
        $limiter = $this->byKey(5, null, static fn (): mixed => $answer);

        // Assert
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('$identify must return');

        // Act
        $this->admits($limiter);
    }
}
