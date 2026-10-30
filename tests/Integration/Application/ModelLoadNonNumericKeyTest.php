<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * Loading a model by a key that is not a whole number, for an integer primary key — a bot asking
 * `/testimonial/some-slug` where the id is a bigint — on MySQL and PostgreSQL.
 *
 * MySQL compared "abc" as 0 and found nothing (or row 0); PostgreSQL refused the statement with
 * "invalid input syntax for type bigint", an exception on a page that only had a bad id, and an
 * aborted transaction if one was open. Every application patched it in each model's load().
 *
 * What holds: such a key is no row, on both, without a query, and a transaction stays usable; a
 * whole number still loads, written as a string or not; and a text primary key is still looked up
 * by whatever it is given.
 */
#[CoversClass(Model::class)]
class ModelLoadNonNumericKeyTest extends TestCase
{
    private ?Database $previous = null;
    private ?Database $db = null;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query('DROP TABLE IF EXISTS pf_nonnum_int');
            $this->db->query('DROP TABLE IF EXISTS pf_nonnum_text');
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        Model::$columnCache = [];
    }

    /** Connect, make the two tables, and be the application's database. */
    private function connect(string $type, string $host, int $port): void
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        if ($type === 'postgresql') {
            $db->schema = 'public';
        }
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $db;
        $this->db       = $db;

        $db->query('DROP TABLE IF EXISTS pf_nonnum_int');
        $db->query('DROP TABLE IF EXISTS pf_nonnum_text');
        $db->query('CREATE TABLE pf_nonnum_int (id BIGINT NOT NULL PRIMARY KEY, name VARCHAR(20))');
        $db->query('CREATE TABLE pf_nonnum_text (slug VARCHAR(40) NOT NULL PRIMARY KEY, name VARCHAR(20))');
        $db->query("INSERT INTO pf_nonnum_int (id, name) VALUES (0, 'zero'), (7, 'seven')");
        $db->query("INSERT INTO pf_nonnum_text (slug, name) VALUES ('abc', 'text key')");
        Model::$columnCache = [];
    }

    /** A model of one of the tables, its load reachable. */
    private static function model(string $table, string $key): Model
    {
        $controller = (new \ReflectionClass(\Pramnos\Application\Controller::class))->newInstanceWithoutConstructor();

        return new class ($controller, $table, $key) extends Model {
            public function __construct($controller, string $table, string $key)
            {
                parent::__construct($controller);
                $this->_dbtable    = $table;
                $this->_primaryKey = $key;
            }

            /** Whether nothing was loaded. */
            public function isNew(): bool
            {
                return (bool) $this->_isnew;
            }

            /** The protected load, with the cache off so every load is a real read. */
            public function load($id): static
            {
                return parent::_load($id, null, null, false, false);
            }
        };
    }

    /**
     * "abc", "7abc" and "" for a bigint key: nothing loaded — not row 0 as MySQL's coercion
     * found — no exception on PostgreSQL, and an open transaction still takes statements.
     */
    #[DataProvider('databases')]
    public function testANonNumericKeyIsNoRow(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $this->db->startTransaction();

        // Act
        $loaded = [];
        foreach (['abc', '7abc', '', '1.5'] as $key) {
            $model    = self::model('pf_nonnum_int', 'id');
            $loaded[] = [$model->load($key)->name, $model->isNew()];
        }
        $count = $this->db->queryBuilder()->table('pf_nonnum_int')->count();
        $this->db->rollbackTransaction();

        // Assert
        $this->assertSame(array_fill(0, 4, [null, true]), $loaded);
        // The transaction was not aborted: the next statement ran.
        $this->assertSame(2, $count);
    }

    /** A whole number loads, as an int, a string of digits, or with space around it. */
    #[DataProvider('databases')]
    public function testAWholeNumberStillLoads(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        $names = array_map(static fn ($key) => self::model('pf_nonnum_int', 'id')->load($key)->name, [7, '7', ' 7 ', '0']);

        // Assert
        $this->assertSame(['seven', 'seven', 'seven', 'zero'], $names);
        // A whole number never reads the columns: the common load costs nothing.
        $this->assertSame([], Model::$columnCache);
    }

    /** A text primary key is looked up by whatever it is given — "abc" is a real key there. */
    #[DataProvider('databases')]
    public function testATextKeyIsStillLookedUp(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);

        // Act
        $found   = self::model('pf_nonnum_text', 'slug')->load('abc');
        $missing = self::model('pf_nonnum_text', 'slug')->load('nope');

        // Assert
        $this->assertSame('text key', $found->name);
        $this->assertFalse($found->isNew());
        $this->assertNull($missing->name);
    }

    /**
     * A table whose columns cannot be read is left to the database, as before: the load's own
     * query reports what is wrong.
     */
    #[DataProvider('databases')]
    public function testUnreadableColumnsLeaveItToTheDatabase(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connect($type, $host, $port);
        $this->db->throwOnError = true;

        // Act / Assert
        $this->expectException(\Throwable::class);
        self::model('pf_nonnum_missing', 'id')->load('abc');
    }
}
