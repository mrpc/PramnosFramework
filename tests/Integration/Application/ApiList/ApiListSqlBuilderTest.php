<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application\ApiList;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\ApiList\ApiListSqlBuilder;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * The SQL fragments every API list is assembled from.
 *
 * 149 of 219 statements never executed, on the one class in the list engine whose job is to
 * decide **what a caller is allowed to ask for**. Two of its three responsibilities are security:
 *
 *   - a field name arrives from a query string and is matched against a whitelist — anything not
 *     on it is dropped, not quoted-and-passed;
 *   - an operator arrives the same way and is matched against an allowlist;
 *   - and every value goes through `prepareInput()`.
 *
 * The third is per-driver identifier quoting, which is exactly the kind of thing that works on
 * the engine it was written on. So this runs against real connections — `prepareInput()` escapes
 * for the connected server, not for a guess — and {@see ApiListSqlBuilderPostgreSQLTest} re-runs
 * all of it on PostgreSQL/TimescaleDB.
 */
#[CoversClass(ApiListSqlBuilder::class)]
class ApiListSqlBuilderTest extends BaseTestCase
{
    private $db;

    /** True when the connected server quotes identifiers with double quotes. */
    private bool $pg = false;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->pg = $this->db->type === 'postgresql';
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** `name` as this backend quotes it. */
    private function q(string $name): string
    {
        return $this->pg ? '"' . $name . '"' : '`' . $name . '`';
    }

    // ── Reading a field expression ────────────────────────────────────────────

    /**
     * The result column name of an expression, which is what a row is keyed by.
     *
     * Get this wrong and a list renders blank cells for data that is present — the query
     * succeeded, the column is there, and the code looked for it under another name.
     */
    public function testTheResultNameOfAnExpression(): void
    {
        // Assert
        $this->assertSame('id', ApiListSqlBuilder::resolveFieldResultName('id'));
        $this->assertSame('id', ApiListSqlBuilder::resolveFieldResultName('a.id'));
        $this->assertSame('id', ApiListSqlBuilder::resolveFieldResultName('`a`.`id`'));
        $this->assertSame('uid', ApiListSqlBuilder::resolveFieldResultName('a.id AS uid'));
        $this->assertSame('uid', ApiListSqlBuilder::resolveFieldResultName('a.id as "uid"'));
        $this->assertSame('total', ApiListSqlBuilder::resolveFieldResultName('COUNT(*) AS total'));
        $this->assertSame('id', ApiListSqlBuilder::resolveFieldResultName('   id   '));
    }

    /** A leading keyword is stripped once, and only when it is really leading. */
    public function testALeadingKeywordIsStripped(): void
    {
        // Assert
        $this->assertSame('a = 1', ApiListSqlBuilder::stripSqlKeyword('WHERE a = 1', 'where'));
        $this->assertSame('a = 1', ApiListSqlBuilder::stripSqlKeyword('  where   a = 1', 'WHERE'));
        $this->assertSame('a DESC', ApiListSqlBuilder::stripSqlKeyword('ORDER BY a DESC', 'order by'));
        $this->assertSame(
            "note = 'where it is'",
            ApiListSqlBuilder::stripSqlKeyword("note = 'where it is'", 'where'),
            'a keyword inside a value is not a leading keyword'
        );
    }

    /**
     * The primary key is always selected, because the list cannot link a row without it.
     *
     * A caller asking for `?fields=name` wants a name column; the screen still needs an id to
     * build the link to the record. Adding it is invisible to the caller and its absence is a
     * list of rows nothing can open.
     */
    public function testThePrimaryKeyIsAlwaysSelected(): void
    {
        // Assert
        $this->assertStringContainsString(
            'userid',
            ApiListSqlBuilder::ensurePrimaryKeyInSelect('username, email', 'userid')
        );
        $this->assertSame(
            'userid, username',
            ApiListSqlBuilder::ensurePrimaryKeyInSelect('userid, username', 'userid'),
            'already there, so not added twice'
        );
        // No field list means `*`, which contains the key by definition — so nothing is added,
        // and adding `userid` beside `*` would name the column twice.
        //
        // All three spellings of "nothing asked for" must answer the same, and an empty string
        // used to answer with itself: both callers pass the result to `select()`, so `''`
        // produced `SELECT  FROM users a` — a syntax error, which is a 500 on MySQL and an
        // empty list on PostgreSQL outside strict mode.
        $this->assertSame('*', ApiListSqlBuilder::ensurePrimaryKeyInSelect(null, 'userid'));
        $this->assertSame('*', ApiListSqlBuilder::ensurePrimaryKeyInSelect('', 'userid'));
        $this->assertSame('*', ApiListSqlBuilder::ensurePrimaryKeyInSelect('*', 'userid'));
    }

    // ── The SELECT list ───────────────────────────────────────────────────────

    /** Bare fields are quoted for the connected engine, and aliased when a join is present. */
    public function testSelectFieldsAreQuotedAndAliased(): void
    {
        // Act
        $plain = ApiListSqlBuilder::buildSelectFields(['userid', 'username'], '');
        $joined = ApiListSqlBuilder::buildSelectFields(['userid', 'username'], 'LEFT JOIN b ON …');

        // Assert
        $this->assertSame($this->q('userid') . ', ' . $this->q('username'), $plain);
        $this->assertSame('a.' . $this->q('userid') . ', a.' . $this->q('username'), $joined);
    }

    /**
     * A field that already names its table keeps it, and a collision is aliased apart.
     *
     * Two joined tables with a `status` each would otherwise produce one `status` key in the row
     * and silently drop the other — the second column overwrites the first, and the screen shows
     * the wrong table's value with nothing to indicate it.
     */
    public function testACollidingFieldNameIsAliasedApart(): void
    {
        // Act
        $sql = ApiListSqlBuilder::buildSelectFields(['a.status', 'b.status'], 'JOIN b ON …');

        // Assert
        $this->assertStringContainsString('a.' . $this->q('status'), $sql);
        $this->assertStringContainsString('b.' . $this->q('status') . ' AS ' . $this->q('b_status'), $sql);
    }

    /** An expression that already carries its own alias is passed through untouched. */
    public function testAnExplicitAliasIsLeftAlone(): void
    {
        // Act
        $sql = ApiListSqlBuilder::buildSelectFields(['COUNT(*) as total', 'userid'], '');

        // Assert
        $this->assertStringContainsString('COUNT(*) as total', $sql);
        $this->assertStringNotContainsString('`COUNT', $sql);
        $this->assertStringNotContainsString('"COUNT', $sql);
    }

    /** A three-part name is not something this can safely quote, so it is passed through. */
    public function testAThreePartNameIsPassedThrough(): void
    {
        // Act
        $sql = ApiListSqlBuilder::buildSelectFields(['schema.table.column'], '');

        // Assert
        $this->assertSame('schema.table.column', $sql);
    }

    // ── ORDER BY ──────────────────────────────────────────────────────────────

    /** With nothing asked for, the primary key descending — aliased when there is a join. */
    public function testTheDefaultOrderIsThePrimaryKeyDescending(): void
    {
        // Assert
        $this->assertSame(
            'ORDER BY ' . $this->q('userid') . ' DESC',
            ApiListSqlBuilder::validateAndBuildOrder('', ['userid'], '', 'userid')
        );
        $this->assertSame(
            'ORDER BY a.' . $this->q('userid') . ' DESC',
            ApiListSqlBuilder::validateAndBuildOrder('', ['userid'], 'JOIN b ON …', 'userid')
        );
    }

    /** `+field`, `-field` and an explicit ASC/DESC suffix all mean what they look like. */
    public function testDirectionCanBeGivenThreeWays(): void
    {
        // Arrange
        $fields = ['username', 'email'];

        // Assert
        $this->assertSame(
            'ORDER BY ' . $this->q('username') . ' ASC',
            ApiListSqlBuilder::validateAndBuildOrder('+username', $fields, '', 'userid')
        );
        $this->assertSame(
            'ORDER BY ' . $this->q('username') . ' DESC',
            ApiListSqlBuilder::validateAndBuildOrder('-username', $fields, '', 'userid')
        );
        $this->assertSame(
            'ORDER BY ' . $this->q('username') . ' DESC',
            ApiListSqlBuilder::validateAndBuildOrder('username DESC', $fields, '', 'userid')
        );
        $this->assertSame(
            'ORDER BY ' . $this->q('username') . ' ASC, ' . $this->q('email') . ' DESC',
            ApiListSqlBuilder::validateAndBuildOrder('username, -email', $fields, '', 'userid')
        );
    }

    /**
     * A field nobody offered is dropped, and dropping the only one falls back to the default.
     *
     * This is the injection boundary: `?order=` is a query string. A name that is not on the
     * whitelist must not reach the SQL **even quoted** — quoting is not validation, and the
     * fallback means a caller cannot tell whether a field exists by whether the list breaks.
     */
    public function testAnUnknownOrderFieldIsDroppedRatherThanQuoted(): void
    {
        // Assert
        $this->assertSame(
            'ORDER BY ' . $this->q('userid') . ' DESC',
            ApiListSqlBuilder::validateAndBuildOrder('password', ['username'], '', 'userid')
        );
        $this->assertSame(
            'ORDER BY ' . $this->q('username') . ' ASC',
            ApiListSqlBuilder::validateAndBuildOrder('password, username', ['username'], '', 'userid'),
            'the known field survives and the unknown one is gone'
        );
    }

    /** And a name that is not a name at all never reaches the SQL. */
    public function testAMalformedOrderFieldIsRefused(): void
    {
        // Arrange — every one of these is a shape the sanitiser must reject outright.
        $attempts = [
            'username; DROP TABLE users',
            'username)--',
            '1',
            'a.b.c',
            "username' OR '1'='1",
            '(SELECT 1)',
        ];

        // Assert
        foreach ($attempts as $attempt) {
            $this->assertSame(
                'ORDER BY ' . $this->q('userid') . ' DESC',
                ApiListSqlBuilder::validateAndBuildOrder($attempt, ['username'], '', 'userid'),
                'this reached the ORDER BY: ' . $attempt
            );
        }
    }

    /**
     * A bare name resolves to the joined table's column when only that one is offered.
     *
     * `?order=name` on a joined list has to mean something, and the mapping is what makes it
     * mean the right column instead of nothing.
     */
    public function testABareNameResolvesThroughTheJoinMapping(): void
    {
        // Act
        $sql = ApiListSqlBuilder::validateAndBuildOrder(
            'name',
            ['userid', 'b.name'],
            'JOIN b ON …',
            'userid'
        );

        // Assert
        $this->assertSame('ORDER BY b.' . $this->q('name') . ' ASC', $sql);
    }

    /** An empty token in the list is skipped rather than producing a stray comma. */
    public function testEmptyTokensAreSkipped(): void
    {
        // Act
        $sql = ApiListSqlBuilder::validateAndBuildOrder('username, , ', ['username'], '', 'userid');

        // Assert
        $this->assertSame('ORDER BY ' . $this->q('username') . ' ASC', $sql);
    }

    // ── WHERE, from structured conditions ─────────────────────────────────────

    /** A single condition, quoted for the engine and escaped. */
    public function testASingleCondition(): void
    {
        // Act
        $sql = ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'username', 'op' => '=', 'value' => 'yannis']],
            ['username']
        );

        // Assert
        $this->assertSame($this->q('username') . " = 'yannis'", $sql);
    }

    /** Several conditions are ANDed; an `or` group is parenthesised. */
    public function testConditionsCombineWithAndAndOr(): void
    {
        // Act
        $sql = ApiListSqlBuilder::buildFilterFromConditions(
            [
                ['field' => 'usertype', 'op' => '>=', 'value' => 50],
                ['or' => [
                    ['field' => 'username', 'op' => 'LIKE', 'value' => '%a%'],
                    ['field' => 'email', 'op' => 'LIKE', 'value' => '%a%'],
                ]],
            ],
            ['usertype', 'username', 'email']
        );

        // Assert
        $this->assertStringContainsString($this->q('usertype') . ' >= 50', $sql);
        $this->assertStringContainsString(' AND (', $sql);
        $this->assertStringContainsString(' OR ', $sql);
        $this->assertStringContainsString($this->pg ? 'ILIKE' : 'LIKE', $sql);
    }

    /**
     * `LIKE` becomes `ILIKE` on PostgreSQL, because `LIKE` there is case-sensitive.
     *
     * A search box that matches "Yannis" and not "yannis" on one engine and both on the other is
     * the same code behaving differently, and users report it as a broken search rather than as
     * a driver difference.
     */
    public function testLikeIsCaseInsensitiveOnBothEngines(): void
    {
        // Act
        $sql = ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'username', 'op' => 'LIKE', 'value' => 'YAN']],
            ['username']
        );

        // Assert
        $this->assertStringContainsString($this->pg ? 'ILIKE' : 'LIKE', $sql);
        if ($this->pg) {
            $this->assertStringNotContainsString(' LIKE ', $sql);
        }
    }

    /** `IN` takes a non-empty array and escapes every member; anything else is dropped. */
    public function testInTakesAnArrayAndEscapesEveryMember(): void
    {
        // Act
        $good = ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'usertype', 'op' => 'IN', 'value' => [10, "50' OR '1"]]],
            ['usertype']
        );
        $empty = ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'usertype', 'op' => 'IN', 'value' => []]],
            ['usertype']
        );
        $scalar = ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'usertype', 'op' => 'IN', 'value' => 10]],
            ['usertype']
        );

        // Assert
        $this->assertStringContainsString("IN ('10', '", $good);
        $this->assertStringNotContainsString("OR '1'", $good, 'a member escaped its quotes');
        $this->assertSame('', $empty, 'IN () is not valid SQL, so the condition is dropped');
        $this->assertSame('', $scalar, 'IN needs a list');
    }

    /** `IS NULL` and `IS NOT NULL` take no value, and a null with `=` becomes `IS NULL`. */
    public function testNullnessIsExpressedAsSql(): void
    {
        // Assert
        $this->assertSame(
            $this->q('email') . ' IS NULL',
            ApiListSqlBuilder::buildFilterFromConditions(
                [['field' => 'email', 'op' => 'IS NULL']],
                ['email']
            )
        );
        $this->assertSame(
            $this->q('email') . ' IS NOT NULL',
            ApiListSqlBuilder::buildFilterFromConditions(
                [['field' => 'email', 'op' => 'IS NOT NULL']],
                ['email']
            )
        );
        $this->assertSame(
            $this->q('email') . ' IS NULL',
            ApiListSqlBuilder::buildFilterFromConditions(
                [['field' => 'email', 'op' => '=', 'value' => null]],
                ['email']
            ),
            "= null is IS NULL, because = NULL matches nothing and reads as a bug"
        );
    }

    /** A number is written as a number, not quoted as a string. */
    public function testNumbersAreNotQuoted(): void
    {
        // Act
        $int   = ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'usertype', 'op' => '=', 'value' => 50]],
            ['usertype']
        );
        $float = ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'usertype', 'op' => '>', 'value' => 1.5]],
            ['usertype']
        );

        // Assert
        $this->assertSame($this->q('usertype') . ' = 50', $int);
        $this->assertSame($this->q('usertype') . ' > 1.5', $float);
    }

    /**
     * An unknown field, an unknown operator and a missing value are each dropped silently.
     *
     * Silently on purpose: a filter is a request, not an instruction, and telling a caller which
     * field names exist by which ones produce an error is a way to enumerate the schema.
     */
    public function testUnknownFieldsAndOperatorsAreDropped(): void
    {
        // Assert
        $this->assertSame('', ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'password', 'op' => '=', 'value' => 'x']],
            ['username']
        ), 'a field nobody offered');

        $this->assertSame('', ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'username', 'op' => 'UNION', 'value' => 'x']],
            ['username']
        ), 'an operator nobody allows');

        $this->assertSame('', ApiListSqlBuilder::buildFilterFromConditions(
            [['field' => 'username', 'op' => '=']],
            ['username']
        ), 'no value for an operator that needs one');

        $this->assertSame('', ApiListSqlBuilder::buildFilterFromConditions(
            [['op' => '=', 'value' => 'x']],
            ['username']
        ), 'no field at all');
    }

    // ── Combining ─────────────────────────────────────────────────────────────

    /** The `where` keyword appears exactly once, whichever side supplies it. */
    public function testTheWhereKeywordIsNeverDoubled(): void
    {
        // Assert
        $this->assertSame('', ApiListSqlBuilder::combineFilters('', ''));
        $this->assertSame("where a = 1", ApiListSqlBuilder::combineFilters('a = 1', ''));
        $this->assertSame("where a = 1", ApiListSqlBuilder::combineFilters('where a = 1', ''));
        $this->assertSame("where b = 2", ApiListSqlBuilder::combineFilters('', 'b = 2'));

        $both = ApiListSqlBuilder::combineFilters('where a = 1', 'b = 2');
        $this->assertStringStartsWith('where ', $both);
        $this->assertSame(1, preg_match_all('/\bwhere\b/i', $both), 'the keyword appears twice');
        $this->assertStringContainsString('a = 1', $both);
        $this->assertStringContainsString('b = 2', $both);
    }
}
