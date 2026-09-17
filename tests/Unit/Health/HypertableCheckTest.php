<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\ContinuousAggregateRegistry;
use Pramnos\Database\HypertableRegistry;
use Pramnos\Health\Checks\HypertableCheck;
use Pramnos\Health\HealthStatus;

/**
 * The check that reads back what `timescale:ensure` used to merely claim.
 *
 * WHAT: that a declared hypertable which is an ordinary table is reported, that an
 *       aggregate which fell back to a plain materialised view is reported, and that
 *       neither is reported as `down`.
 * WHY:  both are states in which the application serves every request correctly. A table
 *       that should be chunked and is not stops being convertible once it is large, and
 *       until now the only signal was a tick that had never been checked. `down` would
 *       page somebody for a site that is up, and a check that cries wolf gets muted.
 */
#[CoversClass(HypertableCheck::class)]
class HypertableCheckTest extends TestCase
{
    protected function tearDown(): void
    {
        HypertableRegistry::reset();
        ContinuousAggregateRegistry::reset();
    }

    /**
     * Without the extension there is nothing to be wrong about.
     *
     * Every declaration has a documented software equivalent on a backend that has no
     * TimescaleDB — that is what the registry is for — so reporting it as a problem would
     * leave two of the three supported databases permanently yellow.
     */
    public function testWithoutTimescaleItIsNotAFault(): void
    {
        // Arrange
        $check = new HypertableCheck($this->database(timescale: false));

        // Act
        $result = $check->run();

        // Assert
        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertStringContainsString('software equivalents', $result->message);
    }

    /**
     * A declared hypertable that is an ordinary table is reported, and named.
     *
     * The failure this whole area exists to surface. It is degraded rather than down: the
     * rows are there and every query answers.
     */
    public function testADeclaredHypertableThatIsAnOrdinaryTableIsReported(): void
    {
        // Arrange
        HypertableRegistry::register('probe_metrics', [
            'time_column'    => 'at',
            'chunk_interval' => '1 day',
        ]);
        $check = new HypertableCheck($this->database(
            timescale: true,
            tables: ['probe_metrics'],
            hypertables: []
        ));

        // Act
        $result = $check->run();

        // Assert
        $this->assertSame(HealthStatus::Degraded, $result->status);
        $this->assertStringContainsString('probe_metrics', $result->message);
        $this->assertContains('probe_metrics', $result->details['not_hypertables']);
        $this->assertStringContainsString('timescale:ensure', $result->details['hint']);
    }

    /**
     * A declaration whose table has not been created yet is not a fault.
     *
     * A migration that has not run is not this check's subject, and reporting it would
     * make a fresh install yellow before it has finished setting itself up.
     */
    public function testADeclarationForATableThatDoesNotExistIsIgnored(): void
    {
        // Arrange
        HypertableRegistry::register('probe_metrics', [
            'time_column'    => 'at',
            'chunk_interval' => '1 day',
        ]);
        $check = new HypertableCheck($this->database(timescale: true, tables: []));

        // Act & Assert
        $this->assertSame(HealthStatus::Ok, $check->run()->status);
    }

    /**
     * An aggregate that fell back to a plain materialised view is reported with the version.
     *
     * The fallback is deliberate and correct — the columns are identical — but it refreshes
     * through the PolicyEngine daemon rather than a TimescaleDB background job, so an
     * operator has to know it happened. The version is in the message because the answer to
     * "why" is the extension's version, which is not PostgreSQL's and is in no file.
     */
    public function testAnAggregateThatFellBackIsReportedWithTheVersion(): void
    {
        // Arrange
        ContinuousAggregateRegistry::register('probe_hourly', [
            'start_offset'      => '1 day',
            'end_offset'        => '1 hour',
            'schedule_interval' => '1 hour',
        ]);
        $check = new HypertableCheck($this->database(
            timescale: true,
            views: ['probe_hourly'],
            aggregates: []
        ));

        // Act
        $result = $check->run();

        // Assert
        $this->assertSame(HealthStatus::Degraded, $result->status);
        $this->assertStringContainsString('2.26.4', $result->message);
        $this->assertContains('probe_hourly', $result->details['plain_materialised_views']);
        $this->assertStringContainsString('PolicyEngine', $result->details['hint']);
    }

    /**
     * Everything matching is reported as ok, with the version for the record.
     */
    public function testEverythingMatchingIsOk(): void
    {
        // Arrange
        HypertableRegistry::register('probe_metrics', [
            'time_column'    => 'at',
            'chunk_interval' => '1 day',
        ]);
        $check = new HypertableCheck($this->database(
            timescale: true,
            tables: ['probe_metrics'],
            hypertables: ['probe_metrics']
        ));

        // Act
        $result = $check->run();

        // Assert
        $this->assertSame(HealthStatus::Ok, $result->status);
        $this->assertSame('2.26.4', $result->details['timescaledb']);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * A database whose schema builder and capabilities answer from lists.
     *
     * The schema builder is hand-written because `schema()` has no return type and the
     * four questions asked of it read better as a list than as four `->method()` calls.
     * `capabilities()` is typed, so that one is a real mock.
     *
     * @param list<string> $tables      Tables that exist
     * @param list<string> $hypertables Of those, the ones the catalogue lists as hypertables
     * @param list<string> $views       Views that exist
     * @param list<string> $aggregates  Of those, the ones that really are continuous aggregates
     */
    private function database(
        bool $timescale,
        array $tables = [],
        array $hypertables = [],
        array $views = [],
        array $aggregates = []
    ): \Pramnos\Database\Database {
        $schema = new class ($tables, $hypertables, $views, $aggregates) {
            public function __construct(
                private array $tables,
                private array $hypertables,
                private array $views,
                private array $aggregates
            ) {
            }

            public function hasTable(string $table): bool
            {
                return in_array($table, $this->tables, true);
            }

            public function hasHypertable(string $table): bool
            {
                return in_array($table, $this->hypertables, true);
            }

            public function hasView(string $view): bool
            {
                return in_array($view, $this->views, true);
            }

            public function isContinuousAggregate(string $view): bool
            {
                return in_array($view, $this->aggregates, true);
            }
        };

        // `capabilities()` is typed, so this one has to be the real class; `schema()` is
        // not, so the anonymous double above is enough for the four questions asked of it.
        $capabilities = $this->createMock(\Pramnos\Database\DatabaseCapabilities::class);
        $capabilities->method('hasTimescaleDB')->willReturn($timescale);
        $capabilities->method('timescaleVersion')->willReturn($timescale ? '2.26.4' : '');

        $database = $this->createMock(\Pramnos\Database\Database::class);
        $database->method('schema')->willReturn($schema);
        $database->method('capabilities')->willReturn($capabilities);

        return $database;
    }
}
