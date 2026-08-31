<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * A model over a `schema.table`, on the backend that has no schemas.
 *
 * Two failures found the same way — by running an admin screen that had never been run — and both
 * of them silent in the direction that matters: the framework's own code could not read or write
 * its own table.
 *
 * 1. `Role` declares `authserver.roles`. PostgreSQL reads that as a schema; MySQL reads it as
 *    **another database**, so every `_load()` and `_save()` asked for
 *    `pramnos_test.authserver.roles`. The QueryBuilder has resolved this since `from()` was taught
 *    to; the Model's raw SQL never asked.
 * 2. A `NOT NULL` column with nothing in it was coerced to `''` — a fine empty string and an
 *    impossible date. `created_at` is `NOT NULL DEFAULT CURRENT_TIMESTAMP`, so a model that never
 *    sets it was asking for the column's default and getting timestamp zero, which strict MySQL
 *    and PostgreSQL both refuse.
 *
 * Pinned at the Model rather than through `Role`, because the next model over a `pramnos.*` table
 * inherits both.
 *
 * Requires the Docker MySQL container.
 */
#[CoversClass(Model::class)]
class ModelOverASchemaTableTest extends BaseTestCase
{
    private \Pramnos\Database\Database $db;
    private ?\Pramnos\Database\Database $previousSingleton = null;

    private string $physical;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        Application::getInstance();

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->previousSingleton = $dbRef;
        $dbRef = null;
        $this->db = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if ($this->db->type === 'postgresql') {
            $this->markTestSkipped('The flattening is the MySQL half of the convention.');
        }

        $this->physical = $this->db->schema()->resolveTableName('pramnos.modeltest');
        $this->db->query("DROP TABLE IF EXISTS `{$this->physical}`");
        $this->db->query(
            "CREATE TABLE `{$this->physical}` ("
            . "  id INT AUTO_INCREMENT PRIMARY KEY,"
            . "  label VARCHAR(50) NOT NULL DEFAULT '',"
            . "  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP"
            . ") ENGINE=InnoDB"
        );
    }

    protected function tearDown(): void
    {
        $this->db->query("DROP TABLE IF EXISTS `{$this->physical}`");

        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->previousSingleton;
    }

    /** A model whose table is named the way the framework names its own. */
    private function model(): Model
    {
        $controller = $this->getMockBuilder(\Pramnos\Application\Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database        = $this->db;
        $controller->application = $app;

        return new class ($controller) extends Model {
            protected $_dbtable    = 'pramnos.modeltest';

            protected $_primaryKey = 'id';

            public $id = 0;

            public $label = '';

            public $created_at = null;

            public function load(int $id): static
            {
                return parent::_load($id);
            }

            public function save(): static
            {
                return parent::_save();
            }
        };
    }

    /**
     * The schema name is flattened, so the write lands in the current database.
     *
     * Before this, the insert threw `Table 'pramnos_test.pramnos.modeltest' doesn't exist` — the
     * schema read as a database name.
     */
    public function testAModelOverASchemaTableCanWrite(): void
    {
        // Arrange
        $model        = $this->model();
        $model->label = 'written';

        // Act
        $model->save();

        // Assert
        $row = $this->db->query("SELECT * FROM `{$this->physical}` WHERE label = 'written'");
        $this->assertSame(1, (int) $row->numRows);
        $this->assertGreaterThan(0, (int) $model->id, 'the insert id came back');
    }

    /** And read it back by its key. */
    public function testAModelOverASchemaTableCanRead(): void
    {
        // Arrange
        $this->db->query("INSERT INTO `{$this->physical}` (label) VALUES ('stored')");
        $id = (int) $this->db->getInsertId();

        // Act
        $model = $this->model()->load($id);

        // Assert
        $this->assertSame('stored', $model->label);
    }

    /**
     * A NOT NULL timestamp the model never set gets the column's default, not zero.
     *
     * The whole failure: `''` into a `DATETIME` under strict mode is an error, so creating a row
     * through a model that has no opinion about `created_at` threw rather than saved.
     */
    public function testATemporalDefaultIsLeftToTheColumn(): void
    {
        // Arrange
        $model             = $this->model();
        $model->label      = 'defaulted';
        $model->created_at = null;

        // Act
        $model->save();

        // Assert
        $row = $this->db->query("SELECT created_at FROM `{$this->physical}` WHERE label = 'defaulted'");
        $this->assertNotSame('0000-00-00 00:00:00', $row->fields['created_at']);
        $this->assertGreaterThan(
            0,
            strtotime((string) $row->fields['created_at']),
            'created_at holds a real time'
        );
    }

    /**
     * A value the model *does* set is still written.
     *
     * The fix omits the column only when there is nothing to say about it; omitting a set value
     * would be the same silent data loss from the other side.
     */
    public function testASetTimestampIsStillWritten(): void
    {
        // Arrange
        $model             = $this->model();
        $model->label      = 'explicit';
        $model->created_at = '2020-02-02 03:04:05';

        // Act
        $model->save();

        // Assert
        $row = $this->db->query("SELECT created_at FROM `{$this->physical}` WHERE label = 'explicit'");
        $this->assertSame('2020-02-02 03:04:05', (string) $row->fields['created_at']);
    }

    /**
     * A NOT NULL string with nothing in it still becomes `''`, as it always did.
     *
     * The coercion was only ever wrong for dates. Changing it for strings would break every
     * model that relies on a missing value writing an empty one.
     */
    public function testANullStringIsStillWrittenAsEmpty(): void
    {
        // Arrange
        $model        = $this->model();
        $model->label = null;

        // Act
        $model->save();

        // Assert
        $row = $this->db->query("SELECT label FROM `{$this->physical}` WHERE id = " . (int) $model->id);
        $this->assertSame('', (string) $row->fields['label']);
    }

    /**
     * `#PREFIX#` keeps winning over the dot, because it is an explicit instruction.
     *
     * A name like `#PREFIX#some.thing` has already said where the prefix goes; resolving the dot
     * as a schema on top of that would rename the table twice.
     */
    public function testAnExplicitPrefixTokenIsNotTreatedAsASchema(): void
    {
        // Arrange
        $model = $this->model();

        // Act
        $name = $model->getFullTableName('#PREFIX#users');

        // Assert
        $this->assertSame($this->db->prefix . 'users', $name);
    }
}
