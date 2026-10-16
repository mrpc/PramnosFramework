<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `ai_routines.audience` — who may receive a shared report, as its maker chose: everyone from a
 * usertype up, and named people. Null, what every existing row has, keeps the rule it had: a
 * reader of the maker's standing or more.
 */
class AddAudienceToAiRoutines extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description = 'Adds ai_routines.audience, who may receive a shared AI report';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('ai_routines') || $schema->hasColumn('ai_routines', 'audience')) {
            return;
        }

        $schema->table('ai_routines', fn ($table) => $table->text('audience')->nullable()
            ->comment('JSON {min_usertype, users}: who may receive a shared report; null for the maker\'s standing and up'));
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('ai_routines') && $schema->hasColumn('ai_routines', 'audience')) {
            $schema->table('ai_routines', fn ($table) => $table->dropColumn(['audience']));
        }
    }
}
