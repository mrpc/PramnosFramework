<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * Lets `pramnos.chat_skills` hold a skill installed from a catalogue for the whole application
 * ({@see \Pramnos\Llm\Chat\CatalogSkills}), kept in the database so a deploy does not take it
 * away: where it came from (`source`, `source_commit`), the hash of what was taken, the licence
 * named for it, and its files — references and evals — as JSON. State, as the rest of the row:
 * who installed, enabled or updated which commit is the audit log's.
 */
class AddCatalogueColumnsToChatSkills extends Migration
{
    public string $feature      = 'llm';
    public string $scope        = 'framework';
    public int    $priority     = 53;
    public array  $dependencies = [];
    public $description = 'Adds the columns of a skill installed from a catalogue to chat_skills';

    /** The columns, where the table has none of them yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.chat_skills') || $schema->hasColumn('pramnos.chat_skills', 'source')) {
            return;
        }
        $schema->table('pramnos.chat_skills', function ($table) {
            $table->string('source', 255)->nullable()->comment('A catalogue skill: owner/repo/path it was taken from');
            $table->string('source_commit', 40)->nullable()->comment('The commit it was taken at');
            $table->string('content_hash', 64)->nullable()->comment('sha256 of what was taken');
            $table->string('licence', 64)->nullable()->comment('The licence its repository names, as an SPDX id');
            // Long: a published skill is up to 200 files of 256 KB, past what MySQL keeps in TEXT.
            $table->longText('files')->nullable()->comment('What was taken: JSON {skill: SKILL.md body, references: {file: text}, evals: {file: text}}');
        });
    }

    /** Remove them again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.chat_skills') || !$schema->hasColumn('pramnos.chat_skills', 'source')) {
            return;
        }
        $schema->table('pramnos.chat_skills', function ($table) {
            foreach (['source', 'source_commit', 'content_hash', 'licence', 'files'] as $column) {
                $table->dropColumn($column);
            }
        });
    }
}
