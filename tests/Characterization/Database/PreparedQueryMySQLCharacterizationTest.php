<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;

/**
 * Characterization tests for preparedQuery() comment handling against live
 * MySQL 8.0.
 *
 * Why a MySQL file of its own. The rules for what opens a comment are not the
 * same in the two dialects, and the differences are not academic — each one
 * decides whether a placeholder binds:
 *
 *   - '#' is a line comment in MySQL; in PostgreSQL it is an operator.
 *   - '--' needs whitespace after it in MySQL ("5--3" is 8); in PostgreSQL it
 *     always opens a comment ("5--3" is 5).
 *   - '/ * ... / * ... * /' does not nest in MySQL; the first close ends it.
 *   - '/ *! ... * /' is a version-gated *executable* comment in MySQL: the SQL
 *     inside it runs, so placeholders there are real.
 *
 * A unit test can assert the rewritten SQL, but only the server can say whether
 * the rewrite was right about its own dialect — which is what these run for.
 * The dialect-agnostic cases are covered in
 * PreparedQueryPostgreSQLCharacterizationTest and the unit scanner test.
 *
 * Table used: pq_notes (id INT PK AUTO_INCREMENT, name VARCHAR, qty INT)
 */
#[CoversClass(Database::class)]
#[\PHPUnit\Framework\Attributes\Group('mysql')]
#[\PHPUnit\Framework\Attributes\Group('characterization')]
class PreparedQueryMySQLCharacterizationTest extends TestCase
{
    private Database $db;

    private const TABLE = 'pq_notes';

    protected function setUp(): void
    {
        // Arrange — connect to Docker MySQL
        $this->db = new Database();
        $this->db->type     = 'mysql';
        $this->db->server   = 'db';
        $this->db->user     = 'root';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 3306;
        $this->db->connect(true);

        // Arrange — a table with one known row to read back
        $this->db->query('DROP TABLE IF EXISTS `' . self::TABLE . '`');
        $this->db->query(
            'CREATE TABLE `' . self::TABLE . '` ('
            . '`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,'
            . '`name` VARCHAR(255) NOT NULL,'
            . '`qty` INT NOT NULL DEFAULT 0'
            . ') ENGINE=InnoDB'
        );
        $this->db->preparedQuery(
            'INSERT INTO `' . self::TABLE . '` (`name`, `qty`) VALUES (:n, :q)',
            ['n' => 'row', 'q' => 11]
        );
    }

    protected function tearDown(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . self::TABLE . '`');
    }

    /**
     * The filed failure, on MySQL: an apostrophe inside a block comment must
     * not stop the placeholder after it from binding.
     *
     * The bug was reported from PostgreSQL, but the scanner it lived in is
     * shared, so the same statement failed here too.
     */
    public function testApostropheInBlockCommentDoesNotBreakBinding(): void
    {
        // Act
        $result = $this->db->preparedQuery(
            "SELECT /* a JOIN's clause */ `qty` FROM `" . self::TABLE
            . "` WHERE `name` = :name",
            ['name' => 'row']
        );

        // Assert — a Result, not the false a failed prepare returns.
        $this->assertNotFalse($result);
        $this->assertSame(11, (int) $result->fetch()['qty']);
    }

    /**
     * '#' opens a line comment on MySQL, so an apostrophe in the prose after it
     * does not open a string literal, and the placeholder on the next line
     * binds. The server is the authority: if the scanner were wrong here, the
     * statement would not prepare.
     */
    public function testHashCommentWithApostrophe(): void
    {
        // Act
        $result = $this->db->preparedQuery(
            "SELECT `qty` FROM `" . self::TABLE . "`\n"
            . "# the caller's own note\n"
            . "WHERE `name` = :name",
            ['name' => 'row']
        );

        // Assert
        $this->assertNotFalse($result);
        $this->assertSame(11, (int) $result->fetch()['qty']);
    }

    /**
     * '--' followed by whitespace is a comment on MySQL, as everywhere.
     */
    public function testDashDashCommentWithApostrophe(): void
    {
        // Act
        $result = $this->db->preparedQuery(
            "SELECT `qty` FROM `" . self::TABLE . "`\n"
            . "-- the station's own window\n"
            . "WHERE `name` = :name",
            ['name' => 'row']
        );

        // Assert
        $this->assertNotFalse($result);
        $this->assertSame(11, (int) $result->fetch()['qty']);
    }

    /**
     * A tight '--' is arithmetic on MySQL, not a comment: "5--3" is 8, and the
     * placeholder written after it is live SQL that must bind.
     *
     * This is the case that would break if the PostgreSQL rule were applied to
     * both dialects — the scanner would read the rest of the line as prose and
     * leave ':name' unbound, and the statement would fail to prepare.
     */
    public function testTightDashDashIsArithmeticAndLaterPlaceholderBinds(): void
    {
        // Act
        $result = $this->db->preparedQuery(
            'SELECT 5--3 AS math, `qty` FROM `' . self::TABLE
            . '` WHERE `name` = :name',
            ['name' => 'row']
        );

        // Assert — the server agrees it is 8, and the binding survived.
        $this->assertNotFalse($result);
        $row = $result->fetch();
        $this->assertSame(8, (int) $row['math']);
        $this->assertSame(11, (int) $row['qty']);
    }

    /**
     * MySQL does not nest block comments: the first close ends the comment, and
     * what follows is SQL again. Counting depth (the PostgreSQL rule) would
     * swallow the rest of the statement and lose the binding.
     */
    public function testBlockCommentsDoNotNest(): void
    {
        // Act — after the first close, the WHERE clause is real SQL.
        $result = $this->db->preparedQuery(
            'SELECT `qty` FROM `' . self::TABLE
            . '` /* outer /* inner */ WHERE `name` = :name',
            ['name' => 'row']
        );

        // Assert
        $this->assertNotFalse($result);
        $this->assertSame(11, (int) $result->fetch()['qty']);
    }

    /**
     * A version-gated comment is executed by the server, so a placeholder
     * inside it is real and must be bound — treating the block as prose would
     * send an unbound ':name' into SQL that runs.
     */
    public function testExecutableCommentBindsItsPlaceholder(): void
    {
        // Act — the 40101 gate is far below 8.0, so the body executes.
        $result = $this->db->preparedQuery(
            'SELECT /*!40101 :name AS gated, */ `qty` FROM `' . self::TABLE
            . '` WHERE `name` = :name',
            ['name' => 'row']
        );

        // Assert — the gated column came back, carrying the bound value.
        $this->assertNotFalse($result);
        $row = $result->fetch();
        $this->assertSame('row', $row['gated']);
        $this->assertSame(11, (int) $row['qty']);
    }

    /**
     * A placeholder that is commented out is not bound, and the ones that are
     * not commented out keep their order.
     *
     * Proven through the positional style, where a miscount is silent: were the
     * commented '?' counted, 'row' would bind to it and the real placeholder
     * would receive 11 — matching nothing.
     */
    public function testCommentedOutPositionalPlaceholderIsIgnored(): void
    {
        // Act
        $result = $this->db->preparedQuery(
            'SELECT `qty` FROM `' . self::TABLE
            . '` WHERE /* `qty` = ? AND */ `name` = ?',
            ['row']
        );

        // Assert
        $this->assertNotFalse($result);
        $this->assertSame(11, (int) $result->fetch()['qty']);
    }

    /**
     * The framework's own '%s' placeholder style goes through the same masking,
     * so a '%s' written inside a comment must not be counted as a parameter.
     *
     * This is the half of the bug that only surfaced when the fix above was
     * tested against a live server: prepare() counted '%X' with a quote-aware,
     * comment-blind regex of its own, so an apostrophe in a comment hid the
     * real placeholder and the prepare failed with an argument-count mismatch.
     */
    public function testPercentPlaceholderInsideACommentIsNotAParameter(): void
    {
        // Arrange — execute() takes its arguments by reference, so they must be
        // variables rather than literals.
        $name = 'row';

        // Act — one real %s, one commented out, and an apostrophe between them.
        $result = $this->db->execute(
            "SELECT `qty` FROM `" . self::TABLE
            . "` /* the caller's own %s */ WHERE `name` = %s",
            $name
        );

        // Assert
        $this->assertNotFalse($result);
        $this->assertSame(11, (int) $result->fetch()['qty']);
    }

    /**
     * A '%' inside a LIKE pattern is data, not a placeholder — the behaviour
     * the original quote-only masking existed to protect, re-asserted here so
     * the rewrite cannot regress it.
     */
    public function testPercentInsideALikePatternIsNotAParameter(): void
    {
        // Arrange
        $qty = 11;

        // Act
        $result = $this->db->execute(
            'SELECT `qty` FROM `' . self::TABLE
            . '` WHERE `name` LIKE \'%ro%\' AND `qty` = %d',
            $qty
        );

        // Assert
        $this->assertNotFalse($result);
        $this->assertSame(11, (int) $result->fetch()['qty']);
    }
}
