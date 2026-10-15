<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Errors the application's pages hit in the browser, one row per distinct error.
 *
 * `Logs\ClientErrors` records what `pf-utils.js`, the SPA's client and the service worker report;
 * a second report of the same error raises `seentimes` and `lastseen` instead of adding a row, so
 * an error every visitor hits is one row with a count, not a million rows.
 *
 * ## The table may already exist, and that is deliberate
 *
 * Applications built on this framework logged browser errors before it did, into a
 * `javascripterrors` table of `idhash`, `firstseen`, `lastseen`, `seentimes`, `error` and `agent`
 * (Unix times, 255-character text). This keeps that table and those columns, and only adds what
 * it lacks — so such an application adopts this without losing its history, and an admin screen
 * written against the old columns keeps working.
 */
class CreateJavascripterrorsTable extends Migration
{
    public string $feature     = 'core';
    public string $scope       = 'framework';
    public int    $priority    = 230;
    public $description = 'Creates javascripterrors (browser errors, one row per distinct error), or adds its new columns';

    /** The columns added to a table that already has the original six. */
    private const ADDED = ['kind', 'message', 'source', 'line', 'col', 'stack', 'page', 'release', 'userid'];

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('javascripterrors')) {
            $schema->createTable('javascripterrors', function ($table) {
                $table->comment('Browser errors, one row per distinct error; a repeat raises seentimes');
                $table->string('idhash', 100)->comment('md5 of what makes two reports the same error');
                $table->unsignedInteger('firstseen')->comment('Unix time of the first report');
                $table->unsignedInteger('lastseen')->comment('Unix time of the latest report');
                $table->integer('seentimes')->comment('How many reports');
                $table->string('error', 255)->comment('One line: source:line and the message');
                $table->string('agent', 255)->comment('The latest reporter\'s user agent');
                $this->addedColumns($table);
                $table->unique(['idhash'], 'idx_javascripterrors_idhash');
                $table->index(['lastseen'], 'idx_javascripterrors_lastseen');
            });

            return;
        }

        foreach (self::ADDED as $column) {
            if ($schema->hasColumn('javascripterrors', $column)) {
                continue;
            }
            $schema->table('javascripterrors', function ($table) use ($column) {
                $this->addedColumns($table, $column);
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('javascripterrors')) {
            return;
        }

        // Created here — the lastseen index is this migration's — so it goes. An application's own
        // table loses only what was added to it, and keeps its history.
        if ($schema->hasIndex('javascripterrors', 'idx_javascripterrors_lastseen')) {
            $schema->dropTableIfExists('javascripterrors');
            return;
        }

        $present = array_values(array_filter(self::ADDED, fn (string $c): bool => $schema->hasColumn('javascripterrors', $c)));
        if ($present !== []) {
            $schema->table('javascripterrors', function ($table) use ($present) {
                $table->dropColumn($present);
            });
        }
    }

    /**
     * The columns this migration brings, or one of them.
     *
     * Nullable, every one: rows the table held before have none of them.
     */
    private function addedColumns($table, ?string $only = null): void
    {
        $columns = [
            'kind'    => fn () => $table->string('kind', 20)->nullable()->comment('error, rejection or worker'),
            'message' => fn () => $table->text('message')->nullable()->comment('The message, whole'),
            'source'  => fn () => $table->string('source', 255)->nullable()->comment('The script, without its query'),
            'line'    => fn () => $table->integer('line')->nullable()->comment('Line in the script'),
            'col'     => fn () => $table->integer('col')->nullable()->comment('Column in the script'),
            'stack'   => fn () => $table->text('stack')->nullable()->comment('The stack, as the browser wrote it'),
            'page'    => fn () => $table->string('page', 255)->nullable()->comment('The page, without its query'),
            'release' => fn () => $table->string('release', 64)->nullable()->comment('The build the page ran'),
            'userid'  => fn () => $table->integer('userid')->nullable()->comment('Who hit it last, when signed in'),
        ];
        foreach ($columns as $name => $add) {
            if ($only === null || $only === $name) {
                $add();
            }
        }
    }
}
