<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;

/**
 * Characterization tests for legacy Migration base class behavior.
 *
 * These tests document query queue execution order and exception handling
 * semantics before migration-system overhaul work.
 */
#[CoversClass(Migration::class)]
class MigrationCharacterizationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Arrange
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . DS . 'var');
        }

        if (!is_dir(LOG_PATH . DS . 'logs')) {
            @mkdir(LOG_PATH . DS . 'logs', 0777, true);
        }
    }

    /**
     * Ensures getDescription returns the current public description property.
     */
    public function testGetDescriptionReturnsPublicDescriptionValue(): void
    {
        // Arrange
        $migration = new TestMigration($this->makeApplicationWithDatabaseMock($this->makeDatabaseMock()));
        $migration->description = 'Create users table';

        // Act
        $description = $migration->getDescription();

        // Assert
        $this->assertSame('Create users table', $description);
    }

    /**
     * Ensures queued queries are executed in insertion order.
     */
    public function testExecuteQueriesRunsAllQueuedQueriesInOrder(): void
    {
        // Arrange
        $executed = [];
        $db = $this->makeDatabaseMock();
        $db->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $sql) use (&$executed) {
                $executed[] = $sql;

                return new \stdClass();
            });

        $migration = new TestMigration($this->makeApplicationWithDatabaseMock($db));
        $migration->queue('CREATE TABLE one (id INT)');
        $migration->queue('ALTER TABLE one ADD name VARCHAR(50)');
        $migration->queue('DROP TABLE one');

        // Act
        $migration->runExecute();

        // Assert
        $this->assertSame([
            'CREATE TABLE one (id INT)',
            'ALTER TABLE one ADD name VARCHAR(50)',
            'DROP TABLE one',
        ], $executed);
    }

    /**
     * Ensures executeQueries swallows query Exceptions and continues with
     * remaining statements.
     */
    public function testExecuteQueriesContinuesAfterException(): void
    {
        // Arrange
        $executed = [];
        $db = $this->makeDatabaseMock();
        $db->expects($this->exactly(3))
            ->method('query')
            ->willReturnCallback(function (string $sql) use (&$executed) {
                $executed[] = $sql;
                if ($sql === 'BROKEN SQL') {
                    throw new \Exception('syntax error');
                }

                return new \stdClass();
            });

        $migration = new TestMigration($this->makeApplicationWithDatabaseMock($db));
        $migration->queue('CREATE TABLE one (id INT)');
        $migration->queue('BROKEN SQL');
        $migration->queue('DROP TABLE one');

        // Act
        $migration->runExecute();

        // Assert
        // This proves execution does not stop at first failed query.
        $this->assertSame([
            'CREATE TABLE one (id INT)',
            'BROKEN SQL',
            'DROP TABLE one',
        ], $executed);
    }

    /**
     * Ensures default up/down implementations in base migration are no-op.
     */
    public function testDefaultUpAndDownAreNoOp(): void
    {
        // Arrange
        $db = $this->makeDatabaseMock();
        $db->expects($this->never())->method('query');

        $migration = new TestMigration($this->makeApplicationWithDatabaseMock($db));

        // Act
        $migration->up();
        $migration->down();

        // Assert
        $this->assertTrue(true);
    }

    /**
     * @return Database&\PHPUnit\Framework\MockObject\MockObject
     */
    private function makeDatabaseMock(): Database
    {
        /** @var Database&\PHPUnit\Framework\MockObject\MockObject $db */
        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['query'])
            ->getMock();

        return $db;
    }

    private function makeApplicationWithDatabaseMock(Database $db): Application
    {
        /** @var Application&\PHPUnit\Framework\MockObject\MockObject $application */
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $application->database = $db;

        return $application;
    }
}

/**
 * Minimal concrete migration used to exercise protected queue/execute methods.
 */
class TestMigration extends Migration
{
    public function queue(string $query): void
    {
        $this->addQuery($query);
    }

    public function runExecute(): void
    {
        $this->executeQueries();
    }
}
