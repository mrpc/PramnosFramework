<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\ApiList;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiList\ApiListQuery;
use Pramnos\Application\ApiList\ApiListSource;

/**
 * What a list request may arrive as, and what happens when the query behind it fails.
 *
 * `ApiListQuery::run()` is the one entry point every listable object shares, and its parameters
 * come off a URL — so `fields` and `search` each arrive in three shapes, none of which the caller
 * controls. All 36 of this class's uncovered statements were in that method: every alternative
 * shape, and both of the failure answers.
 *
 * A unit test with a fake source. The interface is nine methods and none of the branches under
 * test is about SQL — the fragment building has its own tests, and the schema, the row fetching
 * and the counting are the *source's* business. What is left is the orchestration, which is
 * exactly what was never executed.
 *
 * The three that matter beyond shape-handling:
 *
 *   - **the primary key is always selected**, whatever the caller asked for. A row without its
 *     key is a row nothing can link to, edit or delete, and the list is the screen those actions
 *     start from;
 *   - **an unknown field is dropped, not passed through.** The field list comes off a URL and
 *     ends up in a `SELECT`;
 *   - **a failed query answers with an error envelope naming the filter and the order**, rather
 *     than raising. This is an API list: the caller gets JSON either way, and the fragment it was
 *     given back is the only way to see what was actually run.
 */
#[CoversClass(ApiListQuery::class)]
class ApiListQueryInputShapesTest extends TestCase
{
    // ── How `fields` arrives ──────────────────────────────────────────────────

    /**
     * A comma-separated list is accepted, because that is what a query string carries.
     *
     * `?fields=id,name` is the ordinary shape, and the one a person types by hand.
     */
    public function testFieldsMayBeCommaSeparated(): void
    {
        // Act
        $source = $this->source();
        $answer = ApiListQuery::run($source, ' userid , username ');

        // Assert
        $this->assertSame(['userid', 'username'], $answer['fields']);
        $this->assertStringContainsString('username', $source->selectFields);
    }

    /** A JSON array is accepted too, which is what a client library sends. */
    public function testFieldsMayBeJson(): void
    {
        // Act
        $source = $this->source();
        $answer = ApiListQuery::run($source, urlencode('["userid","email"]'));

        // Assert
        $this->assertSame(['userid', 'email'], $answer['fields']);
    }

    /** And an array, which is what application code passes. */
    public function testFieldsMayBeAnArray(): void
    {
        // Act
        $answer = ApiListQuery::run($this->source(), ['userid', 'email']);

        // Assert
        $this->assertSame(['userid', 'email'], $answer['fields']);
    }

    /**
     * With no fields asked for, the source decides.
     *
     * A source curates `apiListDefaultFields()` to a subset it is willing to expose, so "no
     * preference" must not mean "everything in the schema".
     */
    public function testWithNoFieldsTheSourceDecides(): void
    {
        // Act
        $answer = ApiListQuery::run($this->source(), '');

        // Assert
        $this->assertSame(['userid', 'username'], $answer['fields'], 'the default set was ignored');
    }

    /**
     * A field the schema does not have is dropped.
     *
     * The list comes off a URL and ends up in a `SELECT`. Passing an unknown name through would
     * be a query built from whatever somebody typed.
     */
    public function testAnUnknownFieldIsDropped(): void
    {
        // Act
        $source = $this->source();
        $answer = ApiListQuery::run($source, 'userid,password_hash,username');

        // Assert
        $this->assertSame(['userid', 'username'], $answer['fields']);
        $this->assertStringNotContainsString('password_hash', $source->selectFields);
    }

    /**
     * With a join, a field may be named with or without its table prefix.
     *
     * The schema reports `u.username` because that is what the `SELECT` needs; a caller asking
     * for `username` means the same column, and refusing it would make every joined list require
     * the caller to know the alias.
     *
     * `fields` in the answer is the caller's list with the prefixes stripped — it describes what
     * was asked for, not what the query selected. The primary key is added to the query
     * separately (see {@see testThePrimaryKeyIsAlwaysSelected}) and does not appear here unless
     * the caller named it.
     */
    public function testAFieldMayBeNamedWithoutItsTablePrefix(): void
    {
        // Arrange
        $source = $this->source(schema: ['u.userid', 'u.username', 'a.name'], default: ['u.userid']);

        // Act
        $answer = ApiListQuery::run($source, 'username,name', '', '', '', 'JOIN apps a');

        // Assert
        // The builder quotes the column, so the alias and the name are asserted apart.
        $this->assertMatchesRegularExpression('~u\.`?username`?~', $source->selectFields);
        $this->assertMatchesRegularExpression('~a\.`?name`?~', $source->selectFields);
        $this->assertSame(
            ['username', 'name'],
            $answer['fields'],
            'the prefixes reached the answer, so a caller would have to strip them'
        );
    }

    /** When nothing survives validation, the default set is used rather than nothing. */
    public function testWhenNothingSurvivesTheDefaultSetIsUsed(): void
    {
        // Act
        $answer = ApiListQuery::run($this->source(), 'nothing,in,the,schema');

        // Assert
        $this->assertSame(['userid', 'username'], $answer['fields']);
    }

    /**
     * The primary key is selected whether or not it was asked for.
     *
     * A row without its key is a row nothing can link to, edit or delete — and the list is the
     * screen those actions start from. Asked of the source, or overridden by the caller.
     */
    public function testThePrimaryKeyIsAlwaysSelected(): void
    {
        // Act
        $source = $this->source();
        $answer = ApiListQuery::run($source, 'username');

        // Assert
        $this->assertStringContainsString('userid', $source->selectFields);
        $this->assertSame(
            ['username'],
            $answer['fields'],
            'the key is added to the query, not to the list the caller asked for'
        );

        // …and an override is honoured.
        $other = $this->source();
        ApiListQuery::run($other, 'username', '', '', '', '', '', null, 'email');
        $this->assertStringContainsString('email', $other->selectFields);
    }

    // ── How `search` arrives ──────────────────────────────────────────────────

    /** A plain string is a search across everything. */
    public function testAPlainStringIsAGlobalSearch(): void
    {
        // Act
        $source = $this->source();
        ApiListQuery::run($source, '', 'yannis');

        // Assert
        $this->assertSame('yannis', $source->globalSearch);
        $this->assertSame([], $source->fieldSearches);
    }

    /**
     * A JSON object is a search per field.
     *
     * `?search={"username":"yan"}` is how a table with a box under each column asks. Read as a
     * global search it would look for the literal JSON in every column and find nothing, which
     * presents as "the filters do not work".
     */
    public function testAJsonObjectIsAPerFieldSearch(): void
    {
        // Act
        $source = $this->source();
        ApiListQuery::run($source, '', urlencode('{"username":"yan","email":"example"}'));

        // Assert
        $this->assertSame('', $source->globalSearch);
        $this->assertSame(['username' => 'yan', 'email' => 'example'], $source->fieldSearches);
    }

    /** An array is the same thing from application code. */
    public function testAnArrayIsAPerFieldSearch(): void
    {
        // Act
        $source = $this->source();
        ApiListQuery::run($source, '', ['username' => 'yan']);

        // Assert
        $this->assertSame(['username' => 'yan'], $source->fieldSearches);
    }

    // ── When the query fails ──────────────────────────────────────────────────

    /**
     * A paginated query that raises answers with an error envelope, not an exception.
     *
     * This is an API list: the caller gets JSON either way, and a 500 with a stack trace is not
     * one. The filter and the order come back with it, because they are the only way to see what
     * was actually run — the caller sent a page number and a search box, not a `WHERE` clause.
     */
    public function testAFailedPaginatedQueryAnswersWithAnError(): void
    {
        // Arrange
        $source = $this->source();
        $source->paginateThrows = new \RuntimeException('the connection went away');

        // Act
        $answer = ApiListQuery::run($source, 'username', 'yan', '', '', '', '', null, null, 2, 10);

        // Assert
        $this->assertArrayHasKey('error', $answer, 'a failed query reported no error');
        $this->assertStringContainsString('the connection went away', (string) $answer['error']);
        $this->assertSame([], $answer['data'], 'a failed query returned rows');
        $this->assertArrayHasKey('filter', $answer['debug'] ?? []);
        $this->assertArrayHasKey('order', $answer['debug'] ?? []);
    }

    /**
     * An unpaginated fetch that returns nothing while the source reports an error says so.
     *
     * "No rows" and "the query failed" are the same empty array, and telling them apart is what
     * `apiListLastError()` is for. Reporting a failure as an empty list is how a broken filter
     * looks exactly like a table with nothing in it.
     */
    public function testAnEmptyFetchWithAnErrorIsReportedAsAnError(): void
    {
        // Arrange
        $source = $this->source();
        $source->rows      = [];
        $source->lastError = 'Unknown column in where clause';

        // Act
        $answer = ApiListQuery::run($source, 'username');

        // Assert
        $this->assertArrayHasKey('error', $answer, 'a failed fetch was reported as an empty list');
        $this->assertStringContainsString('Unknown column', (string) $answer['error']);
    }

    /** An empty result with no error is an empty list, which is not a failure. */
    public function testAnEmptyFetchWithNoErrorIsAnEmptyList(): void
    {
        // Arrange
        $source = $this->source();
        $source->rows = [];

        // Act
        $answer = ApiListQuery::run($source, 'username');

        // Assert
        $this->assertArrayNotHasKey('error', $answer, 'an empty list was reported as a failure');
        $this->assertSame([], $answer['data']);
        $this->assertNull($answer['pagination']);
    }

    // ── The DataTables envelope ───────────────────────────────────────────────

    /**
     * With a search active, the grand total is counted again without it.
     *
     * DataTables draws "showing 3 of 50 (filtered from 900)" from two different numbers, and the
     * one it has is the filtered one. Reporting the filtered count as both would make the table
     * say a search matched everything there is — and the pager would then offer one page where
     * there are ninety.
     */
    public function testTheDataTablesTotalsAreCountedSeparatelyWhenSearching(): void
    {
        // Arrange
        $source = $this->source();
        $source->rows             = [['userid' => 1], ['userid' => 2]];
        $source->searchConditions = "username LIKE '%yan%'";
        $source->recordsTotal     = 900;

        // Act
        $answer = ApiListQuery::run(
            $source, 'username', 'yan', '', '', '', '', null, null, 0, 10,
            false, false, false, false, false, 'datatables'
        );

        // Assert
        $this->assertSame(900, $answer['recordsTotal']);
        $this->assertSame(2, $answer['recordsFiltered']);
        $this->assertSame(1, $source->recordsTotalCalls, 'the grand total was not recounted');
    }

    /**
     * With no search, the second count is skipped and the two totals are the same.
     *
     * The extra query buys nothing when nothing was filtered out, and a list is drawn on every
     * page load.
     */
    public function testWithNoSearchTheSecondCountIsSkipped(): void
    {
        // Arrange
        $source = $this->source();
        $source->rows = [['userid' => 1], ['userid' => 2], ['userid' => 3]];

        // Act
        $answer = ApiListQuery::run(
            $source, 'username', '', '', '', '', '', null, null, 0, 10,
            false, false, false, false, false, 'datatables'
        );

        // Assert
        $this->assertSame(3, $answer['recordsTotal']);
        $this->assertSame(3, $answer['recordsFiltered']);
        $this->assertSame(0, $source->recordsTotalCalls, 'a count was run for nothing');
    }

    /** The same, paginated: the totals come from the page result rather than from the rows. */
    public function testTheDataTablesEnvelopeWorksPaginatedToo(): void
    {
        // Arrange
        $source = $this->source();
        $source->rows             = [['userid' => 1]];
        $source->pageTotal        = 42;
        $source->searchConditions = "username LIKE '%yan%'";
        $source->recordsTotal     = 900;

        // Act
        $answer = ApiListQuery::run(
            $source, 'username', 'yan', '', '', '', '', null, null, 1, 10,
            false, false, false, false, false, 'datatables'
        );

        // Assert
        $this->assertSame(900, $answer['recordsTotal']);
        $this->assertSame(42, $answer['recordsFiltered']);
    }

    // ── The rows ──────────────────────────────────────────────────────────────

    /** Every row goes through the source's own processing, which is where JSON is decoded. */
    public function testEveryRowGoesThroughTheSourcesProcessing(): void
    {
        // Arrange
        $source = $this->source();
        $source->rows = [['userid' => 1], ['userid' => 2]];

        // Act
        $answer = ApiListQuery::run($source, 'username');

        // Assert
        $this->assertSame(2, $source->processedRows, 'a row was returned without being processed');
        $this->assertTrue($answer['data'][0]['processed'] ?? false);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * A source that records what the query asked it for.
     *
     * Nine methods, none of which needs a database to answer honestly: the schema is a list, the
     * search conditions are a string this test chooses, and the rows are an array. What is under
     * test is what `run()` computes *between* those calls.
     *
     * @param list<string> $schema
     * @param list<string> $default
     */
    private function source(array $schema = [], array $default = []): object
    {
        return new class (
            $schema !== [] ? $schema : ['userid', 'username', 'email'],
            $default !== [] ? $default : ['userid', 'username']
        ) implements ApiListSource {
            public string $selectFields = '';

            public string $globalSearch = '';

            public array $fieldSearches = [];

            public string $finalFilter = '';

            public string $searchConditions = '';

            /** @var list<array<string, mixed>> */
            public array $rows = [['userid' => 1]];

            public int $pageTotal = 1;

            public int $recordsTotal = 0;

            public int $recordsTotalCalls = 0;

            public int $processedRows = 0;

            public string $lastError = '';

            public ?\Throwable $paginateThrows = null;

            public function __construct(private array $schema, private array $default)
            {
            }

            public function apiListSchemaFields($join = ''): array
            {
                return $this->schema;
            }

            public function apiListDefaultFields($join = ''): array
            {
                return $this->default;
            }

            public function apiListPrimaryKey(): string
            {
                return 'userid';
            }

            public function apiListSearchConditions(
                array $validFields,
                $globalSearch,
                array $fieldSearches,
                $join
            ): string {
                $this->globalSearch  = (string) $globalSearch;
                $this->fieldSearches = $fieldSearches;

                return $this->searchConditions;
            }

            public function apiListPaginate(
                $itemsPerPage, $page, $filter, $order, $table, $key, $debug,
                $join, $selectFields, $group, $returnAsModels, $useGetData,
                $customGetListMethod, $addedfields
            ): array {
                $this->selectFields = (string) $selectFields;
                $this->finalFilter  = (string) $filter;

                if ($this->paginateThrows !== null) {
                    throw $this->paginateThrows;
                }

                return [
                    'items' => $this->rows,
                    'total' => $this->pageTotal,
                    'pages' => 1,
                ];
            }

            public function apiListFetchAll(
                $filter, $order, $table, $key, $debug, $join, $selectFields,
                $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
            ) {
                $this->selectFields = (string) $selectFields;
                $this->finalFilter  = (string) $filter;

                return $this->rows;
            }

            public function apiListProcessRow(array $row, $join): array
            {
                $this->processedRows++;

                return $row + ['processed' => true];
            }

            public function apiListLastError()
            {
                return $this->lastError;
            }

            public function apiListRecordsTotal(
                $baseFilter, $table, $key, $join, $selectFields, $group, $addedfields
            ): int {
                $this->recordsTotalCalls++;

                return $this->recordsTotal;
            }
        };
    }
}
