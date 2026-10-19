<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates `bounces` — the addresses mail could not be delivered to, and those blocked by hand
 * ({@see \Pramnos\Email\Bounces}).
 *
 * The layout applications already keep under this name: one row per address, keyed by the sha256
 * of the lowercased address, with the count and the moment of the last soft and hard bounce. An
 * installation that has the table keeps its rows and gains the two columns it lacks: `blocked`,
 * the moment somebody stopped all mail to the address, and `reason`, why the last thing recorded
 * happened.
 */
class CreateBouncesTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = [];
    public $description  = 'Creates bounces, or adds blocked and reason to one an application already has';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('bounces')) {
            $schema->createTable('bounces', function ($table) {
                $table->comment('Addresses mail bounced from or was blocked to — one row per address, by its hash');
                $table->increments('bounceid');
                $table->char('hash', 64)->comment('sha256 of the lowercased address');
                $table->integer('lastsoft')->default(0)->comment('Unix time of the last soft bounce');
                $table->integer('lasthard')->default(0)->comment('Unix time of the last hard bounce');
                $table->integer('hardbounces')->default(0)->comment('Hard bounces recorded');
                $table->integer('softbounces')->default(0)->comment('Soft bounces recorded');
                $table->unique(['hash'], 'bounces_hash');
            });
        }

        $add = array_filter(['blocked', 'reason'], static fn (string $column): bool => !$schema->hasColumn('bounces', $column));
        if ($add !== []) {
            $schema->alterTable('bounces', function ($table) use ($add) {
                if (in_array('blocked', $add, true)) {
                    $table->integer('blocked')->default(0)->comment('Unix time all mail to the address was stopped by hand; 0 when it was not');
                }
                if (in_array('reason', $add, true)) {
                    $table->string('reason', 255)->default('')->comment('Why the last bounce or block was recorded');
                }
            });
        }
    }

    public function down(): void
    {
        // An application's own table, with years of rows, is not this migration's to drop.
        $schema = $this->application->database->schema();
        if ($schema->hasColumn('bounces', 'blocked')) {
            $schema->alterTable('bounces', function ($table) {
                $table->dropColumn('blocked');
                $table->dropColumn('reason');
            });
        }
    }
}
