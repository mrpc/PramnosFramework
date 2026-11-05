<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Llm\Chat\MemoryAdmin;

/**
 * Everybody's Ask memory against a database that fails: reads answer nothing, and a correction or
 * a deletion says it did not happen — logged, never thrown into the page.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(MemoryAdmin::class)]
final class MemoryAdminFailureTest extends TestCase
{
    /**
     * A database whose reads find one memory (or fail, with `$readsFail`) and whose writes fail.
     */
    private static function failing(TestCase $test, bool $readsFail): Database
    {
        $query = $test->getMockBuilder(QueryBuilder::class)->disableOriginalConstructor()->getMock();
        foreach (['table', 'where', 'whereNull', 'whereIn', 'select', 'groupBy', 'orderBy'] as $chain) {
            $query->method($chain)->willReturnSelf();
        }
        $query->method('raw')->willReturnCallback(static fn (string $sql): string => $sql);
        $row = ['memory_id' => 3, 'userid' => 8, 'kind' => 'fact', 'content' => 'x', 'created_at' => '', 'updated_at' => ''];
        $readsFail
            ? $query->method('getAllOrFail')->willThrowException(new \RuntimeException('down'))
            : $query->method('getAllOrFail')->willReturn([$row]);
        $query->method('get')->willThrowException(new \RuntimeException('down'));
        foreach (['update', 'delete'] as $write) {
            $query->method($write)->willThrowException(new \RuntimeException('read only'));
        }
        $db = $test->getMockBuilder(Database::class)->disableOriginalConstructor()->getMock();
        $db->method('queryBuilder')->willReturn($query);

        return $db;
    }

    /** With every read failing, nobody is listed, nothing is read, and nothing can be changed. */
    public function testReadsThatFailFindNothing(): void
    {
        // Arrange
        $admin = new MemoryAdmin(self::failing($this, true));
        $me    = (object) ['userid' => 7];

        // Act + Assert
        $this->assertSame([], $admin->people());
        $this->assertSame([], $admin->of($me, 8, null));
        $this->assertFalse($admin->correct($me, 3, 'new'), 'nothing found to correct');
        $this->assertFalse($admin->delete($me, 3));
    }

    /** A memory that is found but cannot be written says so; an empty correction is refused. */
    public function testWritesThatFailSayTheyDidNotHappen(): void
    {
        // Arrange
        $admin = new MemoryAdmin(self::failing($this, false));
        $me    = (object) ['userid' => 7];

        // Act + Assert
        $this->assertFalse($admin->correct($me, 3, '   '), 'an empty correction');
        $this->assertFalse($admin->correct($me, 3, 'new'));
        $this->assertFalse($admin->delete($me, 3));
    }
}
