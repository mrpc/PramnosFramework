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
}
