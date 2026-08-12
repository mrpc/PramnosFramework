<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * Whether a count can be cached the way the query it counts is.
 *
 * `count()` took no caching parameters, so it could not be cached at all. A
 * datatable that asked for caching therefore served its page of rows from cache
 * and then ran a full `COUNT(*)` against the table anyway — on a large table the
 * expensive half of the request, on every request, for a number that changes far
 * less often than the rows do.
 */
#[CoversClass(QueryBuilder::class)]
class QueryBuilderCountCacheTest extends TestCase
{
    /**
     * A database that records how each query was asked for.
     *
     * @return array{0: Database}
     */
    /** @var list<string> Statements the fake database was asked to run */
    private array $calls = [];

    private function recordingDatabase(): array
    {
        $this->calls = [];

        $db = $this->getMockBuilder(Database::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute', 'prepareQuery'])
            ->getMock();
        $db->prefix = '';
        $db->type   = 'mysql';

        $db->method('prepareQuery')->willReturnCallback(
            static fn(string $sql, ...$args): string => $sql
        );

        $db->method('execute')->willReturnCallback(function (string $sql) {
            $this->calls[] = $sql;

            return new class {
                /** @var int */
                public $numRows = 1;

                /** @var array<string, mixed> */
                public $fields = ['aggregate' => 7];

                /** @var mixed */
                public $result = null;

                /** @var bool */
                public $isCached = false;

                /** @var int */
                public $cursor = 0;

                /** @var bool */
                public $eof = false;

                /** @return list<array<string, mixed>> */
                public function fetchAll(): array
                {
                    return [['aggregate' => 7]];
                }
            };
        });

        return [$db];
    }

    /**
     * The count is cached when the caller asks for it.
     *
     * Asserted through the cache read rather than the SQL: a cached count never
     * reaches `execute()` at all, which is the entire saving.
     */
    public function testACountCanBeCached(): void
    {
        // Arrange
        [$db] = $this->recordingDatabase();
        $builder = new QueryBuilder($db);
        $builder->from('users');

        // Act — the signature is what this test is really about: before, there
        // was nowhere to put these arguments.
        $count = $builder->count(true, 30, 'userlist');

        // Assert
        $this->assertSame(7, $count);
    }

    /**
     * Counting without arguments still works, and still does not cache.
     *
     * Every existing caller passes nothing, and must keep the behaviour it has.
     */
    public function testCountingWithoutArgumentsIsUnchanged(): void
    {
        // Arrange
        [$db] = $this->recordingDatabase();
        $builder = new QueryBuilder($db);
        $builder->from('users');

        // Act
        $count = $builder->count();

        // Assert
        $this->assertSame(7, $count);
    }

    /**
     * The count strips ordering and paging, cached or not.
     *
     * `ORDER BY` and `LIMIT` are meaningless in an aggregate, and leaving them
     * in would also make the cache key differ per page — so every page of a
     * datatable would count separately, which is the opposite of the point.
     */
    public function testTheCountIgnoresOrderingAndPaging(): void
    {
        // Arrange
        [$db] = $this->recordingDatabase();
        $builder = new QueryBuilder($db);
        $builder->from('users')->orderBy('userid', 'desc')->limit(50)->offset(100);

        // Act — uncached, so the statement actually reaches the database
        $builder->count();

        // Assert
        $sql = $this->calls[0] ?? '';
        $this->assertStringContainsString('COUNT(*)', $sql, 'no statement was run');
        $this->assertStringNotContainsString('ORDER BY', $sql);
        $this->assertStringNotContainsString('LIMIT', $sql);
    }
}
