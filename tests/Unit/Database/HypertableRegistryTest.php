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

    /** @var array<int, array<string, mixed>> Every call, in order */
    public array $calls = [];

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

        return true;
    }

    public function enableCompression(string $table, array $options = []): bool
    {
        $this->calls[] = ['compress', $table, $options];

        return true;
    }

    public function addCompressionPolicy(string $table, string $compressAfter): bool
    {
        $this->calls[] = ['compressPolicy', $table, $compressAfter];

        return true;
    }

    public function addRetentionPolicy(string $table, string $dropAfter, string $timeColumn = 'created_at'): bool
    {
        $this->calls[] = ['retentionPolicy', $table, $dropAfter, $timeColumn];

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
     * `tokenactions` declares segment-by and order-by columns; losing them
     * would silently change the compression ratio and query performance of the
     * busiest table the framework ships.
     */
    public function testCompressionOptionsArePassedThrough(): void
    {
        // Arrange
        $schema = new FakeHypertableSchema();

        // Act
        HypertableRegistry::apply($schema, 'tokenactions');

        // Assert
        $this->assertSame(
            ['segmentby' => 'tokenid, urlid, method', 'orderby' => 'action_time DESC'],
            $schema->calls[1][2]
        );
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
