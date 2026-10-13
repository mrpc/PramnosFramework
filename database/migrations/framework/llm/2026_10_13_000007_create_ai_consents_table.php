<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `ai_consents` — who agreed to their data going to which provider. See `Policy\\StoredConsent`.
 *
 * A table that already exists — an application that kept these under the same name — is left
 * as it is. `organization_id` is nullable and has no foreign key: an application without
 * organisations writes none, and the framework does not assume one.
 */
class CreateAiConsentsTable extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Creates ai_consents';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('ai_consents')) {
            return;
        }

        $schema->createTable('ai_consents', function ($table) {
            $table->increments('consent_id');
            $table->integer('organization_id')->nullable();
            $table->integer('userid');
            $table->string('provider', 32);
            $table->timestamps();
            $table->index(['organization_id', 'userid', 'provider']);
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('ai_consents');
    }
}
