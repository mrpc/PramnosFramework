<?php

namespace Pramnos\Framework\Migrations\Stats;

use Pramnos\Database\Migration;

/**
 * The hour's table, `stats.metric_values_hour`, for an installation whose grains' tables were made
 * before metrics could be kept by the hour; the view takes it in. {@see \Pramnos\Metrics\Store::ensure()}
 * makes only what is missing, so on a new installation this finds it all there.
 */
class AddHourGrainToMetricValues extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 63;
    public array  $dependencies = [];
    public $description = 'Adds stats.metric_values_hour, the values of metrics kept by the hour';

    /** Make what is missing: the hour's table, and the view again. */
    public function up(): void
    {
        \Pramnos\Metrics\Store::ensure($this->application->database);
    }

    /** Drop the hour's table, and the view that reads it (it depends on it); the next ensure makes both again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropView(\Pramnos\Metrics\Store::VIEW);
        $schema->dropTableIfExists(\Pramnos\Metrics\Store::table('hour'));
    }
}
