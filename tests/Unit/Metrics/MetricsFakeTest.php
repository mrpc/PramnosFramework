<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Testing\AssertsMetrics;
use Pramnos\Metrics\Window;

/**
 * BI phase 7g (FW-518): an application's own test checks a metric without a real table or a real
 * `metrics:compute` — `Metrics::fake()` keeps values in memory, `assertMetric()` checks one, by
 * its own filters, never the database while faking.
 */
#[CoversClass(Metrics::class)]
final class MetricsFakeTest extends TestCase
{
    use AssertsMetrics;

    protected function tearDown(): void
    {
        Metrics::reset();
    }

    /**
     * A faked value is read back by its metric, period and filters — never touching the real
     * metric or a database the test never set up; the same filters with a different value are
     * their own row; nothing faked for a period, or for filters never given, is null; `fake()`
     * with nothing turns it off, back to a real read (refused here, since the metric is not
     * registered at all — proof the real path was taken, not the fake one).
     */
    public function testAFakedValueIsReadBackByItsOwnFiltersNeverTheDatabase(): void
    {
        // Arrange
        Metrics::fake([
            'orders' => [
                ['period' => '2026-09', 'value' => 12.0, 'filters' => ['region' => 'north'], 'grain' => Window::MONTH],
                ['period' => '2026-09', 'value' => 30.0, 'filters' => ['region' => 'south'], 'grain' => Window::MONTH],
                ['period' => '2026-09-05', 'value' => 2.0],
            ],
        ]);

        // Act
        $this->assertMetric('orders', '2026-09', 12.0, ['region' => 'north'], Window::MONTH);
        $this->assertMetric('orders', '2026-09', 30.0, ['region' => 'south'], Window::MONTH);
        $this->assertMetric('orders', '2026-09-05', 2.0);
        $missingPeriod  = Metrics::value('orders', Window::MONTH, '2026-10', ['region' => 'north']);
        $missingFilters = Metrics::value('orders', Window::MONTH, '2026-09', ['region' => 'east']);
        Metrics::fake();
        $refused = null;
        try {
            Metrics::value('orders', Window::MONTH, '2026-09', ['region' => 'north']);
        } catch (\InvalidArgumentException $e) {
            $refused = $e->getMessage();
        }

        // Assert
        $this->assertNull($missingPeriod, 'nothing faked for October');
        $this->assertNull($missingFilters, 'nothing faked for that filter');
        $this->assertStringContainsString('orders', (string) $refused, 'off again: the real path, which refuses an unregistered metric');
    }
}
