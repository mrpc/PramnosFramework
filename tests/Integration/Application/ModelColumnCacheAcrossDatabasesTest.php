<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;
use Pramnos\Database\Database;

/**
 * A model's column cache tells two databases with the same table apart.
 *
 * `Model::$columnCache` was keyed by the table name alone. A process holding two connections
 * to databases with a table of the same name — two MySQL databases on one server, a
 * migration copying between them — read the columns of whichever had been introspected first
 * for both. The write path builds its column list from that
 * cache, so a column only the second table had was silently left out of every save.
 *
 * The two tables here differ by one column, and the row is read back from the second.
 */
#[CoversClass(Model::class)]
class ModelColumnCacheAcrossDatabasesTest extends TestCase
{
    private const TABLE = 'colcache_probe';

    private ?Database $previous = null;

    /** @var list<Database> */
    private array $connections = [];

    /**
     * A connection, or a skipped test when the container is not there.
     */
    private function connect(string $type, string $host, int $port, string $database = 'pramnos_test'): Database
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = $database;
        $db->schema   = $type === 'mysql' ? null : 'public';

        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $db->schema()->dropTableIfExists(self::TABLE);
        $this->connections[] = $db;

        return $db;
    }

    /** Make $db the one Model reads and writes through. */
    private function use(Database $db): void
    {
        $singleton = &Database::getInstance();
        $singleton = $db;
    }

    protected function setUp(): void
    {
        $this->previous = Database::getInstance();
        Model::$columnCache = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->connections as $db) {
            if ($db->connected) {
                $db->schema()->dropTableIfExists(self::TABLE);
            }
        }
        if (isset($this->connections[0]) && $this->connections[0]->connected) {
            $this->connections[0]->query('DROP DATABASE IF EXISTS pramnos_test_colcache');
        }
        $singleton = &Database::getInstance();
        $singleton = $this->previous;
        Model::$columnCache = [];
    }

    /**
     * Saving through the second database writes the column only its table has.
     *
     * Two MySQL databases on one server, because that is where the old key collided: a
     * PostgreSQL table name carries its schema, so it never shared a key with MySQL's, while
     * two MySQL databases — staging and production on one server, a tenant per database —
     * give the same table the same bare name.
     */
    public function testEachDatabaseIsSavedWithItsOwnColumns(): void
    {
        // Arrange — the same table in two databases; only the second has `note`
        $first = $this->connect('mysql', 'db', 3306);
        $first->query('CREATE DATABASE IF NOT EXISTS pramnos_test_colcache');
        $second = $this->connect('mysql', 'db', 3306, 'pramnos_test_colcache');

        $first->schema()->createTable(self::TABLE, function ($table) {
            $table->increments('id');
            $table->string('name', 50);
        });
        $second->schema()->createTable(self::TABLE, function ($table) {
            $table->increments('id');
            $table->string('name', 50);
            $table->string('note', 50)->nullable();
        });

        // Act — a save through the first fills the cache, then one through the second
        $this->use($first);
        $a = new ColumnCacheProbe();
        $a->name = 'in the first';
        $a->save();

        $this->use($second);
        $b = new ColumnCacheProbe();
        $b->name = 'in the second';
        $b->note = 'only here';
        $b->save();

        // Assert — the column only the second table has was written
        $row = $second->queryBuilder()->table(self::TABLE)->where('name', 'in the second')->first();
        $this->assertSame(1, (int) $row->numRows);
        $this->assertSame('only here', $row->fields['note'], 'the save used the other database\'s columns');
        $this->assertSame(1, (int) $first->queryBuilder()->table(self::TABLE)->where('name', 'in the first')->count());
    }
}

/**
 * A model over the probe table.
 */
class ColumnCacheProbe extends Model
{
    /** @var int|null */
    public $id;

    /** @var string|null */
    public $name;

    /** @var string|null */
    public $note;

    protected $_primaryKey = 'id';

    protected $_dbtable = 'colcache_probe';

    /** No controller is needed to save. */
    public function __construct()
    {
        parent::__construct(new \Pramnos\Application\Controller());
    }

    /** Write the row, as a model's own save() does. */
    public function save(): void
    {
        $this->_save(null, null, false);
    }
}
