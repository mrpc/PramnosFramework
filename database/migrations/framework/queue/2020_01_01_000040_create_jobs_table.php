<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Creates the jobs table for the background job queue.
 *
 * @package PramnosFramework
 */
class CreateJobsTable extends Migration
{
    public string  $feature      = 'queue';
    public string  $scope        = 'framework';
    public int     $priority     = 10;
    public string  $description  = 'Creates the jobs table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('jobs')) {
            return;
        }

        $schema->createTable('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue', 255)->default('default');
            $table->text('payload');
            $table->integer('attempts')->unsigned()->default(0);
            $table->integer('reserved_at')->unsigned()->nullable();
            $table->integer('available_at')->unsigned();
            $table->integer('created_at')->unsigned();
            $table->index(['queue', 'reserved_at', 'available_at'], 'idx_jobs_queue');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('jobs');
    }
}
