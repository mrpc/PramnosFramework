<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\User;

use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\DatabaseTestCase;
use Pramnos\User\Token;

/**
 * `Token::updateAction()` repairing a `tokenactions` table older than the columns it writes.
 *
 * Twenty-three statements, and every one of them issues **DDL against the live database** — `ALTER
 * TABLE`, `CREATE INDEX`, and on PostgreSQL a function and a trigger. None had ever executed.
 *
 * That combination is the reason this is worth an integration test rather than a note. The code runs
 * only on an installation whose `tokenactions` predates `return_status`, `execution_time_ms`,
 * `return_data` and `action_time` — so it runs *once*, on somebody's production database, on the first
 * API call after an upgrade, inside a `catch`. There is no second chance to get it right and nobody is
 * watching when it happens.
 *
 * ## The fixture is hand-written DDL, deliberately
 *
 * Everywhere else in this suite the rule is to build framework tables from their migrations. Here the
 * point is a table the shipped migration would never produce: the *old* shape, without the four
 * columns. Building it from `CreateTokenactionsTable` would produce a table that already has them, and
 * the `catch` this file exists for would never be entered.
 *
 * ## The two lanes are doing different work
 *
 * MySQL adds four columns and two indexes. PostgreSQL does that and then converts `action_time` into a
 * real time dimension: back-fills it from the legacy `servertime` integer, installs a trigger function
 * that keeps the two in sync in both directions, and only then makes the column `NOT NULL`. That order
 * is load-bearing — `NOT NULL` before the back-fill fails on every existing row — and it is the kind of
 * thing that is correct in the author's head and wrong in the file.
 */
class TokenActionsSelfHealTest extends DatabaseTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'type'     => 'mysql',
            'server'   => 'db',
            'user'     => 'root',
            'password' => 'secret',
            'database' => 'pramnos_test',
            'port'     => 3306,
            /*
             * A prefix of this class's own, so the repair works on `heal_tokenactions`.
             *
             * Not decoration. `Token::updateAction()` names `#PREFIX#tokenactions`, so without this
             * the class owns the *real* audit table — drops it at teardown, and every later class that
             * needs it pays to rebuild it. Measured before the prefix: these eight tests take 0.97s
             * alone and added **23 seconds** to the suite.
             */
            'prefix'   => 'heal_',
        ];
    }

    /** @return string[] */
    protected static function ownedTables(): array
    {
        return ['heal_tokenactions'];
    }

    /**
     * The table as it was before the four columns existed.
     *
     * @return string[]
     */
    protected static function schemaStatements(): array
    {
        $isPostgres = (static::connectionConfig()['type'] ?? '') === 'postgresql';

        return [
            $isPostgres
                ? 'CREATE TABLE heal_tokenactions (
                    actionid SERIAL PRIMARY KEY,
                    tokenid INTEGER NOT NULL,
                    urlid INTEGER NOT NULL,
                    method VARCHAR(6) NOT NULL,
                    params TEXT NULL,
                    servertime INTEGER NOT NULL DEFAULT 0
                  )'
                : 'CREATE TABLE heal_tokenactions (
                    actionid INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    tokenid INT NOT NULL,
                    urlid INT NOT NULL,
                    method VARCHAR(6) NOT NULL,
                    params TEXT NULL,
                    servertime INT NOT NULL DEFAULT 0
                  ) ENGINE=InnoDB',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // `Token` reaches for the Factory singleton, not for `$this->db`, so the lane's connection has
        // to *be* the singleton or the DDL lands in whichever engine the settings file names — which
        // would make the second lane a copy of the first.
        $singleton = &Factory::getDatabase();
        $singleton = $this->db;
    }

    protected function tearDown(): void
    {
        $singleton = &Factory::getDatabase();
        $singleton = null;

        parent::tearDown();
    }

    /**
     * The function outlives the table, so it is dropped once — after the class, not after each test.
     *
     * Dropping it per test was this file's own first bug and worth recording: `CASCADE` takes the
     * trigger with it, so the first test repaired the table and every test after it ran against a
     * repaired table with no trigger. The time assertions then measured the column's `DEFAULT
     * CURRENT_TIMESTAMP` and read as «the back-fill lost the row's time» — a failure pointing at the
     * code under test, caused by the fixture.
     */
    public static function tearDownAfterClass(): void
    {
        if ((static::connectionConfig()['type'] ?? '') === 'postgresql') {
            $db = static::openConnection();

            try {
                $db->query('DROP FUNCTION IF EXISTS heal_sync_tokenactions_time() CASCADE');
            } catch (\Throwable) {
                // Never installed.
            }

            $db->close();
        }

        parent::tearDownAfterClass();
    }

    /** A row in the old shape, and its id. */
    private function legacyRow(int $serverTime): int
    {
        $this->db->query(
            "INSERT INTO heal_tokenactions (tokenid, urlid, method, params, servertime) "
            . "VALUES (1, 1, 'GET', 'a=b', " . $serverTime . ")"
        );

        $row = $this->db->query('SELECT MAX(actionid) AS id FROM heal_tokenactions');

        return (int) $row->fields['id'];
    }

    /**
     * Which columns the table has *now*, lower-cased.
     *
     * `$fresh = true`, and this file's second bug was leaving it out. `getColumns()` caches for an
     * hour in a shared store, so reading the list before the repair and again after it returned the
     * same answer both times — and the test reported «the column was not added» about a column that
     * had been. Its own docblock names this case: pass `$fresh` when a stale answer would be wrong
     * rather than merely slow.
     */
    private function columnsNow(): array
    {
        $names = [];
        $result = $this->db->getColumns('heal_tokenactions', null, false, true);

        while ($result && $result->fetch()) {
            $names[] = strtolower((string) ($result->fields['Field'] ?? ''));
        }

        return $names;
    }

    private function token(): Token
    {
        return new Token([
            'tokenid'   => 1,
            'userid'    => 1,
            'tokentype' => 'auth',
            'status'    => 1,
        ]);
    }

    /**
     * The four columns are added and the row is written, on the first call that needed them.
     *
     * The row is the assertion, not the columns. An `ALTER` that ran and a retry that did not would
     * leave a repaired table and a lost audit record — and the caller cannot tell, because
     * `updateAction()` returns nothing either way. Every API call between the upgrade and somebody
     * noticing would be recorded with no status and no duration.
     */
    public function testTheMissingColumnsAreAddedAndTheRowIsWritten(): void
    {
        // Arrange
        $actionId = $this->legacyRow(1756000000);

        $before = $this->columnsNow();
        $this->assertNotContains('return_status', $before, 'the fixture is not the old shape');

        // Act
        $this->token()->updateAction($actionId, 200, 12.5, ['ok' => true]);

        // Assert — repaired
        $after = $this->columnsNow();
        foreach (['return_status', 'execution_time_ms', 'return_data', 'action_time'] as $column) {
            $this->assertContains($column, $after, $column . ' was not added');
        }

        // …and the record that prompted the repair is in it
        $row = $this->db->query(
            'SELECT return_status, execution_time_ms, return_data FROM heal_tokenactions '
            . 'WHERE actionid = ' . $actionId
        );
        $this->assertSame(1, $row->numRows);
        $this->assertSame(200, (int) $row->fields['return_status']);
        $this->assertSame(12.5, (float) $row->fields['execution_time_ms']);
        $this->assertStringContainsString('"ok"', (string) $row->fields['return_data']);
    }

    /**
     * A second call, on the now-repaired table, does no DDL and still writes.
     *
     * The `catch` is only entered when the `UPDATE` fails, so this is what establishes that the repair
     * actually repaired: if the columns had been added in a way the `UPDATE` still cannot see — a
     * different schema, a name that got quoted — the second call would fall into the `catch` again and
     * re-run every `ALTER` on every API request for ever.
     */
    public function testASecondCallOnTheRepairedTableJustWrites(): void
    {
        // Arrange — the first call does the repair
        $first = $this->legacyRow(1756000000);
        $this->token()->updateAction($first, 200, 1.0, null);

        $second = $this->legacyRow(1756000100);

        // Act
        $this->token()->updateAction($second, 404, 7.25, null);

        // Assert
        $row = $this->db->query(
            'SELECT return_status FROM heal_tokenactions WHERE actionid = ' . $second
        );
        $this->assertSame(404, (int) $row->fields['return_status']);
    }

    /**
     * On PostgreSQL the legacy `servertime` is carried into `action_time` before it becomes `NOT NULL`.
     *
     * The order is the whole risk in that branch. `SET NOT NULL` on a column every existing row has as
     * `NULL` fails, and it fails *after* three `ALTER`s have already gone through — so on a table with
     * history the repair would half-apply and then throw from inside a `catch`, on somebody's
     * production database, during an API call.
     *
     * The value is asserted rather than merely the absence of `NULL`: a back-fill that wrote
     * `CURRENT_TIMESTAMP` instead of the row's own `servertime` would satisfy `NOT NULL` and lose the
     * time every audit row is about.
     */
    public function testTheLegacyTimestampIsCarriedOverBeforeTheColumnIsRequired(): void
    {
        if (($this->db->type ?? '') !== 'postgresql') {
            $this->markTestSkipped('The trigger and the back-fill are the PostgreSQL branch.');
        }

        // Arrange
        $serverTime = 1756000000;
        $actionId = $this->legacyRow($serverTime);

        // Act
        $this->token()->updateAction($actionId, 200, 1.0, null);

        // Assert — back-filled from the row's own servertime, not from now
        $row = $this->db->query(
            'SELECT EXTRACT(EPOCH FROM action_time)::INTEGER AS epoch FROM heal_tokenactions '
            . 'WHERE actionid = ' . $actionId
        );
        $this->assertSame($serverTime, (int) $row->fields['epoch'], 'the audit row lost its time');

        // …and the column is required now, which is what the back-fill had to precede
        $nullable = $this->db->query(
            "SELECT is_nullable FROM information_schema.columns "
            . "WHERE table_name = 'heal_tokenactions' AND column_name = 'action_time'"
        );
        $this->assertSame('NO', (string) $nullable->fields['is_nullable']);
    }

    /**
     * And the trigger keeps the two representations in step afterwards.
     *
     * Both directions, because the function has a branch for each: a row inserted with a `servertime`
     * gets that instant as its `action_time`, and a row inserted without one gets `CURRENT_TIMESTAMP`
     * *and* has `servertime` filled in from it. The second direction is what stops the legacy integer
     * column going stale on every write made by code that has been updated — which would leave two
     * columns that disagree about when a request happened, with nothing saying which to believe.
     */
    public function testTheTriggerKeepsTheTwoTimeColumnsInStep(): void
    {
        if (($this->db->type ?? '') !== 'postgresql') {
            $this->markTestSkipped('The trigger is the PostgreSQL branch.');
        }

        // Arrange — one call to install the repair
        $this->token()->updateAction($this->legacyRow(1756000000), 200, 1.0, null);

        // Act — a row with a servertime and nothing else
        $withTime = $this->legacyRow(1756000500);

        // …and one with no servertime at all
        $this->db->query(
            "INSERT INTO heal_tokenactions (tokenid, urlid, method, params) VALUES (1, 1, 'GET', 'x=1')"
        );
        $withoutTime = (int) $this->db->query('SELECT MAX(actionid) AS id FROM heal_tokenactions')
            ->fields['id'];

        // Assert — servertime drove action_time
        $row = $this->db->query(
            'SELECT EXTRACT(EPOCH FROM action_time)::INTEGER AS epoch FROM heal_tokenactions '
            . 'WHERE actionid = ' . $withTime
        );
        $this->assertSame(1756000500, (int) $row->fields['epoch']);

        // …and action_time drove servertime
        $back = $this->db->query(
            'SELECT servertime, EXTRACT(EPOCH FROM action_time)::INTEGER AS epoch '
            . 'FROM heal_tokenactions WHERE actionid = ' . $withoutTime
        );
        $this->assertGreaterThan(0, (int) $back->fields['servertime'], 'the legacy column went stale');
        $this->assertSame(
            (int) $back->fields['epoch'],
            (int) $back->fields['servertime'],
            'the two columns disagree about when the request happened'
        );
    }
}
