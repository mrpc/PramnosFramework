<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates experiments and experiment_assignments: A/B tests defined in the administration — who
 * is in them, the variants and their shares, the goal — and which variant each person or visitor
 * was given, for good.
 *
 * An assignment is kept rather than recomputed so a person sees the same variant every time, and
 * so a visitor's carries over to their account when they sign up. Times are Unix seconds.
 */
class CreateExperimentsTables extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 247;
    public $description = 'Creates experiments and experiment_assignments, the A/B tests';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('experiments')) {
            $schema->createTable('experiments', function ($table) {
                $table->comment('A/B tests: who, the variants and their shares, the goal, the winner');
                $table->increments('experimentid')->comment('Auto-increment experiment identifier');
                $table->string('slug', 64)->comment('What the site code asks for: variant(\'slug\')');
                $table->string('name', 150)->comment('Shown in the administration');
                $table->string('status', 16)->default('draft')->comment('draft, running or stopped');
                $table->integer('segment')->unsigned()->nullable()->comment('segments.segmentid of who takes part; NULL for everybody');
                $table->text('variants')->comment('The variants as JSON: key, share, label');
                $table->string('goalevent', 100)->nullable()->comment('The person event that counts as success');
                $table->integer('goaldays')->default(7)->comment('Within how many days of being assigned');
                $table->string('winner', 32)->nullable()->comment('The variant everybody gets once decided');
                $table->bigInteger('owner')->nullable()->comment('users.userid of who made it');
                $table->unsignedInteger('startedat')->nullable()->comment('Unix time it started');
                $table->unsignedInteger('endedat')->nullable()->comment('Unix time it stopped');
                $table->unsignedInteger('createdat')->comment('Unix time it was made');
                $table->unique(['slug'], 'uq_experiments_slug');
            });
        }

        if (!$schema->hasTable('experiment_assignments')) {
            $schema->createTable('experiment_assignments', function ($table) {
                $table->comment('Which variant each person or visitor was given, for good');
                $table->bigIncrements('id')->comment('Auto-increment row identifier');
                $table->integer('experimentid')->unsigned()->comment('experiments.experimentid');
                $table->string('subject', 80)->comment('p:<userid> for a person, v:<visitor id> before signing up');
                $table->bigInteger('personid')->nullable()->comment('users.userid, once known');
                $table->string('variant', 32)->comment('The variant given');
                $table->unsignedInteger('assignedat')->comment('Unix time it was given');
                $table->unique(['experimentid', 'subject'], 'uq_experiment_assignments');
                $table->index(['experimentid', 'variant', 'personid'], 'idx_experiment_assignments_variant');
                $table->index(['personid'], 'idx_experiment_assignments_person');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('experiment_assignments');
        $schema->dropTableIfExists('experiments');
    }
}
