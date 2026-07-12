<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the mails table — email send history and outbox queue.
 *
 * Records every email sent or attempted by the framework. Rows with status=2
 * (queued) are picked up by the queue processor for delivery. Sent emails
 * (status=1) are retained for audit and resend capability.
 *
 */
class CreateMailsTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 10;
    public $description  = 'Creates the mails email history/queue table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('mails')) {
            return;
        }

        $schema->createTable('mails', function ($table) {
            $table->comment('Email send history and outbox queue — status 2 = queued for delivery, 1 = sent, 0 = failed');

            $table->increments('id')
                ->comment('Auto-increment mail record identifier');
            $table->tinyInteger('status')
                ->comment('Delivery status: 0 = failed, 1 = sent successfully, 2 = queued for delivery');
            $table->string('frommail', 128)
                ->comment('Sender email address');
            $table->string('fromname', 255)
                ->comment('Sender display name');
            $table->string('tomail', 128)
                ->comment('Recipient email address');
            $table->string('toname', 255)
                ->comment('Recipient display name');
            $table->string('subject', 255)
                ->comment('Email subject line');
            $table->text('content')
                ->comment('Full email body (HTML or plain text)');
            $table->integer('date')
                ->comment('Unix timestamp when the mail record was created');
            $table->string('module', 128)
                ->comment('Application module that triggered the email (e.g. "auth", "billing", "notifications")');
            $table->string('moduleinfo', 255)
                ->comment('Module-specific context string (e.g. template name, event type)');
            $table->text('extrainfo')
                ->comment('Additional metadata in JSON or serialised format for debugging and audit');
            $table->string('path', 255)
                ->comment('Template path or file reference used to render this email');
            $table->char('hash', 32)
                ->comment('MD5 hash of the email content — used for deduplication of identical emails');

            $table->index(['status'], 'idx_mails_status');
            $table->index(['tomail'], 'idx_mails_tomail');
            $table->index(['date'], 'idx_mails_date');
            $table->index(['module'], 'idx_mails_module');
            $table->index(['hash'], 'idx_mails_hash');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('mails');
    }
}
