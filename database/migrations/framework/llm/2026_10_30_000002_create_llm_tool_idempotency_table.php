<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * `pramnos.llm_tool_idempotency` — the changes an answer has made, so the same one is not made
 * twice ({@see \Pramnos\Llm\Idempotency}).
 *
 * A row per change a tool that writes made: the key is the answer, the tool and its arguments,
 * taken by an insert before the tool runs, so the primary key is the whole of the exclusion. The
 * result is kept beside it, to tell the model what the change returned the first time. A failed
 * run gives the key back; `expires_at` is when it may be taken again.
 *
 * State, not history: what is true is "this change was made in this answer", for as long as the
 * answer could be run again. The logs cannot hold it, because the insert has to fail on a key
 * already there — a log takes every row — and the row is gone a day later.
 */
class CreateLlmToolIdempotencyTable extends Migration
{
    public string $feature      = 'llm';
    public string $scope        = 'framework';
    public int    $priority     = 50;
    public array  $dependencies = [];
    public $description = 'Creates pramnos.llm_tool_idempotency, the changes an answer has made';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pramnos.llm_tool_idempotency')) {
            return;
        }

        $schema->createTable('pramnos.llm_tool_idempotency', function ($table) {
            $table->comment('Changes an answer has made, so the same one is not made twice');
            $table->string('idempotency_key', 64)->comment('sha256 of the answer, the tool and its arguments');
            $table->string('tool', 64)->comment('The tool\'s name');
            $table->mediumText('result')->nullable()->comment('What the change returned; null while it runs');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable()->comment('When the same change may be made again');
            $table->primary(['idempotency_key']);
            $table->index(['expires_at'], 'idx_llm_tool_idempotency_expires');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.llm_tool_idempotency');
    }
}
