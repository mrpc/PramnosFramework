<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\DeferredWriteQueue;
use Pramnos\Database\Migration;

/**
 * Creates the table that holds writes which arrived too late for their chunk.
 *
 * A hypertable with a compression policy refuses inserts into ranges it has
 * already compressed, so a late row — a delayed reading, a backfill, a webhook
 * that turns up months after the event — is simply lost. {@see DeferredWriteQueue}
 * puts it here instead, and `timescale:drain` writes it later, grouping the
 * expensive decompress/compress pair once per chunk rather than once per row.
 *
 * The table is created on every backend, not only on TimescaleDB. Nothing ever
 * defers a write where there is no compression policy, so on MySQL and on a
 * development box without the extension this stays empty — but the write path
 * is then the same code everywhere, which is the only way an application can
 * use it without branching on the backend it happens to be running against.
 *
 * Current-date timestamp (§9) so it runs on installations whose
 * migration_cutoff skips the 2020_01_01 baseline.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class CreateDeferredwritesTable extends Migration
{
    /** @var string The feature that owns this table */
    public string $feature = 'core';

    /** @var string Framework-level migration */
    public string $scope = 'framework';

    /** @var int Runs after the core tables it does not depend on */
    public int $priority = 220;

    /** @var string What this migration does */
    public $description = 'Creates the deferredwrites queue for late writes into compressed chunks';

    /**
     * Creates the queue table and its partial "pending work" index.
     *
     * @return void
     */
    public function up(): void
    {
        $schema = $this->schema();

        if ($schema->hasTable(DeferredWriteQueue::TABLE)) {
            return;
        }

        $schema->createTable(DeferredWriteQueue::TABLE, function ($table) {
            $table->comment(
                'Rows that could not be written into an already-compressed chunk. '
                . 'Drained by the timescale:drain command.'
            );

            $table->bigIncrements('id')
                ->comment('Queue row identifier');
            $table->string('tablename', 100)
                ->comment('Logical name of the target table, as declared in HypertableRegistry');
            $table->timestamp('targetdate')
                ->comment('The row\'s own time — what decides which chunk it belongs to');
            $table->json('data')
                ->comment('The row itself, as a column => value JSON object');
            $table->smallInteger('status')->default(DeferredWriteQueue::STATUS_PENDING)
                ->comment('0 = waiting, 2 = tried and failed (kept for inspection)');
            $table->timestamp('createdat')->useCurrent()
                ->comment('When the row was queued');
            $table->timestamp('processedat')->nullable()
                ->comment('When it was last attempted; null while it is still waiting');
            $table->text('errormessage')->nullable()
                ->comment('Why the last attempt failed, truncated to 500 characters');

            // The drain only ever asks for pending rows, in table and time
            // order. A full index would also carry every failed row, which is
            // the part that never gets read.
            $table->index(
                ['status', 'tablename', 'targetdate'],
                'idx_deferredwrites_pending'
            );
        });
    }

    /**
     * Drops the queue table.
     *
     * @return void
     */
    public function down(): void
    {
        $this->schema()->dropTableIfExists(DeferredWriteQueue::TABLE);
    }
}
