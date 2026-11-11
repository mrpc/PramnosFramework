<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_profiles.source` and `detached_at`: a profile the application gives — a child it already
 * keeps — linked by its own key (`child:123`), so it is made once and kept up to date, and marked
 * detached, not deleted, when the application no longer gives it.
 */
class AddSourceToChatProfiles extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 53;
    public $description  = 'Adds source and detached_at to chat_profiles';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('assist.chat_profiles') || $schema->hasColumn('assist.chat_profiles', 'source')) {
            return;
        }
        $schema->alterTable('assist.chat_profiles', function ($table) {
            $table->string('source', 64)->nullable();
            $table->timestamp('detached_at')->nullable();
            $table->unique(['userid', 'source'], 'chat_profiles_source_unique');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasColumn('assist.chat_profiles', 'source')) {
            $schema->alterTable('assist.chat_profiles', function ($table) {
                $table->dropUnique('chat_profiles_source_unique');
                $table->dropColumn('source');
                $table->dropColumn('detached_at');
            });
        }
    }
}
