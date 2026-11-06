<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * Reports people build from metrics ({@see \Pramnos\Metrics\Reports}): their definition, who sees
 * them, and where they are mailed.
 *
 * State, not history: a row is a report that exists now and goes when it is deleted.
 * `sent_period` is the last period mailed — what the next mailing compares with, not a record of
 * sendings, which the mail log keeps. In `stats` beside the metrics' values they are built on.
 */
class CreateReportsTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 63;
    public array  $dependencies = [];
    public $description = 'Creates stats.reports, the reports people build from metrics';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('stats');
        if ($schema->hasTable('stats.reports')) {
            return;
        }

        $schema->createTable('stats.reports', function ($table) {
            $table->comment('Reports built from metrics, saved and shared');
            $table->increments('reportid');
            $table->string('name', 120);
            $table->text('definition')->comment('The report as JSON: grain, columns, compare, sections');
            $table->integer('ownerid')->comment('Who saved it');
            $table->string('visibility', 16)->default('private')->comment('private, role, organization or everyone');
            $table->string('audience', 64)->nullable()->comment('The role or the organisation id it is shared with');
            $table->string('schedule', 8)->default('')->comment('Mailed after each closed day, week or month; empty for never');
            $table->text('recipients')->nullable()->comment('Email addresses it is mailed to, comma-separated');
            $table->string('sent_period', 10)->default('')->comment('The first day of the last period mailed');
            $table->integer('created_at')->default(0);
            $table->integer('updated_at')->default(0);
            $table->index(['ownerid'], 'idx_reports_owner');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('stats.reports');
    }
}
