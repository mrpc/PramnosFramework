<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * Skills kept in the database ({@see \Pramnos\Llm\Chat\StoredSkills}): a role's, written by an
 * administrator, and a person's own — the same shape as a skill file (name, description, tools,
 * the method), so Ask turns them on the same way.
 *
 * State, not history: a row is a skill that exists now, and it goes when it is deleted. `status`
 * is what it is now — `on`, `off`, `draft` (proposed from a conversation, not yet kept by its
 * person) or `pending` (offered to a role, waiting for an administrator); who changed it and when
 * is the audit log's.
 */
class CreateChatSkillsTable extends Migration
{
    public string $feature      = 'llm';
    public string $scope        = 'framework';
    public int    $priority     = 52;
    public array  $dependencies = [];
    public $description = 'Creates chat_skills, the skills of a role and of a person';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('pramnos.chat_skills')) {
            return;
        }

        $schema->createTable('pramnos.chat_skills', function ($table) {
            $table->comment('Skills of a role and of a person, for Ask');
            $table->increments('skill_id');
            $table->integer('userid')->comment('Who wrote it: its owner, for a person\'s own');
            $table->string('level', 8)->comment('user (its owner\'s own) or role');
            $table->string('role', 64)->nullable()->comment('For a role\'s: the usertype or role name it is for, from that up');
            $table->string('name', 64)->comment('Its name, as use_skill takes it after the mine: or role: prefix');
            $table->string('description', 300)->comment('The line the model chooses it by');
            $table->text('tools')->comment('The tools it opens, as a JSON list of names');
            $table->text('body')->comment('The method, handed to the model when the skill is turned on');
            $table->string('status', 8)->default('on')->comment('on, off, draft or pending');
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
            $table->index(['level', 'status'], 'idx_chat_skills_level');
            $table->index(['userid'], 'idx_chat_skills_user');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.chat_skills');
    }
}
