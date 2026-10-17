<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database\FromMysql;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\FromMysql\TopologicalSorter;

/** Tables in load order: parents first, a self-reference no obstacle, a ring last and named. */
#[CoversClass(TopologicalSorter::class)]
final class TopologicalSorterTest extends TestCase
{
    /**
     * Every table comes after the tables it points at; a table pointing at itself or at a table
     * outside the set is not held back; two tables pointing at each other come last.
     */
    public function testParentsFirstRingsLast(): void
    {
        // Arrange
        $tables    = ['tokenactions', 'usertokens', 'users', 'categories', 'a', 'b', 'logs'];
        $dependsOn = [
            'tokenactions' => ['usertokens', 'urls'],   // urls: not migrated, not a constraint here
            'usertokens'   => ['users', 'usertokens'],  // its own parent token
            'categories'   => ['categories'],
            'a'            => ['b'],
            'b'            => ['a'],
        ];

        // Act
        $sorted = TopologicalSorter::sort($tables, $dependsOn);
        $at     = array_flip($sorted['order']);

        // Assert
        $this->assertCount(7, $sorted['order']);
        $this->assertLessThan($at['usertokens'], $at['users']);
        $this->assertLessThan($at['tokenactions'], $at['usertokens']);
        $this->assertSame(['a', 'b'], $sorted['cycles']);
        $this->assertSame(['a', 'b'], array_slice($sorted['order'], -2), 'a ring comes last');
    }
}
