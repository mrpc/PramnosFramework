<?php

namespace Pramnos\Framework\Migrations\Moderation;

use Pramnos\Database\Migration;

/**
 * Creates what moderation keeps: who may not write, or not be here, and what was done about whom.
 *
 * - `moderation_restrictions` — a ban (not here at all) or a mute (here, silent) of a subject — an
 *   account, a visitor, an address or a nickname — everywhere or in one scope, for good or until a
 *   time, lifted early or not.
 * - `moderation_reports` — somebody reporting somebody, with what they wrote as it was then.
 * - `moderation_scopes` — a scope's own rules: its slow mode.
 *
 * What was done about whom — bans, mutes, kicks, refused messages, reports — is the audit log's
 * (`moderation.*`), not a table of moderation's own.
 *
 * Subjects are a kind and a value, so a visitor with no account and an address are moderated as an
 * account is. Times are Unix seconds. The subjects and what they wrote are personal data.
 */
class CreateModerationTables extends Migration
{
    public string $feature     = 'moderation';
    public string $scope       = 'framework';
    public int    $priority    = 10;
    public $description = 'Creates moderation: restrictions, reports and each scope\'s rules';

    /** Create the tables. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('moderation_restrictions')) {
            $schema->createTable('moderation_restrictions', function ($table) {
                $table->comment('A ban or a mute of a subject, everywhere or in one scope');
                $table->bigIncrements('id')->comment('Auto-increment identifier');
                $table->string('scope', 100)->default('')->comment('Where: empty for everywhere, else e.g. conversation:12');
                $table->string('effect', 8)->comment('ban | mute');
                $table->string('subjectkind', 10)->comment('account | visitor | ip | nickname');
                $table->string('subject', 191)->comment('The account id, visitor key, address or nickname');
                $table->string('reason', 255)->default('')->comment('Why');
                $table->bigInteger('createdby')->nullable()->comment('Who, or null for the rules themselves');
                $table->unsignedInteger('createdat')->comment('When');
                $table->unsignedInteger('expiresat')->nullable()->comment('Until when; null for good');
                $table->unsignedInteger('liftedat')->nullable()->comment('When it was lifted early');
                $table->bigInteger('liftedby')->nullable()->comment('Who lifted it');
                $table->index(['subjectkind', 'subject'], 'idx_moderation_restrictions_subject');
                $table->index(['scope'], 'idx_moderation_restrictions_scope');
            });
        }

        if (!$schema->hasTable('moderation_reports')) {
            $schema->createTable('moderation_reports', function ($table) {
                $table->comment('Somebody reporting somebody, with what they wrote as it was');
                $table->bigIncrements('id')->comment('Auto-increment identifier');
                $table->string('scope', 100)->default('')->comment('Where');
                $table->string('reporterkind', 10)->comment('account | visitor');
                $table->string('reporter', 191)->comment('Who reported');
                $table->string('subjectkind', 10)->comment('account | visitor | ip | nickname');
                $table->string('subject', 191)->comment('Whom');
                $table->string('content', 100)->default('')->comment('What, e.g. message:123');
                $table->text('snapshot')->nullable()->comment('What it said when it was reported');
                $table->string('reason', 255)->default('')->comment('Why');
                $table->string('status', 10)->default('open')->comment('open | resolved | dismissed');
                $table->unsignedInteger('createdat')->comment('When');
                $table->unsignedInteger('resolvedat')->nullable()->comment('When it was dealt with');
                $table->bigInteger('resolvedby')->nullable()->comment('By whom');
                $table->index(['status', 'createdat'], 'idx_moderation_reports_status');
                $table->index(['subjectkind', 'subject'], 'idx_moderation_reports_subject');
            });
        }

        if (!$schema->hasTable('moderation_scopes')) {
            $schema->createTable('moderation_scopes', function ($table) {
                $table->comment('A scope\'s own rules');
                $table->bigIncrements('id')->comment('Auto-increment identifier');
                $table->string('scope', 100)->comment('e.g. conversation:12');
                $table->integer('slowmode')->default(0)->comment('Seconds between one person\'s messages; 0 off');
                $table->unsignedInteger('updatedat')->comment('When');
                $table->unique(['scope'], 'uq_moderation_scopes');
            });
        }
    }

    /** Drop them. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        foreach (['moderation_scopes', 'moderation_reports', 'moderation_restrictions'] as $table) {
            $schema->dropTableIfExists($table);
        }
    }
}
