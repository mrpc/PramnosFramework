<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Policy;

use Pramnos\Application\Settings;
use Pramnos\Database\Database;

/**
 * The retention tests, against plain PostgreSQL.
 *
 * Every test is inherited; only the connection and the DDL differ. That is not
 * symmetry for its own sake — the bounded delete is **different code** per backend.
 * PostgreSQL has no `LIMIT` on `DELETE`, so the statement selects physical row ids
 * first:
 *
 * ```sql
 * DELETE FROM t WHERE ctid IN (SELECT ctid FROM t WHERE … LIMIT 5000)
 * ```
 *
 * A batching bug on that branch is invisible to the MySQL class next door, and the
 * consequence — a delete that turns out to be unbounded after all — is exactly what
 * these tests exist to catch.
 *
 * Requires the Docker TimescaleDB container (host: timescaledb, port: 5432). The
 * extension being present does not matter here: `PolicyEngine::run()` returns early on
 * TimescaleDB, so these tests drive `executeRetention()` through a connection whose type
 * is declared `postgresql`, which is what a plain-PostgreSQL installation looks like.
 */
class PolicyEngineRetentionPostgreSQLTest extends PolicyEngineRetentionMySQLTest
{
    protected static string $table = 'pramnos_retention_probe_pg';

    protected static function openConnection(): ?Database
    {
        $settings = Settings::getSetting('postgresql');
        if (!$settings) {
            return null;
        }

        $db           = new Database();
        $db->type     = 'postgresql';
        $db->server   = $settings->hostname;
        $db->user     = $settings->user;
        $db->password = $settings->password;
        $db->database = $settings->database;
        $db->port     = $settings->port ?? 5432;
        $db->schema   = $settings->schema ?? 'public';

        try {
            $db->connect(true);
        } catch (\Throwable) {
            return null;
        }

        return $db->connected ? $db : null;
    }

    protected static function createPolicyTableOn(Database $db): void
    {
        $name = $db->schema()->resolveTableName('pramnos.framework_policies');

        $db->query('CREATE SCHEMA IF NOT EXISTS pramnos');
        $db->query(
            'CREATE TABLE IF NOT EXISTS ' . $name . ' ('
            . 'policyid SERIAL PRIMARY KEY, '
            . 'policy_type VARCHAR(50) NOT NULL, '
            . 'target VARCHAR(255) NOT NULL, '
            . 'config JSONB NULL, '
            . 'enabled BOOLEAN NOT NULL DEFAULT TRUE, '
            . 'last_run TIMESTAMP NULL, '
            . 'next_run TIMESTAMP NULL, '
            . 'last_result TEXT NULL, '
            . 'last_error TEXT NULL, '
            . 'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
            . ')'
        );
    }

    protected static function createTableOn(Database $db): void
    {
        $db->query('DROP TABLE IF EXISTS ' . static::$table);
        $db->query(
            'CREATE TABLE ' . static::$table . ' ('
            . 'id SERIAL PRIMARY KEY, '
            . 'created_at TIMESTAMP NOT NULL'
            . ')'
        );
        $db->query(
            'CREATE INDEX IF NOT EXISTS idx_probe_pg_created ON '
            . static::$table . ' (created_at)'
        );
    }
}
