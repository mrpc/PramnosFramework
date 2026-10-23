<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Creates the event stream: `event_log`, `event_consumers` and `event_deliveries`.
 *
 * With `'events' => ['transport' => 'stream']`, a fired event is written **once** to `event_log`
 * — in the caller's transaction, like a queue task — with the consumer groups it is for; each
 * queued listener is a group that reads the log from its own position (`event_consumers`). What
 * failed waits in `event_deliveries` to be tried again, and past its attempts is kept there as
 * dead. One row per event, however many listeners, instead of one task per listener.
 *
 * `event_log` holds what the event was fired with — nothing more — and is declared personal data,
 * so a diagnostic tool returns none of its rows. It keeps `events.log_days` (30) and never what a
 * group has not read yet. Times are Unix seconds.
 */
class CreateEventLogTables extends Migration
{
    public string $feature     = 'queue';
    public string $scope       = 'framework';
    public int    $priority    = 12;
    public array  $dependencies = ['create_queue_idempotency_table'];
    public $description = 'Creates event_log, event_consumers and event_deliveries, the event stream';

    /** Create the tables. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('pramnos.event_log')) {
            $schema->createTable('pramnos.event_log', function ($table) {
                $table->comment('Each fired event once, for the consumer groups it is for');
                $table->bigIncrements('entryid')->comment('The position in the stream');
                $table->string('event', 150)->comment('The event, by name');
                $table->string('eventid', 64)->comment('The firing\'s id, as its meta says');
                $table->text('payload')->comment('Arguments, which of them are event objects, and meta, as JSON');
                $table->text('groups')->comment('The consumer groups it is for, as a JSON list');
                $table->unsignedInteger('createdat')->comment('Unix time it was written');
                $table->index(['createdat'], 'idx_event_log_created');
            });
        }

        if (!$schema->hasTable('pramnos.event_consumers')) {
            $schema->createTable('pramnos.event_consumers', function ($table) {
                $table->comment('Each consumer group\'s position in the stream');
                $table->string('groupname', 191)->comment('The group: a queued listener\'s class');
                $table->bigInteger('position')->default(0)->comment('The last entry it has read');
                $table->string('owner', 100)->nullable()->comment('The consumer holding it');
                $table->unsignedInteger('leaseduntil')->default(0)->comment('Until when that consumer holds it');
                $table->unsignedInteger('updatedat')->default(0)->comment('When it last moved');
                $table->primary(['groupname']);
            });
        }

        if (!$schema->hasTable('pramnos.event_deliveries')) {
            $schema->createTable('pramnos.event_deliveries', function ($table) {
                $table->comment('Entries a group failed, to be tried again, or dead past their attempts');
                $table->bigIncrements('id')->comment('Auto-increment row identifier');
                $table->string('groupname', 191)->comment('The consumer group');
                $table->bigInteger('entryid')->comment('event_log.entryid');
                $table->integer('attempts')->default(0)->comment('How many times it was tried');
                $table->string('status', 10)->default('pending')->comment('pending | dead');
                $table->unsignedInteger('nextat')->default(0)->comment('When it is tried again');
                $table->text('error')->nullable()->comment('The last failure');
                $table->unsignedInteger('createdat')->comment('When it first failed');
                $table->unique(['groupname', 'entryid'], 'uq_event_deliveries_entry');
                $table->index(['groupname', 'status', 'nextat'], 'idx_event_deliveries_due');
            });
        }
    }

    /** Drop them. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('pramnos.event_deliveries');
        $schema->dropTableIfExists('pramnos.event_consumers');
        $schema->dropTableIfExists('pramnos.event_log');
    }
}
