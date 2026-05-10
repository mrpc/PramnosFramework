<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Creates the loginlockout table — progressive brute-force lockout state store.
 *
 * Tracks failed login attempts per (locktype, lookupvalue) pair. Three lock types
 * are supported: 'user' (by user ID), 'identifier' (by normalised username/email),
 * and 'ip' (by IP address). Rows are keyed by a unique composite index so that a
 * single upsert-by-lookup pattern works across all supported databases.
 *
 * Timestamps are stored as Unix integers to avoid timezone ambiguity between
 * MySQL and PostgreSQL. A sliding window (default 900 s) resets the counter when
 * the gap between failures is large; lockoutuntil = 0 means no active lockout.
 *
 * @package PramnosFramework
 */
class CreateLoginlockoutTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 70;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.loginlockouts progressive brute-force state table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.loginlockouts')) {
            return;
        }

        $schema->createTable('authserver.loginlockouts', function ($table) {
            $table->comment('Progressive brute-force lockout state: tracks failed login attempts per scope+identifier pair');

            $table->increments('lockoutid')
                ->comment('Auto-increment record identifier');
            $table->string('locktype', 20)
                ->comment('Lock scope: user | identifier | ip');
            $table->string('lookupvalue', 255)
                ->comment('The scope-specific identifier being tracked (user ID string, normalised email, or IP address)');
            $table->integer('failedattempts')->default(0)
                ->comment('Number of failed attempts within the current sliding window');
            $table->integer('firstfailedat')->default(0)
                ->comment('Unix timestamp of the first failure in the current window (0 = no attempts)');
            $table->integer('lastfailedat')->default(0)
                ->comment('Unix timestamp of the most recent failure (0 = no attempts)');
            $table->integer('lockoutuntil')->default(0)
                ->comment('Unix timestamp when the lockout expires (0 = no active lockout)');
            $table->integer('createdat')
                ->comment('Unix timestamp when this row was first created');
            $table->integer('updatedat')
                ->comment('Unix timestamp of the last update to this row');

            $table->unique(['locktype', 'lookupvalue'], 'uq_loginlockout_type_value');
            $table->index(['lockoutuntil'], 'idx_loginlockout_until');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.loginlockouts');
    }
}
