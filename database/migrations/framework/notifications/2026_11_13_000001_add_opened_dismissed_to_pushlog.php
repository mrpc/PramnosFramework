<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;

/**
 * `pushlog.opened_at` / `dismissed_at` — when the browser's service worker reported the
 * notification clicked or closed, so a push attempt row answers not only *was it delivered* but
 * *did anybody do anything with it*.
 *
 * Needed for the hold-unopened rule (FW-495): a phone that auto-revokes notification permission
 * after many unopened ones must not be sent another conversation push while an earlier one on it
 * is still sitting there, and "still sitting there" means exactly these two columns are null.
 */
class AddOpenedDismissedToPushlog extends Migration
{
    public string $feature      = 'notifications';
    public string $scope        = 'framework';
    public int    $priority     = 31;
    public array  $dependencies = ['create_pushlog_table'];
    public $description = 'Adds pushlog.opened_at/dismissed_at and an index for the hold-unopened rule';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.pushlog') || $schema->hasColumn('pramnos.pushlog', 'opened_at')) {
            return;
        }

        $schema->alterTable('pramnos.pushlog', function ($table) {
            $table->timestampTz('opened_at')->nullable()
                ->comment('When the service worker reported notificationclick; never for a self-closing pf-quiet one');
            $table->timestampTz('dismissed_at')->nullable()
                ->comment('When the service worker reported notificationclose, if never opened first');
        });

        // The hold-unopened rule's own query: one browser, one notification class, undelivered-or-unopened.
        $schema->alterTable('pramnos.pushlog', function ($table) {
            $table->index(['endpoint_hash', 'notification', 'status'], 'idx_pushlog_unopened');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('pramnos.pushlog') || !$schema->hasColumn('pramnos.pushlog', 'opened_at')) {
            return;
        }

        $schema->alterTable('pramnos.pushlog', function ($table) {
            $table->dropIndex('idx_pushlog_unopened');
            $table->dropColumn('opened_at');
            $table->dropColumn('dismissed_at');
        });
    }
}
