<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;
use Pramnos\Database\SchemaBuilder;

/**
 * Two framework migrations aborted the batch on a compressed hypertable.
 *
 * `authserver.data_processing_records` is a hypertable that compresses chunks older than
 * 90 days, and two migrations add a foreign key to it. TimescaleDB refuses:
 *
 * ```
 * ERROR:  operation not supported on hypertables that have compressed data
 * HINT:  Decompress the data before retrying the operation.
 * ```
 *
 * A failing `ALTER` aborts the whole batch, so on any installation old enough to have
 * compressed a chunk of that table, those two migrations took unrelated migrations down
 * with them on every `migrate` — an entirely framework-internal contradiction, since the
 * framework both compresses the table and tries to alter it.
 *
 * Skipped rather than decompressed: skipping is recoverable and a broken batch is not, and
 * decompressing a production hypertable inside a migration is minutes of maintenance mode
 * on a table nobody asked to touch.
 *
 * The tests run against the Docker TimescaleDB container, because the behaviour being
 * asserted is the engine's refusal — a unit test could only assert that a mock was asked.
 */
#[CoversClass(SchemaBuilder::class)]
class ForeignKeyOnCompressedHypertableTest extends TestCase
{
    private Database $db;
    private Application $app;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';

        try {
            $this->db->connect(true);
        } catch (\Exception) {
            $this->markTestSkipped('TimescaleDB container not reachable (timescaledb:5432)');
        }

        $has = $this->db->execute(
            "SELECT COUNT(*) AS cnt FROM pg_extension WHERE extname = 'timescaledb'"
        );
        if ((int) ($has->fields['cnt'] ?? 0) === 0) {
            $this->markTestSkipped('This connection has no TimescaleDB; the refusal is its behaviour.');
        }

        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        $this->app = $app;

        $this->cleanUp();
        $this->db->execute('CREATE SCHEMA IF NOT EXISTS authserver');
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS authserver.fkprobe CASCADE');
        $this->db->execute('DROP TABLE IF EXISTS public.fkprobe_parent CASCADE');
    }

    /**
     * A hypertable shaped like `data_processing_records`: a compressed chunk past the cut.
     *
     * @param bool $compress Whether to compress the aged chunk
     */
    private function makeHypertable(bool $compress): void
    {
        $this->db->execute('CREATE TABLE public.fkprobe_parent (userid bigint PRIMARY KEY)');
        $this->db->execute('INSERT INTO public.fkprobe_parent (userid) VALUES (1)');

        $this->db->execute(
            'CREATE TABLE authserver.fkprobe (
                 id bigserial,
                 userid bigint NOT NULL,
                 operation varchar(100) NOT NULL,
                 processed_at timestamptz NOT NULL,
                 PRIMARY KEY (id, processed_at)
             )'
        );
        $this->db->execute(
            "SELECT create_hypertable('authserver.fkprobe', 'processed_at',
                 chunk_time_interval => INTERVAL '1 week')"
        );
        $this->db->execute(
            "ALTER TABLE authserver.fkprobe
                 SET (timescaledb.compress, timescaledb.compress_segmentby = 'operation')"
        );
        $this->db->execute(
            "INSERT INTO authserver.fkprobe (userid, operation, processed_at)
             VALUES (1, 'data_export', now() - INTERVAL '200 days')"
        );

        if ($compress) {
            $this->db->execute(
                "SELECT compress_chunk(c) FROM show_chunks('authserver.fkprobe') c"
            );
        }
    }

    private function schema(): SchemaBuilder
    {
        return $this->db->schema();
    }

    /**
     * The engine really does refuse, so the guard is guarding something.
     *
     * Asserted first because everything else here is a reaction to it: if a later
     * TimescaleDB allowed the ALTER, the guard would be skipping a key it could have
     * added, and this test is what would say so.
     */
    public function testTimescaleRefusesTheAlterOnCompressedChunks(): void
    {
        // Arrange
        $this->makeHypertable(true);

        // Act & Assert
        try {
            $this->db->execute(
                'ALTER TABLE authserver.fkprobe ADD CONSTRAINT fk_probe
                     FOREIGN KEY (userid) REFERENCES public.fkprobe_parent(userid)'
            );
            $this->fail('TimescaleDB accepted the ALTER; the skip guard is now unnecessary');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString(
                'compressed',
                strtolower($exception->getMessage()),
                'the refusal must be the compression one, not some other failure'
            );
        }
    }

    /**
     * `hasCompressedChunks()` sees a compressed chunk.
     *
     * The question a migration asks before altering a hypertable, and the whole of the fix
     * depends on the answer being right — a false negative is the aborted batch back again.
     */
    public function testItSeesACompressedChunk(): void
    {
        // Arrange
        $this->makeHypertable(true);

        // Act & Assert
        $this->assertTrue($this->schema()->hasCompressedChunks('fkprobe', 'authserver'));
    }

    /**
     * And an uncompressed hypertable is not reported as compressed.
     *
     * The control: a guard that answered true for every hypertable would skip the key on a
     * fresh installation too, which is where it can and should be added.
     */
    public function testAnUncompressedHypertableIsNotReported(): void
    {
        // Arrange
        $this->makeHypertable(false);

        // Act & Assert
        $this->assertFalse($this->schema()->hasCompressedChunks('fkprobe', 'authserver'));
    }

    /**
     * A plain table is not reported either.
     *
     * Nothing about an ordinary table can refuse an ALTER for this reason, and every other
     * caller of the foreign-key migrations passes one.
     */
    public function testAPlainTableIsNotReported(): void
    {
        // Arrange
        $this->db->execute('CREATE TABLE public.fkprobe_parent (userid bigint PRIMARY KEY)');

        // Act & Assert
        $this->assertFalse($this->schema()->hasCompressedChunks('fkprobe_parent', 'public'));
    }

    /**
     * A foreign key and compression coexist once the key is there.
     *
     * This is what makes skipping the right answer rather than a capitulation: a fresh
     * installation adds the key while the table is empty, and compresses afterwards without
     * complaint — so the key is not lost to the framework, only to installations that had
     * already compressed before the migration existed.
     */
    public function testCompressionStillWorksOnATableThatAlreadyHasTheKey(): void
    {
        // Arrange — the key first, on an uncompressed table
        $this->makeHypertable(false);
        $this->db->execute(
            'ALTER TABLE authserver.fkprobe ADD CONSTRAINT fk_probe
                 FOREIGN KEY (userid) REFERENCES public.fkprobe_parent(userid) ON DELETE CASCADE'
        );

        // Act — now compress
        $this->db->execute("SELECT compress_chunk(c) FROM show_chunks('authserver.fkprobe') c");

        // Assert
        $this->assertTrue($this->schema()->hasCompressedChunks('fkprobe', 'authserver'));

        // And the cascade still fires through a compressed chunk, which is the point of
        // having the key at all.
        $this->db->execute('DELETE FROM public.fkprobe_parent WHERE userid = 1');
        $left = $this->db->execute('SELECT COUNT(*) AS cnt FROM authserver.fkprobe');
        $this->assertSame(
            0,
            (int) $left->fields['cnt'],
            'the cascade did not reach the compressed chunk'
        );
    }

    /**
     * The two migrations complete instead of aborting the batch.
     *
     * The reported symptom, end to end: with `data_processing_records` compressed, both
     * migrations used to raise and take the batch with them. They must now run to
     * completion — the foreign key on that one table is skipped, and the four others in the
     * same list are still added.
     *
     * @param string $feature The migrations directory
     * @param string $class   The migration class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('foreignKeyMigrations')]
    public function testTheMigrationCompletesWithACompressedHypertable(string $feature, string $class): void
    {
        // Arrange — the real table, compressed, plus what the migration needs to exist
        $this->db->execute('DROP TABLE IF EXISTS authserver.data_processing_records CASCADE');
        $this->loadMigration('auth', 'CreateUsersTable')->up();
        $this->loadMigration('auth', 'CreateDataProcessingRecordsTable')->up();

        $this->db->execute(
            "INSERT INTO authserver.data_processing_records
                 (userid, operation, data_category, legal_basis, processed_at)
             VALUES (1, 'data_export', 'profile', 'consent', now() - INTERVAL '200 days')"
        );
        $this->db->execute(
            "SELECT compress_chunk(c)
             FROM show_chunks('authserver.data_processing_records') c"
        );
        $this->assertTrue(
            $this->schema()->hasCompressedChunks('data_processing_records', 'authserver'),
            'the arrangement did not compress anything, so this proves nothing'
        );

        // Act & Assert — it must not raise
        $this->loadMigration($feature, $class)->up();

        // And the key really was left off, rather than added by some other path
        $constraint = $this->db->execute(
            "SELECT COUNT(*) AS cnt FROM information_schema.table_constraints
              WHERE constraint_name = 'fk_data_processing_records_userid'"
        );
        $this->assertSame(
            0,
            (int) $constraint->fields['cnt'],
            'the key was added to a compressed hypertable, which TimescaleDB should refuse'
        );
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function foreignKeyMigrations(): array
    {
        return [
            'AddMissingForeignKeysToExistingTables' =>
                ['core', 'AddMissingForeignKeysToExistingTables'],
            'RepairGdprRequestsAndAuthserverForeignKeys' =>
                ['auth', 'RepairGdprRequestsAndAuthserverForeignKeys'],
        ];
    }

    /**
     * Load one migration by class name, wherever its feature directory is.
     */
    private function loadMigration(string $feature, string $class): \Pramnos\Database\Migration
    {
        $base = dirname(__DIR__, 3) . '/database/migrations/framework';

        foreach (MigrationLoader::loadFromDirectory($base . '/' . $feature, $this->app) as $m) {
            if ((new \ReflectionClass($m))->getShortName() === $class) {
                return $m;
            }
        }

        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (MigrationLoader::loadFromDirectory($dir, $this->app) as $m) {
                if ((new \ReflectionClass($m))->getShortName() === $class) {
                    return $m;
                }
            }
        }

        $this->fail('Migration not found: ' . $class);
    }
}
