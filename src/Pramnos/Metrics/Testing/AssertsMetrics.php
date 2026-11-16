<?php

declare(strict_types=1);

namespace Pramnos\Metrics\Testing;

use PHPUnit\Framework\Assert;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;

/**
 * One metric's value, checked without a real table or a real `metrics:compute` — against
 * {@see Metrics::fake()}'s in-memory values, or the database when nothing is faked, the same
 * assertion either way.
 *
 * ```php
 * use Pramnos\Metrics\Testing\AssertsMetrics;
 *
 * class OrdersTest extends TestCase
 * {
 *     use AssertsMetrics;
 *
 *     public function testThePlacedOrdersAreCounted(): void
 *     {
 *         Metrics::fake(['orders' => [['period' => '2026-09', 'value' => 12.0, 'filters' => ['region' => 'north']]]]);
 *         $this->assertMetric('orders', '2026-09', 12.0, ['region' => 'north'], Window::MONTH);
 *     }
 * }
 * ```
 */
trait AssertsMetrics
{
    /**
     * @param array<string, string|list<string>> $filters
     */
    public static function assertMetric(string $metric, string $period, float $expected, array $filters = [], string $grain = Window::DAY, string $message = ''): void
    {
        Assert::assertSame($expected, Metrics::value($metric, $grain, $period, $filters),
            $message !== '' ? $message : sprintf('%s at %s (%s)', $metric, $period, $grain));
    }
}
