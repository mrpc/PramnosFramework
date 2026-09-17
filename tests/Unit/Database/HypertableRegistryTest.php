<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\HypertableRegistry;
use Pramnos\Database\SchemaBuilder;

/**
 * A schema builder that answers from the test instead of a database.
 *
 * Every step of the repair is guarded by an existence check, and it is those
 * guards — not the SQL — that decide whether a second run is a no-op or an
 * error. Driving them directly is the only way to assert the guard rather than
 * the outcome.
 */
class FakeHypertableSchema extends SchemaBuilder
{
    /** @var bool Whether the table is already a hypertable */
    public bool $isHypertable = false;

    /** @var bool Whether compression is already enabled */
    public bool $compressionOn = false;

    /** @var bool Whether a compression policy already exists */
    public bool $compressionPolicy = false;

    /** @var bool Whether a retention policy already exists */
    public bool $retentionPolicy = false;

    /** @var string|null The interval the live retention policy reports */
    public ?string $retentionInterval = null;

    /** @var string|null The interval the live compression policy reports */
    public ?string $compressionInterval = null;

    /** @var string|null What the time column is declared as, or null for "cannot tell" */
    public ?string $timeColumnType = null;

    /** @var array<int, array<string, mixed>> Every call, in order */
    public array $calls = [];

    /**
     * Operations that fail: the call is made and the catalogue does not change.
     *
     * A real failure looks exactly like this — `runTimescaleStatement()` logs the driver
     * error and returns false, and the catalogue keeps saying no. Until `apply()` read it
     * back, this state was indistinguishable from success.
     *
     * @var list<string> Any of: create, compress, compressPolicy, retentionPolicy
     */
    public array $failing = [];

    public function __construct()
    {
        // No parent constructor: this exercises the sequencing, which needs no
        // connection and no capability detection.
    }

    public function hasHypertable(string $table): bool
    {
        return $this->isHypertable;
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

    public function createHypertable(string $table, string $timeColumn, array $options = []): bool
    {
        $this->calls[] = ['create', $table, $timeColumn, $options];

        // The catalogue changes only when the call worked, which is what `apply()` reads
        // back. A double that always answered "yes, it is a hypertable now" could not tell
        // the two apart either — and that is the bug this is about.
        return $this->isHypertable = !in_array('create', $this->failing, true);
    }

    public function enableCompression(string $table, array $options = []): bool
    {
        $this->calls[] = ['compress', $table, $options];

        return $this->compressionOn = !in_array('compress', $this->failing, true);
    }

    public function addCompressionPolicy(string $table, string $compressAfter): bool
    {
        $this->calls[] = ['compressPolicy', $table, $compressAfter];

        return $this->compressionPolicy = !in_array('compressPolicy', $this->failing, true);
    }

    public function addRetentionPolicy(string $table, string $dropAfter, string $timeColumn = 'created_at'): bool
    {
        $this->calls[] = ['retentionPolicy', $table, $dropAfter, $timeColumn];

        return $this->retentionPolicy = !in_array('retentionPolicy', $this->failing, true);
    }

    /**
     * The time column's type, as the registry asks before applying a declaration.
     *
     * Null by default — "cannot tell" — which is the answer that lets every existing test
     * go on describing a timestamp column without saying so.
     */
    public function columnType(string $table, string $column): ?string
    {
        return $this->timeColumnType;
    }

    public function policyInterval(string $table, string $kind = 'retention'): ?string
    {
        return $kind === 'compression'
            ? $this->compressionInterval
            : $this->retentionInterval;
    }

    public function removeRetentionPolicy(string $table): bool
    {
        $this->calls[] = ['removeRetentionPolicy', $table];

        return true;
    }

    public function removeCompressionPolicy(string $table): bool
    {
        $this->calls[] = ['removeCompressionPolicy', $table];

        return true;
    }

    /** The names of the operations performed, in order. */
    public function performed(): array
    {
        return array_map(static fn(array $call): string => (string) $call[0], $this->calls);
    }
}

/**
 * Covers the framework's single declaration of its hypertables.
 *
 * Seven migrations used to carry these parameters inline, which made them
 * unreachable to anything but a fresh install — and a database that ran those
 * migrations before TimescaleDB was installed keeps plain tables for ever,
 * because the migration is recorded as applied and never runs again. Such
 * tables are never partitioned, never compressed, and their retention policies
 * never apply, so they grow without bound.
 */
class HypertableRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        // Other tests must see the framework's own declarations, not a
        // registration made here.
        HypertableRegistry::reset();
        parent::tearDown();
    }

    /**
     * Every table the framework converts is declared.
     *
     * If a migration converts a table that is not here, `timescale:ensure`
     * cannot repair it, and the gap is invisible: the table simply stays a
     * plain table on any installation that gained TimescaleDB late.
     */
    public function testEveryFrameworkHypertableIsDeclared(): void
    {
        // Arrange
        $expected = [
            'tokenactions',
            'authserver.twofactor_attempts',
            'authserver.user_activity_log',
            'authserver.user_consents',
            'authserver.data_processing_records',
            'authserver.gdpr_requests',
            'applications.application_stats',
        ];

        // Act
        $declared = array_keys(HypertableRegistry::all());

        // Assert
        foreach ($expected as $table) {
            $this->assertContains($table, $declared);
        }
    }

    /**
     * Each declaration carries the parameters the repair needs.
     *
     * A missing time column or chunk interval would fail at conversion time,
     * on the one database where the operator least wants a surprise.
     */
    public function testEveryDeclarationIsComplete(): void
    {
        // Act + Assert
        // A sweep over nothing passes. The collection is asserted non-empty first: a
        // registry that failed to populate, or a helper that quietly returns [], turns
        // this guard into a no-op that still reports success.
        $this->assertNotEmpty(HypertableRegistry::all(), 'the sweep found nothing to check');
        foreach (HypertableRegistry::all() as $table => $spec) {
            $this->assertNotSame('', (string) $spec['time_column'], $table . ': time column');
            $this->assertNotSame('', (string) $spec['chunk_interval'], $table . ': chunk interval');
            $this->assertArrayHasKey('compress_after', $spec, $table);
            $this->assertArrayHasKey('retention', $spec, $table);
        }
    }

    /**
     * The declared parameters are the ones the migrations used to hold.
     *
     * Spot-checked on the table the brief singles out as the expensive one.
     * This is a value test on purpose: if these drift, retention silently
     * changes for every installation that runs the repair.
     */
    public function testTheDeclaredParametersMatchWhatTheMigrationsUsed(): void
    {
        // Act
        $spec = HypertableRegistry::spec('authserver.user_activity_log');

        // Assert
        $this->assertSame('created_at', $spec['time_column']);
        $this->assertSame('1 day', $spec['chunk_interval']);
        $this->assertSame('30 days', $spec['compress_after']);
        $this->assertSame('24 months', $spec['retention']);
    }

    /**
     * An undeclared table is not guessed at.
     */
    public function testAnUndeclaredTableHasNoSpecAndIsNotTouched(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();

        // Act
        $done = HypertableRegistry::apply($schema, 'something.else');

        // Assert
        $this->assertNull(HypertableRegistry::spec('something.else'));
        $this->assertSame([], $done);
        $this->assertSame([], $schema->calls);
    }

    /**
     * On a plain table, all four steps run — in the only order that works.
     *
     * A compression policy on a table that is not yet a hypertable raises, and
     * so does compression where the setting was never enabled. The order is the
     * behaviour, not an implementation detail.
     */
    public function testAPlainTableGetsAllFourStepsInOrder(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        HypertableRegistry::register('probe', [
            'time_column'    => 'at',
            'chunk_interval' => '1 day',
            'compress_after' => '7 days',
            'retention'      => '90 days',
        ]);

        // Act
        $done = HypertableRegistry::apply($schema, 'probe');

        // Assert
        $this->assertSame(
            ['create', 'compress', 'compressPolicy', 'retentionPolicy'],
            $schema->performed()
        );
        $this->assertCount(4, $done);
    }

    /**
     * The offset is spelled for the column's type, not always as an interval.
     *
     * `add_compression_policy('t', INTERVAL '30 days')` is rejected outright on a `bigint`
     * time column — the offset must have that column's type — and the rejection was logged
     * and then reported as a tick. `2592000::bigint` is the form it accepts.
     *
     * Told apart by the value rather than by a new parameter: widening the method's
     * signature would be a fatal at class load for any subclass that overrides it, which
     * this repository's own test double demonstrated within a minute of trying it.
     */
    public function testThePolicyOffsetIsSpelledForTheColumnsType(): void
    {
        // Arrange
        $spell = new \ReflectionMethod(\Pramnos\Database\SchemaBuilder::class, 'timescaleOffset');

        // Act & Assert
        $this->assertSame('2592000::bigint', $spell->invoke(null, '2592000'));
        $this->assertSame('2592000::bigint', $spell->invoke(null, 2592000));
        $this->assertSame("INTERVAL '30 days'", $spell->invoke(null, '30 days'));
    }

    /**
     * A step the database refused is not reported as done.
     *
     * The failure this is all about. `runTimescaleStatement()` logs the driver error and
     * returns false; `apply()` appended its line regardless, so `timescale:ensure` printed
     * three ticks against a table `timescaledb_information.hypertables` does not list, and
     * exited 0. Run it again: the same three ticks, the same nothing.
     *
     * **Nothing breaks**, which is what makes it the worst available failure mode. Rows
     * are written and read as before, every test passes and every screen works. What is
     * missing is chunking, compression and a table that stays usable holding years rather
     * than days — found when it is far too large to convert quickly.
     */
    public function testAStepTheDatabaseRefusedIsNotReportedAsDone(): void
    {
        // Arrange — the conversion fails, exactly as it does for a bigint time column
        // given an interval: the call is made and the catalogue does not change.
        $schema = new FakeHypertableSchema();
        $schema->failing = ['create'];
        HypertableRegistry::register('probe', [
            'time_column'    => 'at',
            'chunk_interval' => '1 day',
        ]);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('convert to hypertable');
        HypertableRegistry::apply($schema, 'probe');
    }

    /**
     * Each later step is read back too, not only the conversion.
     *
     * Three separate calls failed in the report that produced this, and each was reported
     * as a tick of its own. A check on the first one alone would have caught one of three.
     */
    public function testAPolicyTheDatabaseRefusedIsNotReportedAsDone(): void
    {
        // Arrange — the table converts and compresses; the policy does not take.
        $schema = new FakeHypertableSchema();
        $schema->failing = ['compressPolicy'];
        HypertableRegistry::register('probe', [
            'time_column'    => 'at',
            'chunk_interval' => '1 day',
            'compress_after' => '7 days',
        ]);

        // Act & Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('add a compression policy');
        HypertableRegistry::apply($schema, 'probe');
    }

    /**
     * An interval declared for an integer time column is refused, by name.
     *
     * TimescaleDB requires every offset to have the time column's type. Given a `bigint`
     * column and `'7 days'` it does not say that — it says no function matches the
     * argument types, which reads like a version problem and is not one. A Unix timestamp
     * in a `bigint` is an ordinary choice, and the Hypertable guide's every example is a
     * `timestamptz`, so this is a mistake worth naming rather than diagnosing.
     */
    public function testAnIntervalForAnIntegerTimeColumnIsRefusedByName(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        $schema->timeColumnType = 'bigint';
        HypertableRegistry::register('probe', [
            'time_column'    => 'at',
            'chunk_interval' => '7 days',
        ]);

        // Act & Assert — the message names the column, its type and the key at fault.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('probe.at is bigint');
        HypertableRegistry::apply($schema, 'probe');
    }

    /**
     * A number for an integer time column is accepted, and reaches the database as one.
     *
     * The other half: `'604800'` must arrive as `604800::bigint`, not as
     * `INTERVAL '604800'`. The cast was applied by a `(string)` in this class and an
     * `is_string()` in the schema builder, neither of which knew about the column.
     */
    public function testANumberForAnIntegerTimeColumnIsAccepted(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        $schema->timeColumnType = 'bigint';
        HypertableRegistry::register('probe', [
            'time_column'    => 'at',
            'chunk_interval' => 604800,
            'compress_after' => 2592000,
        ]);

        // Act
        $done = HypertableRegistry::apply($schema, 'probe');

        // Assert — both steps ran and were confirmed.
        $this->assertSame(['create', 'compress', 'compressPolicy'], $schema->performed());
        $this->assertCount(3, $done);

        // …and the number was passed through as a number.
        $this->assertSame('604800', (string) $schema->calls[0][3]['chunk_time_interval']);
    }

    /**
     * The conversion asks for the data to be migrated.
     *
     * Without it, converting an existing table with rows fails. This is the one
     * option that turns a create-only step into a repair — and the reason the
     * command warns about an exclusive lock first.
     */
    public function testTheConversionMigratesExistingData(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        HypertableRegistry::register('probe', [
            'time_column' => 'at', 'chunk_interval' => '1 day',
        ]);

        // Act
        HypertableRegistry::apply($schema, 'probe');

        // Assert
        $options = $schema->calls[0][3];
        $this->assertTrue($options['migrate_data'], 'existing rows must be moved into chunks');
        $this->assertTrue($options['if_not_exists'], 'a concurrent conversion must not raise');
        $this->assertSame('1 day', $options['chunk_time_interval']);
    }

    /**
     * A fully-configured table is left completely alone.
     *
     * This is what makes the command safe to run repeatedly:
     * `add_compression_policy()` and `add_retention_policy()` raise on a
     * duplicate rather than no-opping, so an unguarded second run would fail.
     */
    public function testAConfiguredTableIsUntouched(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        $schema->isHypertable      = true;
        $schema->compressionOn     = true;
        $schema->compressionPolicy = true;
        $schema->retentionPolicy   = true;
        HypertableRegistry::register('probe', [
            'time_column'    => 'at',
            'chunk_interval' => '1 day',
            'compress_after' => '7 days',
            'retention'      => '90 days',
        ]);

        // Act
        $done = HypertableRegistry::apply($schema, 'probe');

        // Assert
        $this->assertSame([], $done);
        $this->assertSame([], $schema->calls, 'nothing may be re-issued');
    }

    /**
     * A half-configured table gets only the missing half.
     *
     * This is the realistic state after a partial repair or a manual
     * conversion, and re-issuing the completed steps would raise.
     */
    public function testOnlyTheMissingStepsRun(): void
    {
        // Arrange — converted and compressed already, but no policies
        $schema = new FakeHypertableSchema();
        $schema->isHypertable  = true;
        $schema->compressionOn = true;
        HypertableRegistry::register('probe', [
            'time_column'    => 'at',
            'chunk_interval' => '1 day',
            'compress_after' => '7 days',
            'retention'      => '90 days',
        ]);

        // Act
        HypertableRegistry::apply($schema, 'probe');

        // Assert
        $this->assertSame(['compressPolicy', 'retentionPolicy'], $schema->performed());
    }

    /**
     * A declaration without policies gets none.
     *
     * "Keep for ever" is a legitimate choice, and inventing a retention policy
     * for a table that did not ask for one would delete data.
     */
    public function testATableWithoutPoliciesGetsNone(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        HypertableRegistry::register('probe', [
            'time_column' => 'at', 'chunk_interval' => '1 day',
        ]);

        // Act
        HypertableRegistry::apply($schema, 'probe');

        // Assert
        $this->assertSame(['create', 'compress'], $schema->performed());
    }

    /**
     * Compression options declared for a table are passed through.
     *
     * `tokenactions` declares segment-by and order-by columns; losing them would silently
     * change the compression ratio and query performance of the busiest table the
     * framework ships.
     */
    public function testCompressionOptionsArePassedThrough(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();

        // Act
        HypertableRegistry::apply($schema, 'tokenactions');

        // Assert
        $this->assertSame(
            ['segmentby' => 'urlid, method', 'orderby' => 'action_time DESC'],
            $schema->calls[1][2]
        );
    }

    /**
     * `tokenid` stays out of `tokenactions`'s segment key.
     *
     * The value above is a whole declaration and would be updated wholesale by anybody
     * changing anything about it. This asserts the one property that matters, and says
     * why — so a future edit that puts `tokenid` back has to argue with a number rather
     * than with a preference.
     *
     * Measured on 2 M rows: with `tokenid` in the segment key, an API whose callers are
     * browser sessions compresses to a ratio of **0.50** — compression making the table
     * larger, 515 MB against 38 MB — while losing the per-token lookup it exists to
     * optimise. It is the right layout only for a handful of long-lived server-to-server
     * tokens, which a framework default cannot assume and an application can declare.
     */
    public function testTokenactionsDoesNotSegmentByToken(): void
    {
        // Act
        $spec = HypertableRegistry::spec('tokenactions');

        // Assert
        $this->assertStringNotContainsString('tokenid', (string) $spec['segmentby'],
            'tokenid is high cardinality: segmenting by it compresses a session-heavy '
            . 'API to a ratio below 1');
        $this->assertStringContainsString('urlid', (string) $spec['segmentby']);
    }

    /**
     * The retention policy is given the declared time column.
     *
     * On a non-TimescaleDB backend `addRetentionPolicy()` records a software
     * policy that compares against that column, so passing the wrong one would
     * make the emulated policy delete by the wrong clock.
     */
    public function testRetentionUsesTheDeclaredTimeColumn(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();

        // Act
        HypertableRegistry::apply($schema, 'authserver.user_consents');

        // Assert
        $retention = end($schema->calls);
        $this->assertSame('retentionPolicy', $retention[0]);
        $this->assertSame('7 years', $retention[2]);
        $this->assertSame('granted_at', $retention[3]);
    }

    /**
     * An application can declare its own, and override a framework default.
     */
    // ── Drift: a changed declaration reaching the database ──────────────────

    /**
     * A retention policy whose interval no longer matches is replaced.
     *
     * Until there was a way to remove one there was no way to change one:
     * `add_retention_policy()` raises on a duplicate, so the guard that skipped an
     * existing policy also made a changed declaration permanently unreachable. The
     * command reported "nothing missing" and the two numbers disagreed for ever.
     */
    public function testAChangedRetentionIntervalIsReplaced(): void
    {
        // Arrange — everything in place, but the live policy says something else
        $schema = new FakeHypertableSchema();
        $schema->isHypertable      = true;
        $schema->compressionOn     = true;
        $schema->compressionPolicy = true;
        $schema->retentionPolicy   = true;
        $schema->retentionInterval = '2 years';

        HypertableRegistry::register('drift_probe', [
            'time_column'    => 'created_at',
            'chunk_interval' => '7 days',
            'compress_after' => '30 days',
            'retention'      => '90 days',
        ]);
        $schema->compressionInterval = '30 days';

        // Act
        $done = HypertableRegistry::apply($schema, 'drift_probe');

        // Assert — removed then re-added, in that order
        $this->assertSame(
            ['removeRetentionPolicy', 'retentionPolicy'],
            $schema->performed()
        );
        $this->assertSame(['retention policy changed to 90 days'], $done);
    }

    /**
     * A policy that already matches is left alone.
     *
     * The common case: every run of the repair command against a correct database must
     * do nothing at all. Churning a policy on each pass would be constant work against
     * the scheduler for no change.
     */
    public function testAMatchingIntervalIsNotTouched(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        $schema->isHypertable        = true;
        $schema->compressionOn       = true;
        $schema->compressionPolicy   = true;
        $schema->retentionPolicy     = true;
        $schema->retentionInterval   = '90 days';
        $schema->compressionInterval = '30 days';

        HypertableRegistry::register('drift_probe', [
            'time_column'    => 'created_at',
            'chunk_interval' => '7 days',
            'compress_after' => '30 days',
            'retention'      => '90 days',
        ]);

        // Act
        $done = HypertableRegistry::apply($schema, 'drift_probe');

        // Assert
        $this->assertSame([], $schema->performed());
        $this->assertSame([], $done);
    }

    /**
     * Spellings of the same duration are not drift.
     *
     * PostgreSQL hands intervals back as `@ 90 days`; a declaration says `90 days`. Left
     * unnormalised, every table with a retention policy would be reported as drifted and
     * rewritten on every single run — for ever, over a leading `@`.
     */
    public function testTheSameDurationSpeltDifferentlyIsNotDrift(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        $schema->isHypertable      = true;
        $schema->compressionOn     = true;
        $schema->retentionPolicy   = true;
        $schema->retentionInterval = '@ 90 day';

        HypertableRegistry::register('drift_probe', [
            'time_column'    => 'created_at',
            'chunk_interval' => '7 days',
            'compress_after' => null,
            'retention'      => '90 days',
        ]);

        // Act
        $done = HypertableRegistry::apply($schema, 'drift_probe');

        // Assert
        $this->assertSame([], $schema->performed());
        $this->assertSame([], $done);
    }

    /**
     * An interval nothing can parse is left alone rather than replaced.
     *
     * The bias that matters. A false positive rewrites a policy on every run for ever; a
     * false negative costs one changed number not taking effect — which is the situation
     * this mechanism arrived to improve, not a regression it introduces.
     */
    public function testAnUnparseableIntervalIsLeftAlone(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        $schema->isHypertable      = true;
        $schema->compressionOn     = true;
        $schema->retentionPolicy   = true;
        $schema->retentionInterval = '1 year 6 mons 3 days';

        HypertableRegistry::register('drift_probe', [
            'time_column'    => 'created_at',
            'chunk_interval' => '7 days',
            'compress_after' => null,
            'retention'      => '90 days',
        ]);

        // Act
        $done = HypertableRegistry::apply($schema, 'drift_probe');

        // Assert
        $this->assertSame([], $schema->performed());
        $this->assertSame([], $done);
    }

    /**
     * A changed compression interval is replaced too.
     */
    public function testAChangedCompressionIntervalIsReplaced(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();
        $schema->isHypertable        = true;
        $schema->compressionOn       = true;
        $schema->compressionPolicy   = true;
        $schema->compressionInterval = '60 days';

        HypertableRegistry::register('drift_probe', [
            'time_column'    => 'created_at',
            'chunk_interval' => '7 days',
            'compress_after' => '14 days',
            'retention'      => null,
        ]);

        // Act
        $done = HypertableRegistry::apply($schema, 'drift_probe');

        // Assert
        $this->assertSame(
            ['removeCompressionPolicy', 'compressPolicy'],
            $schema->performed()
        );
        $this->assertSame(['compression policy changed to 14 days'], $done);
    }

    // ── Config overrides ────────────────────────────────────────────────────

    /**
     * An override changes only the keys it names.
     *
     * Retuning a framework hypertable used to mean editing the framework. Seven tables
     * are declared and none of their intervals fit every installation — a busy API's
     * `tokenactions` and a quiet one's are the same declaration and very different
     * amounts of disk.
     */
    public function testAnOverrideChangesOnlyTheKeysItNames(): void
    {
        // Arrange
        $before = HypertableRegistry::spec('tokenactions');

        // Act
        HypertableRegistry::loadOverridesFromConfig([
            'tokenactions' => ['retention' => '10 years'],
        ]);
        $after = HypertableRegistry::spec('tokenactions');

        // Assert — the named key changed, the rest did not
        $this->assertSame('10 years', $after['retention']);
        $this->assertSame($before['time_column'], $after['time_column']);
        $this->assertSame($before['chunk_interval'], $after['chunk_interval']);
        $this->assertSame($before['compress_after'], $after['compress_after']);
    }

    /**
     * Overriding a table nobody declared registers it.
     *
     * So an application can use the same config block for its own tables as for retuning
     * the framework's, rather than needing a code path for each.
     */
    public function testOverridingAnUndeclaredTableRegistersIt(): void
    {
        // Arrange & Act
        HypertableRegistry::loadOverridesFromConfig([
            'app_readings' => ['time_column' => 'measured_at', 'retention' => '1 year'],
        ]);

        // Assert
        $spec = HypertableRegistry::spec('app_readings');
        $this->assertNotNull($spec);
        $this->assertSame('measured_at', $spec['time_column']);
        $this->assertSame('1 year', $spec['retention']);
    }

    /**
     * A key the spec does not know about is ignored.
     *
     * These values are interpolated into `create_hypertable()` and the policy calls, so
     * a typo in app.php must not become an unknown option in a statement — it would fail
     * at migration time, on somebody else's installation, with a message about SQL.
     */
    public function testAnUnknownOverrideKeyIsIgnored(): void
    {
        // Arrange & Act
        HypertableRegistry::loadOverridesFromConfig([
            'tokenactions' => ['retenshun' => '10 years', 'drop_everything' => true],
        ]);

        // Assert
        $spec = HypertableRegistry::spec('tokenactions');
        $this->assertArrayNotHasKey('retenshun', $spec);
        $this->assertArrayNotHasKey('drop_everything', $spec);
    }

    public function testApplicationsCanRegisterTheirOwn(): void
    {
        // Act
        HypertableRegistry::register('readings', [
            'time_column'    => 'measured_at',
            'chunk_interval' => '1 day',
        ]);

        // Assert
        $spec = HypertableRegistry::spec('readings');
        $this->assertSame('measured_at', $spec['time_column']);
        $this->assertNull($spec['retention'], 'unspecified means keep for ever, not a default');
        $this->assertArrayHasKey(
            'tokenactions',
            HypertableRegistry::all(),
            'registering must not displace the framework declarations'
        );
    }
}
