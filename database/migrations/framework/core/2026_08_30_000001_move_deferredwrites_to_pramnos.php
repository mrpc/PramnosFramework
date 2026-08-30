<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\DeferredWriteQueue;
use Pramnos\Database\Migration;

/**
 * Moves the deferred-write queue out of `public` and into `pramnos`.
 *
 * `public` is the application's schema. `deferredwrites` is the framework's own bookkeeping —
 * nobody writing an application queries it, {@see DeferredWriteQueue} does — and a `\dt` that
 * lists it beside `users` and `mails` is a `\dt` that takes longer to read. It joins the push
 * tables and the email suppression and tracking tables, which moved for the same reason.
 *
 * ### Why a second migration rather than an edit to the first
 *
 * The push and email tables were created and moved on the same day, with nothing deployed from
 * them, so their own migrations were corrected in place. This one has been running since 12
 * August: an installation has the table, in `public`, with the creating migration marked
 * applied. Editing that file would change nothing there — the runner never revisits an applied
 * migration — so the move has to be its own step.
 *
 * ### It costs nothing to run
 *
 * `ALTER TABLE … SET SCHEMA` is a catalogue update; no row is copied and nothing is locked for
 * longer than the statement. The queue is drained continuously and holds few rows anyway.
 *
 * A no-op where the table is already in `pramnos` (a fresh installation, whose creating
 * migration now puts it there), and where there is no table at all.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class MoveDeferredwritesToPramnos extends Migration
{
    /** @var string The feature that owns this table */
    public string $feature = 'core';

    /** @var string Framework-level migration */
    public string $scope = 'framework';

    /** @var int After the table exists, and after the schema does */
    public int $priority = 230;

    /** @var array<int, string> */
    public array $dependencies = ['create_pramnos_schema', 'create_deferredwrites_table'];

    /** @var string What this migration does */
    public $description = 'Moves deferredwrites into the pramnos schema';

    public function up(): void
    {
        $schema = $this->schema();

        $schema->ensureSchema('pramnos');
        $schema->moveToSchema('deferredwrites', 'pramnos');
    }

    /**
     * Back to `public`.
     *
     * A rollback has to put the table where the migration before this one expects to find it,
     * or that migration's own `down()` drops nothing.
     */
    public function down(): void
    {
        $schema = $this->schema();

        if (!$schema->hasTable(DeferredWriteQueue::TABLE) || $schema->hasTable('deferredwrites')) {
            return;
        }

        $schema->moveToSchema(DeferredWriteQueue::TABLE, 'public');
    }
}
