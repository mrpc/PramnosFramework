<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Database\Migration;
use Pramnos\Database\MigrationRunner;

/**
 * A ledger the legacy migration path made — `key` and nothing else — is brought up to shape.
 *
 * `Application::runMigration()` writes only `key` into `schemaversion`, so an installation that
 * came from it can have a ledger with no `when` and no `extra`. The runner added every newer
 * column but those two, and its first insert names both: the first `migrate` on such an
 * installation failed with "Unknown column 'extra'".
 */
#[CoversClass(MigrationRunner::class)]
class LegacyKeyOnlyLedgerTest extends TestCase
{
    private ?Database $db = null;

    private string $ledger = '';

    /** @return array<string, array{string, string, int, string}> */
    public static function databases(): array
    {
        return [
            'mysql'      => ['mysql', 'db', 3306, 'root'],
            'postgresql' => ['postgresql', 'timescaledb', 5432, 'postgres'],
        ];
    }

    /** A connection to the test database on one engine. */
    private function connect(string $type, string $host, int $port, string $user): Database
    {
        $db           = new Database();
        $db->type     = $type;
        $db->server   = $host;
        $db->port     = $port;
        $db->user     = $user;
        $db->password = 'secret';
        $db->database = TEST_DATABASE;
        if ($type === 'postgresql') {
            $db->schema = 'public'; // on MySQL the schema is the database itself
        }
        try {
            if (!$db->connect(false)) {
                $this->markTestSkipped($host . ' not reachable');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped($host . ' not reachable: ' . $e->getMessage());
        }

        return $this->db = $db;
    }

    protected function tearDown(): void
    {
        if ($this->db !== null && $this->ledger !== '') {
            $this->db->schema()->dropTableIfExists($this->ledger);
        }
    }

    /**
     * The runner adds `when` and `extra`, and records the migration it ran.
     *
     * Without both columns the runner's first insert fails, so this is the whole upgrade
     * path for such an installation.
     */
    #[DataProvider('databases')]
    public function testAKeyOnlyLedgerIsCompletedAndWrittenTo(string $type, string $host, int $port, string $user): void
    {
        // Arrange — the legacy shape, under a name of this test's own
        $db           = $this->connect($type, $host, $port, $user);
        $this->ledger = 'legacy_ledger_' . bin2hex(random_bytes(4));
        $db->schema()->createTable($this->ledger, function ($table) {
            $table->string('key', 100)->nullable();
        });
        $migration = new LegacyLedgerProbe(null);

        // Act
        $result = (new MigrationRunner($db, $this->ledger))->run([$migration]);

        // Assert — it ran, the columns are there, and the row says what ran
        $this->assertNotEmpty($result['ran'], 'the migration did not run: ' . json_encode($result));
        $this->assertSame([], $result['failed']);
        $this->assertTrue($db->schema()->hasColumn($this->ledger, 'extra'));
        $this->assertTrue($db->schema()->hasColumn($this->ledger, 'when'));
        $row = $db->queryBuilder()->table($this->ledger)->where('key', $result['ran'][0])->first();
        $this->assertSame('probe', $row->fields['extra']);
    }

    /**
     * A ledger whose keys already repeat cannot take the unique index, and still migrates.
     *
     * The legacy path never enforced uniqueness, so an old installation can hold the same key
     * twice. Building the index then fails; the runner must treat that as "this ledger stays
     * as it was" rather than refuse every migration after it.
     *
     * MySQL only: its upsert works without the index. PostgreSQL's `ON CONFLICT` does not, so
     * there the repeats have to be removed first — the Migration Guide says so.
     */
    public function testRepeatedKeysLeaveTheLedgerWithoutTheIndexAndStillMigrate(): void
    {
        // Arrange — a key-only ledger holding one key twice
        $db           = $this->connect('mysql', 'db', 3306, 'root');
        $this->ledger = 'legacy_ledger_' . bin2hex(random_bytes(4));
        $db->schema()->createTable($this->ledger, function ($table) {
            $table->string('key', 100)->nullable();
        });
        $db->queryBuilder()->table($this->ledger)->insert(['key' => 'twice']);
        $db->queryBuilder()->table($this->ledger)->insert(['key' => 'twice']);

        // Act
        $result = (new MigrationRunner($db, $this->ledger))->run([new LegacyLedgerProbe(null)]);

        // Assert — the probe ran, and both old rows are still there (nothing was deduplicated)
        $this->assertNotEmpty($result['ran'], 'the migration did not run: ' . json_encode($result));
        $this->assertSame([], $result['failed']);
        $this->assertSame(2, $db->queryBuilder()->table($this->ledger)->where('key', 'twice')->count());
    }
}

/** A migration with a slug the size a real one has, which a legacy `key VARCHAR(100)` holds. */
class LegacyLedgerProbe extends Migration
{
    /** @var string What the ledger's `extra` column records */
    public $description = 'probe';

    /** No application: the runner is given the connection. */
    public function __construct($application)
    {
    }

    public function up(): void
    {
    }

    public function down(): void
    {
    }
}
