<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Console\DaemonControls;
use Pramnos\Database\Migration;

/**
 * The two tables behind the services screen's controls: pools, and stopped services.
 *
 * The screen used to control workers through sentinel files alone, and a sentinel cannot
 * say "stay stopped": a worker asked to stop exited, and the supervisor — which still had it
 * on its list — started it again on the next cycle. What an operator decides has to be
 * somewhere the supervisor reads every cycle, so it lives here.
 *
 * `worker_pools` holds a pool defined on the screen, or the screen's changes to a pool the
 * application declares in code. Every limit is nullable, and null means "as declared", so
 * changing one field of a code pool does not freeze the others. `stopped_services` holds
 * the services an operator stopped, until they start them again.
 */
class CreateWorkerControlTables extends Migration
{
    public string $feature      = 'core';
    public string $scope        = 'framework';

    /** After the schema exists, like every other `pramnos.*` table. */
    public int    $priority     = 225;

    public array  $dependencies = ['create_pramnos_schema'];

    public $description = 'Creates pramnos.worker_pools and pramnos.stopped_services for the services screen';

    public function up(): void
    {
        $schema = $this->schema();
        $schema->ensureSchema('pramnos');

        if (!$schema->hasTable(DaemonControls::POOLS_TABLE)) {
            $schema->createTable(DaemonControls::POOLS_TABLE, function ($table) {
                $table->comment('Queue worker pools defined or adjusted on the services screen');

                $table->string('name', 60)->primary()
                    ->comment('Pool name; matches a queuePool() declared in code, or stands alone');
                $table->string('types', 255)->nullable()
                    ->comment('Comma-separated task types; null = as declared (every type for a screen pool)');
                $table->integer('floor')->nullable()->comment('Minimum workers; null = as declared');
                $table->integer('ceiling')->nullable()->comment('Maximum workers; null = as declared');
                $table->integer('grow_above')->nullable()->comment('Backlog above which one is added');
                $table->integer('shrink_below')->nullable()->comment('Backlog below which one is removed');
                $table->smallInteger('load_percent')->nullable()
                    ->comment('Load per core, as a percentage, past which nothing is added');
                $table->integer('cooldown')->nullable()->comment('Cycles to wait after a change');
                $table->boolean('enabled')->default(true)
                    ->comment('False = stopped by an operator: the pool runs no workers');
                $table->integer('updated_at')->unsigned()->comment('Unix time of the last change');
                $table->string('updated_by', 191)->nullable()->comment('Who made it');
            });
        }

        if (!$schema->hasTable(DaemonControls::STOPPED_TABLE)) {
            $schema->createTable(DaemonControls::STOPPED_TABLE, function ($table) {
                $table->comment('Services an operator stopped; the supervisor does not start them');

                $table->string('id', 191)->primary()->comment('The service id in the orchestrator state');
                $table->integer('stopped_at')->unsigned()->comment('Unix time it was stopped');
                $table->string('stopped_by', 191)->nullable()->comment('Who stopped it');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->schema();
        $schema->dropTableIfExists(DaemonControls::POOLS_TABLE);
        $schema->dropTableIfExists(DaemonControls::STOPPED_TABLE);
    }
}
