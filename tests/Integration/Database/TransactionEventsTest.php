<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Event\ChangeFeed;
use Pramnos\Event\Event;

/**
 * Integration tests for the transaction events Database fires, against both engines.
 *
 * `commitTransaction()` and `rollbackTransaction()` announce themselves so listeners can
 * release or drop work they were holding. The whole change feed hangs off these two
 * names, and a rename or a missed firing is silent everywhere else: the feed would simply
 * stop delivering, with nothing logged and no test failing.
 *
 * These run against the real engines rather than a mock because the firing is placed
 * relative to the actual COMMIT/ROLLBACK statement, and the ordering — the flag cleared,
 * the statement run, then the event — is what a listener calling inTransaction() depends
 * on.
 *
 * Requires the Docker containers (PostgreSQL/TimescaleDB on `timescaledb`, MySQL on `db`).
 */
class TransactionEventsTest extends TestCase
{
    /** @var list<string> Every transaction event seen during the current test. */
    private array $seen = [];

    protected function setUp(): void
    {
        // Arrange — a clean bus, and a recorder on both names.
        Event::forget();
        ChangeFeed::reset();

        $this->seen = [];
        Event::listen(ChangeFeed::EVENT_COMMITTED, function (): void {
            $this->seen[] = 'committed';
        });
        Event::listen(ChangeFeed::EVENT_ROLLED_BACK, function (): void {
            $this->seen[] = 'rolledback';
        });
    }

    protected function tearDown(): void
    {
        Event::forget();
        ChangeFeed::reset();
    }

    /**
     * Connect to one of the two engines the framework supports.
     */
    private function connect(string $type): Database
    {
        $db = new Database();

        if ($type === 'mysql') {
            $db->type     = 'mysql';
            $db->server   = 'db';
            $db->user     = 'root';
            $db->password = 'secret';
            $db->database = 'pramnos_test';
            $db->port     = 3306;
        } else {
            $db->type     = 'postgresql';
            $db->server   = 'timescaledb';
            $db->user     = 'postgres';
            $db->password = 'secret';
            $db->database = 'pramnos_test';
            $db->port     = 5432;
            $db->schema   = 'public';
        }

        $db->connect(true);

        return $db;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        return [
            'PostgreSQL' => ['postgresql'],
            'MySQL'      => ['mysql'],
        ];
    }

    /**
     * A committed transaction fires the committed event, and only that one.
     *
     * This is the release signal: a listener holding work until the rows are durable acts
     * on it. Asserting the rollback event did *not* fire matters as much — a listener
     * that both flushes and discards on the same commit would deliver nothing.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('engines')]
    public function testCommitFiresTheCommittedEvent(string $engine): void
    {
        // Arrange
        $db = $this->connect($engine);
        $db->startTransaction();

        // Act
        $ok = $db->commitTransaction();

        // Assert
        $this->assertTrue($ok);
        $this->assertSame(['committed'], $this->seen);
    }

    /**
     * A rolled-back transaction fires the rolledback event, and only that one.
     *
     * The invariant the change feed's buffer exists for: work held during a transaction
     * that is undone must be dropped, not delivered.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('engines')]
    public function testRollbackFiresTheRolledBackEvent(string $engine): void
    {
        // Arrange
        $db = $this->connect($engine);
        $db->startTransaction();

        // Act
        $ok = $db->rollbackTransaction();

        // Assert
        $this->assertTrue($ok);
        $this->assertSame(['rolledback'], $this->seen);
    }

    /**
     * By the time a listener runs, the transaction is already closed.
     *
     * A listener released on commit typically goes on to do work of its own — a write, a
     * publish. If `inTransaction()` still answered true at that moment, a change feed
     * listener re-entering the feed would buffer into a transaction that has ended and
     * hold its work until the next commit, which may never come.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('engines')]
    public function testTheTransactionIsClosedBeforeListenersRun(string $engine): void
    {
        // Arrange
        $db = $this->connect($engine);
        $stillOpen = null;
        Event::listen(ChangeFeed::EVENT_COMMITTED, function () use ($db, &$stillOpen): void {
            $stillOpen = $db->inTransaction();
        });
        $db->startTransaction();

        // Act
        $db->commitTransaction();

        // Assert — the listener observed a closed transaction, not the one it came from
        $this->assertFalse($stillOpen);
    }

    /**
     * Rows written inside a committed transaction are visible, and the event fired.
     *
     * Ties the announcement to the durability it claims: an event that fired while the
     * rows were still absent would release listeners onto data that is not there.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('engines')]
    public function testCommittedRowsArePresentWhenTheEventFires(string $engine): void
    {
        // Arrange
        $db    = $this->connect($engine);
        $table = 'pramnos_txevents_' . ($engine === 'mysql' ? 'my' : 'pg');
        $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE ' . $table . ' (id INTEGER)');

        $rowsAtFiring = null;
        Event::listen(ChangeFeed::EVENT_COMMITTED, function () use ($db, $table, &$rowsAtFiring): void {
            $result       = $db->query('SELECT COUNT(*) AS cnt FROM ' . $table);
            $rowsAtFiring = (int) ($result->fields['cnt'] ?? -1);
        });

        // Act
        $db->startTransaction();
        $db->query('INSERT INTO ' . $table . ' (id) VALUES (1)');
        $db->commitTransaction();

        // Assert
        $this->assertSame(['committed'], $this->seen);
        $this->assertSame(1, $rowsAtFiring, 'the row must be durable before listeners run');

        // Cleanup
        $db->query('DROP TABLE IF EXISTS ' . $table);
    }

    /**
     * Rows written inside a rolled-back transaction are gone, and the rollback fired.
     *
     * The mirror of the test above, and the one that proves the feed's discard path is
     * attached to a real undo rather than to an optimistic assumption.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('engines')]
    public function testRolledBackRowsAreAbsentWhenTheEventFires(string $engine): void
    {
        // Arrange
        $db    = $this->connect($engine);
        $table = 'pramnos_txevents_rb_' . ($engine === 'mysql' ? 'my' : 'pg');
        $db->query('DROP TABLE IF EXISTS ' . $table);
        // InnoDB and PostgreSQL both roll back DML; the table itself is created outside
        // the transaction because MySQL commits DDL implicitly and would end it early.
        $db->query('CREATE TABLE ' . $table . ' (id INTEGER) ' . ($engine === 'mysql' ? 'ENGINE=InnoDB' : ''));

        // Act
        $db->startTransaction();
        $db->query('INSERT INTO ' . $table . ' (id) VALUES (1)');
        $db->rollbackTransaction();

        // Assert
        $this->assertSame(['rolledback'], $this->seen);
        $result = $db->query('SELECT COUNT(*) AS cnt FROM ' . $table);
        $this->assertSame(0, (int) ($result->fields['cnt'] ?? -1));

        // Cleanup
        $db->query('DROP TABLE IF EXISTS ' . $table);
    }
}
