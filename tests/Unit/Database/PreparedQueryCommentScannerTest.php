<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Unit tests for the comment handling in Database::bindPlaceholders() — the
 * scanner behind preparedQuery().
 *
 * Why this file exists. The scanner was quote-aware but not comment-aware, so a
 * single apostrophe inside a comment — the possessive in "a JOIN's clause" — put
 * it into its in-string state, and every ':name' after that point was emitted
 * into the SQL unbound. The statement then failed, and because preparedQuery()
 * answers false rather than throwing, an application that reads `$result ?: []`
 * could not tell the failure from an empty table: it printed a blank page and
 * a sentence that was a legitimate thing for that page to say.
 *
 * The invariant under test: whether a stretch of text is a comment decides only
 * whether placeholders and quotes inside it are *read*, never whether the text
 * survives. Every assertion therefore checks two things at once — the rewritten
 * SQL is byte-for-byte the input with placeholders replaced, and the ordered
 * value list contains exactly the bindings the server should receive.
 *
 * The dialects are tested separately because they genuinely disagree about what
 * opens a comment, and guessing one rule for the pair breaks the other.
 */
#[CoversClass(Database::class)]
class PreparedQueryCommentScannerTest extends TestCase
{
    /**
     * Run the private scanner directly.
     *
     * bindPlaceholders() is private and has no public seam that does not also
     * need a live connection, so reflection is the only way to test the parsing
     * in isolation. It is the parsing that was wrong.
     *
     * @param string $driver 'mysql' | 'postgresql' | 'timescaledb'
     * @return array{0:string,1:array} [rewritten SQL, ordered values]
     */
    private function scan(string $driver, string $sql, array $bindings): array
    {
        $db = new Database();
        $db->type = $driver;

        $method = new \ReflectionMethod(Database::class, 'bindPlaceholders');

        return $method->invoke($db, $sql, $bindings);
    }

    // ── The reported bug, on both dialects ──────────────────────────────────

    /**
     * The three drivers, so every dialect-agnostic rule is asserted on all of
     * them rather than on whichever one the author had in mind.
     *
     * @return array<string,array{string}>
     */
    public static function driverProvider(): array
    {
        return [
            'mysql'       => ['mysql'],
            'postgresql'  => ['postgresql'],
            'timescaledb' => ['timescaledb'],
        ];
    }

    /**
     * The filed reproduction: an apostrophe inside a block comment must not
     * swallow the placeholder that follows it.
     *
     * This is the exact statement shape that dark-screened a consuming
     * application's "now playing" page — a prose comment containing "JOIN's"
     * ahead of a ':minutes' interval.
     *
     * @param string $driver Driver name under test.
     */
    #[DataProvider('driverProvider')]
    public function testApostropheInBlockCommentDoesNotEatLaterPlaceholders(
        string $driver
    ): void {
        // Arrange
        $sql = "SELECT /* a JOIN's clause */ x FROM t WHERE m = :minutes";

        // Act
        [$out, $ordered] = $this->scan($driver, $sql, ['minutes' => 15]);

        // Assert — the comment survives verbatim, the placeholder is bound.
        $this->assertSame(
            "SELECT /* a JOIN's clause */ x FROM t WHERE m = %s",
            $out
        );
        $this->assertSame([15], $ordered);
    }

    /**
     * The same failure through a line comment rather than a block one. Both
     * were reachable; only the block form had been reported.
     */
    #[DataProvider('driverProvider')]
    public function testApostropheInLineCommentDoesNotEatLaterPlaceholders(
        string $driver
    ): void {
        // Arrange
        $sql = "SELECT x\n-- the station's own clock\nFROM t WHERE m = :m";

        // Act
        [$out, $ordered] = $this->scan($driver, $sql, ['m' => 5]);

        // Assert
        $this->assertSame(
            "SELECT x\n-- the station's own clock\nFROM t WHERE m = %s",
            $out
        );
        $this->assertSame([5], $ordered);
    }

    /**
     * A placeholder *inside* a comment is not a placeholder. Binding it would
     * consume a value the server never sees, which for the positional style
     * shifts every later argument by one — a wrong answer rather than an error.
     */
    #[DataProvider('driverProvider')]
    public function testPlaceholderInsideCommentIsNotBound(string $driver): void
    {
        // Arrange — ':old' is commented out; only ':new' should bind.
        $sql = 'SELECT x FROM t WHERE /* was = :old */ y = :new';

        // Act
        [$out, $ordered] = $this->scan($driver, $sql, ['new' => 'n']);

        // Assert — ':old' is still spelled out, and never asked for a binding.
        $this->assertSame(
            'SELECT x FROM t WHERE /* was = :old */ y = %s',
            $out
        );
        $this->assertSame(['n'], $ordered);
    }

    /**
     * A commented-out '?' does not consume a positional binding either — and
     * here the count check proves it: were the '?' inside the comment counted,
     * the two given bindings would satisfy it plus the real one and the throw
     * below would not happen.
     */
    #[DataProvider('driverProvider')]
    public function testCommentedPositionalPlaceholderIsNotCounted(
        string $driver
    ): void {
        // Arrange / Act
        [$out, $ordered] = $this->scan(
            $driver,
            'SELECT x FROM t WHERE /* or ? */ y = ?',
            ['only']
        );

        // Assert
        $this->assertSame('SELECT x FROM t WHERE /* or ? */ y = %s', $out);
        $this->assertSame(['only'], $ordered);
    }

    /**
     * A comment inside a string literal is text, not a comment: the literal's
     * own rules win. Getting this backwards would stop binding at the first
     * '--' that happened to appear inside quoted data.
     */
    #[DataProvider('driverProvider')]
    public function testCommentMarkersInsideStringLiteralAreJustText(
        string $driver
    ): void {
        // Arrange
        $sql = "SELECT '-- not a comment /* nor this */' AS lit, :real AS b";

        // Act
        [$out, $ordered] = $this->scan($driver, $sql, ['real' => 'ok']);

        // Assert
        $this->assertSame(
            "SELECT '-- not a comment /* nor this */' AS lit, %s AS b",
            $out
        );
        $this->assertSame(['ok'], $ordered);
    }

    /**
     * An escaped quote ('') inside a literal keeps the scanner in-string, and a
     * comment opened after the literal closes is still recognised. This pins
     * the interaction between the two states rather than each alone.
     */
    #[DataProvider('driverProvider')]
    public function testEscapedQuoteThenCommentThenPlaceholder(
        string $driver
    ): void {
        // Arrange — 'it''s' is one literal; the comment follows it.
        $sql = "SELECT 'it''s' AS lit /* and it's fine */, :v AS v";

        // Act
        [$out, $ordered] = $this->scan($driver, $sql, ['v' => 1]);

        // Assert
        $this->assertSame(
            "SELECT 'it''s' AS lit /* and it's fine */, %s AS v",
            $out
        );
        $this->assertSame([1], $ordered);
    }

    /**
     * An unterminated comment cannot be closed, so everything after it is
     * comment. The SQL is invalid and the server will say so; the scanner's job
     * is to not invent a binding on the way there.
     */
    #[DataProvider('driverProvider')]
    public function testUnterminatedBlockCommentSwallowsTheRest(
        string $driver
    ): void {
        // Arrange / Act
        [$out, $ordered] = $this->scan(
            $driver,
            'SELECT x FROM t /* never closed :v',
            ['v' => 1]
        );

        // Assert — emitted verbatim, nothing bound.
        $this->assertSame('SELECT x FROM t /* never closed :v', $out);
        $this->assertSame([], $ordered);
    }

    /**
     * A line comment that runs to the end of the statement, with no trailing
     * newline, terminates cleanly.
     */
    #[DataProvider('driverProvider')]
    public function testLineCommentAtEndOfStatement(string $driver): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan(
            $driver,
            "SELECT :v -- trailing note, no newline",
            ['v' => 2]
        );

        // Assert
        $this->assertSame('SELECT %s -- trailing note, no newline', $out);
        $this->assertSame([2], $ordered);
    }

    /**
     * '::' casts still work when a comment sits next to them — the cast branch
     * is reached only outside comments, and this proves the new states did not
     * shadow it.
     */
    #[DataProvider('driverProvider')]
    public function testCastStillRecognisedAfterAComment(string $driver): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan(
            $driver,
            'SELECT /* c */ (:minutes || \' minutes\')::interval AS v',
            ['minutes' => 15]
        );

        // Assert — the '::' is emitted untouched, the placeholder bound.
        $this->assertSame(
            'SELECT /* c */ (%s || \' minutes\')::interval AS v',
            $out
        );
        $this->assertSame([15], $ordered);
    }

    // ── Where the dialects disagree ─────────────────────────────────────────

    /**
     * PostgreSQL: '--' always opens a comment, whatever follows it. "5--3" is 5.
     */
    public function testPostgresTreatsDashDashWithoutSpaceAsAComment(): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan(
            'postgresql',
            "SELECT :v\n--tight comment, no space\nFROM t",
            ['v' => 1]
        );

        // Assert — the placeholder before it bound; nothing after asked to.
        $this->assertSame("SELECT %s\n--tight comment, no space\nFROM t", $out);
        $this->assertSame([1], $ordered);
    }

    /**
     * MySQL: '--' opens a comment only when whitespace (or the end of the
     * statement) follows, so "5--3" is 8 — the second '-' is a unary minus.
     *
     * The consequence for the scanner is the one that matters: text after a
     * tight '--' is still SQL, so a placeholder in it must bind. Reading it as
     * a comment would leave ':v' unbound and fail the statement.
     */
    public function testMysqlTreatsTightDashDashAsArithmeticNotComment(): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan('mysql', 'SELECT 5--3, :v', ['v' => 7]);

        // Assert — no comment was opened, so ':v' bound.
        $this->assertSame('SELECT 5--3, %s', $out);
        $this->assertSame([7], $ordered);
    }

    /**
     * MySQL with the space present behaves as every dialect does.
     */
    public function testMysqlDashDashWithSpaceIsAComment(): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan(
            'mysql',
            "SELECT :v -- it's a note\nFROM t",
            ['v' => 1]
        );

        // Assert
        $this->assertSame("SELECT %s -- it's a note\nFROM t", $out);
        $this->assertSame([1], $ordered);
    }

    /**
     * A bare '-' that is not doubled is arithmetic on every dialect, and must
     * not be mistaken for the start of a comment.
     */
    #[DataProvider('driverProvider')]
    public function testSingleDashIsNeverAComment(string $driver): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan($driver, 'SELECT 5 - :v', ['v' => 2]);

        // Assert
        $this->assertSame('SELECT 5 - %s', $out);
        $this->assertSame([2], $ordered);
    }

    /**
     * A '-' as the statement's very last character cannot be doubled — the
     * lookahead must not read past the end of the string.
     */
    #[DataProvider('driverProvider')]
    public function testTrailingDashDoesNotOverrunTheString(string $driver): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan($driver, 'SELECT :v -', ['v' => 1]);

        // Assert
        $this->assertSame('SELECT %s -', $out);
        $this->assertSame([1], $ordered);
    }

    /**
     * MySQL treats '#' as a line comment, so an apostrophe written in the prose
     * after it does not open a string literal and the placeholder on the next
     * line binds.
     *
     * This case is asserted for MySQL alone deliberately. The same statement is
     * not valid PostgreSQL at all: there '#' is an operator, so "it's mine"
     * opens a literal that is never closed. A test that expected both dialects
     * to agree here would be asserting that the scanner mis-parses one of them.
     */
    public function testMysqlHashCommentContainingAnApostrophe(): void
    {
        // Arrange
        $sql = "SELECT a # it's mine\n, :v";

        // Act
        [$out, $ordered] = $this->scan('mysql', $sql, ['v' => 1]);

        // Assert — the comment ended at the newline, so ':v' was read as SQL.
        $this->assertSame("SELECT a # it's mine\n, %s", $out);
        $this->assertSame([1], $ordered);
    }

    /**
     * On PostgreSQL a '#' does not open a comment, so a placeholder written
     * after one on the same line still binds — the case that separates the two
     * dialects rather than merely agreeing by accident.
     */
    public function testPostgresBindsPlaceholderAfterAHashOnTheSameLine(): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan(
            'postgresql',
            'SELECT arr # :v',
            ['v' => 3]
        );

        // Assert — bound; MySQL would have read the rest of the line as prose.
        $this->assertSame('SELECT arr # %s', $out);
        $this->assertSame([3], $ordered);
    }

    /**
     * MySQL reads the rest of the line after '#' as a comment, so a placeholder
     * there is not a placeholder — the mirror of the assertion above.
     */
    public function testMysqlDoesNotBindPlaceholderAfterAHash(): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan('mysql', 'SELECT a # :v', []);

        // Assert — no binding was needed, so no exception for a missing one.
        $this->assertSame('SELECT a # :v', $out);
        $this->assertSame([], $ordered);
    }

    /**
     * PostgreSQL nests block comments: an inner '/*' must be closed before the
     * outer one ends. Without depth tracking the first '*(/)' would end the
     * comment and the remaining prose would be parsed as SQL.
     */
    public function testPostgresNestsBlockComments(): void
    {
        // Arrange — the inner close does not end the outer comment.
        $sql = "SELECT /* outer /* inner's */ still comment */ :v";

        // Act
        [$out, $ordered] = $this->scan('postgresql', $sql, ['v' => 1]);

        // Assert
        $this->assertSame(
            "SELECT /* outer /* inner's */ still comment */ %s",
            $out
        );
        $this->assertSame([1], $ordered);
    }

    /**
     * MySQL does not nest: the first '*(/)' closes the comment, and what
     * follows is SQL again. Counting depth here would swallow the rest of the
     * statement.
     */
    public function testMysqlDoesNotNestBlockComments(): void
    {
        // Arrange — after the first close, ':v' is real SQL.
        $sql = 'SELECT /* outer /* inner */ :v';

        // Act
        [$out, $ordered] = $this->scan('mysql', $sql, ['v' => 1]);

        // Assert
        $this->assertSame('SELECT /* outer /* inner */ %s', $out);
        $this->assertSame([1], $ordered);
    }

    /**
     * MySQL's '/*!' is a version-gated *executable* comment: the server runs
     * the SQL inside it. A placeholder there is therefore real and must bind —
     * treating the block as prose would leave it unbound in SQL that executes.
     */
    public function testMysqlExecutableCommentBindsItsPlaceholders(): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan(
            'mysql',
            'SELECT /*!40101 :v */ 1',
            ['v' => 9]
        );

        // Assert — bound, because that text is executed.
        $this->assertSame('SELECT /*!40101 %s */ 1', $out);
        $this->assertSame([9], $ordered);
    }

    /**
     * On PostgreSQL '/*!' has no special meaning — it is an ordinary comment,
     * so the placeholder inside it is prose and must not bind.
     */
    public function testPostgresTreatsBangCommentAsAnOrdinaryComment(): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan('postgresql', 'SELECT /*! :v */ 1', []);

        // Assert — no binding asked for, so no exception.
        $this->assertSame('SELECT /*! :v */ 1', $out);
        $this->assertSame([], $ordered);
    }

    /**
     * A lone '/' is division, not the start of a comment, and a '/' at the very
     * end of the statement must not be read past.
     */
    #[DataProvider('driverProvider')]
    public function testSlashWithoutStarIsDivision(string $driver): void
    {
        // Arrange / Act
        [$out, $ordered] = $this->scan($driver, 'SELECT :v / 2', ['v' => 8]);

        // Assert
        $this->assertSame('SELECT %s / 2', $out);
        $this->assertSame([8], $ordered);
    }

    /**
     * A missing binding for a placeholder that is *not* in a comment still
     * throws — the comment states must not suppress the guard that tells a
     * caller they forgot a value.
     */
    #[DataProvider('driverProvider')]
    public function testMissingBindingOutsideACommentStillThrows(
        string $driver
    ): void {
        // Arrange
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing binding for placeholder ":b"');

        // Act — ':a' is commented out, ':b' is not and has no value.
        $this->scan($driver, 'SELECT /* :a */ :b', ['other' => 1]);
    }
}
