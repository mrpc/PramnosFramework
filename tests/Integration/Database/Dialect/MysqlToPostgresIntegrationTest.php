<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database\Dialect;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Dialect\DialectException;
use Pramnos\Database\Dialect\MysqlToPostgres;

/**
 * SQL written for MySQL, run on PostgreSQL with `translate_mysql` on (FW-160, phase 1).
 *
 * Every way an application reaches the database: `query()`, `execute()` with placeholders,
 * `prepareQuery()` then `query()`, and the query builder with a raw WHERE — the path the admin
 * DataTables take with their hand-written conditions. Each statement is checked by what it did
 * in the database, not by the text it became: rows inserted once, counted up, replaced; values
 * computed as MySQL computes them. And with the setting off, the same statement is refused, so
 * it is the translation doing it.
 */
#[CoversClass(MysqlToPostgres::class)]
#[CoversClass(Database::class)]
final class MysqlToPostgresIntegrationTest extends TestCase
{
    private ?Database $db = null;

    private string $t = '';

    protected function setUp(): void
    {
        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = 'timescaledb';
        $db->port     = 5432;
        $db->user     = 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped('PostgreSQL not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('PostgreSQL not reachable: ' . $e->getMessage());
        }
        $db->translateMysql = true;
        $this->db = $db;
        $this->t  = 'dlx' . bin2hex(random_bytes(3));
        foreach ([
            "CREATE TABLE {$this->t}_counters (id INT PRIMARY KEY, n INT NOT NULL, label TEXT)",
            "CREATE TABLE {$this->t}_events (id INT PRIMARY KEY, kind TEXT, at BIGINT, made TIMESTAMP)",
            "INSERT INTO {$this->t}_events VALUES (1, 'a', 1768478400, '2026-01-15 12:00:00'), (2, 'b', 1768564800, '2026-01-16 12:00:00'), (3, NULL, NULL, NULL)",
        ] as $sql) {
            pg_query($db->getConnectionLink(), $sql);
        }
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            pg_query($this->db->getConnectionLink(), "DROP TABLE IF EXISTS {$this->t}_counters, {$this->t}_events");
            $this->db->close();
        }
    }

    /** One value from a statement run through query(). */
    private function value(string $sql): mixed
    {
        $result = $this->db->query($sql);
        $result->fetch();

        return array_values($result->fields)[0] ?? null;
    }

    /**
     * Writes: `INSERT IGNORE` twice leaves one row; `ON DUPLICATE KEY UPDATE` counts up through
     * `execute()` with placeholders; `REPLACE INTO` through prepareQuery() replaces the row.
     */
    public function testWritesDoWhatTheyDidOnMysql(): void
    {
        // Arrange
        $c = $this->t . '_counters';

        // Act
        $this->db->query("INSERT IGNORE INTO `{$c}` (`id`, `n`) VALUES (1, 1)");
        $this->db->query("INSERT IGNORE INTO `{$c}` (`id`, `n`) VALUES (1, 99)");
        $this->db->execute("INSERT INTO {$c} (id, n) VALUES (%i, %i) ON DUPLICATE KEY UPDATE n = n + VALUES(n)", ...[2, 5]);
        $this->db->execute("INSERT INTO {$c} (id, n) VALUES (%i, %i) ON DUPLICATE KEY UPDATE n = n + VALUES(n)", ...[2, 5]);
        $this->db->query($this->db->prepareQuery("REPLACE INTO {$c} (id, n, label) VALUES (%d, %d, %s)", 1, 7, "it's"));
        $rows = pg_fetch_all(pg_query($this->db->getConnectionLink(), "SELECT id, n, label FROM {$c} ORDER BY id")) ?: [];

        // Assert
        $this->assertSame([['id' => '1', 'n' => '7', 'label' => "it's"], ['id' => '2', 'n' => '10', 'label' => null]], $rows,
            'ignored once, counted up, replaced');
    }

    /**
     * Code that adds to an upsert after prepareQuery() has translated it — `, n = n + 1` on the
     * end of what is by then `ON CONFLICT … DO UPDATE SET` — ran into "column reference is
     * ambiguous" on every call (FW-165 §7). The statement is read again when it runs, and the
     * bare name is the row already there, as MySQL took it.
     */
    public function testAnUpsertAddedToAfterItWasTranslatedRuns(): void
    {
        // Arrange
        $c   = $this->t . '_counters';
        $sql = $this->db->prepareQuery("INSERT INTO {$c} (id, n, label) VALUES (%d, %d, %s) ON DUPLICATE KEY UPDATE label = VALUES(label)", 3, 1, 'seen');

        // Act — appended after the translation, as the application does
        $this->db->query($sql . ', n = n + 1');
        $this->db->query($sql . ', n = n + 1');
        $this->db->query($sql . ', "n" = "n" + 1');
        $row = pg_fetch_assoc(pg_query($this->db->getConnectionLink(), "SELECT n, label FROM {$c} WHERE id = 3")) ?: [];

        // Assert — inserted once, counted up twice
        $this->assertStringContainsString('ON CONFLICT', $sql, 'translated before it was added to');
        $this->assertSame(['n' => '3', 'label' => 'seen'], $row);
    }

    /**
     * The translations FW-165 §8 measured missing run, and give what MySQL gives: a hash as the
     * same hex, a cast to a number, the parts of a date, a named lock taken and released, a value
     * out of a JSON document, a delete through a join, an alias in HAVING, and foreign-key checks
     * switched off and on.
     */
    public function testTheTranslationsMeasuredMissingRun(): void
    {
        // Arrange
        $e = $this->t . '_events';
        $c = $this->t . '_counters';
        pg_query($this->db->getConnectionLink(), "INSERT INTO {$c} VALUES (1, 1, 'a'), (2, 1, 'zzz')");

        // Act
        $hash   = $this->value("SELECT SHA2('abc', 256)");
        $cast   = $this->value("SELECT CAST('42' AS SIGNED) + 1");
        $parts  = $this->value("SELECT CONCAT(DAY(made), '/', MONTH(made), '/', YEAR(made)) FROM {$e} WHERE id = 1");
        $locked = $this->value("SELECT GET_LOCK('pf-dialect-test', 0)");
        $freed  = $this->value("SELECT RELEASE_LOCK('pf-dialect-test')");
        $json   = $this->value("SELECT JSON_EXTRACT('{\"a\": {\"b\": [7, 8]}}', '$.a.b[1]')");
        $text   = $this->value("SELECT JSON_UNQUOTE(JSON_EXTRACT('{\"s\": \"x\"}', '$.s'))");
        $kinds  = $this->value("SELECT COUNT(*) FROM (SELECT kind, COUNT(*) AS c FROM {$e} GROUP BY kind HAVING c >= 1) x");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $role   = $this->value('SHOW session_replication_role');
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");
        $this->db->query("DELETE ev FROM `{$e}` ev JOIN {$c} k ON k.label = ev.kind WHERE k.n = 1");
        $left   = array_column(pg_fetch_all(pg_query($this->db->getConnectionLink(), "SELECT id FROM {$e} ORDER BY id")) ?: [], 'id');

        // Assert
        $this->assertSame(hash('sha256', 'abc'), $hash);
        $this->assertSame(43, (int) $cast);
        $this->assertSame('15/1/2026', $parts);
        $this->assertSame([1, 1], [(int) $locked, (int) $freed], 'taken, then released');
        $this->assertSame('8', (string) $json);
        $this->assertSame('x', $text, 'unquoted');
        $this->assertSame(3, (int) $kinds, 'a, b and the NULL kind, each once');
        $this->assertSame('replica', $role, 'foreign keys off for the session');
        $this->assertSame(['2', '3'], $left, 'the event of kind a, joined to counter a, deleted');
    }

    /** Reads compute what MySQL computed. */
    public function testReadsComputeWhatMysqlComputed(): void
    {
        // Arrange
        $e = $this->t . '_events';

        // Act + Assert
        $this->assertSame('a,b,-', $this->value("SELECT GROUP_CONCAT(IFNULL(kind, '-') ORDER BY id SEPARATOR ',') FROM {$e}"));
        $this->assertSame('2026-01-15', $this->value("SELECT DATE_FORMAT(FROM_UNIXTIME(at), '%Y-%m-%d') FROM {$e} WHERE id = 1"));
        $this->assertSame('b', $this->value("SELECT kind FROM {$e} ORDER BY id LIMIT 1, 1"));
        $this->assertSame(1, (int) $this->value("SELECT COUNT(*) FROM {$e} WHERE made < FROM_UNIXTIME(UNIX_TIMESTAMP())  AND id = 1"));
        $this->assertTrue((int) $this->value('SELECT UNIX_TIMESTAMP()') > 1760000000);
    }

    /**
     * `prepareQuery()` with a `DATE_FORMAT` written for printf — `'%%Y-%%m'` — and a value to put
     * in: the pattern reaches `to_char` as `YYYY-MM` and the value its place. It threw "Unknown
     * format specifier" because the pattern was translated as MySQL would read it.
     */
    public function testPrepareQueryTranslatesAPatternWrittenForPrintf(): void
    {
        // Arrange
        $e = $this->t . '_events';

        // Act
        $sql   = $this->db->prepareQuery("SELECT DATE_FORMAT(`made`, '%%Y-%%m') AS f FROM `{$e}` WHERE `kind` = %s", 'a');
        $value = $this->value($sql);

        // Assert
        $this->assertStringContainsString("'a'", $sql);
        $this->assertSame('2026-01', $value);
    }

    /**
     * The query builder's raw fragments — how the admin DataTables pass their WHERE — are
     * translated too.
     */
    public function testTheBuildersRawFragmentsAreTranslated(): void
    {
        // Act
        $rows = $this->db->queryBuilder()->table($this->t . '_events')->select(['id'])->whereRaw("IFNULL(kind, '') <> ''")->orderBy('id')->get();
        $ids  = [];
        while ($rows->fetch()) {
            $ids[] = (int) $rows->fields['id'];
        }

        // Assert
        $this->assertSame([1, 2], $ids);
    }

    /**
     * An upsert the table cannot decide is refused with what to write instead; with the setting
     * off, MySQL's syntax reaches PostgreSQL as it is and is refused there.
     */
    public function testWhatItRefusesAndWhatItDoesNotTouch(): void
    {
        // Arrange
        $e = $this->t . '_events';

        // Act
        try {
            $this->db->query("REPLACE INTO {$e} (kind) VALUES ('x')");
            $refused = null;
        } catch (DialectException $ex) {
            $refused = $ex->getMessage();
        }
        $this->db->translateMysql = false;
        try {
            $untranslated = @$this->db->query("SELECT IFNULL(kind, 'x') FROM {$e}");
        } catch (\Throwable) {
            $untranslated = false;
        }

        // Assert
        $this->assertStringContainsString('no unique key of the table is among the columns', (string) $refused);
        $this->assertFalse($untranslated, 'off, the statement is PostgreSQL\'s to refuse');
    }
}
