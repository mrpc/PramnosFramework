<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Creates the queueitems table — the background job queue.
 *
 * Stores pending, processing, and completed background tasks. Workers claim
 * tasks by atomically setting status to 'processing' and recording their
 * identity in lockedby. Lock expiry (lockexpires) allows stalled workers to
 * be detected and their tasks reclaimed.
 *
 * Status values (PostgreSQL ENUM / MySQL TINYINT):
 *   pending    (0) — ready to be claimed by a worker
 *   processing (1) — currently held by a worker
 *   completed  (2) — finished successfully
 *   failed     (3) — exhausted all retry attempts
 *   warning    (4) — completed with non-fatal issues
 *
 * The task_hash column enables deduplication: a task with the same hash that
 * is already pending or processing will be rejected by QueueManager::addTask()
 * when $unique=true.
 *
 * @package PramnosFramework
 */
class CreateQueueitemsTable extends Migration
{
    public string  $feature      = 'queue';
    public string  $scope        = 'framework';
    public int     $priority     = 10;
    public $description  = 'Creates the queueitems background job table';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        $caps   = $schema->getCapabilities();

        if ($schema->hasTable('queueitems')) {
            return;
        }

        // PostgreSQL: create the queue_status ENUM type before the table
        if ($caps->isPostgreSQL()) {
            $this->application->database->query(
                "DO \$\$
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'queue_status') THEN
                        CREATE TYPE queue_status AS ENUM ('pending','processing','completed','failed','warning');
                    END IF;
                END
                \$\$"
            );
        }

        $schema->createTable('queueitems', function ($table) use ($caps) {
            $table->comment('Background job queue — workers claim tasks by setting status=processing and recording lockedby');

            $table->bigIncrements('taskid')
                ->comment('Auto-increment task identifier (BIGSERIAL on PostgreSQL)');
            $table->string('type', 50)
                ->comment('Task type name — maps to a registered TaskInterface handler class');
            $table->json('payload')
                ->comment('Task input data as JSON — decoded and passed to TaskInterface::execute()');

            // Status column: native ENUM on PostgreSQL, TINYINT on MySQL
            if ($caps->isPostgreSQL()) {
                $table->string('status', 20)->default('pending')
                    ->comment('Task lifecycle state: pending | processing | completed | failed | warning');
            } else {
                $table->tinyInteger('status')->default(0)
                    ->comment('Task lifecycle state: 0=pending, 1=processing, 2=completed, 3=failed, 4=warning');
            }

            $table->smallInteger('priority')->default(10)
                ->comment('Dispatch priority — lower number = higher priority; tasks with priority <=10 are treated as urgent');
            $table->integer('attempts')->default(0)
                ->comment('Number of execution attempts so far (incremented each time a worker claims the task)');
            $table->integer('maxattempts')->default(3)
                ->comment('Maximum number of attempts before the task is permanently marked as failed');
            $table->timestamp('createdat')->useCurrent()
                ->comment('Timestamp when the task was enqueued');
            $table->timestamp('updatedat')->nullable()
                ->comment('Timestamp of the last status change');
            $table->timestamp('startedat')->nullable()
                ->comment('Timestamp when the most recent worker claimed the task');
            $table->timestamp('completedat')->nullable()
                ->comment('Timestamp when the task reached a terminal state (completed/failed/warning)');
            $table->text('error')->nullable()
                ->comment('Error message or exception details from the most recent failed attempt');
            $table->string('lockedby', 100)->nullable()
                ->comment('Identity of the worker holding this task: "hostname:workerid" or "hostname:pid"');
            $table->timestamp('lockexpires')->nullable()
                ->comment('Timestamp after which the lock is considered stale and another worker may reclaim the task');
            $table->string('task_hash', 64)->nullable()
                ->comment('SHA-256 hash of type+payload for deduplication — addTask($unique=true) rejects duplicates with same hash');
            $table->decimal('execution_time', 10, 3)->nullable()
                ->comment('Wall-clock execution time in seconds (set by worker on completion)');
            $table->text('success_message')->nullable()
                ->comment('Human-readable success or warning message returned by TaskInterface::execute()');

            $table->index(['status', 'priority', 'createdat'], 'idx_queueitems_status_priority_created');
            $table->index(['type'], 'idx_queueitems_type');
            $table->index(['lockedby', 'lockexpires'], 'idx_queueitems_locked');
            $table->index(['task_hash'], 'idx_queueitems_task_hash');
            $table->index(['status', 'lockexpires', 'attempts', 'maxattempts'], 'idx_queueitems_processing_stalled');
        });

        // PostgreSQL: add CHECK constraint to enforce the ENUM type on the status column
        if ($caps->isPostgreSQL()) {
            $this->application->database->query(
                "ALTER TABLE \"queueitems\" ADD CONSTRAINT chk_queueitems_status
                 CHECK (\"status\" IN ('pending','processing','completed','failed','warning'))"
            );
        }
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('queueitems');

        if ($this->application->database->schema()->getCapabilities()->isPostgreSQL()) {
            $this->application->database->query(
                "DROP TYPE IF EXISTS queue_status"
            );
        }
    }
}
