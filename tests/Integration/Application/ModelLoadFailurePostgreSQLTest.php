<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Database\QueryException;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * A model that cannot be loaded, on PostgreSQL.
 *
 * On MySQL a failed query throws `mysqli_sql_exception`; on PostgreSQL a failed prepare returns
 * `false`, and `_load()` read `->numRows` from it — "Attempt to read property numRows on false",
 * a warning, and a model that looked loaded with nothing in it. It throws a `QueryException`.
 */
#[CoversClass(Model::class)]
class ModelLoadFailurePostgreSQLTest extends BaseTestCase
{
    private ?\Pramnos\Database\Database $previousSingleton = null;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'pg_settings.php');
        Application::getInstance();
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->previousSingleton = $dbRef;
        $dbRef = null;
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }
        // The default, as an application has it: a failed prepare returns false on PostgreSQL.
        $db->throwOnError = false;
    }

    protected function tearDown(): void
    {
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->previousSingleton;
    }

    /**
     * Loading from a table that does not exist throws a QueryException naming the query,
     * instead of reading a property of `false`.
     */
    public function testLoadingFromATableThatIsNotThereThrows(): void
    {
        // Arrange
        $controller = $this->getMockBuilder(\Pramnos\Application\Controller::class)->disableOriginalConstructor()->getMock();
        $model = new class ($controller) extends Model {
            protected $_dbtable    = 'pf_no_such_table_for_load';
            protected $_primaryKey = 'id';

            /** The protected load, reachable. */
            public function load(int $id): static
            {
                return parent::_load($id);
            }
        };

        // Act
        try {
            $model->load(1);
            $thrown = null;
        } catch (QueryException $exception) {
            $thrown = $exception;
        }

        // Assert
        $this->assertNotNull($thrown, 'a failed load went unnoticed');
        $this->assertStringContainsString('pf_no_such_table_for_load', $thrown->getQuery());
    }
}
