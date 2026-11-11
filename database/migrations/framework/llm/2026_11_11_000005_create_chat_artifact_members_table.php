<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `chat_artifact_members`: who else shares an artifact live, and whether they may change it.
 *
 * State, not history: a row is a member now, deleted when they are taken off or the artifact is
 * deleted. A member reads the one artifact, not a copy — every change reaches them — and one whose
 * `role` is `edit` changes it too. A row with `userid` null and an `organization_id` makes every
 * member of that organisation one.
 */
class CreateChatArtifactMembersTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates chat_artifact_members';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('assist');

        if ($schema->hasTable('assist.chat_artifact_members')) {
            return;
        }

        $schema->createTable('assist.chat_artifact_members', function ($table) {
            $table->increments('member_id');
            $table->integer('artifact_id');
            $table->integer('userid')->nullable();
            $table->integer('organization_id')->nullable();
            $table->string('role', 8)->default('read');
            $table->timestamps();
            $table->index(['artifact_id']);
            $table->index(['userid']);
            $table->index(['organization_id']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('assist.chat_artifact_members');
    }
}
