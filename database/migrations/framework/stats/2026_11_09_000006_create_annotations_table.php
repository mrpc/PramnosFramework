<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * `stats.annotations`: what happened that explains a figure — a deploy, a price change, a
 * campaign — a moment or a span, drawn on the charts ({@see \Pramnos\Metrics\Annotations}).
 *
 * Why a table and not the changelog (rule 14): an annotation is the business's own note on its
 * figures, written by people as much as by the application, edited and taken away; it is read by
 * time range across every metric and kept for as long as the figures are — the changelog's events
 * are kept two years and are read per record.
 */
class CreateAnnotationsTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 66;
    public array  $dependencies = [];
    public $description = 'Creates stats.annotations, the notes drawn on the metrics\' charts';

    /** Create it. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('stats');
        if ($schema->hasTable('stats.annotations')) {
            return;
        }
        $schema->createTable('stats.annotations', function ($table) {
            $table->comment('Notes drawn on the metrics\' charts: deploys, price changes, campaigns');
            $table->increments('annotationid');
            $table->integer('at')->comment('When (unix)');
            $table->integer('until')->nullable()->comment('Till when, for a span (unix); null: a moment');
            $table->bigInteger('tenant')->default(0)->comment('The organisation it concerns; 0: every one');
            $table->string('kind', 20)->default('note')->comment('note, deploy, campaign, price, …');
            $table->string('words', 250);
            $table->string('link', 500)->nullable();
            $table->integer('createdby')->nullable();
            $table->index(['at'], 'idx_annotations_at');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('stats.annotations');
    }
}
