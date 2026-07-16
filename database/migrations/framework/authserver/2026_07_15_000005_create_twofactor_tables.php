<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the three two-factor-authentication tables consumed by
 * {@see \Pramnos\Auth\TwoFactorAuthService}.
 *
 * The framework already shipped the 2FA service and TOTP helper, but not the
 * tables — so a freshly scaffolded authserver had no schema for them. This
 * migration closes that gap so 2FA works out of the box.
 *
 * Tables (schema `authserver.` on PostgreSQL / prefix `authserver_` on MySQL,
 * resolved by the schema builder):
 *   - user_twofactor     one row per user: enabled flag, TOTP secret, hashed
 *                        backup codes, replay/last-used marker.
 *   - twofactor_setup    pending setup sessions (temporary secret + TTL) before
 *                        the user confirms the first code.
 *   - twofactor_attempts audit trail of verification attempts.
 *
 * Time columns that the service stores as UNIX timestamps (`last_used`,
 * `expires_at`, `created_at`, `updated_at`, `setup_completed_at`) are BIGINT;
 * `attempt_time` is a datetime (the service writes a formatted string).
 *
 * Non-breaking (framework rule §8, DB-safety §3): brand-new tables, each guarded
 * by hasTable(); NO hard foreign key on userid (app-layer integrity, consistent
 * with the other authserver tables). Current-date timestamp (§9).
 */
class CreateTwofactorTables extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 64;
    public array  $dependencies = ['create_authserver_schema', 'create_users_table'];
    public $description  = 'Creates the two-factor authentication tables (user_twofactor, twofactor_setup, twofactor_attempts)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('authserver.user_twofactor')) {
            $schema->createTable('authserver.user_twofactor', function ($table) {
                $table->comment('Per-user two-factor authentication state');

                $table->bigIncrements('id')->comment('Auto-increment primary key');
                $table->bigInteger('userid')
                    ->comment('Owner (users.userid) — no hard FK (app-layer integrity)');
                $table->smallInteger('enabled')->default(0)
                    ->comment('1 when 2FA is fully set up and active');
                $table->string('secret', 255)->nullable()
                    ->comment('Base32 TOTP secret (null until setup completes)');
                $table->text('backup_codes')->nullable()
                    ->comment('JSON array of hashed one-time backup codes');
                $table->bigInteger('last_used')->default(0)
                    ->comment('UNIX time of the last accepted code (TOTP replay window guard)');
                $table->bigInteger('setup_completed_at')->nullable()
                    ->comment('UNIX time setup was completed');
                $table->bigInteger('created_at')->nullable()
                    ->comment('UNIX time the row was created');
                $table->bigInteger('updated_at')->nullable()
                    ->comment('UNIX time the row was last updated');

                $table->unique(['userid'], 'uq_user_twofactor_userid');
            });
        }

        if (!$schema->hasTable('authserver.twofactor_setup')) {
            $schema->createTable('authserver.twofactor_setup', function ($table) {
                $table->comment('Pending 2FA setup sessions (temporary secret + TTL)');

                $table->bigIncrements('id')->comment('Auto-increment primary key');
                $table->bigInteger('userid')
                    ->comment('User setting up 2FA — no hard FK (app-layer integrity)');
                $table->string('temp_secret', 255)
                    ->comment('Candidate base32 TOTP secret, promoted on confirmation');
                $table->smallInteger('used')->default(0)
                    ->comment('1 once the setup session has been consumed');
                $table->bigInteger('expires_at')->default(0)
                    ->comment('UNIX time after which this setup session is invalid');
                $table->bigInteger('created_at')->nullable()
                    ->comment('UNIX time the setup session was created');

                $table->index(['userid'], 'idx_twofactor_setup_userid');
            });
        }

        if (!$schema->hasTable('authserver.twofactor_attempts')) {
            $schema->createTable('authserver.twofactor_attempts', function ($table) {
                $table->comment('Audit trail of 2FA verification attempts');

                $table->bigIncrements('id')->comment('Auto-increment primary key');
                $table->bigInteger('userid')
                    ->comment('User the attempt was for — no hard FK (app-layer integrity)');
                // The service passes a PHP bool here, so this must be a real
                // boolean column (PostgreSQL rejects a bool in a smallint column).
                $table->boolean('success')->default(false)
                    ->comment('True when the submitted code was accepted');
                $table->string('ip_address', 45)->nullable()
                    ->comment('Client IP (IPv4/IPv6)');
                $table->string('code_used', 16)->nullable()
                    ->comment('crc32 fingerprint of the submitted code (never the code itself)');
                $table->string('user_agent', 255)->nullable()
                    ->comment('Client user-agent string');
                $table->dateTime('attempt_time')->nullable()
                    ->comment('When the attempt happened (UTC)');

                $table->index(['userid'], 'idx_twofactor_attempts_userid');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('authserver.twofactor_attempts');
        $schema->dropTableIfExists('authserver.twofactor_setup');
        $schema->dropTableIfExists('authserver.user_twofactor');
    }
}
