<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the user_twofactor table — one row per user, stores TOTP secret and state.
 *
 * userid is the primary key (no auto-increment) matching the users.userid value.
 * enabled=0 means the row exists but 2FA is disabled (happens after calling disable()).
 * Backup codes are stored as a JSON array of bcrypt hashes.
 * All timestamps are Unix integers for cross-DB portability.
 *
 * @package PramnosFramework
 */
class CreateUserTwofactorTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 80;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.user_twofactor 2FA state table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.user_twofactor')) {
            return;
        }

        $schema->createTable('authserver.user_twofactor', function ($table) {
            $table->comment('Two-factor authentication state — one row per user; userid is the PK matching users.userid');

            $table->bigInteger('userid')
                ->comment('User ID — primary key matching users.userid; no auto-increment');
            $table->tinyInteger('enabled')->default(0)
                ->comment('1 = 2FA is active for this user, 0 = disabled (but row retained for audit)');
            $table->string('secret', 64)->nullable()
                ->comment('Base32-encoded TOTP secret; NULL when 2FA is disabled');
            $table->text('backup_codes')->nullable()
                ->comment('JSON array of bcrypt-hashed backup codes; NULL when 2FA is disabled');
            $table->integer('last_used')->default(0)
                ->comment('Unix timestamp of the last successful TOTP code use (for replay protection)');
            $table->integer('setup_completed_at')->default(0)
                ->comment('Unix timestamp when setup was last completed (0 = never)');
            $table->integer('created_at')
                ->comment('Unix timestamp when this row was first created');
            $table->integer('updated_at')
                ->comment('Unix timestamp of the most recent update');

            $table->primary(['userid']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.user_twofactor');
    }
}
