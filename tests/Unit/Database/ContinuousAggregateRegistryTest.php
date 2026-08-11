<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\ContinuousAggregateRegistry;
use Pramnos\Database\SchemaBuilder;

/**
 * A schema builder whose answers about a view are set by the test.
 */
class FakeAggregateSchema extends SchemaBuilder
{
    /** @var bool Whether the view exists at all */
    public bool $viewExists = true;

    /** @var bool Whether a refresh policy is already registered */
    public bool $hasPolicy = false;

    /** @var array<int, array<int, string>> Policies this schema was asked to add */
    public array $added = [];

    public function __construct()
    {
        // No connection: what is under test is the decision, not the SQL.
    }

    /** @var bool Whether there is somewhere to record a software policy */
    public bool $policiesTableExists = true;

    public function hasView(string $view): bool
    {
        return $this->viewExists;
    }

    /**
     * Stands in for the capability probe the registry consults before writing a
     * software policy. False here means "not TimescaleDB", which is the case
     * the frozen views belong to.
     */
    public function getCapabilities(): \Pramnos\Database\DatabaseCapabilities
    {
        return new class extends \Pramnos\Database\DatabaseCapabilities {
            public function __construct()
            {
            }

            public function hasTimescaleDB(): bool
            {
                return false;
            }
        };
    }

    public function hasTable(string $table, ?string $schema = null): bool
    {
        return $this->policiesTableExists;
    }

    public function hasContinuousAggregatePolicy(string $view): bool
    {
        return $this->hasPolicy;
    }

    public function addContinuousAggregatePolicy(
        string $view,
        string $startOffset,
        string $endOffset,
        string $scheduleInterval
    ): bool {
        $this->added[] = [$view, $startOffset, $endOffset, $scheduleInterval];

        return true;
    }
}

/**
 * Covers the framework's declaration of which rolled-up views must keep
 * refreshing.
 *
 * Four migrations create a TimescaleDB continuous aggregate where the extension
 * is present and a plain materialized view where it is not — and registered the
 * refresh policy only in the first branch. PostgreSQL never refreshes a
 * materialized view on its own, so on every other backend those views were
 * frozen at the moment they were created: present, queryable, and answering with
 * the data of the day the migration ran.
 */
class ContinuousAggregateRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        ContinuousAggregateRegistry::reset();
        parent::tearDown();
    }

    /**
     * Every view the framework rolls up is declared.
     *
     * A view that is created but not declared cannot be repaired, and the gap is
     * invisible — it simply keeps returning old data.
     */
    public function testEveryFrameworkAggregateIsDeclared(): void
    {
        // Arrange
        $expected = [
            'authserver.daily_activity_summary',
            'authserver.daily_2fa_stats',
            'applications.tokenactions_hourly',
            'applications.application_stats_daily',
            'applications.application_stats_hourly',
        ];

        // Act
        $declared = array_keys(ContinuousAggregateRegistry::all());

        // Assert
        foreach ($expected as $view) {
            $this->assertContains($view, $declared);
        }
    }

    /**
     * The declared parameters are the ones the migrations used.
     *
     * These came from inside the TimescaleDB branch; moving them must not have
     * changed how often anything refreshes.
     */
    public function testTheDeclaredParametersMatchTheMigrations(): void
    {
        // Act
        $spec = ContinuousAggregateRegistry::spec('applications.tokenactions_hourly');

        // Assert
        $this->assertSame('3 hours', $spec['start_offset']);
        $this->assertSame('1 hour', $spec['end_offset']);
        $this->assertSame('1 hour', $spec['schedule_interval']);
    }

    /**
     * A view with no policy gets one.
     */
    public function testAViewWithoutARefreshPolicyGetsOne(): void
    {
        // Arrange
        $schema = new FakeAggregateSchema();

        // Act
        $done = ContinuousAggregateRegistry::apply($schema, 'authserver.daily_2fa_stats');

        // Assert
        $this->assertCount(1, $done);
        $this->assertSame(
            ['authserver.daily_2fa_stats', '1 month', '1 hour', '1 hour'],
            $schema->added[0]
        );
    }

    /**
     * A view that already refreshes is left alone.
     *
     * `add_continuous_aggregate_policy()` raises on a duplicate, so an unguarded
     * repair would work once and fail ever after.
     */
    public function testAnAlreadyRefreshingViewIsUntouched(): void
    {
        // Arrange
        $schema = new FakeAggregateSchema();
        $schema->hasPolicy = true;

        // Act
        $done = ContinuousAggregateRegistry::apply($schema, 'authserver.daily_2fa_stats');

        // Assert
        $this->assertSame([], $done);
        $this->assertSame([], $schema->added);
    }

    /**
     * A view this installation does not have is not invented.
     *
     * Not every installation enables every feature, and adding a policy for a
     * view that is not there would fail the whole run.
     */
    public function testAnAbsentViewIsSkipped(): void
    {
        // Arrange
        $schema = new FakeAggregateSchema();
        $schema->viewExists = false;

        // Act
        $done = ContinuousAggregateRegistry::apply($schema, 'authserver.daily_2fa_stats');

        // Assert
        $this->assertSame([], $done);
        $this->assertSame([], $schema->added);
    }

    /**
     * An undeclared view is not guessed at.
     */
    public function testAnUndeclaredViewIsIgnored(): void
    {
        // Arrange
        $schema = new FakeAggregateSchema();

        // Act
        $done = ContinuousAggregateRegistry::apply($schema, 'somewhere.else');

        // Assert
        $this->assertSame([], $done);
        $this->assertSame([], $schema->added);
    }

    /**
     * An application can declare its own.
     */
    public function testApplicationsCanRegisterTheirOwn(): void
    {
        // Arrange
        $schema = new FakeAggregateSchema();
        ContinuousAggregateRegistry::register('reports.daily_totals', [
            'start_offset'      => '7 days',
            'end_offset'        => '1 day',
            'schedule_interval' => '6 hours',
        ]);

        // Act
        ContinuousAggregateRegistry::apply($schema, 'reports.daily_totals');

        // Assert
        $this->assertSame(
            ['reports.daily_totals', '7 days', '1 day', '6 hours'],
            $schema->added[0]
        );
        $this->assertArrayHasKey(
            'authserver.daily_2fa_stats',
            ContinuousAggregateRegistry::all(),
            'registering must not displace the framework declarations'
        );
    }

    /**
     * No migration registers the policy inside its capability branch any more.
     *
     * That is the defect itself: the call sat inside `ifCapable(TIMESCALEDB, …)`,
     * so the other branch — the one that creates a materialized view PostgreSQL
     * will never refresh — silently produced a frozen view. Nothing in the code
     * shows the absence, which is why it survived; this reads the migrations and
     * settles it.
     */
    public function testNoMigrationRegistersTheRefreshInsideACapabilityBranch(): void
    {
        // Arrange
        $base  = dirname(__DIR__, 3) . '/database/migrations/framework';
        $files = [];
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $files = array_merge($files, glob($dir . '/*.php') ?: []);
        }

        // Act + Assert
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // The call, not the name: the migrations mention the method in a
            // comment explaining why the registry is used instead.
            if (!preg_match('/->\s*addContinuousAggregatePolicy\s*\(/', $source)) {
                continue;
            }
            $this->fail(
                basename($file) . ' calls addContinuousAggregatePolicy() directly. '
                . 'Use ContinuousAggregateRegistry::apply() outside the capability '
                . 'branch, so both backends get a refresh.'
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * With nowhere to record it, nothing is attempted.
     *
     * On a backend without TimescaleDB the refresh is a row in
     * `pramnos.framework_policies`. An installation whose core migrations have
     * not created that table cannot be given one, and trying would fail the
     * insert and take the surrounding migration down with it — which is exactly
     * what happened the first time this ran against a database that had the
     * views but not the policies table.
     */
    public function testNothingIsAttemptedWithoutAPoliciesTable(): void
    {
        // Arrange
        $schema = new FakeAggregateSchema();
        $schema->policiesTableExists = false;

        // Act
        $done = ContinuousAggregateRegistry::apply($schema, 'authserver.daily_2fa_stats');

        // Assert
        $this->assertSame([], $done);
        $this->assertSame([], $schema->added);
    }
}
