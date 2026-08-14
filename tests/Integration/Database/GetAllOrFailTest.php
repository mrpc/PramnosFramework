<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use Pramnos\Database\QueryException;
use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * `QueryBuilder::getAllOrFail()` — the distinction `getAll()` discards, put back.
 *
 * `getAll()` answers `[]` for a failed query *and* for an empty table, which its docblock
 * presents as the convenience it is. The sharp edge is where that helper gets reached for: it is
 * the obvious way to read a list, and the lists whose empty answer is most plausible are exactly
 * the ones where it is most consequential — settings, permissions, bans, allowlists.
 *
 * A consumer measured it rather than reasoned about it: they renamed the `settings` table away,
 * `getGlobalSettings()` returned `array()` without throwing, and that answer was **cached as the
 * installation's configuration**. Every feature toggle at its compiled-in default for the whole
 * TTL, nothing in the logs. A ban list that fails to read is an empty ban list, and one cache
 * call later it is a cached empty ban list.
 *
 * These tests drive a real database and rename a table away, because that is the only way to
 * prove the difference between "no rows" and "no table" is preserved.
 */
class GetAllOrFailTest extends DatabaseTestCase
{
    /**
     * MySQL, in the project's own container.
     *
     * @return array<string, mixed> Connection properties
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 3306,
        ];
    }

    /**
     * One table, standing in for any guarded list.
     *
     * @return string[] Table names
     */
    protected static function ownedTables(): array
    {
        return ['goaf_blacklist'];
    }

    /**
     * A list whose empty answer would be a decision.
     *
     * @return string[] DDL statements
     */
    protected static function schemaStatements(): array
    {
        return [
            'CREATE TABLE `goaf_blacklist` (
                id      INT AUTO_INCREMENT PRIMARY KEY,
                pattern VARCHAR(100) NOT NULL
            ) ENGINE=InnoDB',
        ];
    }

    /**
     * An empty table returns an empty array, exactly like `getAll()`.
     *
     * The point of the method is the failure case; if it also changed the success case it would
     * not be a drop-in for a caller that wants to fail closed.
     */
    public function testAnEmptyTableReturnsAnEmptyArray(): void
    {
        // Act
        $rows = $this->db->queryBuilder()->from('goaf_blacklist')->getAllOrFail();

        // Assert
        $this->assertSame([], $rows);
    }

    /**
     * Rows come back the same as from `getAll()`.
     */
    public function testRowsAreReturnedUnchanged(): void
    {
        // Arrange
        $this->db->query("INSERT INTO `goaf_blacklist` (pattern) VALUES ('spam.example'), ('bad.example')");

        // Act
        $rows = $this->db->queryBuilder()->from('goaf_blacklist')->orderBy('id')->getAllOrFail();

        // Assert
        $this->assertCount(2, $rows);
        $this->assertSame('spam.example', $rows[0]['pattern']);
    }

    /**
     * A query against a table that is not there throws instead of answering `[]`.
     *
     * This is the whole method. The table is renamed away rather than never created, because
     * that is the shape the outage had: a table that existed when the code was written and does
     * not now.
     */
    public function testAMissingTableThrowsInsteadOfLookingEmpty(): void
    {
        // Arrange — the table exists, then does not
        $this->db->query('RENAME TABLE `goaf_blacklist` TO `goaf_blacklist_probe`');

        try {
            // Act & Assert
            $this->expectException(QueryException::class);
            $this->db->queryBuilder()->from('goaf_blacklist')->getAllOrFail();
        } finally {
            // Cleanup — put it back for the class's remaining tests and its teardown
            $this->db->query('RENAME TABLE `goaf_blacklist_probe` TO `goaf_blacklist`');
        }
    }

    /**
     * On **MySQL**, `getAll()` was never ambiguous — the driver throws.
     *
     * This is the refinement the original filing did not have, and it matters: with
     * `throwOnError` off, a failed prepare *returns false* on PostgreSQL and *throws*
     * `mysqli_sql_exception` on MySQL. So `getAll()` collapses failure into `[]` on PostgreSQL
     * only, and an application developed against one engine and deployed against the other
     * gets a different failure mode for free.
     *
     * Asserted rather than assumed, because it is the reason `getAllOrFail()` wraps whatever
     * the driver did into one exception type instead of only checking for `false`.
     * {@see GetAllOrFailPostgreSQLTest} holds the other half.
     */
    public function testOnMysqlEvenGetAllSurfacesTheFailure(): void
    {
        // Arrange
        $this->db->query('RENAME TABLE `goaf_blacklist` TO `goaf_blacklist_probe`');

        try {
            // Act
            $threw = false;
            try {
                $this->db->queryBuilder()->from('goaf_blacklist')->getAll();
            } catch (\Throwable) {
                $threw = true;
            }

            // Assert
            $this->assertTrue($threw, 'MySQL reports a missing table by throwing, not by [].');
        } finally {
            // Cleanup
            $this->db->query('RENAME TABLE `goaf_blacklist_probe` TO `goaf_blacklist`');
        }
    }

    /**
     * The exception carries the SQL that failed.
     *
     * A "the query failed" with no query in it sends the reader to the logs to find out which
     * read it was, and the logs are exactly what was empty in the reported outage.
     */
    public function testTheExceptionNamesTheQuery(): void
    {
        // Arrange
        $this->db->query('RENAME TABLE `goaf_blacklist` TO `goaf_blacklist_probe`');

        try {
            // Act
            $this->db->queryBuilder()->from('goaf_blacklist')->getAllOrFail();
            $this->fail('getAllOrFail() must throw when the table is missing.');
        } catch (QueryException $e) {
            // Assert
            $this->assertStringContainsString('goaf_blacklist', $e->getQuery());
        } finally {
            // Cleanup
            $this->db->query('RENAME TABLE `goaf_blacklist_probe` TO `goaf_blacklist`');
        }
    }
}
