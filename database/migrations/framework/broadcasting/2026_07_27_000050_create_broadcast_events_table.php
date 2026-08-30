<?php

namespace Pramnos\Framework\Migrations\Broadcasting;

use Pramnos\Database\Migration;

/**
 * Creates the broadcast_events table — the durable backplane for the database
 * broadcasting driver.
 *
 * Only needed when the broadcasting feature is configured with the 'database'
 * driver (typically shared hosting without Redis). Publishers append one row per
 * event; consumers (SSE streams, the WebSocket server) range-scan by ascending
 * id for rows newer than the last one they saw, filtered by channel. The
 * (channel, id) index makes that poll cheap.
 *
 * Rows are transient — a scheduled cleanup should prune events older than a few
 * minutes; they exist only long enough for connected consumers to pick them up.
 */
class CreateBroadcastEventsTable extends Migration
{
    public string $feature     = 'broadcasting';
    public string $scope       = 'framework';
    public int    $priority    = 10;
    public        $description  = 'Creates the broadcast_events table for the database broadcasting driver';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('broadcast_events')) {
            return;
        }

        $schema->createTable('broadcast_events', function ($table) {
            $table->comment('Durable event backplane for the database broadcasting driver — consumers poll by ascending id');

            $table->bigIncrements('id')
                ->comment('Auto-increment event id; consumers track the last id they have delivered');
            $table->string('channel', 191)
                ->comment('Logical channel the event was broadcast on');
            $table->string('event', 191)->default('')
                ->comment('Event name within the channel');
            $table->json('payload')
                ->comment('Event payload as JSON');
            $table->timestamp('created_at')->useCurrent()
                ->comment('When the event was appended — used by the cleanup job to prune old rows');

            // Primary poll access path: newer-than-lastId rows for a set of channels.
            $table->index(['channel', 'id'], 'idx_broadcast_events_channel_id');
            $table->index(['created_at'], 'idx_broadcast_events_created_at');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('broadcast_events');
    }
}
