<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Debug\RequestId;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\FeatureRegistry;
use Pramnos\Debug\ApiDebugPayload;
use Pramnos\Debug\Collectors\CollectorInterface;
use Pramnos\Debug\Collectors\ExceptionsCollector;
use Pramnos\Debug\DebugBar;

/**
 * A collector that returns whatever it was given.
 */
class FakeCollector implements CollectorInterface
{
    /**
     * @param string               $name Collector name
     * @param array<string, mixed> $data What collect() returns
     */
    public function __construct(private string $name, private array $data) {}

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function collect(): array
    {
        return $this->data;
    }
}

/**
 * A collector that fails, as one might on a half-initialised request.
 */
class BrokenCollector implements CollectorInterface
{
    public function name(): string
    {
        return 'broken';
    }

    /** @return array<string, mixed> */
    public function collect(): array
    {
        throw new \RuntimeException('collector exploded');
    }
}

/**
 * Covers the debug data a JSON API carries.
 *
 * The HTML toolbar is injected before `</body>`; a JSON response has none, and a
 * SPA's page is a static shell that never reaches that middleware — so
 * single-page applications had no debug information at all, for exactly the
 * requests that do the work. The data now travels with the response it
 * describes, which is only correct if it is strictly absent in production and
 * incapable of breaking the response it annotates.
 */
#[CoversClass(ApiDebugPayload::class)]
class ApiDebugPayloadTest extends TestCase
{
    protected function setUp(): void
    {
        // Request ids are process-wide and change Logger's output shape while
        // active. A test that activated them must not decide how another test's
        // log lines are written — which is exactly what happened once.
        RequestId::reset();
        parent::setUp();
        DebugBar::reset();
        ApiDebugPayload::resetHeaderState();
    }

    protected function tearDown(): void
    {
        RequestId::reset();
        DebugBar::reset();
        ApiDebugPayload::resetHeaderState();
        parent::tearDown();
    }

    /**
     * With no toolbar there is nothing to attach.
     *
     * This is the production path, and the whole safety argument: collectors are
     * registered only by DebugBarServiceProvider, which only boots in debug
     * mode. Asking the toolbar rather than re-reading APP_DEBUG keeps one
     * definition of "development" instead of two that can drift apart.
     */
    public function testDisabledWhenNoCollectorsAreRegistered(): void
    {
        // Act + Assert
        $this->assertFalse(ApiDebugPayload::isEnabled());
    }

    /**
     * A registered collector means the toolbar is active for this request.
     */
    public function testEnabledOnceACollectorIsRegistered(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));

        // Act + Assert
        $this->assertTrue(ApiDebugPayload::isEnabled());
    }

    /**
     * The payload carries the numbers a developer opens the panel for, plus
     * whatever each collector gathered.
     */
    public function testPayloadCarriesTimingMemoryAndCollectorData(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('route', ['name' => 'thing.list']));

        // Act
        $payload = ApiDebugPayload::build();

        // Assert
        $this->assertArrayHasKey('time', $payload);
        $this->assertArrayHasKey('memory', $payload);
        $this->assertIsFloat($payload['time']);
        $this->assertSame(['name' => 'thing.list'], $payload['route']);
    }

    /**
     * A failing collector must not take the API response down with it.
     *
     * Instrumentation is never a good reason for a request to fail, so the
     * failure is reported inside the payload instead of thrown.
     */
    public function testABrokenCollectorIsReportedNotThrown(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new BrokenCollector());

        // Act
        $payload = ApiDebugPayload::build();

        // Assert
        $this->assertSame(['error' => 'collector exploded'], $payload['broken']);
    }

    /**
     * The queries list is capped, and says so.
     *
     * A page that ran hundreds of queries would otherwise attach a payload
     * heavier than the response it annotates — while the count, which is what
     * actually reveals an N+1, is kept intact.
     */
    public function testQueryListIsCappedButTheCountSurvives(): void
    {
        // Arrange — more queries than the cap
        $queries = array_fill(0, 150, ['sql' => 'SELECT 1', 'time' => 0.1]);
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', ['queries' => $queries]));

        // Act
        $payload = ApiDebugPayload::build();

        // Assert
        $this->assertSame(150, $payload['queries']['count'], 'the real count is what matters');
        $this->assertCount(100, $payload['queries']['queries']);
        $this->assertSame(50, $payload['queries']['truncated'], 'and the cut is stated, not silent');
    }

    /**
     * A short query list is passed through untouched.
     */
    public function testShortQueryListIsNotTruncated(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', [
            'queries' => [['sql' => 'SELECT 1']],
        ]));

        // Act
        $payload = ApiDebugPayload::build();

        // Assert
        $this->assertSame(1, $payload['queries']['count']);
        $this->assertArrayNotHasKey('truncated', $payload['queries']);
    }

    /**
     * Server-Timing is the channel that needs no front-end code and works even
     * for responses with no body at all.
     */
    public function testServerTimingReportsDurationAndQueryCount(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', [
            'queries' => [['sql' => 'SELECT 1'], ['sql' => 'SELECT 2']],
        ]));

        // Act
        $header = ApiDebugPayload::serverTiming();

        // Assert
        $this->assertStringContainsString('app;dur=', $header);
        $this->assertStringContainsString('db;desc="2 queries"', $header);
    }

    // -------------------------------------------------------------------------
    // summary() — what a response with no body can still say
    // -------------------------------------------------------------------------

    /**
     * The summary is counts and timings, and small enough to be a header.
     *
     * A 204, a redirect, an HTML fragment — none of them has anywhere to put a
     * `_debug` key, and those are ordinary shapes for the calls a page makes
     * after it has rendered. This is what the AJAX panel shows for them.
     */
    public function testTheSummaryCarriesCountsAndTimings(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', [
            'queries' => [['sql' => 'SELECT 1'], ['sql' => 'SELECT 2']],
        ]));
        DebugBar::getInstance()->addCollector(new FakeCollector('route', [
            'controller' => 'orders',
            'action'     => 'list',
        ]));

        // Act
        $summary = json_decode(ApiDebugPayload::summary(), true);

        // Assert
        $this->assertSame(2, $summary['queries']);
        $this->assertSame('orders/list', $summary['route']);
        $this->assertArrayHasKey('time', $summary);
        $this->assertArrayHasKey('memory', $summary);
    }

    /**
     * The summary never carries the statements themselves.
     *
     * A header is written to the web server's access log and to every proxy in
     * front of it. Query text there would put customer data in files nobody
     * treats as sensitive — the reason this is a summary rather than the payload.
     */
    public function testTheSummaryDoesNotCarryQueryText(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', [
            'queries' => [['sql' => "SELECT * FROM users WHERE email = 'a@b.c'"]],
        ]));

        // Act
        $summary = ApiDebugPayload::summary();

        // Assert
        $this->assertStringNotContainsString('SELECT', $summary);
        $this->assertStringNotContainsString('a@b.c', $summary);
    }

    /**
     * A query collector reporting something unexpected is passed through.
     *
     * Collectors are replaceable, and one that reports `queries` as anything but
     * a list should not make the summary raise — it should simply have nothing
     * to say about queries.
     */
    public function testAQueryCollectorWithUnexpectedShapeIsToleratedd(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', [
            'queries' => 'not a list at all',
        ]));

        // Act
        $summary = json_decode(ApiDebugPayload::summary(), true);

        // Assert
        $this->assertArrayNotHasKey('queries', $summary);
        $this->assertArrayHasKey('time', $summary, 'The rest of the summary survives');
    }

    /**
     * Errors are counted into the summary — from the shape the real collector
     * actually emits.
     *
     * A row in the requests list showing a 200 that quietly raised three
     * warnings is the whole reason to look at a request nobody reported a
     * problem with. And when a request dies, the header is the *only* channel
     * left: an error page is not a JSON object, so it cannot carry a payload.
     *
     * This used the real ExceptionsCollector rather than a fake for a reason.
     * The counting looked for `exceptions` / `errors` keys, which that collector
     * has never emitted — it reports `count` and `items` — so the summary said
     * nothing about a request that threw, and a test built on an invented shape
     * kept saying it did.
     */
    public function testErrorsAreCountedIntoTheSummary(): void
    {
        // Arrange
        $collector = new ExceptionsCollector();
        $collector->record(new \RuntimeException('Undefined array key'));
        $collector->recordPhpError(E_WARNING, 'Deprecated', '/x.php', 12);
        DebugBar::getInstance()->addCollector($collector);

        // Act
        $summary = json_decode(ApiDebugPayload::summary(), true);

        // Assert
        $this->assertSame(2, $summary['errors']);
    }

    /**
     * With nothing wrong, the summary says nothing about errors.
     *
     * A key that is always present but usually zero trains the reader to ignore
     * it, which is the opposite of what it is for.
     */
    public function testACleanRequestReportsNoErrorKey(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new ExceptionsCollector());

        // Act
        $summary = json_decode(ApiDebugPayload::summary(), true);

        // Assert
        $this->assertArrayNotHasKey('errors', $summary);
    }

    /**
     * A header value can contain no newline, whatever a collector reported.
     *
     * A collector that picks up a route or a message with a CR/LF in it would
     * otherwise let that string terminate the header and start another one —
     * header injection, from instrumentation.
     */
    public function testTheSummaryIsFreeOfNewlines(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('route', [
            'controller' => "orders\r\nX-Injected: yes",
            'action'     => 'list',
        ]));

        // Act
        $summary = ApiDebugPayload::summary();

        // Assert — json_encode escapes them; nothing raw survives
        $this->assertStringNotContainsString("\r", $summary);
        $this->assertStringNotContainsString("\n", $summary);
    }

    // -------------------------------------------------------------------------
    // attachTo() — the central JSON path
    // -------------------------------------------------------------------------

    /**
     * A JSON object gets the payload.
     *
     * This is what makes the toolbar see datatable endpoints and controllers
     * that echo their own JSON — everything that never goes near the API layer.
     */
    public function testAJsonObjectGetsThePayload(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));

        // Act
        $body = ApiDebugPayload::attachTo('{"data":[1,2,3]}');

        // Assert
        $decoded = json_decode($body, true);
        $this->assertSame([1, 2, 3], $decoded['data'], 'The response is unchanged');
        $this->assertArrayHasKey('_debug', $decoded);
    }

    /**
     * A body that already carries a payload is left alone.
     *
     * The API layer attaches its own before this runs; rebuilding it would
     * double the work and report the time spent doing the first one.
     */
    public function testAnExistingPayloadIsNotRebuilt(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));

        // Act
        $body = ApiDebugPayload::attachTo('{"data":1,"_debug":{"mine":true}}');

        // Assert
        $this->assertSame(['mine' => true], json_decode($body, true)['_debug']);
    }

    /**
     * Anything that is not a JSON object comes back untouched.
     *
     * HTML, a top-level array, a plain string, an empty body: none has anywhere
     * to put the key, and mangling a response to annotate it would be worse than
     * not annotating it.
     */
    public function testNonObjectBodiesAreUntouched(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));

        foreach (['<html></html>', '[1,2,3]', '"a string"', '', 'not json at all', '{broken'] as $body) {
            // Act & Assert
            $this->assertSame($body, ApiDebugPayload::attachTo($body), 'body: ' . $body);
        }
    }

    /**
     * With the toolbar off, nothing is attached to anything.
     *
     * The production path: no collectors, so a JSON response is exactly what the
     * controller produced.
     */
    public function testNothingIsAttachedWhenTheToolbarIsOff(): void
    {
        // Act & Assert — no collector registered in this test
        $this->assertSame('{"data":1}', ApiDebugPayload::attachTo('{"data":1}'));
    }

    /**
     * A second call is refused once the headers have gone out.
     *
     * The API layer sends them as it builds a reply, and the output-buffer
     * callback offers again for every response — including that one.
     * `header('Server-Timing: …', false)` appends rather than replaces, so
     * without this guard a JSON API reply would carry the same timing twice.
     *
     * The guard flag is read directly because under CLI no header is ever
     * emitted, so it is the only observable evidence of the decision.
     */
    public function testASecondCallIsRefusedOnceTheHeadersHaveGoneOut(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));
        $flag = new \ReflectionProperty(ApiDebugPayload::class, 'headersSent');

        // Act — simulate a response whose headers were already emitted
        $flag->setValue(null, true);
        ApiDebugPayload::sendHeaders();

        // Assert
        $this->assertTrue($flag->getValue(), 'The flag was not disturbed by a refused call');

        // Act — and a reset makes it willing again, which is what a worker
        // serving a second request in one PHP lifetime needs.
        ApiDebugPayload::resetHeaderState();

        // Assert
        $this->assertFalse($flag->getValue());
    }

    /**
     * An annotated response tells caches not to share it.
     *
     * The one with real consequences. On a live server the toolbar is open for
     * one browser while everybody else gets the same URLs, and a shared cache in
     * front of the application cannot tell them apart — a cached body with a
     * `_debug` key would hand one browser's query log to the next visitor.
     */
    public function testAnAnnotatedResponseIsNotCacheable(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));

        // Act
        $lines = array_column(ApiDebugPayload::headerLines(), 0);

        // Assert
        $this->assertContains('Vary: Cookie', $lines);
        $this->assertNotEmpty(preg_grep('/^Server-Timing: /', $lines));
        $this->assertNotEmpty(preg_grep('/^X-Pramnos-Debug: /', $lines));
    }

    /**
     * A token-granted response is additionally marked no-store.
     *
     * `Vary: Cookie` is the correct statement, but `no-store` is the one every
     * intermediary actually obeys — and a token grant means this is happening on
     * a server that is not a development one, where a cache in front of the
     * application is likely rather than hypothetical.
     */
    public function testATokenGrantedResponseIsMarkedNoStore(): void
    {
        // Arrange — a live grant, as a redeemed token would leave it
        $originalKey = getenv('APP_KEY') === false ? null : (string) getenv('APP_KEY');
        putenv('APP_KEY=test-key-for-payload');
        $_ENV['APP_KEY'] = 'test-key-for-payload';
        \Pramnos\Debug\DebugAccess::reset();
        $_COOKIE[\Pramnos\Debug\DebugAccess::COOKIE] = \Pramnos\Debug\DebugAccess::issue(3600);

        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));

        try {
            // Act
            $lines = array_column(ApiDebugPayload::headerLines(), 0);

            // Assert
            $this->assertNotEmpty(
                preg_grep('/^Cache-Control: no-store/', $lines),
                'A live debug response must not be cacheable by anything'
            );
        } finally {
            unset($_COOKIE[\Pramnos\Debug\DebugAccess::COOKIE]);
            \Pramnos\Debug\DebugAccess::reset();
            if ($originalKey === null) {
                putenv('APP_KEY');
                unset($_ENV['APP_KEY']);
            } else {
                putenv('APP_KEY=' . $originalKey);
                $_ENV['APP_KEY'] = $originalKey;
            }
        }
    }

    /**
     * Without a token grant there is no no-store header.
     *
     * In development every response would carry it for nothing, and `no-store`
     * on every JSON response is the kind of thing that quietly changes how a
     * front end behaves.
     */
    public function testADevelopmentResponseIsNotMarkedNoStore(): void
    {
        // Arrange — no cookie, no token
        \Pramnos\Debug\DebugAccess::reset();
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));

        // Act
        $lines = array_column(ApiDebugPayload::headerLines(), 0);

        // Assert
        $this->assertEmpty(preg_grep('/^Cache-Control:/', $lines));
        $this->assertContains('Vary: Cookie', $lines, 'but it still declares the Vary');
    }

    /**
     * Server-Timing appends; the debug header replaces.
     *
     * Multiple `Server-Timing` headers are valid and additive, so appending is
     * right there. A second `X-Pramnos-Debug` would be ambiguous, so it must
     * overwrite.
     */
    public function testTheReplaceFlagsMatchWhatEachHeaderMeans(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));

        // Act
        $flags = [];
        foreach (ApiDebugPayload::headerLines() as [$line, $replace]) {
            $flags[explode(':', $line, 2)[0]] = $replace;
        }

        // Assert
        $this->assertFalse($flags['Server-Timing']);
        $this->assertTrue($flags['X-Pramnos-Debug']);
        $this->assertFalse($flags['Vary']);
    }

    /**
     * Under CLI the guard is never burned.
     *
     * Nothing is emitted there, so marking the response as "headers sent" would
     * mean a queue worker that renders a response in-process could never send
     * them later.
     */
    public function testTheGuardIsNotBurnedWhenNothingWasSent(): void
    {
        // Arrange
        DebugBar::getInstance()->addCollector(new FakeCollector('queries', []));
        $flag = new \ReflectionProperty(ApiDebugPayload::class, 'headersSent');

        // Act — PHPUnit always runs under CLI, so this sends nothing
        ApiDebugPayload::sendHeaders();

        // Assert
        $this->assertFalse($flag->getValue());
    }

    /**
     * Sending headers is a no-op when the toolbar is off.
     *
     * Under CLI it is a no-op regardless; the assertion is that it returns
     * quietly rather than raising on a SAPI with no headers to send.
     */
    public function testSendingHeadersIsQuietWhenThereIsNothingToSend(): void
    {
        // Act
        ApiDebugPayload::sendHeaders();

        // Assert — reaching here without a "headers already sent" error is it
        $this->assertFalse(ApiDebugPayload::isEnabled());
    }

}
