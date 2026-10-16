<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * `client_vitals` — what pages measured in visitors' browsers (`pf-vitals.js`): one row per
 * metric of a sampled page view, with what it depends on — the page group, the device, how the
 * page was reached and whether a service worker answered.
 */
class CreateClientVitalsTable extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 230;
    public $description = 'Creates client_vitals, the Core Web Vitals pages measured in the browser';

    public function up(): void
    {
        $schema = $this->application->database->schema();
        if ($schema->hasTable('client_vitals')) {
            return;
        }

        $schema->createTable('client_vitals', function ($table) {
            $table->comment('Core Web Vitals measured in visitors\' browsers, one row per metric of a sampled page view');
            $table->increments('id');
            $table->string('metric', 8)->comment('TTFB, FCP, LCP, INP or CLS');
            $table->decimal('value', 12, 4)->comment('Milliseconds; CLS is unitless');
            $table->string('rating', 20)->default('')->comment('good, needs-improvement or poor, as web-vitals rated it');
            $table->string('page_group', 64)->default('other')->comment('The vitals.groups name the path matched');
            $table->string('path', 255)->default('');
            $table->string('device', 16)->default('')->comment('mobile or desktop, by the viewport');
            $table->string('navigation', 32)->default('')->comment('navigate, reload, back-forward, back-forward-cache, prerender, restore');
            $table->string('delivery', 32)->default('')->comment('The navigation\'s deliveryType: cache, navigational-prefetch, or empty');
            $table->string('worker', 16)->default('')->comment('controlled, supported or unsupported');
            $table->integer('worker_ms')->nullable()->comment('fetchStart − workerStart: the service worker\'s start, when one ran');
            $table->string('source', 16)->default('')->comment('Server-Timing sw: where the worker answered from');
            $table->boolean('logged_in')->default(false);
            $table->integer('created_at');
            $table->index(['created_at'], 'idx_client_vitals_created');
            $table->index(['page_group', 'metric'], 'idx_client_vitals_group');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('client_vitals');
    }
}
