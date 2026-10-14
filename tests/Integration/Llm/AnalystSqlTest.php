<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Llm\Analyst\Analyst;
use Pramnos\Llm\Analyst\Exports;
use Pramnos\Llm\Analyst\ReadOnlySql;
use Pramnos\Llm\Analyst\Tools\ExportCsvTool;
use Pramnos\Llm\Analyst\Tools\SchemaTool;
use Pramnos\Llm\Analyst\Tools\SqlTool;
use Pramnos\Tests\Support\ReusesProbeDatabase;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The analyst's own SQL — `sql`, `export_csv` and `schema` — on MySQL and PostgreSQL: a read
 * runs, a write does not even where the lexer lets it through, a slow statement is stopped, and
 * an export is a file only the administrator who asked can download.
 */
#[CoversClass(ReadOnlySql::class)]
#[CoversClass(SqlTool::class)]
#[CoversClass(ExportCsvTool::class)]
#[CoversClass(SchemaTool::class)]
#[CoversClass(Exports::class)]
#[CoversClass(Analyst::class)]
#[CoversClass(\Pramnos\Database\ReadOnlyConnection::class)]
#[CoversClass(\Pramnos\Application\Controllers\AiController::class)]
class AnalystSqlTest extends LlmTestCase
{
    use ReusesProbeDatabase;

    private const PROBE = 'pramnos_analyst_sql_probe';

    private Database $db;

    private ?Database $previous = null;

    private string $exports = '';

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    private static function connection(string $type, string $host, int $port, string $user, string $name): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = $name;
        if ($type === 'postgresql') {
            $db->schema = 'public';
        }

        return $db;
    }

    /** A database with the users table and three people in it, as the application's own. */
    private function boot(string $type, string $host, int $port, string $user): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $admin = self::connection($type, $host, $port, $user, TEST_DATABASE);
        try {
            if (!$admin->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        $this->db = $this->openProbe($admin, self::PROBE, static fn (string $name): Database => self::connection($type, $host, $port, $user, $name), [
            \Pramnos\Framework\Migrations\Auth\CreateUsersTable::class,
        ]);
        $admin->close();

        $this->db->queryBuilder()->table('users')->where('userid', '>', 1)->delete();
        foreach ([[7, 'maria', 'Γλάρου'], [8, 'nikos', 'Glaros'], [9, 'eleni', 'Glaros']] as [$id, $name, $last]) {
            $this->db->queryBuilder()->table('users')->insert(['userid' => $id, 'username' => $name, 'email' => $name . '@example.com', 'lastname' => $last, 'password' => 'x', 'usertype' => 1, 'active' => 1]);
        }

        $this->previous = Factory::getDatabase();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;

        $this->exports = sys_get_temp_dir() . '/pf-exports-' . bin2hex(random_bytes(4));
        Exports::useDirectory($this->exports);
    }

    protected function tearDown(): void
    {
        if ($this->previous !== null) {
            $singleton = &Factory::getDatabase();
            $singleton = $this->previous;
        }
        // Only the directory this test made: an empty name would be the root's files.
        if (str_starts_with($this->exports, sys_get_temp_dir() . '/pf-exports-') && is_dir($this->exports)) {
            foreach (glob($this->exports . '/*.csv') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->exports);
        }
        Exports::useDirectory(null);
        parent::tearDown();
    }

    /**
     * What the lexer refuses before anything reaches the database: writes anywhere in the
     * statement, a second statement, files, locks and functions that wait.
     */
    public function testWhatIsRefusedBeforeItRuns(): void
    {
        // Arrange
        $refused = [
            'UPDATE users SET usertype = 99',
            'WITH gone AS (DELETE FROM users RETURNING *) SELECT count(*) FROM gone',
            'SELECT 1; DROP TABLE users',
            'SELECT * FROM users INTO OUTFILE \'/tmp/x\'',
            'SELECT * FROM users FOR UPDATE',
            'SELECT * FROM users FOR SHARE',
            'SELECT * FROM users LOCK IN SHARE MODE',
            'SELECT SLEEP(10)',
            'SELECT BENCHMARK(1000000, md5(1))',
            'SELECT GET_LOCK(\'a\', 10)',
            'SELECT LOAD_FILE(\'/etc/passwd\')',
            'SELECT pg_sleep(10)',
            'SELECT pg_read_file(\'/etc/passwd\')',
        ];

        // Act / Assert
        foreach ($refused as $sql) {
            $this->assertNotNull(ReadOnlySql::refusal($sql), 'let through: ' . $sql);
        }
        // A word inside a string is a value, not a call
        $this->assertNull(ReadOnlySql::refusal("SELECT username FROM users WHERE lastname = 'sleep(1)'"));
    }

    /**
     * A read answers rows; a statement without a limit is given one; more rows than asked are
     * said to be more; and the database's refusal reaches the model in the database's words.
     */
    #[DataProvider('databases')]
    public function testARead(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $tool = new SqlTool();

        // Act
        $glaros   = $tool->execute(['sql' => "SELECT COUNT(*) AS n FROM users WHERE lastname = 'Glaros'"]);
        $two      = $tool->execute(['sql' => 'SELECT username FROM users WHERE userid > 1 ORDER BY userid', 'limit' => 2]);
        $missing  = $tool->execute(['sql' => 'SELECT nothing FROM users']);
        $writing  = $tool->execute(['sql' => 'DELETE FROM users']);

        // Assert
        $this->assertSame(2, (int) $glaros['rows'][0]['n'], 'looking people up is a query like any other');
        $this->assertSame(['maria', 'nikos'], array_column($two['rows'], 'username'));
        $this->assertTrue($two['truncated'], 'a third row was there');
        $this->assertStringStartsWith('The database refused it:', $missing);
        $this->assertStringStartsWith('Refused:', $writing);
        $this->assertSame(3, $this->db->queryBuilder()->table('users')->count(), 'nothing was deleted');
    }

    /**
     * What the lexer cannot see, the transaction refuses: `nextval()` reads like a function and
     * writes a sequence. PostgreSQL only — MySQL has no read that writes past the lexer.
     */
    public function testTheTransactionRefusesAWriteTheLexerMissed(): void
    {
        // Arrange
        $this->boot('postgresql', 'timescaledb', 5432, 'postgres');
        $this->db->query('CREATE SEQUENCE IF NOT EXISTS probe_seq');

        // Act
        $answer = (new SqlTool())->execute(['sql' => "SELECT nextval('probe_seq')"]);

        // Assert
        $this->assertStringContainsString('read-only transaction', (string) $answer);
    }

    /**
     * A statement that would run for minutes is stopped at the limit, and the connection is
     * usable after it — the transaction rolled back and the session limit put back.
     */
    #[DataProvider('databases')]
    public function testASlowStatementIsStopped(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->configure(['analyst' => ['sql_seconds' => 1]]);
        $slow = $type === 'postgresql'
            ? 'SELECT COUNT(*) AS n FROM generate_series(1, 2000000000)'
            : 'SELECT COUNT(*) AS n FROM information_schema.columns a, information_schema.columns b, information_schema.columns c';

        // Act
        $started = microtime(true);
        $answer  = (new SqlTool())->execute(['sql' => $slow]);
        $took    = microtime(true) - $started;
        $after   = (new SqlTool())->execute(['sql' => 'SELECT COUNT(*) AS n FROM users']);

        // Assert
        $this->assertStringStartsWith('The database refused it:', (string) $answer);
        $this->assertLessThan(15, $took, 'stopped near the limit, not run to the end');
        $this->assertSame(3, (int) $after['rows'][0]['n'], 'the connection still answers');
        $this->assertFalse($this->db->inTransaction());
    }

    /** Not inside a transaction the connection already has open, which MySQL would commit. */
    #[DataProvider('databases')]
    public function testNotInsideAnOpenTransaction(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->db->startTransaction();

        try {
            // Act
            $answer = (new SqlTool())->execute(['sql' => 'SELECT 1 AS one']);
        } finally {
            $this->db->rollbackTransaction();
        }

        // Assert
        $this->assertStringContainsString('inside a transaction', (string) $answer);
    }

    /**
     * An export: a file with a byte-order mark, a header and every row, five of them shown to
     * the model with the link — and the link opens for the administrator it was made for only.
     */
    #[DataProvider('databases')]
    public function testAnExport(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act
        // In the queue worker — a console process — with nothing to build a host on.
        $console = \Pramnos\Application\Application::inConsoleContext();
        \Pramnos\Application\Application::markConsoleContext(true);
        try {
            $made = (new ExportCsvTool(7))->execute(['sql' => 'SELECT userid, lastname FROM users WHERE userid > 1 ORDER BY userid']);
        } finally {
            \Pramnos\Application\Application::markConsoleContext($console);
        }
        $where = (new ExportCsvTool(7, 'https://example.com/admin/'))->execute(['sql' => 'SELECT 1 AS one']);
        $name  = basename((string) parse_url($made['link'], PHP_URL_PATH));
        $file  = Exports::path($name, 7);
        $theirs = Exports::path($name, 8);
        $page  = (new ExportScreen(7))->export($name);
        $other = new ExportScreen(8);
        $other->export($name);

        // Assert
        $this->assertSame(3, $made['rows']);
        $this->assertCount(3, $made['rows_inline'], 'a short list comes back whole, to show');
        $this->assertStringContainsString('Markdown table', $made['show']);
        $this->assertArrayNotHasKey('sample', $made);
        $this->assertMatchesRegularExpression('#^/Ai/export/7-[a-f0-9]{16}$#', $made['link'], 'no .csv, short enough to survive redaction, and from the root with no site root to build on');
        $this->assertSame($made['link'], \Pramnos\Llm\Analyst\Redactor::clean($made['link']));
        $this->assertMatchesRegularExpression('#^https://example\.com/admin/Ai/export/7-[a-f0-9]{16}$#', $where['link'], 'under the area the asking request saw');
        $this->assertNotNull($file);
        $this->assertNull($theirs, 'another administrator\'s link opens nothing');
        $this->assertSame("\xEF\xBB\xBFuserid,lastname\n7,Γλάρου\n8,Glaros\n9,Glaros\n", (string) file_get_contents((string) $file));
        $this->assertSame(file_get_contents((string) $file), $page, 'downloaded as written');
        $this->assertSame([adminUrl('Ai')], $other->redirectedTo, 'sent back to Ask');
    }

    /**
     * A list longer than the answer shows is the link and five rows; a title names the file, in
     * Latin letters when it was written in Greek, and the download is saved under it.
     */
    #[DataProvider('databases')]
    public function testALongListAndATitle(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->configure(['analyst' => ['export_inline' => 2]]);

        // Act
        $made = (new ExportCsvTool(7))->execute(['sql' => 'SELECT userid FROM users ORDER BY userid', 'title' => 'Ενεργά μέλη 2026']);
        $name = basename((string) parse_url($made['link'], PHP_URL_PATH));

        // Assert
        $this->assertArrayNotHasKey('rows_inline', $made, 'three rows are more than two');
        $this->assertCount(3, $made['sample']);
        $this->assertMatchesRegularExpression('/^7-energa-meli-2026-[a-f0-9]{16}$/', $name);
        $this->assertNotNull(Exports::path($name, 7));
        $this->assertSame('energa-meli-2026-2026-10-13-120000.csv', Exports::filename($name, mktime(12, 0, 0, 10, 13, 2026)));
        $this->assertSame('export-2026-10-13-120000.csv', Exports::filename('7-0123456789abcdef', mktime(12, 0, 0, 10, 13, 2026)), 'untitled');
    }

    /** A refused or failing export leaves no file, and one made for nobody is not made. */
    #[DataProvider('databases')]
    public function testAnExportThatFails(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act
        $refused = (new ExportCsvTool(7))->execute(['sql' => 'DELETE FROM users']);
        $failed  = (new ExportCsvTool(7))->execute(['sql' => 'SELECT nothing FROM users']);
        $nobody  = (new ExportCsvTool(0))->execute(['sql' => 'SELECT 1 AS one']);

        // Assert
        $this->assertStringStartsWith('Refused:', $refused);
        $this->assertStringStartsWith('The database refused it:', $failed);
        $this->assertStringStartsWith('Error:', $nobody);
        $this->assertSame([], glob($this->exports . '/*.csv') ?: [], 'nothing left behind');
    }

    /** A file past its days is gone, and its link with it. */
    public function testOldExportsArePruned(): void
    {
        // Arrange
        $this->exports = sys_get_temp_dir() . '/pf-exports-' . bin2hex(random_bytes(4));
        Exports::useDirectory($this->exports);
        mkdir($this->exports);
        $old = Exports::name(7);
        file_put_contents($this->exports . '/' . $old . '.csv', 'x');
        touch($this->exports . '/' . $old . '.csv', time() - (Exports::DAYS + 1) * 86400);

        // Act
        $before = Exports::path($old, 7);
        Exports::prune();

        // Assert
        $this->assertNull($before, 'expired already, before it is removed');
        $this->assertFileDoesNotExist($this->exports . '/' . $old . '.csv');
        $this->assertNull(Exports::path('../etc/passwd', 7), 'a name is only ever a name');
    }

    /**
     * The tables and one table's columns, with what the application says they mean — its notes
     * keyed without the prefix the catalogue carries.
     */
    #[DataProvider('databases')]
    public function testTheSchema(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->configure(['analyst' => ['schema' => ['users' => ['about' => 'People.', 'columns' => ['usertype' => '1 member, 99 admin']]]]]);
        $tool = new SchemaTool();

        // Act
        $tables  = $tool->execute([]);
        $users   = $tool->execute(['table' => 'users']);
        $missing = $tool->execute(['table' => 'nope']);

        // Assert
        $this->assertSame('People.', $tables['tables']['users']);
        $this->assertSame('1 member, 99 admin', $users['columns']['usertype']['means']);
        $this->assertTrue($users['columns']['userid']['primary']);
        $this->assertStringStartsWith('There is no table called nope', $missing);
    }

    /**
     * Off until the application turns each on; on, the model's rows are tagged personal and
     * redacted like every other tool's.
     */
    public function testOnlyWhenTurnedOn(): void
    {
        // Arrange / Act
        $none = array_keys(Analyst::tools(7));
        $this->configure(['analyst' => ['sql' => true]]);
        $sql = Analyst::tools(7);
        $this->configure(['analyst' => ['export' => true]]);
        $export   = array_keys(Analyst::tools(7));
        $noAdmin  = array_keys(Analyst::tools(0));

        // Assert
        $this->assertNotContains('sql', $none);
        $this->assertNotContains('schema', $none);
        $this->assertArrayHasKey('schema', $sql);
        $this->assertSame(['personal' => true], $sql['sql']['tags']);
        $this->assertContains('export_csv', $export);
        $this->assertNotContains('sql', $export);
        $this->assertNotContains('export_csv', $noAdmin, 'an export is for somebody');
    }

    /** A statement's own LIMIT is its own: `LIMIT 1` means one, not the tool's ceiling. */
    #[DataProvider('databases')]
    public function testAStatementsOwnLimitIsKept(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);

        // Act
        $one = (new SqlTool())->execute(['sql' => 'SELECT username FROM users ORDER BY userid LIMIT 1;']);

        // Assert
        $this->assertSame(['maria'], array_column($one['rows'], 'username'));
        $this->assertFalse($one['truncated']);
    }

    /**
     * Through the installation's read-only account when it set one — and a broken one is said,
     * never quietly replaced by the writable connection.
     */
    #[DataProvider('databases')]
    public function testThroughTheReadOnlyAccount(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $tool = new SqlTool();

        try {
            // Act
            $this->setting('database_readonly_dsn', $user . ':secret@' . $host . ':' . $port . '/' . self::PROBE);
            $read = $tool->execute(['sql' => 'SELECT COUNT(*) AS n FROM users']);
            $this->setting('database_readonly_dsn', 'user@');
            $malformed = $tool->execute(['sql' => 'SELECT 1 AS one']);
            $this->setting('database_readonly_dsn', $user . ':wrong@' . $host . ':' . $port . '/' . self::PROBE);
            $refused = $tool->execute(['sql' => 'SELECT 1 AS one']);
        } finally {
            $this->setting('database_readonly_dsn', '');
        }

        // Assert
        $this->assertSame(3, (int) $read['rows'][0]['n'], 'the same tables, through the second door');
        $this->assertStringContainsString('is not user:pass@host:port/name', (string) $malformed);
        $this->assertStringContainsString('could not be used', (string) $refused);
    }

    /** Nowhere to write the file is said, rather than a warning and a link to nothing. */
    public function testNowhereToWrite(): void
    {
        // Arrange
        Exports::useDirectory('/proc/pf-no-such-place');

        // Act
        $answer = (new ExportCsvTool(7))->execute(['sql' => 'SELECT 1 AS one']);

        // Assert
        $this->assertSame('Error: there is nowhere to write the file.', $answer);
    }

    /** Below the screen's floor the download is refused before a name is even read. */
    public function testBelowTheFloorNoFile(): void
    {
        // Arrange
        $screen = new ExportScreen(7, true);

        // Act / Assert
        $this->assertNull($screen->export('7-0123456789abcdef'));
        $this->assertSame([], $screen->redirectedTo);
    }
}

/** The screen with an administrator signed in, recording where it sent them. */
final class ExportScreen extends \Pramnos\Application\Controllers\AiController
{
    /** @var list<string> */
    public array $redirectedTo = [];

    public function __construct(private int $admin, private bool $refused = false)
    {
        // Not parent::__construct(): it registers actions against an application this test does not have.
    }

    protected function requireMinUserType($type): bool
    {
        return $this->refused;
    }

    protected function adminId(): int
    {
        return $this->admin;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo[] = (string) $url;
    }

    protected function addError($error)
    {
        return $this;
    }
}
