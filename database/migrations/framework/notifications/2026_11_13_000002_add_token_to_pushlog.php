<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;

/**
 * `pushlog.token` — a per-row secret, so `POST /push/report` cannot be made to mark *any* push
 * opened or dismissed just by guessing a sequential id.
 *
 * `pushid` is a plain auto-increment, so a bare id in the report endpoint let anyone skew open
 * rates for an account they are not that account, and clear the hold-unopened rule (FW-495) for a
 * device they do not hold. The id names *which* row; the token, generated once when the row is
 * reserved and never reused, proves the report came from the browser the push was actually sent
 * to.
 */
class AddTokenToPushlog extends Migration
{
    public string $feature      = 'notifications';
    public string $scope        = 'framework';
    public int    $priority     = 32;
    public array  $dependencies = ['add_opened_dismissed_to_pushlog'];
    public $description = 'Adds pushlog.token, so /push/report cannot be driven by a guessed sequential id';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.pushlog') || $schema->hasColumn('pramnos.pushlog', 'token')) {
            return;
        }

        $schema->alterTable('pramnos.pushlog', function ($table) {
            $table->string('token', 32)->default('')
                ->comment('A per-row secret (random hex) the payload carries, so the report endpoint can tell the real device from a guessed pushid');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.pushlog') || !$schema->hasColumn('pramnos.pushlog', 'token')) {
            return;
        }

        $schema->alterTable('pramnos.pushlog', function ($table) {
            $table->dropColumn('token');
        });
    }
}
