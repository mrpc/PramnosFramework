<?php

namespace Pramnos\Framework\Migrations\Llm;

use Pramnos\Database\Migration;

/**
 * What the person thought of an answer, on `chat_messages`: `rating` (1 good, -1 bad), an optional
 * `rating_note`, and `reviewed_at` once somebody has looked at a bad one.
 *
 * State, not history: the rating is what the person thinks now — rating again replaces it — and
 * `reviewed_at` says whether the answer is still in the review queue. Who reviewed it, and when, is
 * the audit log's (`ai.answer_reviewed`), not a column.
 */
class AddRatingToChatMessages extends Migration
{
    public string  $feature      = 'llm';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public $description  = 'Adds rating, rating_note and reviewed_at to chat_messages';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('chat_messages') || $schema->hasColumn('chat_messages', 'rating')) {
            return;
        }

        $schema->alterTable('chat_messages', function ($table) {
            $table->smallInteger('rating')->nullable();
            $table->string('rating_note', 500)->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('chat_messages', 'rating')) {
            $schema->alterTable('chat_messages', function ($table) {
                $table->dropColumn('rating');
                $table->dropColumn('rating_note');
                $table->dropColumn('rated_at');
                $table->dropColumn('reviewed_at');
            });
        }
    }
}
