<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Gives `urls` a hash that does not collide — `sha`, the SHA-1 of the whole address, as 40 hex
 * characters — and when each was first seen (`createdat`), so a prune leaves the newest alone.
 *
 * Both columns are added empty, which is instant on any size of table: `urls:rehash` fills `sha`
 * in batches, merges the addresses kept twice, and only then makes its unique index. The crc32
 * `hash` stays, unread by the lookup once `sha` is there.
 */
class AddShaToUrls extends Migration
{
    public string $feature     = 'auth';
    public string $scope       = 'framework';
    public int    $priority    = 60;
    public $description = 'Adds urls.sha and createdat: a lookup that does not collide, and the age a prune reads';

    /** Add the columns, where the table has none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('urls') || $schema->hasColumn('urls', 'sha')) {
            return;
        }
        $schema->table('urls', function ($table) {
            $table->string('sha', 40)->nullable()->comment('SHA-1 of the whole address, hex; unique once urls:rehash has run');
            $table->integer('createdat')->nullable()->comment('When it was first seen; null for those from before');
        });
    }

    /** Remove them again, with the unique index urls:rehash made. */
    public function down(): void
    {
        $schema = $this->application->database->schema();
        if (!$schema->hasTable('urls') || !$schema->hasColumn('urls', 'sha')) {
            return;
        }
        $schema->table('urls', function ($table) use ($schema) {
            if ($schema->hasIndex('urls', 'uq_urls_sha')) {
                $table->dropUnique('uq_urls_sha');
            }
            $table->dropColumn('sha');
            $table->dropColumn('createdat');
        });
    }
}
