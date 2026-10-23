<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Remembers what a queued listener has already done ({@see \Pramnos\Event\QueuedListener::idempotencyKey()}).
 *
 * One row per key — `welcome-email:42` — taken by an insert just before the listener runs, so the
 * primary key is the whole of the exclusion: a second event, or a second worker, finds it taken.
 * A failed run gives it back. `expiresat` is when the key may be used again.
 */
class CreateQueueIdempotencyTable extends Migration
{
    public string $feature     = 'queue';
    public string $scope       = 'framework';
    public int    $priority    = 11;
    public array  $dependencies = ['create_queueitems_table'];
    public $description = 'Creates queue_idempotency, the keys of work a queued listener has done';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pramnos.queue_idempotency')) {
            return;
        }

        $schema->createTable('pramnos.queue_idempotency', function ($table) {
            $table->comment('Keys of work queued listeners have done, so it is not done twice');
            $table->string('idempotencykey', 191)
                ->comment('The listener\'s key, e.g. welcome-email:42');
            $table->bigInteger('taskid')->nullable()
                ->comment('The queueitems task that took it');
            $table->timestamp('createdat')->nullable()
                ->comment('When it was taken');
            $table->timestamp('expiresat')->nullable()
                ->comment('When the same work may be done again');
            $table->primary(['idempotencykey']);
            $table->index(['expiresat'], 'idx_queue_idempotency_expires');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.queue_idempotency');
    }
}
