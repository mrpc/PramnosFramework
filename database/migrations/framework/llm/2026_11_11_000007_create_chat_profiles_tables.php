<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_profiles` and `chat_profile_locks`: the people of an account — a family's children, the
 * other parent — who talk to the companion under it, and the devices locked to one of them.
 *
 * State, not history. A profile is a row while it stands, deleted with what it said. A lock is a
 * row while a session is held to a child's profile, deleted when it is unlocked; `key_hash` is a
 * hash of the session's or access token's key, never the key.
 */
class CreateChatProfilesTables extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 52;
    public $description  = 'Creates chat_profiles and chat_profile_locks';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('assist');

        if (!$schema->hasTable('assist.chat_profiles')) {
            $schema->createTable('assist.chat_profiles', function ($table) {
                $table->increments('profile_id');
                $table->integer('userid');
                $table->string('name', 60);
                $table->string('kind', 32)->default('child');
                $table->integer('birth_year')->nullable();
                $table->string('image', 255)->nullable();
                $table->timestamps();
                $table->index(['userid']);
            });
        }
        if (!$schema->hasTable('assist.chat_profile_locks')) {
            $schema->createTable('assist.chat_profile_locks', function ($table) {
                $table->increments('lock_id');
                $table->string('key_hash', 64);
                $table->integer('userid');
                $table->integer('profile_id');
                $table->timestamps();
                $table->unique(['key_hash']);
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('assist.chat_profile_locks');
        $schema->dropTableIfExists('assist.chat_profiles');
    }
}
