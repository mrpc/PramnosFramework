<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\QueryException;
use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * `getAllOrFail()` on PostgreSQL — the engine where the ambiguity actually exists.
 *
 * With `throwOnError` off (the default), a failed prepare **returns false** on PostgreSQL and
 * **throws** on MySQL. `getAll()` turns that `false` into `[]`, so on PostgreSQL — and only
 * there — a failed read is indistinguishable from an empty table.
 *
 * That is where the reported outage happened: PostgreSQL 17, an unreadable `settings` table, and
 * `getAll()` answering "no settings" — which was then cached as the installation's configuration
 * for the whole TTL, with nothing in the logs.
 *
 * So this class asserts both halves against a real PostgreSQL: that `getAll()` still answers `[]`
 * (the documented, BC behaviour), and that `getAllOrFail()` does not.
 *
 * @see GetAllOrFailTest for the MySQL half, where the driver throws on its own
 */
class GetAllOrFailPostgreSQLTest extends DatabaseTestCase
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
     * One table, standing in for any guarded list.
     *
     * @return string[] Table names
     */
    protected static function ownedTables(): array
    {
        return ['goafpg_blacklist'];
    }

    /**
     * A list whose empty answer would be a decision.
     *
     * @return string[] DDL statements
     */
    protected static function schemaStatements(): array
    {
        return [
            'CREATE TABLE goafpg_blacklist (
                id      SERIAL PRIMARY KEY,
                pattern VARCHAR(100) NOT NULL
            )',
        ];
    }

    /**
     * Rows and empty tables behave exactly as with `getAll()`.
     */
    public function testSuccessIsUnchanged(): void
    {
        // Assert — empty
        $this->assertSame([], $this->db->queryBuilder()->from('goafpg_blacklist')->getAllOrFail());

        // Arrange
        $this->db->query("INSERT INTO goafpg_blacklist (pattern) VALUES ('spam.example')");

        // Act & Assert — one row
        $rows = $this->db->queryBuilder()->from('goafpg_blacklist')->getAllOrFail();
        $this->assertCount(1, $rows);
        $this->assertSame('spam.example', $rows[0]['pattern']);
    }

    /**
     * This is the reported bug, reproduced: `getAll()` cannot tell a missing table from an empty one.
     *
     * Kept as a test rather than only as prose, because it is the behaviour `getAllOrFail()` exists
     * to sit beside — and because it is documented, BC behaviour that must not change by accident.
     * The day somebody makes `getAll()` throw here, this test says so.
     */
    public function testGetAllCannotTellFailureFromEmptyOnPostgres(): void
    {
        // Arrange — the table exists, then does not
        $this->db->query('ALTER TABLE goafpg_blacklist RENAME TO goafpg_blacklist_probe');

        try {
            // Act
            $rows = $this->db->queryBuilder()->from('goafpg_blacklist')->getAll();

            // Assert — the same answer an empty table would have given
            $this->assertSame([], $rows);
        } finally {
            // Cleanup
            $this->db->query('ALTER TABLE goafpg_blacklist_probe RENAME TO goafpg_blacklist');
        }
    }

    /**
     * `getAllOrFail()` throws for the same query, with the SQL attached.
     */
    public function testGetAllOrFailThrowsForTheSameQuery(): void
    {
        // Arrange
        $this->db->query('ALTER TABLE goafpg_blacklist RENAME TO goafpg_blacklist_probe');

        try {
            // Act
            $this->db->queryBuilder()->from('goafpg_blacklist')->getAllOrFail();
            $this->fail('getAllOrFail() must throw when the table is missing.');
        } catch (QueryException $e) {
            // Assert
            $this->assertStringContainsString('goafpg_blacklist', $e->getQuery());
        } finally {
            // Cleanup
            $this->db->query('ALTER TABLE goafpg_blacklist_probe RENAME TO goafpg_blacklist');
        }
    }
}
