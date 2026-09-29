<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Lets a mass message go to an address with no account behind it.
 *
 * A recipient was a `userid` and nothing else, so a list's subscribers who never registered —
 * the people a landing-page newsletter form is for — could not be sent to. `email` and `language`
 * carry the recipient's own address and language for such a row (its `userid` is `0`), and for a
 * list recipient who does have an account, the address that subscribed.
 */
class AddAddressToMassmessagerecipients extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_massmessagerecipients_table'];
    public $description  = 'Adds email and language to massmessagerecipients, for recipients without an account';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('massmessagerecipients') || $schema->hasColumn('massmessagerecipients', 'email')) {
            return;
        }

        $schema->alterTable('massmessagerecipients', function ($table) {
            $table->string('email', 255)->nullable()
                ->comment('The address to send to, when it is not the account\'s (a list subscriber)');
            $table->string('language', 10)->nullable()
                ->comment('The language to write to that address in');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('massmessagerecipients', 'email')) {
            $schema->alterTable('massmessagerecipients', function ($table) {
                $table->dropColumn('email');
                $table->dropColumn('language');
            });
        }
    }
}
