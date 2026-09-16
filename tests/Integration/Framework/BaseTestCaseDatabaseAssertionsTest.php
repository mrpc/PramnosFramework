<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Framework;

use Pramnos\Application\Settings;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The database assertions `BaseTestCase` documents, against a real database.
 *
 * **They had never run.** `assertDatabaseHas()` called `prepare()` on `$this->pdo`, which
 * is declared and never assigned — `getConnection()` built a PDO and returned it without
 * caching, so its own `if ($this->pdo !== null)` was unreachable. Assigning the connection
 * by hand did not help either: `getConnection()` reads `static::$dbConfig`, also declared
 * and never populated, so it failed one layer down with
 * `buildDsn(): Argument #2 ($host) must be of type string, null given`.
 *
 * Both are offered to every application in the Testing Guide. A test that calls a
 * documented assertion should not be the thing that finds out it has never worked, so
 * these run it: a real table, a row inserted and removed, and both assertions in both
 * directions.
 *
 * Requires the Docker MySQL container (host: db, port: 3306).
 */
class BaseTestCaseDatabaseAssertionsTest extends BaseTestCase
{
    private const TABLE = 'basetestcase_assertions';

    /** @var array|object|null */
    private static $previousDbConfig;

    protected function setUp(): void
    {
        parent::setUp();

        Settings::loadSettings(ROOT . \DS . 'tests' . \DS . 'fixtures' . \DS . 'app' . \DS . 'settings.php');

        /*
         * Pinned rather than inherited, for two reasons.
         *
         * The suite shares a process and an earlier class may have pointed the settings
         * at PostgreSQL — the first version of this file did not pin them, passed alone,
         * and failed in a full run on a backtick the other dialect does not accept. And
         * setting it is the documented way to aim these assertions at a connection of your
         * own, so pinning it exercises the override as well as removing the ambient
         * dependency.
         */
        self::$previousDbConfig = self::$dbConfig;
        self::$dbConfig = (array) Settings::getSetting('database');

        try {
            $pdo = $this->getConnection();
        } catch (\RuntimeException $exception) {
            $this->markTestSkipped('MySQL container not reachable: ' . $exception->getMessage());
        }

        $pdo->exec('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        $pdo->exec(
            'CREATE TABLE `' . self::TABLE . '` ('
            . '`id` INT NOT NULL AUTO_INCREMENT, `name` VARCHAR(50) NOT NULL, PRIMARY KEY (`id`)'
            . ') ENGINE=InnoDB'
        );
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        }

        // Static, so it would otherwise answer for every class after this one.
        self::$dbConfig = self::$previousDbConfig;

        parent::tearDown();
    }

    /**
     * `getConnection()` caches, so the second call is the same handle.
     *
     * The property this assigns is what the assertions below read. Asserted on identity
     * rather than on "it returns a PDO", because returning a *new* PDO every time is
     * exactly what it used to do and would satisfy the weaker check.
     */
    public function testTheConnectionIsCachedOnTheProperty(): void
    {
        // Act
        $first  = $this->getConnection();
        $second = $this->getConnection();

        // Assert
        $this->assertSame($first, $second);
        $this->assertSame($first, $this->pdo, 'the property the assertions read must be the one assigned');
    }

    /**
     * A row that is there passes `assertDatabaseHas()` and fails `assertDatabaseMissing()`.
     */
    public function testAPresentRowIsFoundAndNotMissing(): void
    {
        // Arrange
        $this->getConnection()->exec("INSERT INTO `" . self::TABLE . "` (`name`) VALUES ('present')");

        // Act & Assert
        $this->assertDatabaseHas(self::TABLE, ['name' => 'present']);

        // …and the negative assertion really is negative, rather than passing on anything.
        try {
            $this->assertDatabaseMissing(self::TABLE, ['name' => 'present']);
            $this->fail('assertDatabaseMissing() must fail for a row that is there');
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * A row that is not there passes `assertDatabaseMissing()` and fails `assertDatabaseHas()`.
     */
    public function testAnAbsentRowIsMissingAndNotFound(): void
    {
        // Act & Assert
        $this->assertDatabaseMissing(self::TABLE, ['name' => 'absent']);

        try {
            $this->assertDatabaseHas(self::TABLE, ['name' => 'absent']);
            $this->fail('assertDatabaseHas() must fail for a row that is not there');
        } catch (\PHPUnit\Framework\AssertionFailedError) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * Several criteria are combined, not just the first.
     *
     * The `WHERE` is built by joining on `AND`; a loop that overwrote rather than appended
     * would pass every test above.
     */
    public function testEveryCriterionIsApplied(): void
    {
        // Arrange
        $this->getConnection()->exec("INSERT INTO `" . self::TABLE . "` (`id`, `name`) VALUES (7, 'seven')");

        // Act & Assert — both right…
        $this->assertDatabaseHas(self::TABLE, ['id' => 7, 'name' => 'seven']);
        // …and one wrong is not a match.
        $this->assertDatabaseMissing(self::TABLE, ['id' => 7, 'name' => 'eight']);
    }
}
