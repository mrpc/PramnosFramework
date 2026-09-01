<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * `prepareQuery()` with no arguments — where every literal `%` used to be a format directive.
 *
 * The method takes `sprintf`-style placeholders, and it ended by calling `vsprintf` **whether or not
 * there was anything to substitute**. So an argument-less query containing a literal `%` was handed
 * to `sprintf` as a format string, and the ordinary victim is `LIKE`:
 *
 * ```php
 * $db->prepareQuery("SELECT * FROM t WHERE payload LIKE '%ApplyStart%'");
 * ```
 *
 * To `sprintf` the trailing `%'` is «pad with the next character», and there is no next character.
 * PHP 8 raises `ValueError: Missing padding character` — and the `@` in front of `vsprintf`
 * suppresses *warnings*, not exceptions, so this was a **fatal error** on a routine query. Reported
 * with a measured surface of 82 lines across 15 files in one consuming application.
 *
 * Both backends, because `prepareQuery()` rewrites the statement differently for each — backticks
 * to double quotes, aliases requoted, `DELETE … LIMIT` restructured — before it ever reaches the
 * formatting step, and the framework calls it internally on both.
 */
#[CoversClass(Database::class)]
class PrepareQueryLiteralPercentTest extends BaseTestCase
{
    private $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── The crash ─────────────────────────────────────────────────────────────

    /**
     * A `LIKE` pattern in an argument-less query does not raise.
     *
     * The reported case, verbatim in shape. It is asserted as «does not throw *and* the pattern
     * survives», because a fix that swallowed the error and returned an empty string would satisfy
     * the first half while breaking every such query more quietly.
     */
    public function testALikePatternWithNoArgumentsSurvives(): void
    {
        // Act
        $sql = $this->db->prepareQuery(
            "SELECT * FROM `#PREFIX#queueitems` "
            . "WHERE `type` = 'jooblepostback' AND `payload` LIKE '%ApplyStart%'"
        );

        // Assert
        $this->assertIsString($sql, 'the query did not survive preparation');
        $this->assertStringContainsString(
            "LIKE '%ApplyStart%'",
            $sql,
            'the LIKE pattern was mangled by the formatting step'
        );
    }

    /**
     * A trailing `%` on its own is the exact shape that raised.
     *
     * `'%'` at the end of the string is what `sprintf` reads as a padding specifier with nothing to
     * pad with, so this is the minimal reproduction rather than a variation on it.
     */
    public function testATrailingPercentDoesNotRaise(): void
    {
        // Act & Assert
        $sql = $this->db->prepareQuery("SELECT * FROM `#PREFIX#users` WHERE `username` LIKE 'a%'");

        $this->assertStringContainsString("LIKE 'a%'", (string) $sql);
    }

    /**
     * Several literal percents in one query all survive.
     *
     * A leading-and-trailing wildcard is the common form, and two directives in one string is where
     * `sprintf` starts consuming arguments that are not there.
     */
    public function testSeveralLiteralPercentsSurvive(): void
    {
        // Act
        $sql = (string) $this->db->prepareQuery(
            "SELECT * FROM `#PREFIX#users` "
            . "WHERE `username` LIKE '%a%' OR `email` LIKE '%b%' OR `name` LIKE '%c%'"
        );

        // Assert
        foreach (["'%a%'", "'%b%'", "'%c%'"] as $pattern) {
            $this->assertStringContainsString($pattern, $sql, $pattern . ' was consumed');
        }
    }

    /**
     * The prepared statement actually runs.
     *
     * The assertion that makes the rest worth making: a string that looks right and the database
     * rejects is no better than an exception, and the `#PREFIX#` rewriting happens in the same
     * method.
     */
    public function testThePreparedQueryRuns(): void
    {
        /*
         * Its own table, not one the suite happens to have.
         *
         * The first version queried `users`, which passed under a filter and failed in the full run
         * on one backend: whether that table exists at this point depends on which test ran first.
         * A test that needs a schema builds it.
         */
        $table = 'pqprobe_' . bin2hex(random_bytes(4));
        $quote = $this->db->type === 'postgresql' ? '"' : '`';
        $this->db->query(
            'CREATE TABLE ' . $quote . $table . $quote . ' ('
            . $quote . 'username' . $quote . ' VARCHAR(190))'
        );

        try {
            // Act
            $sql = (string) $this->db->prepareQuery(
                'SELECT COUNT(*) AS total FROM ' . $quote . $table . $quote
                . ' WHERE ' . $quote . 'username' . $quote . " LIKE '%zzz-no-such%'"
            );
            $result = $this->db->query($sql);

            // Assert
            $this->assertNotFalse($result, 'the prepared query was rejected by the database');
            $this->assertSame(0, (int) ($result->fields['total'] ?? -1));
        } finally {
            $this->db->query('DROP TABLE IF EXISTS ' . $quote . $table . $quote);
        }
    }

    // ── What must not change ──────────────────────────────────────────────────

    /**
     * `%%` still means a literal percent.
     *
     * The reason the guard is a `str_replace` and not a bare `return $query`. `vsprintf` collapsed
     * `%%` to `%`, and the method documents that as its way of writing a literal percent — so
     * callers have been relying on it for years. Returning the query untouched would turn
     * `DATE_FORMAT(f, '%%c')` into SQL that reads a literal `%` followed by `c` instead of the month
     * number, which is a wrong answer rather than an error.
     */
    public function testDoublePercentStillCollapses(): void
    {
        // Act
        $sql = (string) $this->db->prepareQuery(
            "SELECT DATE_FORMAT(`created`, '%%c') FROM `#PREFIX#users`"
        );

        // Assert
        $this->assertStringContainsString("'%c'", $sql, 'a literal percent stopped collapsing');
        $this->assertStringNotContainsString("'%%c'", $sql);
    }

    /**
     * A query *with* arguments substitutes exactly as before.
     *
     * The guard only changes the argument-less path, and this is what says so — the whole value of
     * the method is the substitution, and a fix that skipped it would be far worse than the crash.
     */
    public function testArgumentsStillSubstitute(): void
    {
        // Act
        $sql = (string) $this->db->prepareQuery(
            "SELECT * FROM `#PREFIX#users` WHERE `username` = %s AND `userid` = %d",
            "o'brien",
            42
        );

        // Assert
        $this->assertStringContainsString('42', $sql);
        $this->assertStringContainsString('brien', $sql);
        $this->assertStringNotContainsString('%s', $sql, 'the placeholder was not substituted');
        $this->assertStringNotContainsString('%d', $sql);

        // And the quote is escaped rather than ending the literal.
        $this->assertStringNotContainsString("'o'brien'", $sql, 'the value broke out of its quotes');
    }

    /**
     * A `LIKE` pattern *and* an argument in the same query both work.
     *
     * The case the reported failures were actually made of: one argument and a `LIKE '%…%'` beside
     * it. Here `vsprintf` does run, so the literal percents have to be tolerated by the formatting
     * step rather than avoided — which they are, because `%A` and `%'` only break when there is no
     * argument left to consume.
     */
    public function testALikePatternAlongsideAnArgument(): void
    {
        // Act
        $sql = (string) $this->db->prepareQuery(
            "SELECT * FROM `#PREFIX#users` WHERE `userid` = %d AND `username` LIKE 'a%%'",
            7
        );

        // Assert
        $this->assertStringContainsString('7', $sql);
        $this->assertStringContainsString("LIKE 'a%'", $sql);
    }

    /** A null argument still becomes SQL `null` rather than an empty string. */
    public function testANullArgumentStillBecomesSqlNull(): void
    {
        // Act
        $sql = (string) $this->db->prepareQuery(
            "SELECT * FROM `#PREFIX#users` WHERE `photo` = %d",
            null
        );

        // Assert
        $this->assertMatchesRegularExpression('/IS\s+NULL/i', $sql, 'a null comparison was not rewritten');
    }
}
