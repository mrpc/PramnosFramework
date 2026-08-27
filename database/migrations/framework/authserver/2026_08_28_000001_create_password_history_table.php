<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates password_history — the previous password hashes an account may not reuse.
 *
 * A table of its own, and deliberately not `usersettings` or `userdetails`. Both of those
 * are readable on the administration screens, which is right for the switches an operator
 * has to see and wrong for password material: nobody needs to look at these, and a screen
 * that renders them is one more place a hash can be copied out of.
 *
 * Hashes only, in the same scheme the login verifies, so the comparison is
 * `PasswordHash::verify()` rather than anything new. Nothing here can be turned back into
 * a password, and the row is worth no more than the `users.password` it came from.
 *
 * The table exists whether or not an application uses the feature
 * (`auth.security.password_history`), because a migration that runs conditionally on a
 * config key is a migration that has not run on the day the key is turned on.
 */
class CreatePasswordHistoryTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 65;
    public array  $dependencies = ['create_authserver_schema', 'create_users_table'];
    public $description  = 'Creates the password_history table (previous hashes, for reuse checks)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.password_history')) {
            return;
        }

        $schema->createTable('authserver.password_history', function ($table) {
            $table->comment('Previous password hashes per account — checked to refuse reuse');

            $table->bigIncrements('id')->comment('Auto-increment primary key');
            $table->bigInteger('userid')
                ->comment('Owner (users.userid) — no hard FK (app-layer integrity)');
            $table->string('password_hash', 255)
                ->comment('A hash the account used before, in the scheme the login verifies');
            $table->integer('created_at')
                ->comment('Unix timestamp the hash was retired');

            $table->index(['userid', 'created_at'], 'idx_password_history_user');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()
            ->dropTableIfExists('authserver.password_history');
    }
}
