<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;
use Pramnos\Framework\Testing\DatabaseTestCase;

/**
 * A PHP bool bound on PostgreSQL, into a `boolean` column and into a `smallint` one.
 *
 * A table moved from MySQL keeps its `tinyint(1)` flags as `smallint`. A bool was bound as
 * `'true'`/`'false'`, which a boolean reads and a smallint refuses: "invalid input syntax for type
 * smallint". It is bound as `'1'`/`'0'`, which both read — through the query builder and through
 * `selectOne()`'s bindings.
 */
#[CoversClass(Database::class)]
class BooleanBindingPostgreSQLTest extends DatabaseTestCase
{
    /** @return array<string, mixed> PostgreSQL, in the timescaledb container */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'postgresql',
            'server'   => 'timescaledb',
            'user'     => 'postgres',
            'password' => 'secret',
            'database' => TEST_DATABASE,
            'port'     => 5432,
            'schema'   => 'public',
        ];
    }

    /** @return string[] The flags table */
    protected static function ownedTables(): array
    {
        return ['bbp_flags'];
    }

    /** @return string[] A smallint flag, as MySQL's tinyint(1) arrives, and a boolean one */
    protected static function schemaStatements(): array
    {
        return ['CREATE TABLE bbp_flags (id SERIAL PRIMARY KEY, small SMALLINT NOT NULL, real_bool BOOLEAN NOT NULL)'];
    }

    /**
     * A bool is written into both kinds of column, and found by both — by the builder's WHERE
     * and by a `selectOne()` binding.
     */
    public function testABoolReachesASmallintAndABoolean(): void
    {
        // Arrange
        $rows = $this->db->queryBuilder()->table('bbp_flags');

        // Act
        $rows->insert(['small' => true, 'real_bool' => true]);
        $this->db->queryBuilder()->table('bbp_flags')->insert(['small' => false, 'real_bool' => false]);
        $onSmall = (int) $this->db->queryBuilder()->table('bbp_flags')->where('small', true)->count();
        $onBool  = (int) $this->db->queryBuilder()->table('bbp_flags')->where('real_bool', false)->count();
        $one     = $this->db->selectOne('SELECT small, real_bool FROM bbp_flags WHERE small = ? AND real_bool = ?', [true, true]);

        // Assert
        $this->assertSame(1, $onSmall);
        $this->assertSame(1, $onBool);
        $this->assertNotNull($one, 'selectOne() found nothing with bool bindings');
        $this->assertSame(1, (int) $one['small']);
    }
}
