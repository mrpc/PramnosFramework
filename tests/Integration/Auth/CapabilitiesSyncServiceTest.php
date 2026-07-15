<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\CapabilitiesSyncService;
use Pramnos\Database\Database;
use Pramnos\Framework\Migrations\AuthServer\CreateClientCapabilitiesTables;

/**
 * Integration tests for CapabilitiesSyncService against a real database.
 *
 * WHAT: manifest sync writes to the four capabilities tables with an MD5
 *       short-circuit, upsert, and soft-delete of removed entries.
 * WHY: this is the server side of the CI/CD capabilities push (features 2 & 3);
 *      it must actually persist rows, must not re-write on an unchanged
 *      manifest, and must NEVER hard-delete removed capabilities (existing user
 *      policies would break). Verified against the real DB (§8), not SQL strings.
 *
 * Runs against whichever engine DB_TYPE selects; the CI matrix covers all three.
 */
class CapabilitiesSyncServiceTest extends TestCase
{
    private Database $db;
    private Application $app;
    private CapabilitiesSyncService $svc;
    private bool $isPg;

    /** A fixed fake client id; capabilities tables carry no FK so this is fine. */
    private const APP_ID = 990001;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }
        if (!is_dir(LOG_PATH . \DS . 'logs')) {
            @mkdir(LOG_PATH . \DS . 'logs', 0777, true);
        }

        $driver = $_ENV['DB_TYPE'] ?? (getenv('DB_TYPE') ?: 'mysql');
        $this->isPg = in_array($driver, ['postgresql', 'pgsql', 'timescaledb'], true);

        $this->db = new Database();
        $this->db->type     = $driver;
        $this->db->server   = $_ENV['DB_HOST'] ?? (getenv('DB_HOST') ?: 'db');
        $this->db->port     = (int) ($_ENV['DB_PORT'] ?? (getenv('DB_PORT') ?: ($this->isPg ? 5432 : 3306)));
        $this->db->user     = $_ENV['DB_USER'] ?? (getenv('DB_USER') ?: 'root');
        $this->db->password  = $_ENV['DB_PASS'] ?? (getenv('DB_PASS') ?: 'secret');
        $this->db->database = $_ENV['DB_NAME'] ?? (getenv('DB_NAME') ?: 'pramnos_test');

        try {
            if (!$this->db->connect(false)) {
                $this->markTestSkipped('Database not reachable');
            }
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('Database not reachable: ' . $e->getMessage());
        }

        // The authserver schema must exist for the capabilities tables (PG only).
        if ($this->isPg) {
            $this->db->statement('CREATE SCHEMA IF NOT EXISTS authserver');
        }

        $this->app = new Application();
        $this->app->database = $this->db;

        // Fresh tables for each test.
        $migration = new CreateClientCapabilitiesTables($this->app);
        $migration->down();
        $migration->up();

        $this->svc = new CapabilitiesSyncService($this->db);
    }

    protected function tearDown(): void
    {
        try {
            (new CreateClientCapabilitiesTables($this->app))->down();
        } catch (\Throwable) {
            // Non-fatal cleanup.
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function sampleManifest(): array
    {
        return [
            'resources' => [
                ['name' => 'consumptions', 'description' => 'Meter readings',
                 'scopes' => ['read', 'export', ['name' => 'delete', 'description' => 'Remove a reading']]],
                ['name' => 'reports', 'scopes' => ['read', 'approve']],
            ],
            'conditions' => [
                ['key' => 'location_id', 'value_type' => 'int', 'description' => 'Horizontal access by location'],
            ],
        ];
    }

    private function countActive(string $table, string $col, mixed $val): int
    {
        $rows = $this->db->queryBuilder()->table($table)->where($col, $val)->where('is_active', true)->get();
        $n = 0;
        while ($rows && $rows->fetch()) {
            $n++;
        }
        return $n;
    }

    // ── Tests ──────────────────────────────────────────────────────────────

    /**
     * A first sync persists every resource, scope, and condition and reports
     * 'synced' with the correct counts.
     */
    public function testFirstSyncPersistsManifest(): void
    {
        // Act
        $result = $this->svc->sync(self::APP_ID, $this->sampleManifest());

        // Assert — status + counts
        $this->assertSame('synced', $result['status']);
        $this->assertSame(2, $result['resources'], 'Two resources declared');
        $this->assertSame(5, $result['scopes'], 'read+export+delete (3) + read+approve (2)');
        $this->assertSame(1, $result['conditions']);

        // Assert — rows actually written and active
        $this->assertSame(2, $this->countActive('authserver.client_resources', 'applicationid', self::APP_ID));
        $this->assertSame(1, $this->countActive('authserver.client_supported_conditions', 'applicationid', self::APP_ID));
    }

    /**
     * Re-syncing an identical manifest is a no-op (MD5 short-circuit): the
     * status is 'unchanged' and no counts are reported.
     */
    public function testUnchangedManifestShortCircuits(): void
    {
        // Arrange — initial sync
        $this->svc->sync(self::APP_ID, $this->sampleManifest());

        // Act — same manifest again
        $result = $this->svc->sync(self::APP_ID, $this->sampleManifest());

        // Assert — short-circuited
        $this->assertSame('unchanged', $result['status']);
        $this->assertSame(0, $result['resources']);
    }

    /**
     * A scope/resource removed from a later manifest is soft-deleted
     * (is_active = false), never hard-deleted — the row remains for existing
     * user policies but is reported as deactivated.
     */
    public function testRemovedCapabilitiesAreSoftDeleted(): void
    {
        // Arrange — full manifest first
        $this->svc->sync(self::APP_ID, $this->sampleManifest());

        // Act — push a reduced manifest: drop the 'reports' resource and the
        // 'export' + 'delete' scopes of 'consumptions', and drop the condition.
        $reduced = [
            'resources' => [
                ['name' => 'consumptions', 'scopes' => ['read']],
            ],
            'conditions' => [],
        ];
        $result = $this->svc->sync(self::APP_ID, $reduced);

        // Assert — re-synced, and removed items deactivated (not deleted)
        $this->assertSame('synced', $result['status']);
        $this->assertGreaterThan(0, $result['deactivated']);

        // Only 'consumptions' resource remains active; 'reports' is soft-deleted.
        $this->assertSame(1, $this->countActive('authserver.client_resources', 'applicationid', self::APP_ID));

        // The removed rows still physically exist (soft delete, not hard delete).
        $all = $this->db->queryBuilder()->table('authserver.client_resources')
            ->where('applicationid', self::APP_ID)->get();
        $total = 0;
        while ($all && $all->fetch()) {
            $total++;
        }
        $this->assertSame(2, $total, 'Both resources still exist physically (reports is is_active=false)');

        // Condition removed → deactivated.
        $this->assertSame(0, $this->countActive('authserver.client_supported_conditions', 'applicationid', self::APP_ID));
    }

    /**
     * Re-adding a previously soft-deleted capability reactivates it
     * (is_active flips back to true) rather than creating a duplicate.
     */
    public function testReaddingReactivates(): void
    {
        // Arrange — sync, then drop 'reports', then re-add it.
        $this->svc->sync(self::APP_ID, $this->sampleManifest());
        $this->svc->sync(self::APP_ID, ['resources' => [['name' => 'consumptions', 'scopes' => ['read']]]]);

        // Act — full manifest again
        $this->svc->sync(self::APP_ID, $this->sampleManifest());

        // Assert — both resources active again, still no duplicates.
        $this->assertSame(2, $this->countActive('authserver.client_resources', 'applicationid', self::APP_ID));
        $all = $this->db->queryBuilder()->table('authserver.client_resources')
            ->where('applicationid', self::APP_ID)->where('resource_name', 'reports')->get();
        $n = 0;
        while ($all && $all->fetch()) {
            $n++;
        }
        $this->assertSame(1, $n, 'Re-adding must reactivate the single row, not duplicate it');
    }

    /**
     * hashManifest is order-independent: reordering resources, scopes, and
     * conditions yields the same hash (so a cosmetically-different but
     * semantically-identical manifest still short-circuits).
     */
    public function testHashIsOrderIndependent(): void
    {
        // Arrange — same manifest, elements reordered.
        $a = $this->sampleManifest();
        $b = [
            'conditions' => [
                ['key' => 'location_id', 'value_type' => 'int', 'description' => 'Horizontal access by location'],
            ],
            'resources' => [
                ['name' => 'reports', 'scopes' => ['approve', 'read']],
                ['name' => 'consumptions', 'description' => 'Meter readings',
                 'scopes' => [['name' => 'delete', 'description' => 'Remove a reading'], 'export', 'read']],
            ],
        ];

        // Act & Assert
        $this->assertSame(
            $this->svc->hashManifest($a),
            $this->svc->hashManifest($b),
            'Reordered but identical manifests must hash equal'
        );
    }

    /**
     * A different manifest yields a different hash (guards against the
     * short-circuit wrongly firing on real changes).
     */
    public function testHashDiffersForChangedManifest(): void
    {
        $a = $this->sampleManifest();
        $b = $this->sampleManifest();
        $b['resources'][0]['scopes'][] = 'archive'; // add a scope

        $this->assertNotSame($this->svc->hashManifest($a), $this->svc->hashManifest($b));
    }

    /**
     * An empty manifest syncs cleanly with zero counts and writes a hash so a
     * subsequent empty push short-circuits. Exercises the no-resource path.
     */
    public function testEmptyManifestSyncsWithZeroCounts(): void
    {
        // Act
        $result = $this->svc->sync(self::APP_ID, []);

        // Assert — synced, nothing written, hash stored
        $this->assertSame('synced', $result['status']);
        $this->assertSame(0, $result['resources']);
        $this->assertSame(0, $result['conditions']);
        $this->assertNotSame('', $this->svc->currentHash(self::APP_ID), 'Hash must be stored even for an empty manifest');

        // A second empty push short-circuits.
        $this->assertSame('unchanged', $this->svc->sync(self::APP_ID, [])['status']);
    }

    /**
     * Malformed entries are defensively skipped: a resource with no name, an
     * empty scope, a condition with no key, and non-array values must not crash
     * or produce rows. Covers the guard branches and the asList() coercion.
     */
    public function testMalformedManifestEntriesAreSkipped(): void
    {
        // Arrange — a manifest riddled with junk plus one valid resource.
        $manifest = [
            'resources' => [
                ['description' => 'no name here'],                 // skipped: missing name
                ['name' => '', 'scopes' => ['read']],              // skipped: empty name
                ['name' => 'valid', 'scopes' => ['read', '', ['name' => '']]], // '' scopes skipped
                'not-an-array',                                    // ignored gracefully
            ],
            'conditions' => 'not-a-list',                          // asList → [] (no crash)
        ];

        // Act
        $result = $this->svc->sync(self::APP_ID, $manifest);

        // Assert — only the single valid resource with its single valid scope.
        $this->assertSame('synced', $result['status']);
        $this->assertSame(1, $result['resources']);
        $this->assertSame(1, $result['scopes'], 'Only the non-empty scope "read" is counted');
        $this->assertSame(0, $result['conditions']);
        $this->assertSame(1, $this->countActive('authserver.client_resources', 'applicationid', self::APP_ID));
    }
}
