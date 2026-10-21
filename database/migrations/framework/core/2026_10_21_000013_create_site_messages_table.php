<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Creates site_messages: what a person sees on the site's own pages — a banner across the top, a
 * popup, a card — until they close it or it expires.
 *
 * Written by a rule's `site_message` action (or an application), shown by
 * `Html\SiteMessages::render()` to the person signed in, and kept with when it was first seen and
 * closed — what "did they see it" asks. Times are Unix seconds.
 */
class CreateSiteMessagesTable extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 246;
    public $description = 'Creates site_messages, the banners, popups and cards people see on the site';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('site_messages')) {
            return;
        }

        $schema->createTable('site_messages', function ($table) {
            $table->comment('Messages on the site\'s pages for one person: banners, popups, cards');
            $table->bigIncrements('id')->comment('Auto-increment message identifier');
            $table->bigInteger('personid')->comment('users.userid of the person it is for');
            $table->string('kind', 16)->comment('banner, popup or card');
            $table->string('title', 255)->comment('The heading');
            $table->text('body')->nullable()->comment('The text, as cleaned HTML');
            $table->string('link', 500)->nullable()->comment('Where its button goes');
            $table->string('linktext', 100)->nullable()->comment('The button\'s words');
            $table->string('origin', 255)->nullable()->comment('What wrote it: rule:4:v3:step:run812, or the application\'s own');
            $table->unsignedInteger('createdat')->comment('Unix time it was written');
            $table->unsignedInteger('expiresat')->nullable()->comment('Unix time it stops being shown; NULL for never');
            $table->unsignedInteger('seenat')->nullable()->comment('Unix time it was first shown');
            $table->unsignedInteger('dismissedat')->nullable()->comment('Unix time the person closed it');
            $table->index(['personid', 'dismissedat'], 'idx_site_messages_person');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('site_messages');
    }
}
