<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console\Commands;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\DbWipe;
use Pramnos\Database\Database;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Unit tests for DbWipe's table-dropping logic, exercised WITHOUT a real
 * database. A fake Database records the SQL it is asked to run and returns a
 * scripted table list, so the per-driver DROP generation (MySQL vs PostgreSQL),
 * the empty-database branch and the failure branch are all covered without
 * dropping anything real (the destructive DDL is never sent to a server).
 *
 * The safety-guard branches (interactive/--force) are covered by DbWipeTest.
 */
#[CoversClass(DbWipe::class)]
class DbWipeLogicTest extends TestCase
{
    private ?string $originalPhpSelf = null;

    protected function setUp(): void
    {
        // Building a real \Pramnos\Console\Application registers Symfony's
        // DumpCompletionCommand, whose configure() reads $_SERVER['PHP_SELF'];
        // set it so no "undefined key"/basename(null) warning is emitted.
        $this->originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
        if (!isset($_SERVER['PHP_SELF'])) {
            $_SERVER['PHP_SELF'] = 'phpunit';
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalPhpSelf === null) {
            unset($_SERVER['PHP_SELF']);
        }
    }

    /**
     * MySQL: FK checks are toggled off/on and each table dropped with backticks.
     */
    public function testWipeMysqlDisablesFkChecksAndDropsEachTable(): void
    {
        // Arrange — a MySQL fake with two tables.
        $db  = $this->fakeDb('mysql', ['users', 'orders']);
        $cmd = $this->cmd();
        $out = new BufferedOutput();

        // Act
        $code = $cmd->runWipe($db, $out);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertContains('SET FOREIGN_KEY_CHECKS = 0', $db->executed);
        $this->assertContains('SET FOREIGN_KEY_CHECKS = 1', $db->executed);
        $this->assertContains('DROP TABLE IF EXISTS `users`', $db->executed);
        $this->assertContains('DROP TABLE IF EXISTS `orders`', $db->executed);
        $this->assertStringContainsString('Wiped 2 table(s).', $out->fetch());
    }

    /**
     * PostgreSQL: each table dropped with a schema-qualified CASCADE and NO
     * FK-check toggling.
     */
    public function testWipePostgresUsesCascadeAndSchema(): void
    {
        // Arrange
        $db = $this->fakeDb('postgresql', ['users']);
        $db->schema = 'public';
        $out = new BufferedOutput();

        // Act
        $code = $this->cmd()->runWipe($db, $out);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertContains('DROP TABLE IF EXISTS "public"."users" CASCADE', $db->executed);
        $this->assertNotContains('SET FOREIGN_KEY_CHECKS = 0', $db->executed, 'PostgreSQL must not toggle FK checks');
    }

    /**
     * An empty database reports "No tables to drop" and succeeds.
     */
    public function testWipeEmptyDatabase(): void
    {
        // Arrange
        $out = new BufferedOutput();

        // Act
        $code = $this->cmd()->runWipe($this->fakeDb('mysql', []), $out);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('No tables to drop', $out->fetch());
    }

    /**
     * A failed DROP (adapter returns false) is reported and the command fails.
     */
    public function testWipeReportsFailureWhenDropFails(): void
    {
        // Arrange — fake whose execute() returns false for DROP statements.
        $db = $this->fakeDb('mysql', ['users']);
        $db->failDrops = true;
        $out = new BufferedOutput();

        // Act
        $code = $this->cmd()->runWipe($db, $out);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('Failed to drop users', $out->fetch());
    }

    // ── execute() guard / wiring branches ─────────────────────────────────────

    /**
     * Run outside the Pramnos console application (a plain Symfony app) → the
     * command refuses because it cannot reach the framework database.
     */
    public function testExecuteFailsOutsideConsoleApp(): void
    {
        // Arrange — DbWipe added to a plain Symfony Application.
        $app = new \Symfony\Component\Console\Application('t', '1');
        $app->add(new DbWipe());
        $app->setAutoExit(false);
        $tester = new \Symfony\Component\Console\Tester\CommandTester($app->find('db:wipe'));

        // Act — --force to pass the safety guard.
        $code = $tester->execute(['--force' => true], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('must run within the Pramnos console application', $tester->getDisplay());
    }

    /**
     * Inside the console app but with no database connection → clean failure.
     */
    public function testExecuteFailsWhenNoDatabase(): void
    {
        // Arrange — Pramnos console app whose internalApplication has a null DB.
        $tester = $this->consoleTester(null);

        // Act
        $code = $tester->execute(['--force' => true], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::FAILURE, $code);
        $this->assertStringContainsString('No database connection available', $tester->getDisplay());
    }

    /**
     * Happy path through execute(): a console app carrying a fake DB drops its
     * tables and reports success.
     */
    public function testExecuteWipesViaConsoleApp(): void
    {
        // Arrange
        $tester = $this->consoleTester($this->fakeDb('mysql', ['t1', 't2']));

        // Act
        $code = $tester->execute(['--force' => true], ['interactive' => false]);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Wiped 2 table(s).', $tester->getDisplay());
    }

    /**
     * Interactive: answering "no" at the confirmation is a clean abort (exit 0).
     */
    public function testInteractiveDeclineAborts(): void
    {
        // Arrange
        $tester = $this->consoleTester($this->fakeDb('mysql', ['t1']));
        $tester->setInputs(['no']);

        // Act
        $code = $tester->execute([], ['interactive' => true]);

        // Assert
        $this->assertSame(Command::SUCCESS, $code);
        $this->assertStringContainsString('Aborted.', $tester->getDisplay());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * A CommandTester for db:wipe inside a real Pramnos console application whose
     * internalApplication exposes the given database (or null).
     */
    private function consoleTester(?Database $db): \Symfony\Component\Console\Tester\CommandTester
    {
        $app = new \Pramnos\Console\Application();
        $internal = new class { public $database; };
        $internal->database = $db;
        $app->internalApplication = $internal;
        $app->setAutoExit(false);
        $app->add(new DbWipe());
        return new \Symfony\Component\Console\Tester\CommandTester($app->find('db:wipe'));
    }


    /** A DbWipe subclass exposing the protected wipe() for direct testing. */
    private function cmd(): DbWipe
    {
        return new class extends DbWipe {
            public function runWipe(Database $db, \Symfony\Component\Console\Output\OutputInterface $o): int
            {
                return $this->wipe($db, $o);
            }
        };
    }

    /**
     * A fake Database that returns a scripted table list from query() and
     * records (without running) every execute() SQL string.
     *
     * @param string[] $tables
     */
    private function fakeDb(string $type, array $tables): Database
    {
        return new class($type, $tables) extends Database {
            /** @var string[] */
            public array $executed = [];
            public bool $failDrops = false;
            /** @var string[] */
            private array $tableList;

            public function __construct(string $type, array $tables)
            {
                // Bypass the real Database/Base constructor (no connection needed).
                $this->type      = $type;
                $this->tableList = $tables;
            }

            public function query($sql, $cache = false, $cachetime = 60, $category = "", $dieOnFatalError = false, $skipDataFix = false)
            {
                $rows = array_map(fn ($t) => ['table' => $t], $this->tableList);
                return new class($rows) {
                    public array $fields = [];
                    private int $i = 0;
                    public function __construct(private array $rows) {}
                    public function fetch(): bool
                    {
                        if ($this->i >= count($this->rows)) {
                            return false;
                        }
                        $this->fields = $this->rows[$this->i++];
                        return true;
                    }
                };
            }

            public function execute($sql, &...$arguments)
            {
                $this->executed[] = $sql;
                if ($this->failDrops && str_starts_with($sql, 'DROP TABLE')) {
                    $this->error_text = 'boom';
                    return false;
                }
                return true;
            }
        };
    }
}
