<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\TimescaleEnsure;
use Pramnos\Database\HypertableRegistry;
use Pramnos\Database\SchemaBuilder;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * A schema builder whose answers about a table are set by the test.
 */
class InspectableSchema extends SchemaBuilder
{
    /** @var bool Whether the table exists at all */
    public bool $tableExists = true;

    /** @var bool Whether it is already a hypertable */
    public bool $hypertable = false;

    /** @var bool Whether compression is enabled */
    public bool $compressionOn = false;

    /** @var bool Whether a compression policy exists */
    public bool $compressionPolicy = false;

    /** @var bool Whether a retention policy exists */
    public bool $retentionPolicy = false;

    /** @var list<string> The primary key this table reports */
    public array $primaryKey = ['id', 'created_at'];

    public function __construct()
    {
        // No connection needed: the command's inspection is pure decision-making
        // over what the schema reports.
    }

    public function hasTable(string $table, ?string $schema = null): bool
    {
        return $this->tableExists;
    }

    public function hasHypertable(string $table): bool
    {
        return $this->hypertable;
    }

    public function isCompressionEnabled(string $table): bool
    {
        return $this->compressionOn;
    }

    public function hasCompressionPolicy(string $table): bool
    {
        return $this->compressionPolicy;
    }

    public function hasRetentionPolicy(string $table): bool
    {
        return $this->retentionPolicy;
    }

    /** @var string|null What the live retention policy reports, if there is one */
    public ?string $retentionInterval = null;

    /** @var string|null What the live compression policy reports, if there is one */
    public ?string $compressionInterval = null;

    public function policyInterval(string $table, string $kind = 'retention'): ?string
    {
        return $kind === 'compression'
            ? $this->compressionInterval
            : $this->retentionInterval;
    }

    public function primaryKeyColumns(string $table): array
    {
        return $this->primaryKey;
    }

    public function quoteTable(string $table): string
    {
        return '"' . $table . '"';
    }
}

/**
 * A connection that reports a fixed row count.
 */
class CountingDatabase
{
    /** @var int|null Rows to report; null makes the count fail */
    public ?int $rows = 1000;

    /**
     * Stand in for a COUNT(*).
     *
     * @param  string $sql
     * @return object|false
     */
    public function query($sql)
    {
        if ($this->rows === null) {
            throw new \RuntimeException('cannot count');
        }

        return (object) ['fields' => ['cnt' => $this->rows]];
    }
}

/**
 * Exposes the command's inspection and reporting for assertions.
 */
class TimescaleEnsureProbe extends TimescaleEnsure
{
    /**
     * Inspect one table.
     *
     * @return array<string, mixed>
     */
    public function look($schema, $database, string $table, array $spec): array
    {
        return $this->inspect($schema, $database, $table, $spec);
    }

    /**
     * Render a plan.
     *
     * @param array<string, array<string, mixed>> $plan
     */
    public function show(BufferedOutput $output, array $plan): int
    {
        return $this->report($output, $plan);
    }
}

/**
 * Covers `timescale:ensure`, the repair for databases that gained TimescaleDB
 * after the framework migrations had already run.
 *
 * Those migrations convert their tables inside `ifCapable(TIMESCALEDB, …)` and
 * are then recorded as applied, so an installation without the extension at the
 * time keeps plain tables for ever — never partitioned, never compressed, and
 * with retention policies that never apply, so they grow without bound.
 *
 * What is tested here is the decision-making: what the command concludes about
 * a table, and what it tells an operator before touching an audit table that
 * will be locked for the duration.
 */
class TimescaleEnsureTest extends TestCase
{
    /** @var array<string, mixed> A spec with both policies */
    private array $spec = [
        'time_column'    => 'created_at',
        'chunk_interval' => '1 day',
        'compress_after' => '30 days',
        'retention'      => '24 months',
        'segmentby'      => null,
        'orderby'        => null,
        'feature'        => 'auth',
    ];

    protected function tearDown(): void
    {
        HypertableRegistry::reset();
        parent::tearDown();
    }

    /**
     * A plain table is reported as needing all four steps.
     *
     * This is the state the repair exists for.
     */
    public function testAPlainTableNeedsEverything(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $schema  = new InspectableSchema();

        // Act
        $state = $command->look($schema, new CountingDatabase(), 'probe', $this->spec);

        // Assert
        $this->assertTrue($state['exists']);
        $this->assertFalse($state['hypertable']);
        $this->assertSame(
            ['convert', 'compression', 'compression policy', 'retention policy'],
            $state['missing']
        );
    }

    /**
     * A pending conversion is reported with its row count.
     *
     * The operator's first question is how big the problem is, because the
     * conversion takes an exclusive lock for its duration. Answering that after
     * taking the lock is no answer at all.
     */
    public function testAPendingConversionCountsTheRows(): void
    {
        // Arrange
        $command  = new TimescaleEnsureProbe();
        $database = new CountingDatabase();
        $database->rows = 4_200_000;

        // Act
        $state = $command->look(new InspectableSchema(), $database, 'probe', $this->spec);

        // Assert
        $this->assertSame(4_200_000, $state['rows']);
    }

    /**
     * A configured table needs nothing, and is not counted.
     *
     * Counting rows on a healthy table would make a routine no-op run scan
     * every audit table in the database for no reason.
     */
    public function testAConfiguredTableNeedsNothingAndIsNotCounted(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $schema  = new InspectableSchema();
        $schema->hypertable        = true;
        $schema->compressionOn     = true;
        $schema->compressionPolicy = true;
        $schema->retentionPolicy   = true;

        // Act
        $state = $command->look($schema, new CountingDatabase(), 'probe', $this->spec);

        // Assert
        $this->assertSame([], $state['missing']);
        $this->assertNull($state['rows'], 'nothing to convert means nothing to count');
    }

    /**
     * A table that is not in this database is reported as absent, not repaired.
     *
     * Not every installation enables every feature, so a declared table that
     * was never created is a normal state — not an error and not a conversion.
     */
    public function testAnAbsentTableIsNotAProblem(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $schema  = new InspectableSchema();
        $schema->tableExists = false;

        // Act
        $state = $command->look($schema, new CountingDatabase(), 'probe', $this->spec);

        // Assert
        $this->assertFalse($state['exists']);
        $this->assertSame([], $state['missing']);
    }

    /**
     * A primary key without the partitioning column blocks the conversion, with
     * a reason.
     *
     * TimescaleDB requires the time column in every unique constraint. These
     * tables are created with a composite `(id, <time column>)` key
     * unconditionally, so this should never happen — which is why it is checked
     * rather than assumed, and why the check names the actual key instead of
     * letting a driver error surface.
     */
    public function testAKeyWithoutTheTimeColumnIsAStatedBlocker(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $schema  = new InspectableSchema();
        $schema->primaryKey = ['id'];

        // Act
        $state = $command->look($schema, new CountingDatabase(), 'probe', $this->spec);

        // Assert
        $this->assertNotNull($state['blocker']);
        $this->assertStringContainsString('created_at', $state['blocker']);
        $this->assertStringContainsString('primary key', $state['blocker']);
    }

    /**
     * A correct composite key is not treated as a blocker.
     */
    public function testTheCompositeKeyIsAccepted(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $schema  = new InspectableSchema();

        // Act
        $state = $command->look($schema, new CountingDatabase(), 'probe', $this->spec);

        // Assert
        $this->assertNull($state['blocker']);
    }

    /**
     * A failure to count rows does not become a failure to repair.
     */
    public function testAFailedCountDegradesInsteadOfAborting(): void
    {
        // Arrange
        $command  = new TimescaleEnsureProbe();
        $database = new CountingDatabase();
        $database->rows = null;

        // Act
        $state = $command->look(new InspectableSchema(), $database, 'probe', $this->spec);

        // Assert
        $this->assertNull($state['rows']);
        $this->assertContains('convert', $state['missing']);
    }

    /**
     * The dry run states the lock, the number of tables and the rows involved.
     *
     * "What would this do, and how long will my writes block" is the entire
     * reason `--dry-run` exists; a report that omits the cost is a report an
     * operator cannot act on.
     */
    public function testTheDryRunReportsTheCostBeforeAnythingIsLocked(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $output  = new BufferedOutput();
        $plan    = [
            'authserver.user_activity_log' => [
                'exists' => true, 'hypertable' => false, 'rows' => 4_200_000,
                'missing' => ['convert', 'compression'], 'blocker' => null,
            ],
        ];

        // Act
        $command->show($output, $plan);
        $text = $output->fetch();

        // Assert
        $this->assertStringContainsString('4,200,000', $text, 'the size of the problem');
        $this->assertStringContainsString('exclusive lock', $text, 'the cost of fixing it');
        $this->assertStringContainsString('1 table(s) would be converted', $text);
    }

    /**
     * A healthy database says so plainly.
     */
    public function testTheDryRunOnAHealthyDatabaseSaysNothingToDo(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $output  = new BufferedOutput();
        $plan    = [
            'tokenactions' => [
                'exists' => true, 'hypertable' => true, 'rows' => null,
                'missing' => [], 'blocker' => null,
            ],
        ];

        // Act
        $command->show($output, $plan);
        $text = $output->fetch();

        // Assert
        $this->assertStringContainsString('nothing', $text);
        $this->assertStringNotContainsString('exclusive lock', $text, 'nothing will be locked');
    }

    /**
     * A blocked table is surfaced, not buried among the others.
     */
    public function testTheDryRunSurfacesBlockedTables(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $output  = new BufferedOutput();
        $plan    = [
            'legacy_log' => [
                'exists' => true, 'hypertable' => false, 'rows' => 10,
                'missing' => ['convert'], 'blocker' => 'primary key (id) does not include …',
            ],
        ];

        // Act
        $command->show($output, $plan);
        $text = $output->fetch();

        // Assert
        $this->assertStringContainsString('cannot be converted', $text);
    }
}
