<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Security\ReadOnlyQuery;

/**
 * The lexer that stands between a diagnostic tool and somebody's data.
 *
 * Where an installation has configured a read-only database account this class is
 * a second opinion. Where it has not — and the framework deliberately does not
 * require one — it is the only boundary there is, so the cases below are not
 * decoration: each one is a way a statement that writes could have been read.
 */
#[CoversClass(ReadOnlyQuery::class)]
class ReadOnlyQueryTest extends TestCase
{
    /**
     * A plain read passes, in the forms people actually write.
     *
     * Matters because a guard that refuses ordinary queries gets switched off.
     * The last two are the ones a first-keyword check would get right by luck and
     * this one gets right on purpose: a read `WITH` clause, and a column whose
     * name contains a keyword as a substring rather than a word.
     */
    #[DataProvider('readsProvider')]
    public function testAReadIsAllowed(string $sql): void
    {
        // Act
        $allowed = ReadOnlyQuery::isRead($sql, $reason);

        // Assert
        $this->assertTrue($allowed, 'refused with: ' . (string) $reason);
        $this->assertNull($reason);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function readsProvider(): array
    {
        return array(
            'plain select'      => array('SELECT 1'),
            'trailing semicolon' => array('SELECT count(*) FROM images;'),
            'lower case'        => array('select id from images where width > 100'),
            'read-only CTE'     => array(
                'WITH recent AS (SELECT id FROM images ORDER BY id DESC LIMIT 10)'
                . ' SELECT count(*) FROM recent'
            ),
            'keyword as substring' => array('SELECT created, updated_at_hint FROM images'),
            'quoted identifier that is a keyword' => array('SELECT "delete" FROM t'),
            'writing word inside a literal' => array("SELECT id FROM logs WHERE action = 'delete'"),
        );
    }

    /**
     * The statements that must not get through, and why each one could have.
     *
     * `WITH … DELETE … RETURNING` is the load-bearing case: it begins with `WITH`,
     * ends as a `SELECT`, and empties a table. Every check that reads the first
     * keyword and stops accepts it.
     */
    #[DataProvider('writesProvider')]
    public function testAWriteIsRefused(string $sql, string $expectInReason): void
    {
        // Act
        $allowed = ReadOnlyQuery::isRead($sql, $reason);

        // Assert
        $this->assertFalse($allowed, 'this was allowed through: ' . $sql);
        $this->assertIsString($reason);
        $this->assertStringContainsStringIgnoringCase($expectInReason, $reason);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function writesProvider(): array
    {
        return array(
            'data-modifying CTE' => array(
                'WITH gone AS (DELETE FROM usertokens RETURNING *) SELECT count(*) FROM gone',
                'DELETE',
            ),
            'stacked statement'  => array('SELECT 1; DROP TABLE users', 'one statement'),
            'plain update'       => array('UPDATE users SET password = 1', 'Only SELECT'),
            'copy to program'    => array("COPY (SELECT 1) TO PROGRAM 'sh'", 'Only SELECT'),
            'select into'        => array('SELECT * INTO copies FROM users', 'INTO'),
            'set inside a read'  => array('SELECT 1; SET ROLE postgres', 'one statement'),
            'nothing at all'     => array('   ', 'no statement'),
            'only a comment'     => array('-- SELECT 1', 'no statement'),
        );
    }

    /**
     * A comment cannot hide a keyword, and blanking cannot join two words.
     *
     * Blanking rather than deleting is what stops `'a' delete` collapsing into
     * `adelete`, which no word boundary would then catch.
     */
    public function testCommentsAndLiteralsAreBlankedNotRemoved(): void
    {
        // Act
        $stripped = ReadOnlyQuery::withoutStringsAndComments("SELECT 'a' delete /* x */ FROM t");

        // Assert — same length, so every offset survived
        $this->assertSame(
            strlen("SELECT 'a' delete /* x */ FROM t"),
            strlen($stripped)
        );
        // The keyword outside the literal is still a standalone word
        $this->assertMatchesRegularExpression('/\bdelete\b/', $stripped);
        $this->assertStringNotContainsString('adelete', $stripped);
    }

    /**
     * Dollar quoting is a string literal too.
     *
     * PostgreSQL's `$$ … $$` needs no escaping inside, which makes it the obvious
     * place to hide a keyword from a scanner that only knows about single quotes.
     */
    public function testDollarQuotingIsTreatedAsAString(): void
    {
        // Act
        $allowed = ReadOnlyQuery::isRead('SELECT $tag$ drop table users $tag$ AS note', $reason);

        // Assert
        $this->assertTrue($allowed, 'refused with: ' . (string) $reason);
    }

    /**
     * An unterminated dollar quote runs to the end rather than falling out of the
     * loop, so nothing after it is scanned as SQL. It cannot execute anyway.
     */
    public function testAnUnterminatedDollarQuoteConsumesTheRest(): void
    {
        // Act
        $stripped = ReadOnlyQuery::withoutStringsAndComments('SELECT $$ drop table users');

        // Assert
        $this->assertSame('SELECT                    ', $stripped);
    }

    /**
     * Nested block comments are matched by depth, as PostgreSQL parses them.
     *
     * A scanner that stops at the first `*` + `/` would resume mid-comment and
     * read the tail as SQL — here that tail is `delete`.
     */
    public function testNestedBlockCommentsAreConsumedWhole(): void
    {
        // Act
        $allowed = ReadOnlyQuery::isRead('SELECT 1 /* a /* b */ delete */ FROM t', $reason);

        // Assert
        $this->assertTrue($allowed, 'refused with: ' . (string) $reason);
    }

    /**
     * The doubled-quote escape does not end the literal.
     *
     * `'it''s delete'` is one string. Ending it at the second quote would leave
     * `s delete'` scanned as SQL, refusing a query that is perfectly fine.
     */
    public function testDoubledQuoteEscapeStaysInsideTheLiteral(): void
    {
        // Act
        $allowed = ReadOnlyQuery::isRead("SELECT 'it''s delete' AS note", $reason);

        // Assert
        $this->assertTrue($allowed, 'refused with: ' . (string) $reason);
    }
}
