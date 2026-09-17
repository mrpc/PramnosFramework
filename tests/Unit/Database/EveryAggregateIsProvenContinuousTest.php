<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\ContinuousAggregateRegistry;

/**
 * Every declared continuous aggregate is proven to be one, on the oldest supported version.
 *
 * WHAT: that each view in {@see ContinuousAggregateRegistry} is asserted, by name, to be a
 *       real continuous aggregate by the integration suite.
 * WHY:  a continuous aggregate that quietly becomes a plain materialised view keeps every
 *       column and answers every query, and costs a full re-scan of the source table on
 *       each refresh instead of an incremental one. Nothing about it looks wrong until the
 *       table is large.
 *
 *       The five that exist are each asserted today, and those assertions run against the
 *       version the development image is pinned to — which is the point of pinning it. What
 *       this test adds is the **sixth**: an aggregate added later, whose migration nobody
 *       thought to write an assertion for, would otherwise be covered by nothing at all and
 *       could fall back for years without a failing test.
 *
 *       A list here rather than a live check because the live check needs the migrations
 *       run and a TimescaleDB to run them on — which is exactly what the integration test
 *       this points at already does. This one keeps the two lists in step, which is the
 *       part that rots.
 */
#[CoversNothing]
class EveryAggregateIsProvenContinuousTest extends TestCase
{
    /**
     * Each registered view is named in an assertion that it is a continuous aggregate.
     *
     * Matched against `timescaledb_information.continuous_aggregates` in the integration
     * file: that catalogue is the only thing that can tell a continuous aggregate from a
     * materialised view with the same columns, so a test that queries it is a test that
     * proves the distinction.
     */
    public function testEveryRegisteredAggregateIsAssertedInTheIntegrationSuite(): void
    {
        // Arrange
        $proofs = (string) file_get_contents(
            dirname(__DIR__, 2) . '/Integration/Database/FrameworkMigrationsTimescaleDBTest.php'
        );

        $this->assertStringContainsString(
            'timescaledb_information.continuous_aggregates',
            $proofs,
            'the proof has to come from the catalogue — nothing else distinguishes the two forms'
        );

        // Act
        $unproven = [];
        foreach (array_keys(ContinuousAggregateRegistry::all()) as $view) {
            [$schema, $name] = array_pad(explode('.', $view, 2), 2, '');
            $name = $name === '' ? $schema : $name;

            // The name, anywhere in the file. Deliberately coarse: the two
            // `application_stats_*` views are asserted through a `foreach` with a
            // parameterised query, so a match on the literal `view_name = '…'` would
            // demand one shape of test rather than one fact. What this catches is an
            // aggregate nobody wrote any test for, which is the case that goes unnoticed.
            if (!str_contains($proofs, $name)) {
                $unproven[] = $view;
            }
        }

        // Assert
        $this->assertSame(
            [],
            $unproven,
            "these declared continuous aggregates are not asserted to be continuous:\n"
            . implode("\n", $unproven)
            . "\nAdd a test to FrameworkMigrationsTimescaleDBTest that runs the migration and"
            . " reads timescaledb_information.continuous_aggregates back. Without it, one that"
            . ' falls back to a plain materialised view keeps every column, answers every'
            . ' query, and re-scans the whole source table on every refresh.'
        );
    }
}
