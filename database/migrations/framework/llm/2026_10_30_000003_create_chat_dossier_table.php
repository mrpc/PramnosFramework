<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_dossier`: what the assistant holds true about a person that matters too much to change on
 * its own word — who they are, what they value, the people in their life, what they are working
 * towards — and how they feel lately, when they agreed to that being kept.
 *
 * State, not history: a row is the entry as it stands now, plus `pending`, a change the model
 * proposed and the person has not yet confirmed. Each confirmed change raises `version`, and its
 * before and after go to `changelog_events` (`ChangelogWriter::event('chat_dossier', …)`), which
 * keeps them two years — so the versions are read from the log, and this table never grows a row
 * per change. Beside `chat_memory`, in the application's schema, with the same scope columns.
 */
class CreateChatDossierTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates chat_dossier';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('chat_dossier')) {
            return;
        }

        $schema->createTable('chat_dossier', function ($table) {
            $table->increments('dossier_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid');
            $table->string('space', 32)->default('chat');
            $table->string('area', 24);
            $table->string('subject', 100)->nullable();
            $table->text('content')->nullable();
            $table->text('pending')->nullable();
            $table->integer('version')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'userid', 'space', 'area']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('chat_dossier');
    }
}
