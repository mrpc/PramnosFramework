<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * Per-user settings: one key/value row per user, per setting.
 *
 * The framework had two places to keep something about a user and neither fits this.
 * `users` columns are the schema every application shares, so an application cannot add
 * to them; `userdetails` / `otherinfo` is a per-user blob written by `__set()`, which is
 * fine for a value the *application's own code* reads and unusable for one an operator
 * has to see and change — a blob has no list, no per-key delete and no per-key history.
 *
 * A consuming application had built exactly this table and an editor for it, because an
 * administrator answering "why is this account behaving like that" needs to see the
 * switches somebody set on it. This is that facility in the framework.
 *
 * **The value is JSON**, not text. A setting is as likely to be a list or an object as a
 * string, and a text column forces every caller to pick an encoding — which means two
 * callers pick two.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class CreateUsersettingsTable extends Migration
{
    public string $feature      = 'app';
    public string $scope        = 'framework';
    public int    $priority     = 60;
    public array  $dependencies = [];
    public $description  = 'Creates the usersettings table — per-user key/value settings, visible and editable in the admin';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('#PREFIX#usersettings')) {
            return;
        }

        $schema->createTable('#PREFIX#usersettings', function ($table) {
            $table->comment('Per-user settings: one row per user per setting, value stored as JSON');

            $table->increments('id')
                ->comment('Surrogate key');
            $table->integer('userid')
                ->comment('The user this setting belongs to');
            $table->string('setting', 190)
                ->comment('The setting name, unique per user — 190 so the composite unique index fits utf8mb4 on MySQL');
            $table->text('value')->nullable()
                ->comment('JSON-encoded value; NULL is a deliberate "set to nothing" rather than an absent row');
            $table->integer('updated_at')->nullable()
                ->comment('Unix timestamp of the last write, for an operator asking when this changed');
            $table->integer('updated_by')->nullable()
                ->comment('Who wrote it last: a userid, or NULL when the application wrote it itself');

            // One row per (user, setting): the accessor upserts, and two rows for one
            // name would make "the value" ambiguous in a way no read could resolve.
            $table->unique(['userid', 'setting']);
            $table->index('userid');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('#PREFIX#usersettings');
    }
}
