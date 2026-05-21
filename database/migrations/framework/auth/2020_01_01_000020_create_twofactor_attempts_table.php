<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;
use Pramnos\Database\DatabaseCapabilities;

/**
 * Creates the twofactor_attempts table — append-only 2FA verification log.
 *
 * On TimescaleDB this is a hypertable with:
 *   - 7-day chunks on attempt_time
 *   - compression enabled; compress chunks older than 7 days
 *   - retention: drop chunks older than 2 years
 *
 * The composite PK (attemptid, attempt_time) satisfies TimescaleDB's requirement
 * that the partition key (attempt_time) be part of every unique/primary index.
 *
 * PostgreSQL-only indexes use raw CREATE INDEX with DESC and WHERE clauses because
 * the schema builder does not support column ordering or partial index predicates.
 *
 * attempt_time is stored as a TIMESTAMPTZ (PostgreSQL/TimescaleDB) or DATETIME (MySQL).
 * TwoFactorAuthService::logAttempt() inserts formatted UTC strings for portability.
 *
 * @package PramnosFramework
 */
class CreateTwofactorAttemptsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 90;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.twofactor_attempts audit log (TimescaleDB hypertable when available)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.twofactor_attempts')) {
            return;
        }

        $schema->createTable('authserver.twofactor_attempts', function ($table) {
            $table->comment('Append-only 2FA attempt log; TimescaleDB hypertable on capable backends');

            $table->bigIncrements('attemptid')
                ->comment('Auto-increment surrogate key; part of composite PK with attempt_time for TimescaleDB compatibility');
            $table->bigInteger('userid')->nullable()
                ->comment('User ID whose 2FA was verified (nullable — anonymous attempts may lack a resolved userid)');
            $table->boolean('success')->default(false)
                ->comment('true = code accepted, false = code rejected');
            $table->string('ip_address', 45)->nullable()
                ->comment('IPv4 or IPv6 address of the requester (nullable — not always available)');
            $table->string('code_used', 10)->nullable()
                ->comment('CRC32 hex hash of the code (not the plain code); 8 hex chars');
            $table->text('user_agent')->nullable()
                ->comment('HTTP User-Agent string from the verification request');
            $table->timestampTz('attempt_time')
                ->comment('Timestamp of the attempt — TIMESTAMPTZ on PostgreSQL/TimescaleDB, DATETIME on MySQL; time dimension for hypertable');

            // Composite PK: TimescaleDB requires the partition key (attempt_time) in every
            // unique/primary constraint. bigIncrements sets single-column PK by default;
            // ->primary() below overrides it with the composite form.
            $table->primary(['attemptid', 'attempt_time']);

            $table->index(['userid', 'attempt_time'], 'idx_twofactor_attempts_userid_time');
            $table->index(['attempt_time'], 'idx_twofactor_attempts_time');
        });

        // TimescaleDB: convert to hypertable with compression and retention
        $schema->ifCapable(
            DatabaseCapabilities::TIMESCALEDB,
            function () use ($schema) {
                $schema->createHypertable('authserver.twofactor_attempts', 'attempt_time', [
                    'chunk_time_interval' => '7 days',
                ]);
                $schema->enableCompression('authserver.twofactor_attempts');
                $schema->addCompressionPolicy('authserver.twofactor_attempts', '7 days');
                $schema->addRetentionPolicy('authserver.twofactor_attempts', '2 years');
            }
        );

        // PostgreSQL-only: partial index for failure-rate queries and ip-rate-limit lookups.
        // These use DESC ordering and a WHERE predicate which the schema builder does not
        // support, so they are emitted as raw DDL guarded by the PostgreSQL capability.
        $schema->ifCapable(DatabaseCapabilities::ENGINE_POSTGRESQL, function () {
            $db = $this->application->database;
            $db->query(
                "CREATE INDEX IF NOT EXISTS idx_twofactor_attempts_ip_time
                 ON authserver.twofactor_attempts (ip_address, attempt_time DESC)"
            );
            $db->query(
                "CREATE INDEX IF NOT EXISTS idx_twofactor_attempts_success
                 ON authserver.twofactor_attempts (success, attempt_time DESC)
                 WHERE success = false"
            );
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.twofactor_attempts');
    }
}
