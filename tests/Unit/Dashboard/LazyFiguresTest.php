<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Dashboard\Widgets\LazyFigures;

/**
 * Figures a dashboard view of the older shape reads, worked out only when it reads them.
 *
 * What holds: nothing is asked until an index, a loop, a count or JSON reads it, and then once;
 * it reads as the array it stands for; and it cannot be written, so a view cannot change what
 * the next reader sees.
 */
#[CoversClass(LazyFigures::class)]
class LazyFiguresTest extends TestCase
{
    /** Unread, nothing is asked; read every way, it is the array, asked once. */
    public function testWorkedOutOnceWhenRead(): void
    {
        // Arrange
        $asked   = 0;
        $figures = new LazyFigures(static function () use (&$asked): array {
            $asked++;

            return ['now' => 3, 'cache' => ['status' => 'ok']];
        });
        $before = $asked;

        // Act
        $now     = $figures['now'] ?? 0;
        $missing = $figures['nope'] ?? 'default';
        $keys    = [];
        foreach ($figures as $key => $value) {
            $keys[] = $key;
        }

        // Assert
        $this->assertSame(0, $before, 'nothing asked before it is read');
        $this->assertSame([3, 'default'], [$now, $missing]);
        $this->assertTrue(isset($figures['cache']));
        $this->assertSame(['now', 'cache'], $keys);
        $this->assertCount(2, $figures);
        $this->assertSame('{"now":3,"cache":{"status":"ok"}}', json_encode($figures));
        $this->assertSame(1, $asked, 'asked once');
    }

    /** Figures are read, not written. */
    public function testReadOnly(): void
    {
        // Arrange
        $figures = new LazyFigures(static fn (): array => ['now' => 1]);

        // Act / Assert
        try {
            $figures['now'] = 2;
            $this->fail('written');
        } catch (\LogicException) {
            $this->assertSame(1, $figures['now']);
        }
        $this->expectException(\LogicException::class);
        unset($figures['now']);
    }
}
