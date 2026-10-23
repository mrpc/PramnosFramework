<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * `impersonations` — somebody signed in as somebody else right now: who, as whom, until when, and
 * the hash of the one-time key that brings the first back without a password. A row lives while
 * the way back is open; what happened is the audit log's.
 */
class CreateImpersonationsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_users_table'];
    public $description  = 'Creates impersonations, the ways back from signing in as another account that are still open';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('impersonations')) {
            return;
        }

        $schema->createTable('impersonations', function ($table) {
            $table->comment('Signed in as another account now: who, as whom, and the way back while it is open');
            $table->increments('id');
            $table->bigInteger('actor_userid')->comment('Who signed in as somebody else');
            $table->bigInteger('target_userid')->comment('As whom');
            $table->string('return_hash', 64)->comment('sha256 of the one-time key, held only in the impersonated session');
            $table->boolean('remember')->default(false)->comment('Whether the actor had a remembered sign-in to restore');
            $table->integer('created_at');
            $table->integer('expires_at');
            $table->index(['actor_userid'], 'idx_impersonations_actor');
            $table->index(['target_userid'], 'idx_impersonations_target');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('impersonations');
    }
}
