<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\SchemaDiffCommand;
use Pramnos\Database\Database;
use Pramnos\Database\SchemaDiff;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Tester\CommandTester;

/** The command over a given application, so a test can point it at a database of its own. */
final class SchemaDiffOver extends SchemaDiffCommand
{
    public function __construct(private \Pramnos\Application\Application $app)
    {
        parent::__construct();
    }

    /** The test's application. */
    protected function application(): ?\Pramnos\Application\Application
    {
        return $this->app;
    }

    /** The reference build, reachable from the test to make the "installation". */
    public function build(\Pramnos\Application\Application $app, Database $live, Database $into): array
    {
        return $this->buildReference($app, $live, $into);
    }

    /** Database housekeeping, reachable from the test. */
    public function make(Database $live, string $name): Database
    {
        $this->createDatabase($live, $name);

        return $this->connectTo($live, $name);
    }

    /** Drop a database this test made. */
    public function remove(Database $live, string $name): void
    {
        $this->dropDatabase($live, $name);
    }
}

/**
 * `schema:diff` on both backends: an installation built by the framework's migrations matches
 * them; one that drifted — an index and a column gone, a type changed, a column and an index of
 * its own — is reported key by key; `--apply` adds back what is missing and only that; and the
 * allow-list silences what is intentional.
 */
#[CoversClass(SchemaDiffCommand::class)]
#[CoversClass(SchemaDiff::class)]
final class SchemaDiffTest extends TestCase
{
    private ?Database $server = null;

    private ?Database $installation = null;

    private string $name = '';

    private ?SchemaDiffOver $command = null;

    /** @return array<string, array{string, string, int}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306],
            'postgresql' => ['postgresql', 'timescaledb', 5432],
        ];
    }

    protected function tearDown(): void
    {
        if ($this->installation !== null) {
            $this->installation->close();
            $this->command?->remove($this->server, $this->name);
        }
    }

    /**
     * An "installation": a database of its own on the test server, built by the framework's
     * migrations for the current application's features.
     */
    private function installation(string $type, string $host, int $port): \Pramnos\Application\Application
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $type === 'mysql' ? 'root' : 'postgres';
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }
        $this->server = $db;
        $app          = clone (\Pramnos\Application\Application::currentInstance() ?? \Pramnos\Application\Application::getInstance());
        $this->command = new SchemaDiffOver($app);
        $this->name   = 'pfdiff_' . bin2hex(random_bytes(3));
        $this->installation = $this->command->make($db, $this->name);
        $app->database = $this->installation;
        $this->command->build($app, $db, $this->installation);

        return $app;
    }

    /** The command's run, over the installation: exit code, output, and the differences as JSON. */
    private function diff(array $options = []): array
    {
        $console = new ConsoleApplication();
        $console->add($this->command);
        $tester = new CommandTester($console->find('schema:diff'));
        $code   = $tester->execute($options + ['--json' => true]);
        $display = $tester->getDisplay();
        $json    = json_decode(substr($display, (int) strpos($display, '[')), true);

        return [$code, $display, is_array($json) ? $json : []];
    }

    /** Keys of the found differences of a severity. */
    private static function keys(array $found, string $severity): array
    {
        return array_values(array_map(static fn (array $d): string => $d['key'], array_filter($found, static fn (array $d): bool => $d['severity'] === $severity)));
    }

    /**
     * Built by the migrations, the installation matches; after drifting, every change is a key;
     * `--apply` restores the missing index and column and leaves the changed type; the allow-list
     * then quiets it.
     */
    #[DataProvider('databases')]
    public function testDriftIsFoundAppliedAndAllowed(string $type, string $host, int $port): void
    {
        // Arrange — a table the core always has, and two of its indexes and a nullable column
        $this->installation($type, $host, $port);
        $console = new ConsoleApplication();
        $console->add($this->command);
        $matching = new CommandTester($console->find('schema:diff'));
        $matchCode = $matching->execute(['--keep' => true]);
        preg_match('/Reference kept in (\S+)\./', strip_tags($matching->getDisplay()), $kept);
        $this->command->remove($this->server, (string) ($kept[1] ?? ''));
        $shape   = SchemaDiff::describe($this->installation);
        $table   = 'sessions';
        $indexes = array_values(array_filter($shape[$table]['indexes'], static fn (array $i): bool => !$i['unique']));
        $this->assertGreaterThanOrEqual(2, count($indexes), 'sessions has indexes to drift');
        [$dropped, $renamed] = [$indexes[0], $indexes[1]];
        $droppedCols = array_search($dropped, $shape[$table]['indexes'], true);
        $renamedCols = array_search($renamed, $shape[$table]['indexes'], true);
        $urlNull = $shape[$table]['columns']['url']['nullable'] ? 'NULL' : 'NOT NULL';
        $q       = $type === 'mysql' ? static fn (string $n): string => '`' . $n . '`' : static fn (string $n): string => '"' . $n . '"';
        $sql     = $type === 'mysql'
            ? ['DROP INDEX ' . $q($dropped['name']) . ' ON sessions', 'ALTER TABLE sessions DROP COLUMN agent', 'ALTER TABLE sessions ADD COLUMN mine_only INT NULL',
               'ALTER TABLE sessions RENAME INDEX ' . $q($renamed['name']) . ' TO legacy_name', 'ALTER TABLE sessions MODIFY url VARCHAR(17) ' . $urlNull]
            : ['DROP INDEX ' . $q($dropped['name']), 'ALTER TABLE sessions DROP COLUMN agent', 'ALTER TABLE sessions ADD COLUMN mine_only INT NULL',
               'ALTER INDEX ' . $q($renamed['name']) . ' RENAME TO legacy_name', 'ALTER TABLE sessions ALTER COLUMN url TYPE varchar(17)'];
        foreach ($sql as $statement) {
            $this->installation->query($statement);
        }

        // Act
        $console = new ConsoleApplication();
        $console->add($this->command);
        $human = new CommandTester($console->find('schema:diff'));
        $human->execute([], ['verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE]);
        $missingRef = new CommandTester($console->find('schema:diff'));
        $missingRef->execute(['--reference' => 'no_such_database_' . bin2hex(random_bytes(3))]);
        [$driftCode, , $drift] = $this->diff();
        [$applyCode, , $applied] = $this->diff(['--apply' => true]);
        [$allowedCode, , $allowed] = $this->diff(['--allow' => ['sessions.column.url.*']]);

        // Assert
        $this->assertSame(0, $matchCode, 'built by the migrations: it matches');
        $this->assertStringContainsString('The tables match the framework\'s.', $matching->getDisplay());
        $this->assertNotSame('', $kept[1] ?? '', '--keep names the reference it kept');
        $this->assertStringContainsString('3 difference(s).', $human->getDisplay());
        $this->assertStringContainsString('✗ column agent missing', $human->getDisplay());
        $this->assertStringContainsString('· column mine_only extra', $human->getDisplay(), '-v lists what is beyond the framework');
        $this->assertSame(1, $missingRef->getStatusCode());
        $this->assertStringContainsString('Could not open the reference database', $missingRef->getDisplay());
        $this->assertSame(1, $driftCode);
        $this->assertEqualsCanonicalizing([
            'sessions.index.' . $droppedCols . '.missing',
            'sessions.column.agent.missing',
            'sessions.column.url.type',
        ], self::keys($drift, SchemaDiff::DIFFERENCE));
        $this->assertContains('sessions.column.mine_only.extra', self::keys($drift, SchemaDiff::INFO), 'its own column is information');
        $this->assertContains('sessions.index.' . $renamedCols . '.name', self::keys($drift, SchemaDiff::INFO), 'another name is the same index');
        $this->assertSame(['sessions.column.url.type'], self::keys($applied, SchemaDiff::DIFFERENCE), 'apply added the index and the column, and changed no type');
        $this->assertSame(1, $applyCode);
        $this->assertSame(0, $allowedCode);
        $this->assertSame(['sessions.column.url.type'], self::keys($allowed, SchemaDiff::ALLOWED));
    }

    /**
     * On an installation that adopted a table, its CREATE migration is excluded, and the
     * reference still builds it by default — so the adopted table is compared; with
     * `--honour-exclude` it is left out, as `migrate` leaves it out. An allowance that matches
     * nothing is named.
     */
    #[DataProvider('databases')]
    public function testExcludedTablesAreComparedUnlessAsked(string $type, string $host, int $port): void
    {
        // Arrange — the adopted table drifted
        $app = $this->installation($type, $host, $port);
        $app->applicationInfo['migrations'] = ['exclude' => ['create_sessions_table']];
        $this->installation->query('ALTER TABLE sessions ADD COLUMN adopted_only INT NULL');
        $this->installation->query('ALTER TABLE sessions DROP COLUMN agent');

        // Act
        [$fullCode, , $full] = $this->diff();
        [, , $honoured] = $this->diff(['--honour-exclude' => true]);
        $console = new ConsoleApplication();
        $console->add($this->command);
        $stale = new CommandTester($console->find('schema:diff'));
        $stale->execute(['--allow' => ['nothing.column.matches.*']]);

        // Assert
        $this->assertSame(1, $fullCode);
        $this->assertContains('sessions.column.agent.missing', self::keys($full, SchemaDiff::DIFFERENCE), 'the adopted table is compared');
        $this->assertSame([], array_values(array_filter(self::keys($honoured, SchemaDiff::DIFFERENCE), static fn (string $k): bool => str_starts_with($k, 'sessions.'))), 'left out, as migrate leaves it out');
        $this->assertStringContainsString('Allowed, but matches nothing: nothing.column.matches.*', $stale->getDisplay());
    }

    /**
     * A named reference that is not empty is refused, and emptied with `--fresh`; then it builds
     * and compares as the temporary one does.
     */
    #[DataProvider('databases')]
    public function testANamedReferenceMustBeEmptyOrFresh(string $type, string $host, int $port): void
    {
        // Arrange — a reference database with something left in it
        $this->installation($type, $host, $port);
        $name      = 'pfref_named_' . bin2hex(random_bytes(3));
        $reference = $this->command->make($this->server, $name);
        $reference->query('CREATE TABLE leftover (id INT)');
        $reference->close();
        $console = new ConsoleApplication();
        $console->add($this->command);
        $run = static function (array $options) use ($console): CommandTester {
            $tester = new CommandTester($console->find('schema:diff'));
            $tester->execute($options);

            return $tester;
        };

        try {
            // Act
            $refused = $run(['--reference' => $name]);
            $fresh   = $run(['--reference' => $name, '--fresh' => true]);
        } finally {
            $this->command->remove($this->server, $name);
        }

        // Assert
        $this->assertSame(1, $refused->getStatusCode());
        $this->assertStringContainsString('is not empty', $refused->getDisplay());
        $this->assertSame(0, $fresh->getStatusCode(), $fresh->getDisplay());
        $this->assertStringContainsString('The tables match the framework', $fresh->getDisplay());
    }
}
