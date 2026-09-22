<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaBuilder;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Connection;

/**
 * `SchemaBuilder::columnDetails()` — one column shape on both engines.
 *
 * WHAT: for the same table, both drivers answer the same `hasDefault`, `isPrimary`,
 *       `isForeign` and `nullable`.
 *
 * WHY:  `getColumns()` answers what the driver answers, and the two do not agree on a
 *       single key that matters:
 *
 *       | | MySQL | PostgreSQL |
 *       |---|---|---|
 *       | default | `COLUMN_DEFAULT` | `column_default` |
 *       | primary key | `Key` = `PRI` | `PrimaryKey` = `true` |
 *
 *       Code written against one spelling works on one engine and **silently does
 *       nothing** on the other, because a missing array key is `null` and `null` reads as
 *       "no default" and "not a key". That has now bitten three times: a migration that
 *       rebuilt a table it had already widened, a test that read another database's
 *       schema, and a seeder that filled `bigserial` primary keys with an integer and
 *       collided with every row already in the table.
 *
 *       The last one is why this exists. `passkey_credentials` reports its key as
 *       `nextval('…')` on PostgreSQL — not as anything auto-increment-shaped — so the
 *       "skip auto-increment" guard did not fire, four of seven seeds were refused, and
 *       every refusal was absorbed. The sweep stayed green and the person card rendered
 *       four fewer panels than it could.
 *
 * Both lanes, and that is the whole point: a single-lane test of this would pass on the
 * engine it was written against and prove nothing about the other.
 */
#[CoversClass(SchemaBuilder::class)]
class ColumnDetailsTest extends BaseTestCase
{
    private Database $db;

    private string $table = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $this->db = Connection::fresh();
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->table  = 'column_details_probe_' . bin2hex(random_bytes(4));
        $this->build();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    protected function tearDown(): void
    {
        $this->drop();

        parent::tearDown();
    }

    private function isPostgreSQL(): bool
    {
        return $this->db->type === 'postgresql';
    }

    private function tableName(string $suffix = ''): string
    {
        return $this->db->prefix . $this->table . $suffix;
    }

    private function drop(): void
    {
        if ($this->table === '') {
            return;
        }

        foreach (['', '_parent'] as $suffix) {
            try {
                $this->db->query('DROP TABLE IF EXISTS ' . $this->tableName($suffix)
                    . ($this->isPostgreSQL() ? ' CASCADE' : ''));
            } catch (\Throwable) {
                // Already gone.
            }
        }
    }

    /**
     * A parent table and a child with one of every shape this is about.
     *
     * An auto-incrementing key, a plain required column, a nullable one, one with a
     * literal default and one that is a foreign key — because each takes a different
     * branch and each was a different bug.
     */
    private function build(): void
    {
        $this->drop();

        if ($this->isPostgreSQL()) {
            $this->db->query(
                'CREATE TABLE ' . $this->tableName('_parent') . ' (parentid bigserial PRIMARY KEY)'
            );
            $this->db->query(
                'CREATE TABLE ' . $this->tableName() . ' ('
                . 'id bigserial PRIMARY KEY, '
                . 'userid bigint NOT NULL, '
                . 'note text, '
                . 'status varchar(20) NOT NULL DEFAULT \'draft\', '
                . 'parentid bigint NOT NULL REFERENCES ' . $this->tableName('_parent') . '(parentid))'
            );

            return;
        }

        $this->db->query(
            'CREATE TABLE ' . $this->tableName('_parent')
            . ' (parentid BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB'
        );
        $this->db->query(
            'CREATE TABLE ' . $this->tableName() . ' ('
            . 'id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY, '
            . 'userid BIGINT NOT NULL, '
            . 'note TEXT NULL, '
            . 'status VARCHAR(20) NOT NULL DEFAULT \'draft\', '
            . 'parentid BIGINT NOT NULL, '
            . 'CONSTRAINT fk_' . $this->table . '_parent FOREIGN KEY (parentid) '
            . 'REFERENCES ' . $this->tableName('_parent') . '(parentid)) ENGINE=InnoDB'
        );
    }

    /**
     * The shape is read, and it is the table's.
     *
     * The assertion the rest depends on: an empty answer satisfies every expectation
     * below by vacuum.
     */
    public function testTheColumnsAreRead(): void
    {
        // Act
        $columns = $this->db->schema()->columnDetails($this->tableName());

        // Assert
        $this->assertSame(
            ['id', 'userid', 'note', 'status', 'parentid'],
            array_keys($columns)
        );
    }

    /**
     * A generated key is never something to invent a value for, on either engine.
     *
     * **The one that cost four panels**, and the assertion is deliberately the
     * *disjunction* a caller uses rather than one flag, because the two engines describe
     * the same column differently and neither description is wrong:
     *
     *  - PostgreSQL reports a `bigserial` as `column_default = nextval('…')` — a default,
     *    and nothing auto-increment-shaped.
     *  - MySQL reports `Key = PRI` and a **null** default; its `getColumns()` does not
     *    return `Extra`, so there is no auto-increment marker to read either.
     *
     * So `hasDefault` alone is true on one engine and false on the other, correctly. What
     * holds on both is that the column is generated and must be left alone, and
     * `hasDefault || isPrimary` is how a caller asks that. A seeder reading either flag by
     * itself invents a value on one engine and collides with every row already there.
     */
    public function testAGeneratedKeyIsNeverSomethingToInventAValueFor(): void
    {
        // Act
        $id = $this->db->schema()->columnDetails($this->tableName())['id'];

        // Assert — the question a caller actually asks
        $this->assertTrue(
            $id['hasDefault'] || $id['isPrimary'],
            'a generated key reads as an ordinary required column, so a seeder will fill it'
        );
        $this->assertTrue($id['isPrimary']);
        $this->assertFalse($id['nullable']);

        // And the engine-specific half, so a change to either driver's shape is visible
        // here rather than in whatever breaks three months later.
        if ($this->isPostgreSQL()) {
            $this->assertTrue($id['hasDefault'], 'nextval() is no longer read as a default');
        }
    }

    /** A required column with no default is exactly that. */
    public function testARequiredColumnWithNoDefaultSaysSo(): void
    {
        // Act
        $userid = $this->db->schema()->columnDetails($this->tableName())['userid'];

        // Assert — the only shape a seeder may invent a value for
        $this->assertFalse($userid['hasDefault']);
        $this->assertFalse($userid['isPrimary']);
        $this->assertFalse($userid['isForeign']);
        $this->assertFalse($userid['nullable']);
    }

    /** A literal default is a default. */
    public function testALiteralDefaultIsReported(): void
    {
        // Act
        $status = $this->db->schema()->columnDetails($this->tableName())['status'];

        // Assert
        $this->assertTrue($status['hasDefault']);
        $this->assertFalse($status['nullable']);
    }

    /** And a nullable column is nullable. */
    public function testANullableColumnIsReported(): void
    {
        // Act
        $note = $this->db->schema()->columnDetails($this->tableName())['note'];

        // Assert
        $this->assertTrue($note['nullable']);
    }

    /**
     * A foreign key is reported as one, with what it points at.
     *
     * The other refusal: a value invented for `tokenactions.urlid` cannot point anywhere,
     * and the insert is refused by the constraint. Knowing a column is a foreign key is
     * what separates "a row so the loop runs" from "a row claiming something untrue about
     * another table".
     */
    public function testAForeignKeyIsReportedWithItsTarget(): void
    {
        // Act
        $parent = $this->db->schema()->columnDetails($this->tableName())['parentid'];

        // Assert
        $this->assertTrue($parent['isForeign'], 'a foreign key reads as an ordinary column');
        $this->assertStringContainsString($this->table . '_parent', $parent['foreignTable']);
    }

    /** A table that is not there is an empty answer rather than a failure. */
    public function testAMissingTableIsEmptyRatherThanAnError(): void
    {
        // Act + Assert
        $this->assertSame([], $this->db->schema()->columnDetails('no_such_table_' . bin2hex(random_bytes(4))));
    }
}
