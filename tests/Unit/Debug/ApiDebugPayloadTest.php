<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Debug\ApiDebugPayload;
use Pramnos\Debug\Collectors\CollectorInterface;
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
        parent::setUp();
        DebugBar::reset();
    }

    protected function tearDown(): void
    {
        DebugBar::reset();
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
}
