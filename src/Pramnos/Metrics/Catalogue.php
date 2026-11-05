<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

/**
 * A set of metrics an application declares, listed in app.php:
 * `'metrics' => ['catalogue' => [\App\Metrics\Sales::class]]`.
 *
 * ```php
 * final class Sales implements \Pramnos\Metrics\Catalogue
 * {
 *     public function metrics(): iterable
 *     {
 *         yield Dimension::of('region', 'Region')->values(['north' => 'North', 'south' => 'South']);
 *         yield Metric::flow('orders.count', 'Orders')->dimensions(['region'])->source(...);
 *     }
 * }
 * ```
 */
interface Catalogue
{
    /** @return iterable<Metric|Dimension> */
    public function metrics(): iterable;
}
