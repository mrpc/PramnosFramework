<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Database\QueryException;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * A failed query fails the same way on every backend — under `throwOnError`.
 *
 * The asymmetry is not the framework's invention and it is not small: **mysqli has thrown by
 * default since PHP 8.1**, while `pg_*` answers `false`. Measured on the same builder call
 * against a table that does not exist:
 *
 * | | MySQL | PostgreSQL, lenient |
 * | --- | --- | --- |
 * | `get()` | throws | `false` |
 * | `first()` | throws | `false` |
 * | `count()` | throws | `0` |
 *
 * So this, which is the shape most of `src/` is written in, is complete on one engine and half
 * the handling on the other:
 *
 * ```php
 * try {
 *     $result = $qb->…->get();
 * } catch (\Throwable $e) {
 *     return [];                                  // never reached on PostgreSQL
 * }
 * while (($row = $result->fetch()) !== null) {    // fatal: fetch() on false
 * ```
 *
 * `Database::$throwOnError` closes it, and the framework's own test fixtures set it — so this
 * suite fails where an installation would merely be wrong. The runtime default stays lenient on
 * purpose: turning it on globally would convert every silently-empty answer in every existing
 * application into an exception, which is a BC break dressed up as a fix.
 *
 * Enabling it in this suite found two tests that had been passing against a **missing accounts
 * table** — asserting a refusal that was happening because the query failed, not because the
 * password was wrong.
 */
#[CoversClass(Database::class)]
class QueryFailureParityTest extends BaseTestCase
{
    private $db;

    private const ABSENT = '#PREFIX#no_such_table_for_parity';

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

    /**
     * The fixtures turn strict mode on, and that is what makes the suite honest.
     *
     * Asserted rather than assumed: if a fixture loses the key, every test below still passes on
     * MySQL — mysqli throws either way — and stops proving anything at all on PostgreSQL.
     */
    public function testTheTestFixtureEnablesStrictMode(): void
    {
        // Assert
        $this->assertTrue(
            $this->db->throwOnError,
            'the test fixture no longer enables throwOnError, so a PostgreSQL failure is silent '
            . 'again and this whole class asserts nothing on that backend'
        );
    }

    /**
     * `get()` on a table that is not there raises, rather than answering something falsy.
     *
     * The answer a caller must not be given: a value that reads as "no rows". An empty result is
     * indistinguishable from "there is no data yet", which is what every `catch`-and-return-[]
     * in the framework is written to report — and it is right to report it, once the difference
     * is knowable.
     */
    public function testGetOnAMissingTableRaises(): void
    {
        // Assert
        $this->expectException(\Throwable::class);

        $this->db->queryBuilder()->table(self::ABSENT)->select(['a'])->where('a', 1)->get();
    }

    /** And `first()`, which is the one whose `false` produces "property on false" two files away. */
    public function testFirstOnAMissingTableRaises(): void
    {
        // Assert
        $this->expectException(\Throwable::class);

        $this->db->queryBuilder()->table(self::ABSENT)->select(['a'])->first();
    }

    /**
     * And `count()`, whose lenient answer is the worst of the three.
     *
     * `false` casts to `0`, and a zero count is a number a screen will happily print. "You have
     * no messages" is a sentence; a missing table is not.
     */
    public function testCountOnAMissingTableRaises(): void
    {
        // Assert
        $this->expectException(\Throwable::class);

        $this->db->queryBuilder()->table(self::ABSENT)->count();
    }

    /**
     * The message names the relation, on both backends.
     *
     * Whatever the driver, the thing a reader needs is which table was missing — that is the
     * difference between a two-minute fix and an afternoon.
     */
    public function testTheMessageNamesTheTable(): void
    {
        // Act
        $message = '';
        try {
            $this->db->queryBuilder()->table(self::ABSENT)->select(['a'])->first();
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
        }

        // Assert
        $this->assertNotSame('', $message);
        $this->assertStringContainsString('no_such_table_for_parity', $message);
    }

    /**
     * A query that succeeds is unaffected — strict mode is about failures only.
     *
     * Worth pinning: a flag that made ordinary reads throw would be found immediately, but one
     * that quietly changed an empty result into an exception would not.
     */
    public function testAnEmptyResultIsStillAnEmptyResultAndNotAnError(): void
    {
        // Arrange
        \Pramnos\User\User::setupDb();

        // Act
        $result = $this->db->queryBuilder()->table('#PREFIX#users')
            ->select(['userid'])
            ->where('userid', -424242)
            ->get();

        // Assert
        $this->assertNotFalse($result, 'a table with no matching row is not a failure');
        $this->assertSame(0, (int) ($result->numRows ?? -1));
    }

    /**
     * With strict mode off, PostgreSQL goes back to answering falsy — and MySQL still throws.
     *
     * The asymmetry itself, asserted, because it is the reason the fixtures set the flag. If a
     * future PHP or driver release changes this, the suite should say so here rather than through
     * a screen crashing.
     */
    public function testWithoutStrictModeTheBackendsDisagree(): void
    {
        // Arrange
        $previous = $this->db->throwOnError;
        $this->db->throwOnError = false;

        // Act
        $threw  = false;
        $answer = null;
        try {
            $answer = $this->db->queryBuilder()->table(self::ABSENT)->select(['a'])->first();
        } catch (\Throwable $exception) {
            $threw = true;
        } finally {
            $this->db->throwOnError = $previous;
        }

        // Assert
        if ($this->db->type === 'postgresql') {
            $this->assertFalse($threw, 'PostgreSQL threw without strict mode — parity arrived on its own');
            $this->assertFalse($answer, 'and the lenient answer is falsy, which is the whole problem');
        } else {
            $this->assertTrue(
                $threw,
                'mysqli stopped throwing by default — the asymmetry this flag exists for is gone, '
                . 'and the guide needs rewriting rather than the code'
            );
        }
    }
}
