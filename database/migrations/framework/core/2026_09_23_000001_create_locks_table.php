<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;
use Pramnos\Database\SharedLock;

/**
 * The table behind {@see SharedLock} — a mutual exclusion that holds across servers.
 *
 * The framework's existing lock is a file (`Pramnos\Console\WorkerLock`), and it says what
 * it is: it records the holder's `host` and checks the holder's pid *on the same host*. On
 * one machine that is right and cheap. On two web servers it excludes nothing — each has
 * its own `var/` and its own `sys_get_temp_dir()` — so every scheduled task marked
 * `withoutOverlapping()` runs once per node. Nothing fails; the work happens twice.
 *
 * A database row is the smallest thing every installation already has. A shared cache is
 * the usual answer and is optional here: the default adapter is local files, which is a
 * lock that excludes nothing in the same way and just as quietly.
 *
 * ## The primary key is the lock
 *
 * `name` is the key, so an `INSERT` of a name already present is refused by the engine
 * rather than by a check the caller makes — there is no window between looking and taking.
 *
 * ## `expiresat` is a lease, not a promise
 *
 * A holder that dies never releases, so every lock carries an expiry and is taken over by a
 * guarded `UPDATE` afterwards. Integer seconds rather than a timestamp type, deliberately:
 * the comparison is against `time()` in PHP, so both sides come from one clock and neither
 * engine's timezone handling can enter into it.
 */
class CreateLocksTable extends Migration
{
    public string $feature      = 'core';
    public string $scope        = 'framework';

    /** After the schema exists, like every other `pramnos.*` table. */
    public int    $priority     = 225;

    public array  $dependencies = ['create_pramnos_schema'];

    public $description = 'Creates pramnos.locks, the cross-server mutual exclusion SharedLock uses';

    public function up(): void
    {
        $schema = $this->schema();

        // Declared as a dependency too, but a test that loads one feature's directory runs
        // it without going through the runner. A no-op on MySQL and when already there.
        $schema->ensureSchema('pramnos');

        if ($schema->hasTable(SharedLock::TABLE)) {
            return;
        }

        $schema->createTable(SharedLock::TABLE, function ($table) {
            $table->comment(
                'Cross-server locks. One row per held lock; the primary key is the exclusion.'
            );

            $table->string('name', 191)
                ->comment('What is being protected — the caller names it')
                ->primary();
            $table->string('owner', 191)
                ->comment('host:pid of the holder, for a human reading the table');
            $table->integer('acquiredat')->unsigned()
                ->comment('Unix time the lock was taken');
            $table->integer('expiresat')->unsigned()
                ->comment('Unix time after which another process may take it over');

            // Nothing queries by expiry today — takeover is always by name — so there is
            // no index on it. A sweep of abandoned rows would want one; it can add it then.
        });
    }

    /**
     * Drops the table.
     *
     * Safe in a way most `down()`s are not: a lock row is worth nothing once its lease has
     * passed, and every one of them has a lease.
     */
    public function down(): void
    {
        $this->schema()->dropTableIfExists(SharedLock::TABLE);
    }
}
