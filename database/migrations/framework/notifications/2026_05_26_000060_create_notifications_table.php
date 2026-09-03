<?php

namespace Pramnos\Framework\Migrations\Notifications;

use Pramnos\Database\Migration;

/**
 * Creates the notifications table — persistent in-app notification store.
 *
 * Each row represents one dispatched notification for one notifiable entity.
 * The application reads these rows to display a notification feed; marking
 * them read is done by setting `read_at` to a non-null timestamp.
 *
 * Used by DatabaseChannel from Pramnos\Notification\Channels.
 *
 * ## This directory is ungated, and that is load-bearing
 *
 * `Application::filterMigrationDirsByEnabledFeatures()` gates each `framework/<name>/`
 * directory on `FeatureRegistry::isEnabled(<name>)` — but fail-open: a name that is **not** a
 * registered feature always runs. `notifications` is not registered, so this runs everywhere.
 *
 * Registering a `notifications` feature would therefore be a silent breaking change. Every
 * installation that does not list it in `app.php` would stop getting this table, and
 * `DatabaseChannel` is the one channel that does not skip when its prerequisite is missing — it
 * issues an INSERT, so the failure arrives as a SQL error at send time rather than as a missing
 * feature at boot. If a feature is ever wanted here, it needs a default-on entry, not just a
 * registration.
 *
 */
class CreateNotificationsTable extends Migration
{
    public string $feature     = 'notifications';
    public string $scope       = 'framework';
    public int    $priority    = 10;
    public        $description = 'Creates the notifications in-app notification store table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('notifications')) {
            return;
        }

        $schema->createTable('notifications', function ($table) {
            $table->comment(
                'In-app notification store — one row per dispatched notification. '
                . 'read_at NULL means unread.'
            );

            $table->char('id', 36)
                ->comment('UUID v4 — unique per notification row');
            $table->string('type', 255)
                ->comment('Fully-qualified notification class name (e.g. App\\Notifications\\InvoicePaid)');
            $table->string('notifiable_type', 255)
                ->comment('Fully-qualified class name of the notifiable entity (e.g. App\\User)');
            $table->bigInteger('notifiable_id')
                ->comment('Primary key of the notifiable entity');
            $table->text('data')
                ->nullable()
                ->comment('JSON payload returned by NotificationInterface::toDatabase()');
            $table->dateTime('read_at')
                ->nullable()
                ->comment('Timestamp when the notification was marked read; NULL = unread');
            $table->dateTime('created_at')
                ->comment('Timestamp when the notification was dispatched');

            $table->primary('id');
            $table->index(['notifiable_type', 'notifiable_id'], 'idx_notifications_notifiable');
            $table->index(['type'], 'idx_notifications_type');
            $table->index(['read_at'], 'idx_notifications_read_at');
            $table->index(['created_at'], 'idx_notifications_created_at');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('notifications');
    }
}
