<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Grammar\Grammar;
use Pramnos\Database\QueryBuilder;

/**
 * A reserved word used as a column name is quoted wherever the builder names a column — bare,
 * after a table alias, and as an alias — and nothing else changes.
 *
 * `usage` is reserved by MySQL alone, and `llm_calls.usage` could not be selected there. The
 * rest of what is written — expressions, raw SQL, `t.*`, prefixed names, mixed-case aliases whose
 * key a row comes back under — must compile exactly as before.
 */
#[CoversClass(Grammar::class)]
class ReservedColumnQuotingTest extends TestCase
{
    private function builder(string $type): QueryBuilder
    {
        $db = $this->getMockBuilder(Database::class)->disableOriginalConstructor()->getMock();
        $db->type   = $type;
        $db->prefix = '';

        return (new QueryBuilder($db))->table('llm_calls');
    }

    /** @return array<string, array{string, string}> */
    public static function dialects(): array
    {
        return ['mysql' => ['mysql', '`'], 'postgresql' => ['postgresql', '"']];
    }

    /** A reserved word is quoted bare, qualified, as an alias, and in WHERE, GROUP BY and ORDER BY. */
    #[DataProvider('dialects')]
    public function testAReservedWordIsQuotedWhereverItIsNamed(string $type, string $q): void
    {
        // Act
        $sql = $this->builder($type)
            ->select(['usage', 'c.usage', 'tokens AS order', 'c.usage as u'])
            ->where('usage', '!=', '')->where('c.usage', '!=', '')
            ->groupBy('usage')->orderBy('c.usage')
            ->toSql();

        // Assert
        $this->assertStringContainsString("SELECT {$q}usage{$q}, c.{$q}usage{$q}, tokens AS {$q}order{$q}, c.{$q}usage{$q} AS u FROM", $sql);
        $this->assertStringContainsString("WHERE {$q}usage{$q} != ", $sql);
        $this->assertStringContainsString("c.{$q}usage{$q} != ", $sql);
        $this->assertStringContainsString("GROUP BY {$q}usage{$q}", $sql);
        $this->assertStringContainsString("ORDER BY c.{$q}usage{$q} ASC", $sql);
    }

    /**
     * What is not a reserved word stays as written: ordinary names, `t.*`, expressions, prefixed
     * names, a mixed-case alias (its row key), and a raw expression whatever it says.
     */
    #[DataProvider('dialects')]
    public function testEverythingElseIsAsWritten(string $type, string $q): void
    {
        // Arrange
        $builder = $this->builder($type);

        // Act
        $sql = $builder->select(['model', 'c.model', 'c.*', 'COUNT(*) AS n', 'SUM(cost) AS Total', '#PREFIX#users.userid', $builder->raw('usage AS usage')])->toSql();

        // Assert
        $this->assertStringContainsString('SELECT model, c.model, c.*, COUNT(*) AS n, SUM(cost) AS Total, users.userid, usage AS usage FROM', $sql);
    }
}
