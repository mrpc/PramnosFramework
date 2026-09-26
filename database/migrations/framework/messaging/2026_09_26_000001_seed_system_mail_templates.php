<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;
use Pramnos\Messaging\SystemMailTemplates;

/**
 * Puts the framework's own mail categories in the template editor, **empty**.
 *
 * `MailChannel` lets a stored template replace a notification's text. That gave an operator
 * the ability and no way to find it: Administration → Mail templates showed an empty list,
 * and nothing anywhere said that `auth.twofactor_code` exists or that `{code}` is a thing
 * it may say. A feature reachable only by reading the source is a feature nobody uses.
 *
 * So one row per category, with a title an operator recognises and a **blank subject and
 * body**.
 *
 * ## Why blank rather than the built-in wording
 *
 * Blank means "use the text compiled into the class", so a seeded installation behaves
 * exactly as an unseeded one until somebody types something. Seeding the real wording would
 * look friendlier and be a trap: the row would fork from the class the moment the framework
 * improved a sentence, fixed a translation or added a security note, and the installation
 * would keep sending the old one for ever with nothing to say why.
 *
 * ## Why it is safe to re-run
 *
 * A category that already has a row — because somebody wrote one, or because this ran
 * before — is left alone. An operator's text is never overwritten by a migration.
 */
class SeedSystemMailTemplates extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 25;
    public array   $dependencies = ['create_mailtemplates_table'];
    public $description = 'Lists the framework’s own mail categories in the template editor, empty so the built-in text still applies';

    public function up(): void
    {
        $db       = $this->application->database;
        $language = (string) \Pramnos\Application\Settings::getSetting('default_language', 'en');

        if ($language === '') {
            $language = 'en';
        }

        foreach (SystemMailTemplates::all() as $category => $declared) {
            $existing = $db->queryBuilder()->table('#PREFIX#mailtemplates')
                ->where('category', $category)
                ->where('type', \Pramnos\Messaging\MailTemplate::TYPE_EMAIL)
                ->first();

            // Somebody's row, or this migration's from a previous run. Either way it is not
            // this migration's business to change it.
            if ($existing && (int) ($existing->numRows ?? 0) > 0) {
                continue;
            }

            $db->queryBuilder()->table('#PREFIX#mailtemplates')->insert([
                'title'          => $declared['title'],
                'category'       => $category,
                'language'       => $language,
                'type'           => \Pramnos\Messaging\MailTemplate::TYPE_EMAIL,
                // Blank on purpose. {@see the class doc-block}
                'defaultsubject' => '',
                'defaulttext'    => '',
                'emailtemplate'  => '',
                'sendmethod'     => 0,
                'sound'          => '',
            ]);
        }
    }

    public function down(): void
    {
        $db = $this->application->database;

        foreach (array_keys(SystemMailTemplates::all()) as $category) {
            // Only rows still untouched. An operator who wrote something keeps it — a
            // rollback of the seeding is not a reason to delete somebody's work.
            $db->queryBuilder()->table('#PREFIX#mailtemplates')
                ->where('category', $category)
                ->where('defaultsubject', '')
                ->where('defaulttext', '')
                ->delete();
        }
    }
}
