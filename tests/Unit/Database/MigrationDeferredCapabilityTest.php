<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\DatabaseCapabilities;
use Pramnos\Database\SchemaBuilder;

/**
 * "This capability is absent" and "this migration has been applied" are not the same thing.
 *
 * WHAT: that `ifCapable()` with no fallback records the absence, and that a fallback which
 *       actually does something does not.
 * WHY:  every hypertable conversion the framework ships is wrapped in
 *       `ifCapable(TIMESCALEDB, …)` with no fallback. Recorded as applied, it never runs
 *       again — so a server that gains TimescaleDB later, or a database restored onto a
 *       host that has it, keeps a schema permanently behind what the migration history
 *       claims, and the repair is a command somebody has to know exists.
 *
 *       Measured on a real deployment: the first `migrate` ran before
 *       `shared_preload_libraries` had been set, so every conversion recorded `Ran` having
 *       done nothing, and the aggregates depending on them failed on every run afterwards
 *       with `At least one hypertable should be used in the view definition`.
 */
#[CoversClass(SchemaBuilder::class)]
class MigrationDeferredCapabilityTest extends TestCase
{
    protected function setUp(): void
    {
        SchemaBuilder::resetDeferredCapabilities();
    }

    protected function tearDown(): void
    {
        SchemaBuilder::resetDeferredCapabilities();
    }

    /**
     * A no-op for a missing capability is recorded as deferred.
     */
    public function testANoOpForAMissingCapabilityIsRecorded(): void
    {
        // Arrange
        $schema = $this->schemaWithout(DatabaseCapabilities::TIMESCALEDB);
        $ran    = false;

        // Act
        $schema->ifCapable(DatabaseCapabilities::TIMESCALEDB, static function () use (&$ran) {
            $ran = true;
        });

        // Assert — nothing ran, and the absence is on the record.
        $this->assertFalse($ran);
        $this->assertSame(
            [DatabaseCapabilities::TIMESCALEDB],
            SchemaBuilder::deferredCapabilities()
        );
    }

    /**
     * A fallback that does something is not a deferral.
     *
     * This is the distinction the whole mechanism rests on. A migration that creates a
     * plain materialised view where TimescaleDB would have a continuous aggregate **has**
     * been applied — recording it as deferred would make it run again on every migrate,
     * for ever, on a backend that will never have the capability.
     */
    public function testAFallbackThatDoesSomethingIsNotADeferral(): void
    {
        // Arrange
        $schema   = $this->schemaWithout(DatabaseCapabilities::TIMESCALEDB);
        $fellBack = false;

        // Act
        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            static fn() => null,
            static function () use (&$fellBack) {
                $fellBack = true;
            }
        );

        // Assert
        $this->assertTrue($fellBack);
        $this->assertSame([], SchemaBuilder::deferredCapabilities());
    }

    /**
     * A capability that is present is not recorded either.
     */
    public function testAPresentCapabilityIsNotRecorded(): void
    {
        // Arrange
        $schema = $this->schemaWith(DatabaseCapabilities::TIMESCALEDB);
        $ran    = false;

        // Act
        $schema->ifCapable(DatabaseCapabilities::TIMESCALEDB, static function () use (&$ran) {
            $ran = true;
        });

        // Assert
        $this->assertTrue($ran);
        $this->assertSame([], SchemaBuilder::deferredCapabilities());
    }

    /**
     * The message a declined migration carries says what to do about it.
     *
     * A result nobody can act on is a result nobody reads. This one names the capability
     * and says the migration will be retried — which is the difference between "something
     * is wrong" and "enable the extension and run migrate again".
     */
    public function testTheDeclineMessageNamesTheCapabilityAndTheConsequence(): void
    {
        // Arrange
        $migration = new class ($this->createMock(\Pramnos\Application\Application::class))
            extends \Pramnos\Database\Migration {
            public function up(): void
            {
            }

            public function down(): void
            {
            }
        };

        // Act
        $migration->declineForMissingCapabilities([DatabaseCapabilities::TIMESCALEDB]);

        // Assert
        $this->assertTrue($migration->hasDeclined());
        $this->assertStringContainsString('timescaledb', $migration->declinedReason());
        $this->assertStringContainsString('runs again', $migration->declinedReason());
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    private function schemaWithout(string $capability): SchemaBuilder
    {
        return $this->schemaAnswering($capability, false);
    }

    private function schemaWith(string $capability): SchemaBuilder
    {
        return $this->schemaAnswering($capability, true);
    }

    /**
     * A schema builder whose capability answer is fixed.
     *
     * `ifCapable()` asks `capabilities->has()` and nothing else, so nothing here needs a
     * connection — which is the point: this is about bookkeeping, not about the database.
     */
    private function schemaAnswering(string $capability, bool $answer): SchemaBuilder
    {
        $capabilities = $this->createMock(DatabaseCapabilities::class);
        $capabilities->method('has')->willReturnCallback(
            static fn(string $asked): bool => $asked === $capability ? $answer : false
        );

        // `SchemaBuilder` builds its own `DatabaseCapabilities` in the constructor, so the
        // double is put in place afterwards. Injecting it would be tidier and is a wider
        // change than this finding warrants.
        $schema   = new SchemaBuilder($this->createMock(\Pramnos\Database\Database::class));
        $property = new \ReflectionProperty(SchemaBuilder::class, 'capabilities');
        $property->setValue($schema, $capabilities);

        return $schema;
    }
}
