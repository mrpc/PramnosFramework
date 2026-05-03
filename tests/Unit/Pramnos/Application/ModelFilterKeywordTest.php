<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression tests for Model::_stripSqlKeyword.
 *
 * When callers pass "where x=y", "order by x", "group by x" to _getList / _getPaginated
 * the QueryBuilder adds the keyword itself (WHERE / ORDER BY / GROUP BY). Without stripping
 * the input we get "WHERE where x=y" — invalid SQL that makes execute() return false and
 * then fetch() throws "Call to a member function fetch() on false".
 */
#[CoversClass(\Pramnos\Application\Model::class)]
class ModelFilterKeywordTest extends TestCase
{
    private \ReflectionMethod $method;

    protected function setUp(): void
    {
        if (!defined('ROOT')) {
            define('ROOT', dirname(dirname(dirname(dirname(dirname(__DIR__))))));
        }
        if (!defined('APP_PATH')) {
            define('APP_PATH', dirname(dirname(dirname(__DIR__))) . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'app');
        }

        // Access the private _stripSqlKeyword method via reflection
        $ref = new \ReflectionClass(\Pramnos\Application\Model::class);
        $this->method = $ref->getMethod('_stripSqlKeyword');
        $this->method->setAccessible(true);
    }

    private function strip(string $sql, string $keyword): string
    {
        // Instantiate a minimal stub — Model requires a controller, but we only need
        // the method, which has no $this dependencies.
        $mock = $this->getMockBuilder(\Pramnos\Application\Model::class)
            ->disableOriginalConstructor()
            ->getMock();
        return $this->method->invoke($mock, $sql, $keyword);
    }

    // ── WHERE ────────────────────────────────────────────────────────────────

    public static function whereProvider(): array
    {
        return [
            'lowercase where'          => ['where x = 1', 'WHERE', 'x = 1'],
            'uppercase WHERE'           => ['WHERE x = 1', 'WHERE', 'x = 1'],
            'mixed case Where'          => ['Where x = 1', 'WHERE', 'x = 1'],
            'extra leading space'       => ['  where x = 1', 'WHERE', 'x = 1'],
            'no leading keyword'        => ['x = 1', 'WHERE', 'x = 1'],
            'condition already clean'   => ['deyaid = 0 AND status = 1', 'WHERE', 'deyaid = 0 AND status = 1'],
            'complex condition'         => ['where a.id = 5 AND b.name = \'foo\'', 'WHERE', 'a.id = 5 AND b.name = \'foo\''],
        ];
    }

    #[DataProvider('whereProvider')]
    public function testStripWhere(string $input, string $keyword, string $expected): void
    {
        $this->assertSame($expected, $this->strip($input, $keyword));
    }

    // ── ORDER BY ──────────────────────────────────────────────────────────────

    public static function orderByProvider(): array
    {
        return [
            'lowercase order by'  => ['order by name ASC', 'ORDER BY', 'name ASC'],
            'uppercase ORDER BY'  => ['ORDER BY name ASC', 'ORDER BY', 'name ASC'],
            'no keyword'          => ['name ASC', 'ORDER BY', 'name ASC'],
            'multi-column'        => ['order by a.name ASC, b.date DESC', 'ORDER BY', 'a.name ASC, b.date DESC'],
        ];
    }

    #[DataProvider('orderByProvider')]
    public function testStripOrderBy(string $input, string $keyword, string $expected): void
    {
        $this->assertSame($expected, $this->strip($input, $keyword));
    }

    // ── GROUP BY ──────────────────────────────────────────────────────────────

    public static function groupByProvider(): array
    {
        return [
            'lowercase group by'  => ['group by deyaid', 'GROUP BY', 'deyaid'],
            'uppercase GROUP BY'  => ['GROUP BY deyaid', 'GROUP BY', 'deyaid'],
            'no keyword'          => ['deyaid', 'GROUP BY', 'deyaid'],
        ];
    }

    #[DataProvider('groupByProvider')]
    public function testStripGroupBy(string $input, string $keyword, string $expected): void
    {
        $this->assertSame($expected, $this->strip($input, $keyword));
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', $this->strip('', 'WHERE'));
    }

    public function testKeywordAloneWithNoConditionReturnsEmpty(): void
    {
        // "where" with only trailing whitespace → empty after strip
        $this->assertSame('', trim($this->strip('where ', 'WHERE')));
    }
}
