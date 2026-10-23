<?php

namespace Pramnos\Framework\Migrations\Conversations;

use Pramnos\Database\Migration;

/**
 * Creates `pramnos.link_previews`: what a link shows — its title, site, picture, player — read
 * once and kept a week, so the same link in many messages is fetched once. State, not history:
 * one row per link, replaced when it is read again.
 */
class CreateLinkPreviewsTable extends Migration
{
    public string $feature     = 'conversations';
    public string $scope       = 'framework';
    public int    $priority    = 19;
    public $description = 'Creates pramnos.link_previews, what each link in a message shows';

    /** Create the table, where there is none yet. */
    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('pramnos.link_previews')) {
            return;
        }

        $schema->createTable('pramnos.link_previews', function ($table) {
            $table->comment('What a link in a message shows, read once and kept a week: Conversations\Unfurls');
            $table->string('url_hash', 64)->comment('sha256 of the link, as Unfurls::normalize() writes it');
            $table->text('url')->comment('The link');
            $table->text('preview')->nullable()->comment('Its card as JSON; NULL where it showed nothing');
            $table->unsignedInteger('fetched_at')->comment('Unix time it was read');
            $table->primary(['url_hash']);
        });
    }

    /** Drop it. */
    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.link_previews');
    }
}
