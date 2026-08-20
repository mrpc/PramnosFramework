<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\User\Token;

/**
 * What a logged request records about itself.
 *
 * Two defects, reported together from a "slowest endpoints" report that had finally got
 * data in it and was still useless:
 *
 * ```
 * Endpoint                                              Method  Calls  Avg ms  Max ms
 * http://127.0.0.1/devpanel/logs?request=f768ff13af8a…  GET     1      0.0 ms  0.0 ms
 * http://127.0.0.1/devpanel/logs?request=8faf840bfe40…  GET     1      0.0 ms  0.0 ms
 * ```
 *
 * **Every duration was 0.** `addAction()` holds the row and `updateAction()` completes it
 * with the status and the duration — but only the API path calls `updateAction()`. A web
 * request is written by the shutdown flush, which wrote the held row exactly as it was
 * held: no duration, no status. Every page view ever logged.
 *
 * **Every URL was distinct.** `urls` is a deduplicated registry of endpoints and it was
 * given the absolute URL with the query string, so a page whose query carries an id gets
 * a row of its own on every call. Twenty rows of one call each is a registry with nothing
 * deduplicated in it and a report with nothing to compare.
 */
#[CoversClass(Token::class)]
class TokenActionRecordingTest extends TestCase
{
    /**
     * Split a URL the way `addAction()` does.
     *
     * @param string $url
     * @return array{0: string, 1: string}
     */
    private function split(string $url): array
    {
        return (new \ReflectionMethod(Token::class, 'splitActionUrl'))->invoke(null, $url);
    }

    /**
     * The endpoint is the path; the query travels separately.
     *
     * @return void
     */
    public function testTheEndpointIsThePathWithoutItsQuery(): void
    {
        // Act
        [$path, $query] = $this->split('http://127.0.0.1/devpanel/logs?request=f768ff13af8a7a24');

        // Assert
        $this->assertSame('/devpanel/logs', $path);
        $this->assertSame('request=f768ff13af8a7a24', $query);
    }

    /**
     * Two calls to one endpoint are two calls to one endpoint.
     *
     * The property the registry is for, and the one the report needs: the same page with
     * different query strings must resolve to the same row.
     *
     * @return void
     */
    public function testTwoQueriesOfOnePageShareAnEndpoint(): void
    {
        // Act
        [$first]  = $this->split('http://127.0.0.1/devpanel/logs?request=aaaa');
        [$second] = $this->split('https://example.com:8443/devpanel/logs?request=bbbb&x=1');

        // Assert — and the host and scheme are gone with the query
        $this->assertSame('/devpanel/logs', $first);
        $this->assertSame($first, $second);
    }

    /**
     * A URL with no query keeps its path and reports no query.
     *
     * @return void
     */
    public function testAPlainUrlHasNoQuery(): void
    {
        // Act
        [$path, $query] = $this->split('http://127.0.0.1/api/v1/stations');

        // Assert
        $this->assertSame('/api/v1/stations', $path);
        $this->assertSame('', $query);
    }

    /**
     * Something `parse_url()` cannot read is kept whole.
     *
     * An unparseable URL is still a fact about a request, and losing it to be tidy would
     * be a worse trade than a long row in the registry.
     *
     * @return void
     */
    public function testAnUnparseableUrlIsKept(): void
    {
        // Act
        [$path, $query] = $this->split('http://');

        // Assert
        $this->assertSame('http://', $path);
        $this->assertSame('', $query);
    }

    /**
     * "No inputs" is recognised in each of the shapes the request layer produces.
     *
     * A GET's `params` is `file_get_contents('php://input')` — empty — and a POST with no
     * fields is `json_encode([])`. Both mean the query string is the only input there is.
     *
     * @return void
     */
    public function testAnEmptyPayloadIsRecognised(): void
    {
        // Arrange
        $empty = new \ReflectionMethod(Token::class, 'looksEmpty');

        // Act + Assert
        $this->assertTrue($empty->invoke(null, ''));
        $this->assertTrue($empty->invoke(null, '   '));
        $this->assertTrue($empty->invoke(null, '[]'));
        $this->assertTrue($empty->invoke(null, '{}'));
        $this->assertTrue($empty->invoke(null, 'null'));

        // ...and a real body is not empty
        $this->assertFalse($empty->invoke(null, '{"station":42}'));
    }

    /**
     * The shutdown flush fills in the duration the request took.
     *
     * The regression test: a held row with no `execution_time_ms` must not reach the
     * spool without one. `lastActionTime` is set by `addAction()`; the flush is the moment
     * the request is over, which is the only moment that knows the answer.
     *
     * @return void
     */
    public function testTheShutdownFlushRecordsTheDuration(): void
    {
        // Arrange — a token holding a row, as addAction() leaves it
        $token = new SpyingToken();
        $token->holdAction(['tokenid' => 1, 'url' => '/x', 'method' => 'GET'], 120.0);

        // Act
        $token->flushPendingAction();

        // Assert — a real, positive duration was recorded
        $this->assertArrayHasKey('execution_time_ms', $token->spooled);
        $this->assertGreaterThan(0, $token->spooled['execution_time_ms']);
    }

    /**
     * "Do not record an outcome" survives the flush.
     *
     * A negative status is the caller saying the request happened and what it returned is
     * not to be logged. The flush fills in what it can see, so that decision has to be
     * distinguishable from "nobody has said yet" — an explicit null rather than an absent
     * key, and `array_key_exists()` rather than `isset()` when the flush looks.
     *
     * @return void
     */
    public function testADeliberatelyOmittedOutcomeIsNotFilledIn(): void
    {
        // Arrange — the row as updateAction() leaves it for a negative status
        $token = new SpyingToken();
        $token->holdAction(
            [
                'tokenid'           => 1,
                'url'               => '/x',
                'method'            => 'GET',
                'return_status'     => null,
                'execution_time_ms' => null,
            ],
            120.0
        );

        // Act
        $token->flushPendingAction();

        // Assert — both stay null; the request is logged, the outcome is not
        $this->assertNull($token->spooled['return_status']);
        $this->assertNull($token->spooled['execution_time_ms']);
    }

    /**
     * A duration that was already known is not overwritten.
     *
     * `updateAction()` fills the row in on the API path and flushes it; the flush must
     * report what that path measured rather than the time until shutdown.
     *
     * @return void
     */
    public function testAnAlreadyMeasuredDurationIsKept(): void
    {
        // Arrange
        $token = new SpyingToken();
        $token->holdAction(
            ['tokenid' => 1, 'url' => '/x', 'method' => 'GET', 'execution_time_ms' => 42.5],
            120.0
        );

        // Act
        $token->flushPendingAction();

        // Assert
        $this->assertSame(42.5, $token->spooled['execution_time_ms']);
    }
}

/**
 * A token whose spool write is captured instead of performed.
 */
class SpyingToken extends Token
{
    /** @var array<string, mixed> The row that would have been buffered */
    public array $spooled = [];

    /**
     * Put the token in the state `addAction()` leaves it in.
     *
     * @param  array<string, mixed> $row       The held row
     * @param  float                $startedAgo How long ago the action started, in ms
     * @return void
     */
    public function holdAction(array $row, float $startedAgo): void
    {
        $pending = new \ReflectionProperty(Token::class, 'pendingAction');
        $pending->setValue($this, $row);

        $started = new \ReflectionProperty(Token::class, 'lastActionTime');
        $started->setValue($this, (int) ((microtime(true) * 1000) - $startedAgo));
    }

    protected function appendActionRow(array $row): void
    {
        $this->spooled = $row;
    }
}
