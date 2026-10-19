<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates `mail_bodies` — sent messages' bodies, kept apart from `mails` and compressed — where the
 * database is TimescaleDB, and only there.
 *
 * A body is a few kilobytes of mostly the same templates, and a hypertable compresses many of them
 * together: about seventeen times, measured on real ones, where a gzipped file each saves little
 * once the filesystem's blocks are counted. Without TimescaleDB nothing is created and bodies are
 * kept as files ({@see \Pramnos\Email\BodyStore}).
 */
class CreateMailBodiesTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 10;
    public $description  = 'Creates the mail_bodies hypertable (TimescaleDB only)';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('mail_bodies') || !$this->application->database->capabilities()->hasTimescaleDB()) {
            return;
        }

        $schema->createTable('mail_bodies', function ($table) {
            $table->comment('Sent messages\' bodies, compressed — the mails row names them with path db:');

            $table->bigInteger('mail_id')
                ->comment('The mails row this is the body of');
            $table->timestampTz('sent_at')
                ->comment('The mails row\'s date, as a time: what the table is partitioned and compressed by');
            $table->boolean('rebuild')
                ->default(false)
                ->comment('Kept before what differs per recipient — the unsubscribe link, tracking — which reading puts back');
            $table->text('body')
                ->comment('The body');

            $table->index(['mail_id'], 'idx_mail_bodies_mail');
        });
        $schema->createHypertable('mail_bodies', 'sent_at', ['chunk_time_interval' => '7 days']);
        $schema->enableCompression('mail_bodies', ['orderby' => 'sent_at']);
        $schema->addCompressionPolicy('mail_bodies', '7 days');
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('mail_bodies');
    }
}
