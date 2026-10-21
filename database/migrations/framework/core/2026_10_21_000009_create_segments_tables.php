<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates segments and segment_members: named audiences kept up to date, and who was in each,
 * from when to when.
 *
 * A segment stores its filter, not its people; `segment_members` is what the filter meant each
 * time it was worked out. A row is a stay — `entered_at` to `left_at`, NULL while it lasts — so a
 * person who leaves and comes back has two rows, and "who entered in the last seven days" is a
 * range over one column. Times are Unix seconds.
 */
class CreateSegmentsTables extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 242;
    public $description = 'Creates segments and segment_members, the audiences kept up to date';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('segments')) {
            $schema->createTable('segments', function ($table) {
                $table->comment('Named audiences: a filter over people, worked out again by the scheduler and on changes');
                $table->increments('segmentid')->comment('Auto-increment segment identifier');
                $table->string('name', 150)->comment('Shown in the library, the audience picker and the person card');
                $table->text('description')->nullable()->comment('What it is for');
                $table->text('filter')->comment('The filter as JSON — see Pramnos\\Audience\\Filter');
                $table->bigInteger('owner')->nullable()->comment('users.userid of who made it');
                $table->integer('members')->default(0)->comment('How many people were in it when last worked out');
                $table->unsignedInteger('recomputedat')->nullable()->comment('Unix time it was last worked out in full; NULL before the first');
                $table->unsignedInteger('createdat')->comment('Unix time it was made');
                $table->unsignedInteger('updatedat')->comment('Unix time it was last changed');
            });
        }

        if (!$schema->hasTable('segment_members')) {
            $schema->createTable('segment_members', function ($table) {
                $table->comment('Who was in each segment, from when to when: one row per stay');
                $table->bigIncrements('id')->comment('Auto-increment row identifier');
                $table->integer('segmentid')->unsigned()->comment('segments.segmentid');
                $table->bigInteger('userid')->comment('users.userid of the person');
                $table->unsignedInteger('entered_at')->comment('Unix time the person entered');
                $table->unsignedInteger('left_at')->nullable()->comment('Unix time the person left; NULL while they are in');
                $table->index(['segmentid', 'left_at', 'userid'], 'idx_segment_members_open');
                $table->index(['userid', 'left_at'], 'idx_segment_members_person');
                $table->index(['segmentid', 'entered_at'], 'idx_segment_members_entered');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('segment_members');
        $schema->dropTableIfExists('segments');
    }
}
