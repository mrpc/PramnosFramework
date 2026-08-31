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

    /** Compare two policy intervals. */
    public function compare(string $actual, string $declared): bool
    {
        return $this->sameInterval($actual, $declared);
    }

    /**
     * Execute a plan for real.
     *
     * @param array<string, array<string, mixed>> $plan
     */
    public function fix(BufferedOutput $output, $schema, array $plan): int
    {
        return $this->repair($output, $schema, $plan);
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

    // ── Comparing intervals, which is why a changed declaration reaches the DB ──

    /**
     * The same interval written two ways is the same interval.
     *
     * This method exists because the command used to compare a policy's **presence**: a
     * declaration changed from 30 days to 7 left the old policy in place for ever, silently,
     * and the only symptom was a disk bill. PostgreSQL reports intervals in its own spelling
     * (`@ 30 days`, `30 days`, `1 mon`), so string equality would have reported every table as
     * needing a change and reconfigured all of them on every run.
     */
    public function testEquivalentSpellingsAreTheSameInterval(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();

        // Assert
        $this->assertTrue($command->compare('30 days', '30 days'));
        $this->assertTrue($command->compare('@ 30 days', '30 days'), 'the PostgreSQL @ prefix');
        $this->assertTrue($command->compare('30 DAYS', '30 days'), 'case');
        $this->assertTrue($command->compare('30  days', '30 days'), 'doubled whitespace');
        $this->assertTrue($command->compare('30 day', '30 days'), 'singular and plural');
        $this->assertTrue($command->compare('  7 days  ', '7 days'));
    }

    /** A genuinely different interval is different, which is the whole point. */
    public function testADifferentIntervalIsDetected(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();

        // Assert
        $this->assertFalse($command->compare('30 days', '7 days'));
        $this->assertFalse($command->compare('1 day', '1 hour'), 'the unit matters');
        $this->assertFalse($command->compare('24 months', '2 years'),
            'not normalised across units — months and years are not interchangeable in Postgres');
    }

    /**
     * An interval neither side can parse is treated as **equal**, so nothing is reconfigured.
     *
     * The safe direction, deliberately. A spelling this does not recognise — a composite like
     * `1 mon 15 days`, or whatever a future server version prints — must not be read as "this
     * differs from the declaration" and trigger a policy rewrite on every single run.
     */
    public function testAnUnparsableIntervalIsLeftAlone(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();

        // Assert
        $this->assertTrue($command->compare('1 mon 15 days', '30 days'));
        $this->assertTrue($command->compare('', '30 days'));
        $this->assertTrue($command->compare('30 days', 'whenever'));
        $this->assertTrue($command->compare('nonsense', 'also nonsense'));
    }

    // ── Repairing ─────────────────────────────────────────────────────────────

    /**
     * A table needing nothing is skipped, and a run that changes nothing says so.
     *
     * "Nothing to do" is the answer an operator needs to hear from a repair command, and it has
     * to be distinguishable from "I could not tell".
     */
    public function testARepairWithNothingToDoSaysSo(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $output  = new BufferedOutput();
        $plan    = [
            'authserver.done'    => ['exists' => true,  'missing' => [], 'blocker' => null, 'rows' => 0],
            'authserver.absent'  => ['exists' => false, 'missing' => ['convert'], 'blocker' => null, 'rows' => null],
        ];

        // Act
        $code = $command->fix($output, new InspectableSchema(), $plan);

        // Assert
        $this->assertSame(0, $code);
        $this->assertStringContainsString('Nothing to do', $output->fetch());
    }

    /**
     * A blocked table is reported and counted as a failure, and does not stop the others.
     *
     * One table whose primary key cannot carry the partition column must not abandon the run —
     * the remaining tables are the ones still growing without bound.
     */
    public function testABlockedTableFailsWithoutStoppingTheRun(): void
    {
        // Arrange
        $command = new TimescaleEnsureProbe();
        $output  = new BufferedOutput();
        $plan    = [
            'authserver.blocked' => [
                'exists'  => true,
                'missing' => ['convert'],
                'blocker' => 'the primary key does not include created_at',
                'rows'    => 10,
            ],
        ];

        // Act
        $code = $command->fix($output, new InspectableSchema(), $plan);
        $text = $output->fetch();

        // Assert
        $this->assertSame(1, $code, 'a blocked table must not report success');
        $this->assertStringContainsString('does not include created_at', $text);
        $this->assertStringContainsString('1 table(s) failed', $text);
    }

    /**
     * Before converting, the operator is told the row count and that it locks.
     *
     * The conversion holds an exclusive lock for its duration, and the tables this repairs are
     * audit logs with millions of rows. Somebody running this at 10am on a Monday deserves to
     * read that sentence before it starts, not after.
     */
    public function testAConversionAnnouncesTheLockAndTheSize(): void
    {
        // Arrange
        HypertableRegistry::reset();
        $command = new TimescaleEnsureProbe();
        $output  = new BufferedOutput();
        $plan    = [
            'authserver.big' => [
                'exists'  => true,
                'missing' => ['convert'],
                'blocker' => null,
                'rows'    => 4200000,
            ],
        ];

        // Act
        $command->fix($output, new InspectableSchema(), $plan);
        $text = $output->fetch();

        // Assert
        $this->assertStringContainsString('4,200,000 rows', $text, 'the size is not stated');
        $this->assertStringContainsString('exclusive lock', $text, 'the lock is not stated');
    }

    /** With no row count available the announcement omits it rather than saying zero. */
    public function testAnUnknownSizeIsOmittedRatherThanReportedAsZero(): void
    {
        // Arrange
        HypertableRegistry::reset();
        $command = new TimescaleEnsureProbe();
        $output  = new BufferedOutput();
        $plan    = [
            'authserver.unknown' => [
                'exists'  => true,
                'missing' => ['convert'],
                'blocker' => null,
                'rows'    => null,
            ],
        ];

        // Act
        $command->fix($output, new InspectableSchema(), $plan);
        $text = $output->fetch();

        // Assert
        $this->assertStringContainsString('Converting', $text);
        $this->assertStringNotContainsString('(0 rows)', $text);
    }
}
