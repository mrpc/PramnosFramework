<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework\Testing;

use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * `DatabaseTestCase` on PostgreSQL — the paths MySQL cannot reach.
 *
 * Three things differ per engine and each one fails loudly if it is wrong: identifiers are
 * double-quoted rather than backticked, `SET FOREIGN_KEY_CHECKS` does not exist and must not
 * be sent, and restarting a counter means `ALTER SEQUENCE` rather than `ALTER TABLE`.
 *
 * The class is deliberately thin: the lifecycle itself is asserted in
 * {@see DatabaseTestCaseTest}, and repeating it here would only make both slower.
 */
class DatabaseTestCasePostgreSQLTest extends DatabaseTestCase
{
    /**
     * PostgreSQL, in the timescaledb container.
     *
     * @return array<string, mixed> Connection properties
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'postgresql',
            'server'   => 'timescaledb',
            'user'     => 'postgres',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 5432,
            'schema'   => 'public',
        ];
    }

    /**
     * One table with a sequence behind its primary key.
     *
     * @return string[] Table names
     */
    protected static function ownedTables(): array
    {
        return ['dtc_pg_rows'];
    }

    /**
     * A `SERIAL` key, so the sequence path has something to restart.
     *
     * @return string[] DDL statements
     */
    protected static function schemaStatements(): array
    {
        return [
            'CREATE TABLE dtc_pg_rows (
                id   SERIAL PRIMARY KEY,
                name VARCHAR(50) NOT NULL
            )',
        ];
    }

    /**
     * Exercises the `ALTER SEQUENCE` branch.
     *
     * @return bool Always true here
     */
    protected static function resetAutoIncrement(): bool
    {
        return true;
    }

    /**
     * The schema is built, and each test finds it empty.
     *
     * On PostgreSQL this is also the assertion that no MySQL-only statement was sent: a
     * `SET FOREIGN_KEY_CHECKS` would have raised a syntax error before reaching here.
     */
    public function testTheLifecycleWorksWithoutMysqlOnlyStatements(): void
    {
        // Assert — empty on arrival
        $result = $this->db->query('SELECT COUNT(*) AS n FROM dtc_pg_rows');
        $this->assertSame(0, (int) $result->fields['n']);

        // Act
        $this->db->query("INSERT INTO dtc_pg_rows (name) VALUES ('a')");

        // Assert
        $result = $this->db->query('SELECT COUNT(*) AS n FROM dtc_pg_rows');
        $this->assertSame(1, (int) $result->fields['n']);
    }

    /**
     * `ALTER SEQUENCE` restarts a `SERIAL` column.
     *
     * The sequence name is guessed by PostgreSQL's own convention (`<table>_id_seq`), which
     * is what `SERIAL` creates. If that guess is ever wrong the reset silently does nothing,
     * so it is worth asserting rather than trusting.
     */
    public function testTheSequenceIsRestarted(): void
    {
        // Arrange
        $this->db->query("INSERT INTO dtc_pg_rows (name) VALUES ('a'), ('b'), ('c')");
        $this->emptyOwnedTables();

        // Act
        $this->db->query("INSERT INTO dtc_pg_rows (name) VALUES ('after reset')");

        // Assert
        $result = $this->db->query('SELECT id FROM dtc_pg_rows');
        $this->assertSame(1, (int) $result->fields['id']);
    }

    /**
     * Identifiers are double-quoted, not backticked.
     *
     * A backtick is a syntax error in PostgreSQL, so getting this wrong breaks the class
     * before any test body runs.
     */
    public function testItQuotesIdentifiersForPostgres(): void
    {
        // Act & Assert
        $this->assertSame('"widgets"', self::quote($this->db, 'widgets'));
    }
}
