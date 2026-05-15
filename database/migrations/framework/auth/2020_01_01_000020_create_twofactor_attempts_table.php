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
 * On MySQL and plain PostgreSQL it is a regular table with an index on attempt_time.
 * The table has no auto-increment PK so that TimescaleDB can use attempt_time
 * as the sole time dimension without a PK conflict.
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

            $table->bigInteger('userid')
                ->comment('User ID whose 2FA was verified');
            $table->tinyInteger('success')->default(0)
                ->comment('1 = code accepted, 0 = code rejected');
            $table->string('ip_address', 45)->nullable()
                ->comment('IPv4 or IPv6 address of the requester (nullable — not always available)');
            $table->string('code_used', 10)->nullable()
                ->comment('CRC32 hex hash of the code (not the plain code); 8 hex chars');
            $table->text('user_agent')->nullable()
                ->comment('HTTP User-Agent string from the verification request');
            $table->timestampTz('attempt_time')
                ->comment('Timestamp of the attempt — TIMESTAMPTZ on PostgreSQL/TimescaleDB, DATETIME on MySQL; time dimension for hypertable');

            $table->index(['userid', 'attempt_time'], 'idx_twofactor_attempts_userid');
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
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.twofactor_attempts');
    }
}
