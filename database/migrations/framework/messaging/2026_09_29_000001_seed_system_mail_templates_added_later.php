<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;
use Pramnos\Messaging\SystemMailTemplates;

/**
 * The editor rows for the mail categories added after the first seeding.
 *
 * `seed_system_mail_templates` ran once, on 26 September, and `auth.invitation` and
 * `auth.verify_email` did not exist yet — so an installation that migrated then never got a row
 * for them, and **Administration → Mail templates** did not list them. Reported by glideday.
 *
 * It calls the same seeder, which writes only what is missing: on an installation that migrated
 * later it writes nothing.
 */
class SeedSystemMailTemplatesAddedLater extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 25;
    public array   $dependencies = ['seed_system_mail_templates'];
    public $description = 'Lists the mail categories added since the first seeding (invitations, address confirmation) in the template editor';

    /** The categories this migration was written for; {@see down()}. */
    private const CATEGORIES = ['auth.invitation', 'auth.verify_email'];

    public function up(): void
    {
        $language = (string) \Pramnos\Application\Settings::getSetting('default_language', 'en');

        SystemMailTemplates::seedMissingRows($this->application->database, $language);
    }

    public function down(): void
    {
        foreach (self::CATEGORIES as $category) {
            // Only rows still untouched — somebody's wording is not this migration's to remove.
            $this->application->database->queryBuilder()->table('#PREFIX#mailtemplates')
                ->where('category', $category)
                ->where('defaultsubject', '')
                ->where('defaulttext', '')
                ->delete();
        }
    }
}
