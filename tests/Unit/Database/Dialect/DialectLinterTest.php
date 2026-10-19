<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database\Dialect;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DatabaseDialectLint;
use Pramnos\Database\Dialect\DialectLinter;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The SQL that would run on PostgreSQL and mean something else than on MySQL, named by file and
 * line (FW-160, phase 2) — what the translator leaves alone on purpose.
 */
#[CoversClass(DialectLinter::class)]
#[CoversClass(DatabaseDialectLint::class)]
class DialectLinterTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*/*.php') ?: []);
            array_map('rmdir', glob($this->dir . '/*', GLOB_ONLYDIR) ?: []);
            @rmdir($this->dir);
        }
    }

    /** The rules a piece of SQL triggers, in order. */
    private static function rules(string $sql): array
    {
        return array_column(DialectLinter::sql($sql), 'rule');
    }

    /**
     * Each rule, where it applies and only there: a LIKE (not ILIKE); a column selected but not
     * grouped — bare or qualified, aliased or not — and not an aggregated one, a grouped one, a
     * literal or a star; a double-quoted value, and not a double-quoted name.
     */
    public function testWhatIsReportedAndWhatIsNot(): void
    {
        // Act + Assert
        $this->assertSame(['like'], self::rules("SELECT * FROM users WHERE name LIKE '%maria%'"));
        $this->assertSame([], self::rules("SELECT * FROM users WHERE name ILIKE '%maria%'"));
        $this->assertSame(['group-by'], self::rules('SELECT u.city, u.name AS who, COUNT(*) FROM users u GROUP BY u.city'));
        $this->assertSame([], self::rules('SELECT city, COUNT(*) AS n, MAX(seen) FROM users GROUP BY city ORDER BY n'));
        $this->assertSame([], self::rules('SELECT u.city, 1, NULL, COUNT(*) FROM users u GROUP BY city'), 'a.city grouped as city; literals are no columns');
        $this->assertSame([], self::rules('SELECT * FROM t'), 'no GROUP BY, nothing to judge');
        $this->assertSame(['group-by'], self::rules('SELECT x FROM (SELECT a, b FROM t GROUP BY a) s'), 'b, in the subquery');
        $this->assertSame(['double-quoted-string'], self::rules('SELECT * FROM t WHERE status = "active"'));
        $this->assertSame(['double-quoted-string', 'double-quoted-string'], self::rules('SELECT * FROM t WHERE kind IN ("a", "b")'));
        $this->assertSame([], self::rules('SELECT "name" FROM "users" WHERE "id" = 1'), 'names, double-quoted as PostgreSQL wants');
    }

    /**
     * In a PHP file: the SQL in its string literals, each finding at the line it is on — inside a
     * multi-line string too — a line marked `dialect-ok` left out, and strings that are not SQL
     * not read: a sentence, an operator's name alone, markup holding the word "Delete".
     */
    public function testAFileIsReadByLine(): void
    {
        // Arrange
        $this->dir = sys_get_temp_dir() . '/dialect' . bin2hex(random_bytes(3));
        mkdir($this->dir . '/src', 0777, true);
        file_put_contents($this->dir . '/src/Users.php', <<<'PHP'
<?php
$a = 'not sql, LIKE a sentence';
$b = "SELECT * FROM users
      WHERE name LIKE '%" . $q . "%'";
$c = 'SELECT city, name FROM users GROUP BY city';
$d = "SELECT * FROM t WHERE x LIKE 'y'"; // dialect-ok
$e = ['=', 'LIKE', 'NOT LIKE'];
$f = '<a data-confirm="Delete this task?" href="/x">';
PHP);

        // Act
        $findings = DialectLinter::file($this->dir . '/src/Users.php');

        // Assert
        $this->assertSame([[4, 'like'], [5, 'group-by']], array_map(static fn (array $f): array => [$f['line'], $f['rule']], $findings),
            'line 2 is a sentence; line 6 has been looked at; line 7 holds operator names; line 8 is markup');
    }

    /**
     * The command walks the paths, names each finding as file:line, counts them, and exits 1;
     * with `--json` it says the same as data; a clean path exits 0.
     */
    public function testTheCommand(): void
    {
        // Arrange
        $this->dir = sys_get_temp_dir() . '/dialect' . bin2hex(random_bytes(3));
        mkdir($this->dir . '/src', 0777, true);
        mkdir($this->dir . '/clean', 0777, true);
        file_put_contents($this->dir . '/src/Q.php', "<?php\n\$q = \"SELECT * FROM t WHERE a LIKE 'x' AND b = \\\"y\\\"\";\n");
        file_put_contents($this->dir . '/clean/Ok.php', "<?php\n\$q = 'SELECT a FROM t';\n");
        $console = new ConsoleApplication();
        $console->add(new DatabaseDialectLint());
        $tester = new CommandTester($console->find('db:dialect-lint'));

        // Act
        $found = $tester->execute(['paths' => [$this->dir . '/src']]);
        $text  = $tester->getDisplay();
        $json  = $tester->execute(['paths' => [$this->dir . '/src/Q.php'], '--json' => true]);
        $data  = json_decode($tester->getDisplay(), true);
        $clean = $tester->execute(['paths' => [$this->dir . '/clean']]);

        // Assert
        $this->assertSame(1, $found);
        $this->assertStringContainsString('Q.php:2  like', $text);
        $this->assertStringContainsString('2 finding(s): 1 like, 1 double-quoted-string', $text);
        $this->assertSame(1, $json);
        $this->assertSame(['like', 'double-quoted-string'], array_column($data, 'rule'));
        $this->assertSame(0, $clean);
        $this->assertStringContainsString('Nothing that would mean something else', $tester->getDisplay());
    }

    /**
     * An ORDER BY PostgreSQL refuses (FW-165 §9): in a grouped SELECT, a column neither grouped
     * nor aggregated — but an alias of the select list, or an aggregate, is fine; in a
     * `SELECT DISTINCT`, a column it does not select — unless it is selected under an alias, or
     * the list holds a star.
     */
    public function testWhatAnOrderByMayName(): void
    {
        // Act + Assert
        $this->assertSame(['group-by'], self::rules('SELECT city, COUNT(*) AS n FROM users GROUP BY city ORDER BY name'));
        $this->assertSame([], self::rules('SELECT city, COUNT(*) AS n FROM users GROUP BY city ORDER BY n DESC, city, MAX(seen)'));
        $this->assertSame(['group-by'], self::rules('SELECT DISTINCT (u.userid) FROM users u ORDER BY `subscriptionstart` DESC'));
        $this->assertSame([], self::rules('SELECT DISTINCT u.userid, u.lastlogin AS seen FROM users u ORDER BY seen DESC, u.userid'));
        $this->assertSame([], self::rules('SELECT DISTINCT u.* FROM users u ORDER BY u.name'), 'a star selects what it orders by');
        $this->assertSame([], self::rules('SELECT u.*, COUNT(*) FROM users u GROUP BY u.city'), 'a star is not judged');
        $this->assertSame(['group-by'], self::rules('SELECT u.name who, COUNT(*) FROM users u GROUP BY u.city'), 'an alias without AS');
        $this->assertSame([], self::rules('SELECT a FROM t UNION SELECT b FROM u ORDER BY c'), 'an ORDER BY of a UNION is the whole union\'s');
    }

    /**
     * A grouped key makes its table's columns grouped, as PostgreSQL has it. Without the schema,
     * a grouped column whose name ends in `id` is taken for its table's key: that table's
     * columns, and the unqualified ones, are not reported — another table's are. With the
     * schema, the primary keys decide.
     */
    public function testAGroupedKeyGroupsItsTable(): void
    {
        // Arrange
        $sql    = 'SELECT i.invoiceid, i.total, p.billingcycle, note FROM invoices i JOIN packages p ON p.packageid = i.packageid GROUP BY i.invoiceid';
        $schema = ['columns' => ['invoices' => ['invoiceid' => 'number', 'total' => 'number', 'note' => 'text', 'packageid' => 'number'], 'packages' => ['packageid' => 'number', 'billingcycle' => 'number']],
            'keys' => ['invoices' => ['invoiceid'], 'packages' => ['packageid']]];

        // Act
        $guessed = array_column(DialectLinter::sql($sql), 'message');
        $known   = array_column(DialectLinter::sql($sql, $schema), 'message');
        $notKey  = array_column(DialectLinter::sql($sql, ['columns' => $schema['columns'], 'keys' => ['invoices' => ['invoiceid', 'total']]]), 'message');

        // Assert
        $this->assertCount(1, $guessed);
        $this->assertStringStartsWith('p.billingcycle is selected', $guessed[0], 'another table\'s column');
        $this->assertCount(1, $known);
        $this->assertStringStartsWith('p.billingcycle is selected', $known[0]);
        $this->assertCount(3, $notKey, 'a key not wholly grouped groups nothing: total, billingcycle and note');
    }

    /**
     * With the schema, a text column compared with a number is named — a number, or a column the
     * schema says is one, qualified by an alias or not, with any comparison operator — and
     * nothing else is: text with text, a number with a number, a date with a number, a column the
     * schema does not know, one two tables of the statement give different types.
     */
    public function testATextColumnComparedWithANumber(): void
    {
        // Arrange
        $schema = ['columns' => [
            'users'    => ['userid' => 'number', 'subscription' => 'text', 'name' => 'text', 'born' => 'other', 'code' => 'text'],
            'packages' => ['packageid' => 'number', 'code' => 'number'],
        ], 'keys' => []];
        $rules = static fn (string $sql): array => array_column(DialectLinter::sql($sql, $schema), 'rule');

        // Act + Assert
        $this->assertSame(['implicit-cast'], $rules('SELECT * FROM `#PREFIX#users` u JOIN packages p ON u.subscription = p.packageid'));
        $this->assertSame(['implicit-cast', 'implicit-cast', 'implicit-cast'], $rules('SELECT * FROM users WHERE subscription = 3 OR name != 4 OR 5 <= name'));
        $this->assertSame([], $rules("SELECT * FROM users u JOIN packages p ON p.packageid = u.userid WHERE u.name = 'x' AND u.userid = 3 AND u.born > 5 AND u.missing = 1 AND code = 1"),
            'code is text in one table and a number in the other: not told');
        $this->assertSame([], array_column(DialectLinter::sql('SELECT * FROM users WHERE subscription = 3'), 'rule'), 'no schema, no judgement');
        $message = DialectLinter::sql('SELECT * FROM users u JOIN packages p ON u.subscription = p.packageid', $schema)[0]['message'];
        $this->assertStringContainsString('u.subscription is text, compared with p.packageid, a number', $message);
    }

    /**
     * The SQL is read as it will run (FW-165 §9): a string with variables in it, strings joined
     * with `.`, and a variable built up with `.=` — across an `if` — are each one statement, a
     * variable standing in as `?`; each finding is at the line its part is on. A function's
     * variable is not the next function's, and a heredoc is read too.
     */
    public function testSqlAssembledOverSeveralLinesIsOneStatement(): void
    {
        // Arrange
        $this->dir = sys_get_temp_dir() . '/dialect' . bin2hex(random_bytes(3));
        mkdir($this->dir . '/src', 0777, true);
        file_put_contents($this->dir . '/src/Built.php', <<<'PHP'
<?php
function interpolated($pillar) {
    return "SELECT c.category, locationid, SUM(n)
        FROM cached WHERE pillar = $pillar AND x = {$this->y['z']}
        GROUP BY category";
}
function joined($ids) {
    return $db->prepare("select a.city, a.name "
        . " from users a where a.id in (" . implode(',', $ids) . ") "
        . " group by a.city", 3);
}
function appended($type) {
    $this->sql = 'SELECT u.city, u.name FROM users u';
    if ($type) {
        $this->sql .= ' WHERE u.type = ' . (int) $type;
    }
    $this->sql .= ' GROUP BY u.city';
    $other = "SELECT 1";
}
function next() {
    $sql .= ' GROUP BY nothing';
}
function heredoc($x) {
    return <<<SQL
        SELECT city, name FROM users WHERE n = $x
        GROUP BY city
        SQL;
}
PHP);

        // Act
        $findings = DialectLinter::file($this->dir . '/src/Built.php');

        // Assert
        $this->assertSame([[3, 'group-by'], [8, 'group-by'], [13, 'group-by'], [25, 'group-by']],
            array_map(static fn (array $f): array => [$f['line'], $f['rule']], $findings),
            'locationid; a.name; u.name; name — each on the line it is selected on; next() has no SELECT');
    }

    /**
     * Prose is not SQL, whatever it says: a prompt joined over many lines that says "like" and
     * holds quoted words is not read. A statement is still read when it has one sentence in it,
     * and a fragment with none is read as before.
     */
    public function testProseIsNotSql(): void
    {
        // Arrange
        $this->dir = sys_get_temp_dir() . '/dialect' . bin2hex(random_bytes(3));
        mkdir($this->dir . '/src', 0777, true);
        file_put_contents($this->dir . '/src/Prompt.php', <<<'PHP'
<?php
$prompt = 'Expressions like "every day", "daily" mean full time. '
    . 'Do not use technical terms like "minibio". Use "short bio" or '
    . '"description". Write "verified" or "not verified".';
$one = "SELECT * FROM t WHERE note = 'Done. Next' AND name LIKE 'x%'";
$fragment = ' AND a.status = "active"';
PHP);

        // Act
        $findings = DialectLinter::file($this->dir . '/src/Prompt.php');

        // Assert
        $this->assertSame([[5, 'like'], [6, 'double-quoted-string']], array_map(static fn (array $f): array => [$f['line'], $f['rule']], $findings));
    }

    /**
     * The edges of reading SQL out of PHP: a variable assigned again is a new statement, the old
     * one judged on its own; a string assigned to an array element or passed to a call is a
     * statement of its own; a finding in a later part of a chain is at that part's line; a table
     * aliased with `AS` is known by its alias; and a file that does not parse is read as far as
     * it goes, without an error.
     */
    public function testTheEdgesOfReadingPhp(): void
    {
        // Arrange
        $this->dir = sys_get_temp_dir() . '/dialect' . bin2hex(random_bytes(3));
        mkdir($this->dir . '/src', 0777, true);
        file_put_contents($this->dir . '/src/Edges.php', <<<'PHP'
<?php
$sql = 'SELECT city, name FROM users GROUP BY city';
$sql = 'SELECT a FROM t WHERE b LIKE ?';
$list['q'] = "SELECT * FROM t WHERE c LIKE 'x'";
run(strtoupper("SELECT * FROM t WHERE d " . $op) . " LIKE 'y'");
$q = "SELECT a "
    . ", b FROM t GROUP BY a";
PHP);
        file_put_contents($this->dir . '/src/Broken.php', "<?php\n\$a = 'SELECT * FROM t WHERE a LIKE \\'x\\'' .\n");
        file_put_contents($this->dir . '/src/Open.php', "<?php\n\$a = \"SELECT * FROM t WHERE a LIKE 'x'\n");
        $schema = ['columns' => ['users' => ['code' => 'text']], 'keys' => []];

        // Act
        $edges  = array_map(static fn (array $f): array => [$f['line'], $f['rule']], DialectLinter::file($this->dir . '/src/Edges.php'));
        $broken = array_column(DialectLinter::file($this->dir . '/src/Broken.php'), 'rule');
        $open   = array_column(DialectLinter::file($this->dir . '/src/Open.php'), 'rule');
        $alias  = array_column(DialectLinter::sql('SELECT * FROM users AS x WHERE x.code = 1 AND y =', $schema), 'rule');

        // Assert
        $this->assertSame([[2, 'group-by'], [3, 'like'], [4, 'like'], [5, 'like'], [7, 'group-by']], $edges);
        $this->assertSame(['like'], $broken);
        $this->assertSame(['like'], $open);
        $this->assertSame(['implicit-cast'], $alias, 'x is users; a comparison with nothing after it is not one');
    }
}

