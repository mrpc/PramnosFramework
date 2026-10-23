<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * `remember_tokens` — the credential of each signed-in browser: what the `auth` cookie and
 * `$_SESSION['auth']` carry, in place of the password hash they carried before.
 *
 * One row per sign-in and device. The cookie holds `selector.validator`; the row keeps the
 * selector to find it and only a hash of the validator, so the table alone signs nobody in. Each
 * row expires, is revoked by signing out, and stops working when the password changes — the
 * `password_check` it was issued with no longer matches.
 */
class CreateRememberTokensTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_users_table'];
    public $description  = 'Creates remember_tokens, the per-device sign-in credential';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('authserver.remember_tokens')) {
            return;
        }

        $schema->createTable('authserver.remember_tokens', function ($table) {
            $table->comment('Per-device sign-in credential: the auth cookie, hashed, with expiry');
            $table->increments('id');
            $table->bigInteger('userid')->comment('The account it signs in');
            $table->string('selector', 24)->comment('Finds the row: the part of the cookie before the dot');
            $table->string('validator_hash', 64)->comment('sha256 of the part after the dot; the cookie itself is never stored');
            $table->string('password_check', 64)->comment('sha256 of the password hash it was issued under: a changed password ends it');
            $table->integer('created_at');
            $table->integer('expires_at');
            $table->integer('last_used_at')->nullable();
            $table->string('user_agent', 255)->default('');
            $table->unique(['selector'], 'uniq_remember_tokens_selector');
            $table->index(['userid'], 'idx_remember_tokens_userid');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.remember_tokens');
    }
}
