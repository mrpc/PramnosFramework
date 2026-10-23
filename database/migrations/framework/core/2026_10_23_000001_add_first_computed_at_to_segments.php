<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Expression;
use Pramnos\Database\Migration;

/**
 * When a segment was first worked out: `segments.firstcomputedat`.
 *
 * The first working out puts everybody who matches in at once, with that moment as their
 * `entered_at` — a starting point, not people entering. Who entered and who left over a week counts
 * only what happened after it. For a segment worked out before this migration, the earliest
 * `entered_at` among its members is that moment; one with no members takes its last working out.
 */
class AddFirstComputedAtToSegments extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 243;
    public $description = 'Adds segments.firstcomputedat, so a segment\'s first members are not counted as entering';

    /** Add the column, and fill it for the segments already worked out. */
    public function up(): void
    {
        $db     = $this->application->database;
        $schema = $db->schema();
        if (!$schema->hasTable('segments') || $schema->hasColumn('segments', 'firstcomputedat')) {
            return;
        }

        $schema->table('segments', function ($table) {
            $table->unsignedInteger('firstcomputedat')->nullable()->comment('Unix time it was first worked out; who entered before it is its start, not movement');
        });

        $first = array_column($db->queryBuilder()->table('pramnos.segment_members')
            ->select(['segmentid', new Expression('MIN(entered_at) AS first')])->groupBy(['segmentid'])->getAllOrFail(), 'first', 'segmentid');
        foreach ($db->queryBuilder()->table('#PREFIX#segments')->select(['segmentid', 'recomputedat'])->whereNotNull('recomputedat')->getAllOrFail() as $segment) {
            $db->queryBuilder()->table('#PREFIX#segments')->where('segmentid', (int) $segment['segmentid'])
                ->update(['firstcomputedat' => (int) ($first[$segment['segmentid']] ?? $segment['recomputedat'])]);
        }
    }

    /** Remove it again. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('segments') && $schema->hasColumn('segments', 'firstcomputedat')) {
            $schema->table('segments', function ($table) {
                $table->dropColumn('firstcomputedat');
            });
        }
    }
}
