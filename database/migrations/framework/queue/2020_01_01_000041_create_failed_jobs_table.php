<?php

namespace Pramnos\Framework\Migrations\Queue;

use Pramnos\Database\Migration;

/**
 * Creates the failed_jobs table for storing jobs that could not be processed.
 *
 * @package PramnosFramework
 */
class CreateFailedJobsTable extends Migration
{
    public string  $feature      = 'queue';
    public string  $scope        = 'framework';
    public int     $priority     = 20;
    public array   $dependencies = ['create_jobs_table'];
    public string  $description  = 'Creates the failed_jobs table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('failed_jobs')) {
            return;
        }

        $schema->createTable('failed_jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('uuid', 36)->unique();
            $table->string('connection', 255);
            $table->string('queue', 255);
            $table->text('payload');
            $table->text('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->index(['queue'], 'idx_failed_jobs_queue');
            $table->index(['failed_at'], 'idx_failed_jobs_failed_at');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('failed_jobs');
    }
}
