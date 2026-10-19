<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database\FromMysql;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\FromMysql\Migrator;

/**
 * The names the migration gives indexes and foreign keys: PostgreSQL keeps 63 bytes of one, so a
 * longer name is cut and given a hash of the whole (FW-165 §3).
 */
#[CoversClass(Migrator::class)]
final class MigratorIdentifierTest extends TestCase
{
    /**
     * A name that fits is left as it is; two long names that share their first 63 bytes stay two,
     * each at most 63 bytes; and a cut never splits a character in two, which PostgreSQL would
     * refuse as invalid UTF-8.
     */
    public function testLongNamesAreCutWithAHash(): void
    {
        // Arrange
        $short = 'orders_fk_customer';
        $one   = str_repeat('a', 70) . '_one';
        $two   = str_repeat('a', 70) . '_two';
        $greek = str_repeat('α', 40);   // 80 bytes; byte 54 falls inside a character

        // Act
        $cutOne   = Migrator::identifier($one);
        $cutTwo   = Migrator::identifier($two);
        $cutGreek = Migrator::identifier($greek);

        // Assert
        $this->assertSame($short, Migrator::identifier($short));
        $this->assertSame(str_repeat('a', 63), Migrator::identifier(str_repeat('a', 63)), '63 bytes fit');
        $this->assertNotSame($cutOne, $cutTwo);
        $this->assertSame(63, strlen($cutOne));
        $this->assertSame(Migrator::identifier($one), $cutOne, 'the same name, the same cut: a second run finds it');
        $this->assertTrue(mb_check_encoding($cutGreek, 'UTF-8'));
        $this->assertLessThanOrEqual(63, strlen($cutGreek));
    }
}
