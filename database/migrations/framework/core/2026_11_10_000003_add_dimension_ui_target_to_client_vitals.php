<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Adds to `pramnos.client_vitals` what a measurement is read by beside its page group and device:
 * the application's own `dimension` (`vitals.dimension` — a market, an area), the page's `ui`
 * (`ClientVitals::tag(['ui' => …])`, to set one theme against another), and the `target` of an
 * LCP, INP or CLS — the element the attribution build names.
 */
class AddDimensionUiTargetToClientVitals extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 231;
    public $description = 'Adds dimension, ui and target to pramnos.client_vitals';

    /** Add them, where they are not there. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.client_vitals')) {
            return;
        }
        foreach (['dimension' => 20, 'ui' => 16, 'target' => 100] as $column => $length) {
            if (!$schema->hasColumn('pramnos.client_vitals', $column)) {
                $schema->table('pramnos.client_vitals', static function ($table) use ($column, $length): void {
                    $table->string($column, $length)->nullable();
                });
            }
        }
    }

    /** Take them away. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        foreach (['dimension', 'ui', 'target'] as $column) {
            if ($schema->hasTable('pramnos.client_vitals') && $schema->hasColumn('pramnos.client_vitals', $column)) {
                $schema->table('pramnos.client_vitals', static function ($table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
}
