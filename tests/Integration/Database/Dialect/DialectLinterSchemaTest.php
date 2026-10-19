<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database\Dialect;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DatabaseDialectLint;
use Pramnos\Database\Database;
use Pramnos\Database\Dialect\DialectLinter;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/** `db:dialect-lint` with the database the command is handed, instead of the application's. */
final class SchemaDialectLint extends DatabaseDialectLint
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function database(): Database
    {
        return $this->db;
    }
}

/**
 * `db:dialect-lint --schema` (FW-165 §9): the column types and primary keys read from a live
 * database, MySQL's or PostgreSQL's, so a text column compared with a number is found — the
 * comparison PostgreSQL refuses and MySQL converts — and a GROUP BY of a key is judged exactly.
 */
#[CoversClass(DialectLinter::class)]
#[CoversClass(DatabaseDialectLint::class)]
final class DialectLinterSchemaTest extends TestCase
{
    private ?Database $db = null;

    private string $t = '';

    private string $dir = '';

    /** @return array<string, array{string}> */
    public static function databases(): array
    {
        return ['MySQL' => ['mysql'], 'PostgreSQL' => ['postgresql']];
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->query("DROP TABLE IF EXISTS {$this->t}_users");
            $this->db->query("DROP TABLE IF EXISTS {$this->t}_packages");
            $this->db->close();
        }
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*.php') ?: []);
            @rmdir($this->dir);
        }
    }

    /** A connection of the type asked for, to the test database. */
    private function connect(string $type): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $type === 'mysql' ? 'db' : 'timescaledb';
        $db->port     = $type === 'mysql' ? 3306 : 5432;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($type . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($type . ' not reachable: ' . $e->getMessage());
        }

        return $this->db = $db;
    }

    /**
     * The schema names each column as text, a number or something else, and each table's
     * primary key in order; the command, given `--schema`, finds the text column compared with a
     * number in a file, and without it does not.
     */
    #[DataProvider('databases')]
    public function testTheSchemaIsReadAndUsed(string $type): void
    {
        // Arrange
        $db      = $this->connect($type);
        $this->t = 'dls' . bin2hex(random_bytes(3));
        $db->query("CREATE TABLE {$this->t}_packages (packageid INT NOT NULL, site INT NOT NULL, name VARCHAR(20), PRIMARY KEY (packageid, site))");
        $db->query("CREATE TABLE {$this->t}_users (userid INT PRIMARY KEY, subscription VARCHAR(10), born DATE, notes TEXT)");
        $this->dir = sys_get_temp_dir() . '/dialect' . bin2hex(random_bytes(3));
        mkdir($this->dir);
        file_put_contents($this->dir . '/Q.php', "<?php\n\$q = 'SELECT * FROM {$this->t}_users u JOIN {$this->t}_packages p ON u.subscription = p.packageid';\n");
        $console = new ConsoleApplication();
        $console->add(new SchemaDialectLint($db));
        $tester = new CommandTester($console->find('db:dialect-lint'));

        // Act
        $schema  = DialectLinter::schema($db);
        $with    = $tester->execute(['paths' => [$this->dir], '--schema' => true]);
        $display = $tester->getDisplay();
        $without = $tester->execute(['paths' => [$this->dir]]);

        // Assert
        $users = $schema['columns'][$this->t . '_users'];
        ksort($users);
        $this->assertSame(['born' => 'other', 'notes' => 'text', 'subscription' => 'text', 'userid' => 'number'], $users);
        $this->assertSame(['packageid', 'site'], $schema['keys'][$this->t . '_packages'], 'the key, in order');
        $this->assertSame(['userid'], $schema['keys'][$this->t . '_users']);
        $this->assertSame(1, $with);
        $this->assertStringContainsString('Q.php:2  implicit-cast  u.subscription is text, compared with p.packageid, a number', $display);
        $this->assertSame(0, $without, 'without the schema a text column cannot be told');
    }

    /**
     * Without a database handed to it, the command reads the application's own — connecting it
     * when it is not yet — and a path with nothing to report exits 0.
     */
    public function testTheApplicationsDatabaseIsTheDefault(): void
    {
        // Arrange
        $this->dir = sys_get_temp_dir() . '/dialect' . bin2hex(random_bytes(3));
        mkdir($this->dir);
        file_put_contents($this->dir . '/Ok.php', "<?php\n\$q = 'SELECT a FROM t';\n");
        $console = new ConsoleApplication();
        $console->add(new DatabaseDialectLint());
        $tester = new CommandTester($console->find('db:dialect-lint'));
        \Pramnos\Framework\Factory::getDatabase()->close();

        // Act
        $code = $tester->execute(['paths' => [$this->dir], '--schema' => true]);

        // Assert
        $this->assertSame(0, $code);
        $this->assertTrue(\Pramnos\Framework\Factory::getDatabase()->connected, 'connected to read the schema');
    }
}

