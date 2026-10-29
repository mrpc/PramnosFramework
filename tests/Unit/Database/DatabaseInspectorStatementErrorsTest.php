<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Inspector\DatabaseInspector;

/**
 * What the statement statistics answer when the server refuses.
 *
 * The real refusals — a library missing from `shared_preload_libraries`, a role without
 * EXECUTE on `pg_stat_statements_reset()` — cannot be produced in the suite's container, which
 * preloads the library and connects as a superuser. A connection that throws stands in for them.
 */
#[CoversClass(DatabaseInspector::class)]
class DatabaseInspectorStatementErrorsTest extends TestCase
{
    /** A PostgreSQL connection that answers every statement with the given exception. */
    private function refusingDatabase(\Exception $error): Database
    {
        $db = $this->createStub(Database::class);
        $db->type = 'postgresql';
        $db->method('query')->willThrowException($error);

        return $db;
    }

    /**
     * A failed read is reported with the server's own message, not as "not installed".
     *
     * The DevPanel prints `error` instead of an install hint, and the two send somebody to
     * different places: a missing extension is a config change, a refused read is a grant.
     */
    public function testAFailedReadCarriesTheServersMessage(): void
    {
        // Arrange
        $inspector = new DatabaseInspector(
            $this->refusingDatabase(new \Exception('permission denied for view pg_stat_activity'))
        );

        // Act
        $stats = $inspector->getStatementStats();

        // Assert
        $this->assertSame('permission denied for view pg_stat_activity', $stats['error']);
        $this->assertFalse($stats['installed'], 'nothing is claimed that was not read');
        $this->assertSame([], $stats['rows']);
    }

    /**
     * A reset the server refuses is `false`, not an exception that takes the page down.
     */
    public function testARefusedResetIsFalse(): void
    {
        // Arrange
        $inspector = new DatabaseInspector(
            $this->refusingDatabase(new \Exception('permission denied for function'))
        );

        // Act & Assert
        $this->assertFalse($inspector->resetStatementStats());
    }
}
