<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Adds `bodypath` and `bodybytes` to `mails`.
 *
 * `mails` stores the rendered body, and the body is the whole size of that table: a
 * password-reset mail is two hundred bytes of facts wrapped around forty kilobytes of HTML. The
 * facts are what anybody queries; the HTML is read by one screen, occasionally, and is never
 * joined, filtered or aggregated on.
 *
 * These two columns let it live in a gzipped file instead, with the row keeping a path to it.
 * Nothing is lost — {@see \Pramnos\Email\BodyStore::bodyOf()} reads the body wherever it is, so
 * every screen keeps working.
 *
 * `bodybytes` is recorded rather than measured, so "what is this costing" is a `SUM()` over the
 * table rather than a `stat()` per row.
 *
 * Additive and off by default: an installation that changes nothing keeps writing the body
 * inline, and these two columns stay empty.
 */
class AddBodypathToMails extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 55;
    public array   $dependencies = [];
    public $description  = 'Adds bodypath and bodybytes to mails, for bodies stored as files';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('mails')) {
            return;
        }

        if (!$schema->hasColumn('mails', 'bodypath')) {
            $schema->table('mails', function ($table) {
                $table->string('bodypath', 255)->default('')
                    ->comment('Relative path of the gzipped body under the mail body store; '
                        . 'empty when the body is inline in `content`');
            });
        }

        if (!$schema->hasColumn('mails', 'bodybytes')) {
            $schema->table('mails', function ($table) {
                $table->integer('bodybytes')->default(0)
                    ->comment('Size of the stored file in bytes, recorded so the cost of the '
                        . 'archive is a SUM() rather than a stat() per row');
            });
        }
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('mails')) {
            return;
        }

        foreach (['bodypath', 'bodybytes'] as $column) {
            if ($schema->hasColumn('mails', $column)) {
                $schema->table('mails', function ($table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
}
