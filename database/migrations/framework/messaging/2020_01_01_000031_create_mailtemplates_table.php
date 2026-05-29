<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the mailtemplates table — reusable email/SMS/push notification templates.
 *
 * Templates are looked up by (category, language, type) at send time. The `type`
 * column distinguishes the delivery channel: 0=email, 1=SMS, 2=push notification.
 * Multiple language variants of the same template are stored as separate rows.
 *
 */
class CreateMailtemplatesTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 20;
    public $description  = 'Creates the mailtemplates notification template table';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('mailtemplates')) {
            return;
        }

        $schema->createTable('mailtemplates', function ($table) {
            $table->comment('Notification templates for email, SMS, and push messages — keyed by category + language + type');

            $table->bigIncrements('templateid')
                ->comment('Auto-increment template identifier');
            $table->string('title', 255)
                ->comment('Human-readable template name used in the admin panel');
            $table->text('defaulttext')
                ->comment('Template body with placeholder variables (e.g. {username}, {url})');
            $table->string('defaultsubject', 255)
                ->comment('Default subject line for email templates; used as notification title for push');
            $table->string('category', 100)
                ->comment('Template category key (e.g. "auth", "billing", "alerts") for lookup by code');
            $table->string('language', 50)
                ->comment('BCP 47 language tag for this template variant (e.g. "el", "en")');
            $table->tinyInteger('type')
                ->comment('Delivery channel: 0 = Email, 1 = SMS, 2 = Push notification');
            $table->string('sound', 255)->default('')
                ->comment('Notification sound file reference for push notifications; empty = default sound');
            $table->tinyInteger('sendmethod')
                ->comment('Sending backend: 0 = Default SMTP, 1 = Amazon SES API');
            $table->integer('defaultaccount')->nullable()
                ->comment('FK to email accounts table — override sender account for this template; NULL = use default');
            $table->string('emailtemplate', 20)->default('default')
                ->comment('HTML wrapper template name applied around the body content (e.g. "default", "minimal")');

            $table->index(['category', 'language', 'type'], 'idx_mailtemplates_lookup');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('mailtemplates');
    }
}
