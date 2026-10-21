<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Lets events with the same key become one task ({@see \Pramnos\Event\QueuedListener::debounceKey()}).
 *
 * `dedupekey` is the key a waiting task is found by; `absorbed` counts the events folded into it,
 * so the Queue screen can say why one task stands for six changes. Indexed on the key: an event
 * looks for its waiting task on every fire.
 */
class AddEventColumnsToQueueitems extends Migration
{
    public string  $feature     = 'queue';
    public string  $scope       = 'framework';
    public int     $priority    = 10;
    public $description = 'Adds queueitems.dedupekey and absorbed, for queued event listeners';

    /** Add both columns and the key's index, where the table has none of them yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || $schema->hasColumn('queueitems', 'dedupekey')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            $table->string('dedupekey', 191)->nullable()
                ->comment('Events with this key fold into one waiting task; null = no folding');
            $table->integer('absorbed')->default(0)
                ->comment('How many later events were folded into this task');
            $table->index(['dedupekey'], 'idx_queueitems_dedupekey');
        });
    }

    /** Remove them again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('queueitems') || !$schema->hasColumn('queueitems', 'dedupekey')) {
            return;
        }

        $schema->table('queueitems', function ($table) {
            // The key's index goes with its column, on both engines.
            $table->dropColumn('absorbed');
            $table->dropColumn('dedupekey');
        });
    }
}
