<?php

namespace Pramnos\Framework\Migrations\Visits;

use Pramnos\Database\Migration;

/**
 * Creates `pramnos.sites` — the application's own sites, from `visits.sites` in app.php — and
 * `pramnos.page_hits`, every page view and click out of them, append-only.
 *
 * `page_hits` records events, so rule 14 asks why none of the four logs holds them:
 * - `changelog_events` is what happened to a record, kept two years, written per record. A hit is
 *   about a page, mostly of a visitor with no account, at a rate of millions a month. It needs
 *   columns of its own (place, device, source, the visit and its order) for the aggregates
 *   (`stats.traffic_*`) to group by.
 * - The change feed is what a save changed, thirty days.
 * - `audit_log` is the operators' record of actions.
 * - `ActivityLog` is a person's own history, shown to them.
 *
 * None of these can be a hypertable compressed by site, with continuous aggregates over it and a
 * retention the application sets per site. Never updated: a visit's later facts are read from its
 * hits. `sites` is state: the sites there are now.
 */
class CreateVisitsTables extends Migration
{
    public string $feature     = 'visits';
    public string $scope       = 'framework';
    public int    $priority    = 95;
    public $description = 'Creates pramnos.sites and pramnos.page_hits, the site\'s own traffic';

    /** Create them. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        $schema->ensureSchema('pramnos');
        if (!$schema->hasTable('pramnos.sites')) {
            $schema->createTable('pramnos.sites', function ($table) {
                $table->comment('The application\'s own sites, from visits.sites in app.php');
                $table->increments('siteid');
                $table->string('name', 64)->unique()->comment('Its key in visits.sites');
                $table->text('hosts')->comment('JSON: its hosts, each with www. and its subdomains');
                $table->string('visitor', 12)->default('cookie')->comment('cookie or cookieless');
                $table->integer('idle')->default(1800)->comment('Seconds without a page that end a visit');
            });
        }
        if (!$schema->hasTable('pramnos.page_hits')) {
            $schema->createTable('pramnos.page_hits', function ($table) {
                $table->comment('Every page view and click out of the sites, append-only; a hypertable on at');
                $table->bigIncrements('hitid')->comment('With at, the key');
                $table->timestampTz('at')->useCurrent()->comment('When, to the second; the hypertable\'s time');
                $table->integer('site')->comment('sites.siteid');
                $table->bigInteger('visitor')->comment('The pf_vid cookie, or the day\'s hash');
                $table->bigInteger('visit')->nullable()->comment('The visit, cookie model; null cookieless');
                $table->integer('seq')->nullable()->comment('Its order in the visit: 1 is the landing page');
                $table->string('kind', 8)->default('view')->comment('view, click (a link out) or event');
                $table->string('event', 64)->nullable()->comment('An event\'s name');
                $table->integer('urlid')->comment('The page, through urls');
                $table->integer('target')->nullable()->comment('A click\'s link out, through urls, its query kept');
                $table->string('referrer_host', 255)->nullable()->comment('First hit of a visit');
                $table->integer('referrer')->nullable()->comment('The referrer, through urls; first hit of a visit');
                $table->string('source', 12)->nullable()->comment('direct, search, social, ai, email, campaign, referral, internal');
                $table->string('medium', 64)->nullable();
                $table->string('campaign', 128)->nullable();
                $table->bigInteger('userid')->nullable()->comment('The person, from the hit on which they are known');
                $table->char('country', 2)->nullable();
                $table->string('region', 64)->nullable();
                $table->string('city', 128)->nullable();
                $table->string('device', 8)->nullable()->comment('mobile, tablet or desktop');
                $table->string('browser', 16)->nullable();
                $table->string('os', 16)->nullable();
                $table->string('screen', 11)->nullable();
                $table->string('dimension', 20)->nullable()->comment('The application\'s own: visits.dimension');
                $table->smallInteger('flags')->default(0)->comment('1 bot, 2 no JavaScript, 4 prerender, 8 from the page cache');
                $table->primary(['hitid', 'at']);
                $table->index(['site', 'at'], 'idx_page_hits_site_at');
                $table->index(['visit'], 'idx_page_hits_visit');
            });
        }
    }

    /** Drop them. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        $schema->dropTableIfExists('pramnos.page_hits');
        $schema->dropTableIfExists('pramnos.sites');
    }
}
