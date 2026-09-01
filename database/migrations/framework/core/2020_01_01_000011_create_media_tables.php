<?php

namespace Pramnos\Framework\Migrations\Core;

use Pramnos\Database\Migration;

/**
 * The two tables `Pramnos\Media\MediaObject` has always used, and no migration ever created.
 *
 * `MediaObject` arrived with this framework's original import in April 2020. The migrations were
 * written six years later by reconstructing the schema of a consuming application — and that
 * application does not use `MediaObject`, so these two tables were never in the source that
 * reconstruction read. Not omitted from a list; never on one. The only trace left in the migrations
 * is a comment on `users.photo`, which documents a reference *into* `mediause` while nothing
 * created it.
 *
 * The shape below is the one running in production, read out of `SHOW CREATE TABLE` rather than
 * inferred from the model — with the handful of deliberate improvements listed at the bottom.
 *
 * ### Both tables in one migration, on purpose
 *
 * `mediause.mediaid` has a cascading foreign key onto `media.mediaid`, so `media` has to exist
 * first. Split across two files that is a `dependencies` entry somebody has to get right and the
 * runner has to honour; in one `up()` the order is the order of the statements and cannot be
 * misconfigured. The date matches `create_users_table` because these tables are the same vintage —
 * they were always part of that original schema.
 *
 * There is **no** foreign key from `media.userid` to `users.userid`, and none from `media.medialink`
 * to `media.mediaid`, deliberately: both columns use `0` as a sentinel — «no user» and «not a
 * duplicate» respectively, and `MediaObject` queries `where md5 = %s and medialink = 0` to find the
 * original of a re-upload. A foreign key would reject that zero on the first insert.
 */
class CreateMediaTables extends Migration
{
    public string  $feature      = 'core';
    public string  $scope        = 'framework';
    public int     $priority     = 11;
    public array   $dependencies = [];
    public $description = 'Creates the media and mediause tables used by Pramnos\Media\MediaObject';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('#PREFIX#media')) {
            $schema->createTable('#PREFIX#media', function ($table) {
                $table->comment('Uploaded media — one row per stored file; see Pramnos\Media\MediaObject');

                /*
                 * A **signed** auto-increment, not `increments()`.
                 *
                 * `increments()` emits UNSIGNED on MySQL, and `mediause.mediaid` is `integer()` →
                 * signed, so the foreign key below is refused: «Referencing column and referenced
                 * column are incompatible». Production has both as signed `int(11)`, which is why
                 * the key works there. The same idiom appears in `create_organizations_table` for
                 * exactly this reason.
                 */
                $table->integer('mediaid')->autoIncrement()->primary()
                    ->comment('Auto-increment media id');
                $table->integer('mediatype')->default(0)
                    ->comment('1: image, 2: emoticon, 3: PDF, 0: other document');
                $table->integer('userid')->default(0)
                    ->comment('users.userid of the uploader; 0 when there was no signed-in user');
                $table->string('module', 255)->default('')
                    ->comment('The module that owns the upload, for grouping and per-module access');
                $table->integer('views')->default(0)
                    ->comment('How many times it has been served');
                $table->text('thumbnails')->nullable()
                    ->comment('PHP-serialised list of generated thumbnails, one entry per size');
                $table->bigInteger('filesize')->default(0)
                    ->comment('Bytes, including the thumbnails generated beside it');
                $table->string('description', 255)->default('')
                    ->comment('Caption shown with the file');
                $table->integer('x')->default(0)
                    ->comment('Pixel width; 0 for anything that is not an image');
                $table->integer('y')->default(0)
                    ->comment('Pixel height; 0 for anything that is not an image');
                $table->integer('order')->default(0)
                    ->comment('Display order — used by emoticon sets, where the order is the point');
                $table->string('name', 255)->default('')
                    ->comment('The name a person sees, derived from the uploaded filename');
                $table->string('filename', 255)->default('')
                    ->comment('Path on disk; the stored name is generated, never the client\'s');
                $table->string('url', 255)->default('')
                    ->comment('Public URL, relative to the web root');
                $table->string('shortcut', 128)->default('')
                    ->comment('For emoticons: the text that is replaced by the image');
                $table->string('tags', 255)->default('')
                    ->comment('Free-text tags, for searching the library');
                $table->bigInteger('date')->default(0)
                    ->comment('Unix timestamp of the upload');
                $table->tinyInteger('otherusers')->default(0)
                    ->comment('Whether users other than the uploader may see it');
                $table->tinyInteger('othermodules')->default(0)
                    ->comment('Whether modules other than the owning one may use it');
                $table->string('md5', 32)->default('')
                    ->comment('Hash of the file content — how a re-upload is recognised');
                $table->integer('medialink')->default(0)
                    ->comment('When this row is a duplicate, the mediaid holding the actual file; 0 otherwise');
                $table->integer('usages')->default(0)
                    ->comment('How many mediause rows point here');
                $table->text('extrainfo')->nullable()
                    ->comment('Per-module extra data, for anything the columns above do not cover');

                /*
                 * One index, on the column every upload looks up.
                 *
                 * `uploadFile()` asks `where md5 = %s and medialink = 0` before storing anything, so
                 * without this each upload scans the whole library — and a media table is the kind
                 * that reaches six figures. There is deliberately no index on `module`: nothing
                 * queries it, and an index nobody reads is a write on every insert.
                 */
                $table->index(['md5'], 'idx_media_md5');
            });
        }

        if ($schema->hasTable('#PREFIX#mediause')) {
            return;
        }

        $schema->createTable('#PREFIX#mediause', function ($table) {
            $table->comment(
                'Where a media file is used — one row per usage, so one file can appear in many places'
            );

            // Signed too, for symmetry with media.mediaid and with production.
            $table->integer('usageid')->autoIncrement()->primary()
                ->comment('Auto-increment usage id; users.photo holds one of these');
            $table->integer('mediaid')
                ->comment('media.mediaid — the file being used');
            $table->string('module', 255)->default('')
                ->comment('The module using it');
            $table->string('specific', 255)->default('')
                ->comment('Which record inside that module — an id, or whatever the module needs');
            $table->bigInteger('date')->default(0)
                ->comment('Unix timestamp the usage was recorded');
            $table->string('title', 255)->default('')
                ->comment('Title for this usage, which may differ from the file\'s own');
            $table->string('description', 255)->default('')
                ->comment('Caption for this usage');
            $table->string('tags', 255)->default('')
                ->comment('Tags for this usage');
            $table->integer('order')->default(0)
                ->comment('Display order within the record that uses it — a gallery is ordered');

            $table->index(['mediaid'], 'idx_mediause_mediaid');

            /*
             * `(module, specific)`, which production does not have.
             *
             * Three separate queries in `MediaObject` filter on `module`, on `specific`, or on both,
             * and every one of them is a full scan today. The composite serves the pair and the
             * `module`-only case from the same index; `specific` alone still scans, and that is the
             * rarest of the three.
             */
            $table->index(['module', 'specific'], 'idx_mediause_module_specific');

            /*
             * Cascading both ways, as production does.
             *
             * A usage row whose file is gone is a gallery entry pointing at nothing, and nothing
             * else would clean it up — `MediaObject::delete()` removes the file and its thumbnails,
             * and the database is what keeps the usages honest.
             */
            $table->foreign('mediaid')
                ->references('mediaid')
                ->on('#PREFIX#media')
                ->onDelete('cascade')
                ->onUpdate('cascade')
                ->name('fk_mediause_mediaid');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        // The child first: its foreign key is what would refuse the parent's drop.
        $schema->dropTableIfExists('#PREFIX#mediause');
        $schema->dropTableIfExists('#PREFIX#media');
    }
}
