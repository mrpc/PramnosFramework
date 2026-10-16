<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\AuditLog;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;

/**
 * The audit log never breaks the action it audits: a database that fails asking whether the
 * table exists, one that throws on the write or the read, and one that answers an insert with
 * `false` (as PostgreSQL does) each leave the caller untouched — no exception, an empty read.
 */
#[CoversClass(AuditLog::class)]
final class AuditLogFailureTest extends TestCase
{
    private ?Database $previous = null;

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        AuditLog::resetTableCache();
    }

    /** Makes `$database` the framework's, with the table probe answered as `$available`. */
    private function use(Database $database, ?bool $available): void
    {
        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $database;
        (new \ReflectionProperty(AuditLog::class, 'tableAvailable'))->setValue(null, $available);
    }

    /** A probe that throws is "no table": nothing written, nothing read, no exception. */
    public function testAProbeThatThrowsIsNoTable(): void
    {
        // Arrange
        $database = $this->createMock(Database::class);
        $database->method('schema')->willThrowException(new \RuntimeException('no connection'));
        $database->expects($this->never())->method('queryBuilder');
        $this->use($database, null);

        // Act
        AuditLog::record('user.deleted', 1, 2);

        // Assert
        $this->assertSame([], AuditLog::query());
        $this->assertSame(false, (new \ReflectionProperty(AuditLog::class, 'tableAvailable'))->getValue());
    }

    /** A write or a read that throws is logged and swallowed. */
    public function testAWriteOrAReadThatThrowsIsSwallowed(): void
    {
        // Arrange
        $database = $this->createMock(Database::class);
        $database->method('queryBuilder')->willThrowException(new \RuntimeException('gone away'));
        $this->use($database, true);

        // Act
        AuditLog::record('user.deleted', 1, 2, ['why' => 'asked']);
        $read = AuditLog::query(['event' => 'user.*']);

        // Assert
        $this->assertSame([], $read, 'a failed read is an empty one, not an error page');
    }

    /** An insert answered with `false` is treated as the failure it is, not as a record kept. */
    public function testAnInsertAnsweredFalseIsAFailure(): void
    {
        // Arrange
        $builder = $this->createMock(\Pramnos\Database\QueryBuilder::class);
        $builder->method('table')->willReturnSelf();
        $builder->expects($this->once())->method('insert')->willReturn(false);
        $database = $this->createMock(Database::class);
        $database->method('queryBuilder')->willReturn($builder);
        $this->use($database, true);

        // Act + Assert — returns quietly; the expectation above proves the insert was tried
        AuditLog::change('setting.updated', 1, 'setting', 'x', ['v' => 1], ['v' => 2]);
    }
}
