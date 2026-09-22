<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;

/**
 * What `columnDetails()` does when the read does not produce a table.
 *
 * The integration tests drive it against two real engines, which is where the normalisation
 * is proved. What a real database will not produce on demand is a driver that **refuses** —
 * and both refusals it guards against are declared by `getColumns()`'s own contract: it may
 * return `false`, and a connection that has gone away raises.
 *
 * Neither is reachable from an integration test: a missing table comes back as an empty
 * result, not as `false` or an exception. So they are driven with a double, because a guard
 * nobody has executed is a guard nobody has checked — and the failure it prevents is a
 * caller iterating `false`.
 */
#[CoversClass(SchemaBuilder::class)]
class ColumnDetailsEdgeCasesTest extends TestCase
{
    /** A builder over a connection whose `getColumns()` does whatever the test needs. */
    private function builderWhoseColumnsRead(callable $behaviour): SchemaBuilder
    {
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getColumns'])
            ->getMock();

        $db->method('getColumns')->willReturnCallback($behaviour);

        return new SchemaBuilder($db);
    }

    /**
     * A driver answering `false` is an empty answer, not an iteration over a boolean.
     *
     * `getColumns()` declares `Result|false`, and `false` is what a caller gets from a
     * statement the driver refused. `while ($result->fetch())` on it is a fatal, and the
     * caller of `columnDetails()` would see it rather than the empty map that says "this
     * table could not be read".
     */
    public function testADriverThatAnswersFalseGivesAnEmptyMap(): void
    {
        // Arrange
        $schema = $this->builderWhoseColumnsRead(static fn(): bool => false);

        // Act + Assert
        $this->assertSame([], $schema->columnDetails('things'));
    }

    /**
     * And a connection that has gone away is an empty answer too.
     *
     * The caller is a seeder, a migration guard or a health check — code that runs
     * *around* the thing that is broken. Letting the exception out of a schema read turns
     * "the database is unreachable" into a stack trace from whatever happened to ask about
     * a column first.
     */
    public function testAConnectionThatRaisesGivesAnEmptyMap(): void
    {
        // Arrange
        $schema = $this->builderWhoseColumnsRead(static function (): never {
            throw new \RuntimeException('MySQL server has gone away');
        });

        // Act + Assert
        $this->assertSame([], $schema->columnDetails('things'));
    }
}
