<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Adds `bodypath` and `bodybytes` to `messages`.
 *
 * The same two columns `mails` got, for the same reason and against a worse case. A message row
 * is a handful of facts — who, to whom, when, read or not — around a `text` column holding the
 * whole body, and the body is never joined, filtered or aggregated on. It is read once, by the
 * person it was sent to.
 *
 * ### The mass send is what makes this urgent
 *
 * `mails` grows a row per message. `messages` grows a row per **recipient**: a campaign to forty
 * thousand people writes forty thousand rows, each carrying its own copy of one identical body.
 * The store is content-addressed, so those forty thousand copies become forty thousand path
 * strings and **one file** — the deduplication is not a bonus here, it is the point.
 *
 * Nothing is lost. {@see \Pramnos\Storage\BodyStore::bodyOf()} takes a row from either table and
 * returns the body wherever it is, so the inbox, the message screen and anything an application
 * wrote keep working unchanged.
 *
 * `bodybytes` is recorded rather than measured, so "what is this costing" is a `SUM()` over the
 * table rather than a `stat()` per row.
 *
 * ### And `excerpt`, which is what makes the rest of it usable
 *
 * The inbox listing shows the first line of each message under its subject. With the body in a
 * file that would be one gzip read per row — two hundred of them to draw one page, on the screen
 * people open most often. A listing does not want the body; it wants a summary of it, and a
 * summary is metadata, so it stays on the row where a listing can select it.
 *
 * Without this column the store would be correct and unusable, which is the failure mode worth
 * naming: every read still returns the right body, and the page takes a second to draw.
 *
 * Additive: an installation that changes nothing keeps writing the body inline, and these two
 * columns stay empty. Existing rows are not touched — moving them is a separate, resumable job.
 */
class AddBodypathToMessages extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 56;
    public array   $dependencies = [];
    public $description  = 'Adds bodypath and bodybytes to messages, for bodies stored as files';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('messages')) {
            return;
        }

        if (!$schema->hasColumn('messages', 'bodypath')) {
            $schema->table('messages', function ($table) {
                $table->string('bodypath', 255)->default('')
                    ->comment('Relative path of the gzipped body under the body store; empty '
                        . 'when the body is inline in `text`');
            });
        }

        if (!$schema->hasColumn('messages', 'bodybytes')) {
            $schema->table('messages', function ($table) {
                $table->integer('bodybytes')->default(0)
                    ->comment('Size of the stored file in bytes, recorded so the cost of the '
                        . 'archive is a SUM() rather than a stat() per row');
            });
        }

        if (!$schema->hasColumn('messages', 'excerpt')) {
            $schema->table('messages', function ($table) {
                $table->string('excerpt', 255)->default('')
                    ->comment('Plain-text opening of the body, so the inbox listing never has to '
                        . 'open a stored file to draw a preview line');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('messages')) {
            return;
        }

        foreach (['bodypath', 'bodybytes', 'excerpt'] as $column) {
            if ($schema->hasColumn('messages', $column)) {
                $schema->table('messages', function ($table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
}
