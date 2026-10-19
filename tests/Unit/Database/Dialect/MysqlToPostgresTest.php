<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database\Dialect;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Dialect\DialectException;
use Pramnos\Database\Dialect\MysqlToPostgres;

/**
 * MySQL's SQL, translated where it means one thing in both dialects (FW-160, phase 1).
 *
 * Each case is a statement as an application wrote it for MySQL and the statement PostgreSQL is
 * given. The same output fed back in comes out unchanged — a statement may pass through the layer
 * twice — and what is inside a string literal or a comment is never touched.
 */
#[CoversClass(MysqlToPostgres::class)]
#[CoversClass(DialectException::class)]
class MysqlToPostgresTest extends TestCase
{
    /** The translator, with the unique keys of a few tables. */
    private static function translator(): MysqlToPostgres
    {
        return new MysqlToPostgres(static fn (string $table): array => match ($table) {
            'counters'      => [['id']],
            'pf_counters'   => [['id']],
            'daily'         => [['day', 'device']],
            'accounts'      => [['id'], ['email']],
            default         => [],
        }, static fn (string $table): array => match ($table) {
            'counters', 'pf_counters' => ['id', 'n', 'label'],
            'daily'                   => ['day', 'device', 'total'],
            default                   => [],
        });
    }

    /** @return array<string, array{string, string}> */
    public static function translations(): array
    {
        return [
            'backticks'             => ['SELECT `id`, `name` FROM `users`', 'SELECT "id", "name" FROM "users"'],
            'IFNULL'                => ['SELECT IFNULL(a, 0) FROM t', 'SELECT COALESCE(a, 0) FROM t'],
            'nested'                => ['SELECT IFNULL(IFNULL(a, b), 0)', 'SELECT COALESCE(COALESCE(a, b), 0)'],
            'RAND'                  => ['SELECT * FROM t ORDER BY RAND() LIMIT 1', 'SELECT * FROM t ORDER BY random() LIMIT 1'],
            'LIMIT a, b'            => ['SELECT * FROM t LIMIT 20, 10', 'SELECT * FROM t LIMIT 10 OFFSET 20'],
            'LIMIT placeholders'    => ['SELECT * FROM t LIMIT %d, %d', 'SELECT * FROM t LIMIT %d OFFSET %d'],
            'LIMIT in a subquery'   => ['SELECT * FROM (SELECT id FROM t LIMIT 5,5) x', 'SELECT * FROM (SELECT id FROM t LIMIT 5 OFFSET 5) x'],
            'UNIX_TIMESTAMP()'      => ['SELECT UNIX_TIMESTAMP()', 'SELECT EXTRACT(EPOCH FROM NOW())::bigint'],
            'UNIX_TIMESTAMP(x)'     => ['SELECT UNIX_TIMESTAMP(created) FROM t', 'SELECT EXTRACT(EPOCH FROM CAST((created) AS timestamptz))::bigint FROM t'],
            'FROM_UNIXTIME'         => ['SELECT FROM_UNIXTIME(at) FROM t', 'SELECT to_timestamp(at) FROM t'],
            'FROM_UNIXTIME, format' => ["SELECT FROM_UNIXTIME(at, '%Y-%m-%d') FROM t", "SELECT to_char(to_timestamp(at), 'YYYY\"-\"MM\"-\"DD') FROM t"],
            'CURDATE, CURTIME'      => ['SELECT CURDATE(), CURTIME()', 'SELECT CURRENT_DATE, LOCALTIME'],
            'DATE_FORMAT'           => ["SELECT DATE_FORMAT(d, '%d/%m/%Y %H:%i') FROM t", "SELECT to_char(d, 'DD\"/\"MM\"/\"YYYY\" \"HH24\":\"MI') FROM t"],
            'GROUP_CONCAT'          => ['SELECT GROUP_CONCAT(name) FROM t', "SELECT string_agg((name)::text, ',') FROM t"],
            'GROUP_CONCAT, all'     => ["SELECT GROUP_CONCAT(DISTINCT name ORDER BY name DESC SEPARATOR '; ') FROM t", "SELECT string_agg(DISTINCT (name)::text, '; ' ORDER BY name DESC) FROM t"],
            'INSERT IGNORE'         => ["INSERT IGNORE INTO t (a) VALUES (1)", 'INSERT INTO t (a) VALUES (1) ON CONFLICT DO NOTHING'],
            'INSERT IGNORE ;'       => ["INSERT IGNORE INTO t (a) VALUES (1);", 'INSERT INTO t (a) VALUES (1) ON CONFLICT DO NOTHING;'],
            'ON DUPLICATE KEY'      => ['INSERT INTO counters (id, n) VALUES (1, 1) ON DUPLICATE KEY UPDATE n = n + VALUES(n)',
                                        'INSERT INTO counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO UPDATE SET n = counters.n + EXCLUDED.n'],
            'the row already there' => ["INSERT INTO counters (id, n) VALUES (1, 1) ON DUPLICATE KEY UPDATE n = IF(n > 5, n, n + 1), label = 'x'",
                                        "INSERT INTO counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO UPDATE SET n = CASE WHEN counters.n > 5 THEN counters.n ELSE counters.n + 1 END, label = 'x'"],
            'IF, ISNULL'            => ["SELECT IF(ISNULL(a), 'none', LCASE(a)) FROM t", "SELECT CASE WHEN ((a) IS NULL) THEN 'none' ELSE lower(a) END FROM t"],
            'UCASE'                 => ['SELECT UCASE(name) FROM t', 'SELECT upper(name) FROM t'],
            'DATE_ADD, DATE_SUB'    => ['SELECT DATE_SUB(NOW(), INTERVAL 7 DAY), DATE_ADD(d, INTERVAL %d MONTH)', "SELECT (NOW() - (7) * INTERVAL '1 day'), (d + (%d) * INTERVAL '1 month')"],
            'IF EXISTS untouched'   => ['DROP TABLE IF EXISTS t', 'DROP TABLE IF EXISTS t'],
            'block comment, quotes' => ["SELECT /* IFNULL(x, y) */ IFNULL(a, 'it''s') FROM t", "SELECT /* IFNULL(x, y) */ COALESCE(a, 'it''s') FROM t"],
            'INSERT IGNORE … RETURNING' => ['INSERT IGNORE INTO t (a) VALUES (1) RETURNING id', 'INSERT INTO t (a) VALUES (1) ON CONFLICT DO NOTHING RETURNING id'],
            'INSERT IGNORE … SELECT … ON' => ['INSERT IGNORE INTO t (a) SELECT x.a FROM x JOIN y ON x.id = y.id', 'INSERT INTO t (a) SELECT x.a FROM x JOIN y ON x.id = y.id ON CONFLICT DO NOTHING'],
            'REPLACE, only the key' => ['REPLACE INTO counters (id) VALUES (1)', 'INSERT INTO counters (id) VALUES (1) ON CONFLICT (id) DO NOTHING'],
            'IGNORE and DUPLICATE'  => ['INSERT IGNORE INTO counters (id, n) VALUES (1, 1) ON DUPLICATE KEY UPDATE n = 2', 'INSERT INTO counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO UPDATE SET n = 2'],
            'a literal %'           => ["SELECT DATE_FORMAT(d, '%d%%') FROM t", "SELECT to_char(d, 'DD\"%\"') FROM t"],
            'a format not a literal' => ['SELECT DATE_FORMAT(d, fmt), FROM_UNIXTIME(a, fmt) FROM t', 'SELECT DATE_FORMAT(d, fmt), FROM_UNIXTIME(a, fmt) FROM t'],
            'an interval unit unknown' => ['SELECT DATE_ADD(d, INTERVAL 1 FORTNIGHT), DATE_ADD(d, 7)', 'SELECT DATE_ADD(d, INTERVAL 1 FORTNIGHT), DATE_ADD(d, 7)'],
            'shapes not guessed at' => ["SELECT GROUP_CONCAT(a, b), GROUP_CONCAT(a SEPARATOR sep), UNIX_TIMESTAMP(a, b), RAND(7)", "SELECT GROUP_CONCAT(a, b), GROUP_CONCAT(a SEPARATOR sep), UNIX_TIMESTAMP(a, b), RAND(7)"],
            'a statement in parentheses' => ['(SELECT IFNULL(a, 1) FROM t)', '(SELECT COALESCE(a, 1) FROM t)'],
            'an UPDATE'             => ['UPDATE t SET a = IFNULL(b, 0)', 'UPDATE t SET a = COALESCE(b, 0)'],
            'ON DUPLICATE, quoted'  => ['INSERT INTO `daily` (`day`, `device`, `total`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `total` = VALUES(`total`)',
                                        'INSERT INTO "daily" ("day", "device", "total") VALUES (?, ?, ?) ON CONFLICT (day, device) DO UPDATE SET "total" = EXCLUDED."total"'],
            'REPLACE INTO'          => ["REPLACE INTO counters (id, n, label) VALUES (1, 2, 'x')",
                                        "INSERT INTO counters (id, n, label) VALUES (1, 2, 'x') ON CONFLICT (id) DO UPDATE SET n = EXCLUDED.n, label = EXCLUDED.label"],
            'a prefixed table'      => ['INSERT INTO pf_counters (id, n) VALUES (1, 1) ON DUPLICATE KEY UPDATE n = VALUES(n)',
                                        'INSERT INTO pf_counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO UPDATE SET n = EXCLUDED.n'],
            'a string untouched'    => ["SELECT 'IFNULL(a, b) LIMIT 1, 2 `x`' AS s, IFNULL(x, 'it\\'s')", "SELECT 'IFNULL(a, b) LIMIT 1, 2 `x`' AS s, COALESCE(x, 'it\\'s')"],
            'a comment untouched'   => ["SELECT a -- IFNULL(b, c)\nFROM `t`", "SELECT a -- IFNULL(b, c)\nFROM \"t\""],
            'PostgreSQL already'    => ['SELECT COALESCE(a, 0) FROM "t" LIMIT 10 OFFSET 20', 'SELECT COALESCE(a, 0) FROM "t" LIMIT 10 OFFSET 20'],
            'replace() the function'=> ["SELECT replace(name, 'a', 'b') FROM t", "SELECT replace(name, 'a', 'b') FROM t"],
            // FW-165 §7: code that adds to an upsert already translated, or written in PostgreSQL's.
            'added to after translating' => ['INSERT INTO counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO UPDATE SET label = EXCLUDED.label, n = n + 1',
                                        'INSERT INTO counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO UPDATE SET label = EXCLUDED.label, n = counters.n + 1'],
            'added to, quoted'      => ['INSERT INTO "counters" ("id", "n") VALUES (1, 1) ON CONFLICT ("id") DO UPDATE SET "n" = "n" + 1 WHERE "n" < 10',
                                        'INSERT INTO "counters" ("id", "n") VALUES (1, 1) ON CONFLICT ("id") DO UPDATE SET "n" = counters."n" + 1 WHERE counters."n" < 10'],
            'already qualified'     => ['INSERT INTO counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO UPDATE SET n = counters.n + EXCLUDED.n',
                                        'INSERT INTO counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO UPDATE SET n = counters.n + EXCLUDED.n'],
            'DO NOTHING untouched'  => ['INSERT INTO counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO NOTHING',
                                        'INSERT INTO counters (id, n) VALUES (1, 1) ON CONFLICT (id) DO NOTHING'],
            // FW-165 §8
            'SHA2'                  => ['SELECT SHA2(email, 256), SHA2(x, 0) FROM t', "SELECT encode(sha256(convert_to((email)::text, 'UTF8')), 'hex'), encode(sha256(convert_to((x)::text, 'UTF8')), 'hex') FROM t"],
            'SHA2, other lengths'   => ['SELECT SHA2(x, 512), SHA2(x, 100)', "SELECT encode(sha512(convert_to((x)::text, 'UTF8')), 'hex'), SHA2(x, 100)"],
            'CAST AS SIGNED'        => ["SELECT CAST(a AS SIGNED), CAST(b AS UNSIGNED INTEGER), CAST(c AS CHAR) FROM t", 'SELECT CAST(a AS bigint), CAST(b AS bigint), CAST(c AS CHAR) FROM t'],
            'DAY, MONTH, YEAR'      => ['SELECT DAY(d), MONTH(d), YEAR(NOW()) FROM t WHERE d > DATE_SUB(NOW(), INTERVAL 1 DAY)',
                                        "SELECT EXTRACT(DAY FROM d)::int, EXTRACT(MONTH FROM d)::int, EXTRACT(YEAR FROM NOW())::int FROM t WHERE d > (NOW() - (1) * INTERVAL '1 day')"],
            'GET_LOCK, RELEASE_LOCK'=> ["SELECT GET_LOCK('job', 10), RELEASE_LOCK('job')",
                                        "SELECT (CASE WHEN pg_try_advisory_lock(hashtext('job')) THEN 1 ELSE 0 END), (CASE WHEN pg_advisory_unlock(hashtext('job')) THEN 1 ELSE 0 END)"],
            'JSON_EXTRACT'          => ['SELECT JSON_EXTRACT(doc, \'$.a.b[0]\'), JSON_UNQUOTE(JSON_EXTRACT(doc, \'$."x y"\')) FROM t',
                                        'SELECT ((doc)::jsonb #> \'{a,b,0}\'), ((((doc)::jsonb #> \'{"x y"}\'))::jsonb #>> \'{}\') FROM t'],
            'JSON_EXTRACT, a wildcard' => ['SELECT JSON_EXTRACT(doc, \'$.a[*]\'), JSON_EXTRACT(doc, p) FROM t', 'SELECT JSON_EXTRACT(doc, \'$.a[*]\'), JSON_EXTRACT(doc, p) FROM t'],
            'DELETE through a join' => ['DELETE a FROM `orders` a JOIN users u ON u.id = a.user INNER JOIN shops s ON s.id = a.shop WHERE u.gone = 1;',
                                        'DELETE FROM "orders" a USING users u, shops s WHERE (u.id = a.user) AND (s.id = a.shop) AND (u.gone = 1);'],
            'DELETE by name'        => ['DELETE orders FROM orders JOIN users ON users.id = orders.user',
                                        'DELETE FROM orders USING users WHERE (users.id = orders.user)'],
            'DELETE not translated' => ['DELETE a FROM orders a LEFT JOIN users u ON u.id = a.user WHERE u.id IS NULL', 'DELETE a FROM orders a LEFT JOIN users u ON u.id = a.user WHERE u.id IS NULL'],
            'DELETE of the joined'  => ['DELETE u FROM orders a JOIN users u ON u.id = a.user', 'DELETE u FROM orders a JOIN users u ON u.id = a.user'],
            'a plain DELETE'        => ['DELETE FROM t WHERE id = 1', 'DELETE FROM t WHERE id = 1'],
            'alias in HAVING'       => ['SELECT id, (lat - 1) * 2 AS distance, COUNT(*) AS `n` FROM p GROUP BY id HAVING distance < 25 AND n > 1 ORDER BY distance',
                                        'SELECT id, (lat - 1) * 2 AS distance, COUNT(*) AS "n" FROM p GROUP BY id HAVING ((lat - 1) * 2) < 25 AND (COUNT(*)) > 1 ORDER BY distance'],
            'HAVING in a subquery'  => ['SELECT * FROM (SELECT k, COUNT(*) AS c FROM t GROUP BY k HAVING c > 1) x', 'SELECT * FROM (SELECT k, COUNT(*) AS c FROM t GROUP BY k HAVING (COUNT(*)) > 1) x'],
            'HAVING, its own name'  => ['SELECT SUM(n) AS n FROM t GROUP BY k HAVING n > 1', 'SELECT SUM(n) AS n FROM t GROUP BY k HAVING n > 1'],
            'FOREIGN_KEY_CHECKS'    => ['SET FOREIGN_KEY_CHECKS = 0;', "SET session_replication_role = 'replica';"],
            'FOREIGN_KEY_CHECKS=1'  => ['set @@session.foreign_key_checks=1', "SET session_replication_role = 'origin'"],
            'a pattern to_char lacks' => ["SELECT DATE_FORMAT(d, '%U') FROM t", "SELECT DATE_FORMAT(d, '%U') FROM t"],
        ];
    }

    /**
     * Each statement comes out as PostgreSQL takes it; fed back in, it comes out the same.
     */
    #[DataProvider('translations')]
    public function testItTranslates(string $mysql, string $postgres): void
    {
        // Arrange
        $translator = self::translator();

        // Act
        $once  = $translator->translate($mysql);
        $twice = $translator->translate($once);

        // Assert
        $this->assertSame($postgres, $once);
        $this->assertSame($once, $twice, 'a second pass changes nothing');
    }

    /**
     * An upsert whose conflict target the table does not decide is refused, naming what to
     * write: two unique keys among the columns, or none.
     */
    /**
     * Written for printf — `prepareQuery()`'s statements — `%%` is a percent: a `DATE_FORMAT`
     * pattern `'%%Y-%%m'` is MySQL's `'%Y-%m'`, and the `to_char` pattern that comes out keeps any
     * literal percent doubled, so printf gives it back. The same text translated plainly is
     * remembered apart.
     */
    public function testAStatementWrittenForPrintfKeepsItsPercents(): void
    {
        // Arrange
        $translator = self::translator();
        $sql        = "SELECT DATE_FORMAT(d, '%%Y-%%m %%%%') FROM t WHERE e = '%s'";

        // Act
        $escaped = $translator->translatePercentEscaped($sql);
        $plain   = $translator->translate($sql);

        // Assert
        $this->assertSame("SELECT to_char(d, 'YYYY\"-\"MM\" %%\"') FROM t WHERE e = '%s'", $escaped);
        $this->assertSame("SELECT to_char(d, 'YYYY\"-\"MM\" %\"') FROM t WHERE e = 'x'", sprintf($escaped, 'x'), 'printf gives back the pattern');
        $this->assertNotSame($escaped, $plain, 'the plain reading was taken from the escaped one\'s memory');
    }

    public function testAnUpsertItCannotDecideIsRefused(): void
    {
        // Arrange
        $translator = self::translator();
        $attempts   = [
            'two keys' => 'INSERT INTO accounts (id, email, name) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)',
            'no key'   => 'REPLACE INTO logs (msg) VALUES (?)',
        ];

        foreach ($attempts as $case => $sql) {
            try {
                // Act
                $translator->translate($sql);
                $this->fail($case . ': translated on a guess');
            } catch (DialectException $e) {
                // Assert
                $this->assertStringContainsString('ON CONFLICT (…)', $e->getMessage(), $case);
            }
        }
    }

    /**
     * Translations are remembered by statement, and the memory is bounded: past a thousand it
     * starts again rather than growing with every distinct statement an application sends.
     */
    public function testTheMemoryOfTranslationsIsBounded(): void
    {
        // Arrange
        $translator = self::translator();
        $cache      = new \ReflectionProperty($translator, 'cache');

        // Act
        for ($i = 0; $i <= 1000; $i++) {
            $translator->translate('SELECT IFNULL(a, ' . $i . ')');
        }

        // Assert
        $this->assertLessThanOrEqual(1000, count($cache->getValue($translator)));
        $this->assertSame('SELECT COALESCE(a, 1000)', $translator->translate('SELECT IFNULL(a, 1000)'));
    }
}
