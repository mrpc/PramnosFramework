<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Creates the job_batches table for tracking batch job state.
 *
 * @package PramnosFramework
 */
class CreateJobBatchesTable extends Migration
{
    public string  $feature      = 'queue';
    public string  $scope        = 'framework';
    public int     $priority     = 30;
    public array   $dependencies = ['create_jobs_table'];
    public string  $description  = 'Creates the job_batches table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('job_batches')) {
            return;
        }

        $schema->createTable('job_batches', function ($table) {
            $table->string('id', 36)->primary();
            $table->string('name', 255);
            $table->integer('total_jobs')->unsigned()->default(0);
            $table->integer('pending_jobs')->unsigned()->default(0);
            $table->integer('failed_jobs')->unsigned()->default(0);
            $table->text('failed_job_ids');
            $table->text('options')->nullable();
            $table->integer('cancelled_at')->unsigned()->nullable();
            $table->integer('created_at')->unsigned();
            $table->integer('finished_at')->unsigned()->nullable();
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('job_batches');
    }
}
