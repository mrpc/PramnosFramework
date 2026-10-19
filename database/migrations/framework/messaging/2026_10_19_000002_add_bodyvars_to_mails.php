<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Adds `bodyvars` to `mails`: the values a templated message was filled with, so its body is kept
 * once, as the template, and read back filled for each recipient ({@see \Pramnos\Email\BodyStore}).
 *
 * Nullable and last: on MySQL 8 and MariaDB the column is added instantly, whatever the table's
 * size, and every existing row reads as a message that was not templated.
 */
class AddBodyvarsToMails extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_mails_table'];
    public $description  = 'Adds bodyvars to mails, for messages kept once as their template';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('mails') || $schema->hasColumn('mails', 'bodyvars')) {
            return;
        }

        $schema->alterTable('mails', function ($table) {
            $table->text('bodyvars')->nullable()
                ->comment('The values a templated message was filled with, as JSON; null for one that was not');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('mails', 'bodyvars')) {
            $schema->alterTable('mails', function ($table) {
                $table->dropColumn('bodyvars');
            });
        }
    }
}
