<?php

namespace Pramnos\Framework\Migrations\Metrics;

use Pramnos\Database\Migration;

/**
 * `pramnos.metric_declarations` — a metric defined on the screen (BI roadmap phase 7i,
 * {@see \Pramnos\Metrics\NoCodeMetrics}), over a table and columns the application already
 * allowed in `'metrics' => ['no_code' => ['tables' => [...]]]`.
 *
 * State, not history: a row is a live, editable definition — what an administrator's "paid
 * orders per store" means right now — not a record of a past event. Changing it changes what the
 * metric computes from then on, the same way a saved `query` artifact (FW-474) or a saved report
 * (`Reports`) is state kept with the account, never a log of edits.
 */
class CreateMetricDeclarationsTable extends Migration
{
    public string $feature      = 'metrics';
    public string $scope        = 'framework';
    public int    $priority     = 50;
    public array  $dependencies = [];
    public $description = 'Creates pramnos.metric_declarations, a metric defined on the screen from allowed tables and columns';

    /** Create the table. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pramnos.metric_declarations')) {
            return;
        }

        $schema->createTable('pramnos.metric_declarations', function ($table) {
            $table->comment('A metric defined on the screen, over a table and columns the application allowed');
            $table->bigIncrements('declaration_id');
            $table->string('name', 100)->unique()->comment('The metric\'s name, as Metric::flow() takes it');
            $table->string('label', 200)->comment('Its label, shown wherever the metric is');
            $table->string('source_table', 100)->comment('The allowed table it reads');
            $table->string('time_column', 100)->comment('The allowed time column it is counted by');
            $table->boolean('time_unix')->default(false)->comment('Whether the time column holds unix seconds');
            $table->string('fn', 20)->comment('sum, count, countDistinct, avg, min or max');
            $table->string('value_column', 100)->nullable()->comment('The allowed column counted; null for count()');
            $table->text('where_json')->nullable()->comment('[{column, value}], each column allowed for the table');
            $table->text('dimensions_json')->nullable()->comment('[{name, column}], each column allowed for the table');
            $table->string('currency', 3)->nullable()->comment('ISO currency, or null for the application\'s own');
            $table->boolean('active')->default(true)->comment('Loaded into the catalogue while true; kept, not deleted, when turned off');
            $table->integer('created_by')->comment('Who defined it');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['active'], 'idx_metric_declarations_active');
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.metric_declarations');
    }
}
