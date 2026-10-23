<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html\Datatable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Html\Datatable\ArraySource;

/**
 * A server-side datatable answered from rows in memory: DataTables' request read as it sends it,
 * and the cells drawn for the page alone.
 */
#[CoversClass(ArraySource::class)]
final class ArraySourceTest extends TestCase
{
    /** @var list<array{name: string, n: int}> */
    private const ROWS = [['name' => 'beta', 'n' => 2], ['name' => 'Alpha', 'n' => 2], ['name' => 'gamma', 'n' => 9]];

    /** The answer for these parameters, each row drawn as its name; and how many rows were drawn. */
    private static function answer(array $request, int &$drawn = 0): array
    {
        return ArraySource::answer(self::ROWS, [0 => 'name', 1 => 'n'], static fn (array $row): string => $row['name'],
            static function (array $page) use (&$drawn): array {
                $drawn += count($page);

                return array_map(static fn (array $row): array => [$row['name']], $page);
            }, $request);
    }

    /**
     * Text sorts ignoring case; a second column breaks a tie; a page draws only its rows; -1 is
     * every row.
     */
    public function testSortsByColumnsAndDrawsThePageOnly(): void
    {
        // Arrange
        $drawn = 0;

        // Act
        $byName  = self::answer(['order' => [['column' => 0, 'dir' => 'asc']], 'length' => -1]);
        $tied    = self::answer(['order' => [['column' => 1, 'dir' => 'desc'], ['column' => 0, 'dir' => 'asc']], 'start' => 1, 'length' => 1], $drawn);
        $unknown = self::answer(['order' => [['column' => 7, 'dir' => 'asc']]]);
        $equal   = self::answer(['order' => [['column' => 1, 'dir' => 'asc']]]);

        // Assert
        $this->assertSame([['Alpha'], ['beta'], ['gamma']], $byName['data']);
        $this->assertSame([['Alpha']], $tied['data'], 'gamma first, then the tie of 2 by name: Alpha, beta');
        $this->assertSame(1, $drawn, 'only the page is drawn');
        $this->assertSame([['beta'], ['Alpha'], ['gamma']], $unknown['data'], 'a column it does not sort by leaves the order');
        $this->assertSame([['beta'], ['Alpha'], ['gamma']], $equal['data'], 'a tie with nothing to break it keeps the order');
    }

    /**
     * The search, as DataTables 1.10's array or a plain string, matches anywhere ignoring case; the
     * totals are all rows and those found; nothing found draws nothing.
     */
    public function testSearchesAndCounts(): void
    {
        // Arrange
        $drawn = 0;

        // Act
        $found   = self::answer(['draw' => 5, 'search' => ['value' => ' AMM ']]);
        $string  = self::answer(['search' => 'ta']);
        $nothing = self::answer(['search' => ['value' => 'zzz']], $drawn);

        // Assert
        $this->assertSame(['draw' => 5, 'recordsTotal' => 3, 'recordsFiltered' => 1, 'data' => [['gamma']]], $found);
        $this->assertSame([['beta']], $string['data']);
        $this->assertSame([[], 0, 3], [$nothing['data'], $drawn, $nothing['recordsTotal']]);
    }
}
