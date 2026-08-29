<?php

namespace Pramnos\Framework\Migrations\Messaging;

use Pramnos\Database\Migration;

/**
 * Creates the emailoptouts table — who asked to stop receiving what.
 *
 * Keyed on the **address**, not on a user id, and that is deliberate. An unsubscribe arrives
 * from a mailbox: sometimes from a person with an account, often from one who was added to a
 * list, forwarded a message, or inherited an address. A record that can only describe a user
 * cannot honour the request the moment it is made, which is the one thing a mailbox provider
 * measures.
 *
 * One row per (address, list). `list` is a short name the application chooses — `marketing`,
 * `newsletter` — plus the reserved `all`, which suppresses everything that carries an
 * unsubscribe link. Transactional mail (a password reset, a second-factor code) is not on a
 * list and is never suppressed by a row here: nobody unsubscribes from being able to sign in.
 *
 * `source` says how the request arrived, because the answer changes what it means: a one-click
 * header press is a mailbox provider acting on somebody's behalf (RFC 8058) and must be
 * honoured without confirmation, while a page submission is the person themselves.
 */
class CreateEmailoptoutsTable extends Migration
{
    public string  $feature      = 'messaging';
    public string  $scope        = 'framework';
    public int     $priority     = 50;
    public array   $dependencies = ['create_pramnos_schema'];
    public $description  = 'Creates the emailoptouts table — unsubscribe records by address and list';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        // Declared as a dependency too, but the runner is not the only caller: an integration
        // test loads one feature's directory and runs it, and there `create_pramnos_schema` has
        // not happened. A no-op on MySQL and when the schema is already there.
        $schema->ensureSchema('pramnos');

        if ($schema->hasTable('pramnos.emailoptouts')) {
            return;
        }

        $schema->createTable('pramnos.emailoptouts', function ($table) {
            $table->comment(
                'Unsubscribe records — one row per (email address, list). Consulted before any '
                . 'mail that carries an unsubscribe link; transactional mail is never on a list.'
            );

            $table->increments('optoutid')
                ->comment('Auto-increment record identifier');
            $table->string('email', 255)
                ->comment('Recipient address, lowercased — the key, because an unsubscribe '
                    . 'arrives from a mailbox and not always from an account');
            $table->string('list', 64)->default('all')
                ->comment('List name the application chose, or the reserved "all"');
            $table->string('source', 32)->default('')
                ->comment('How the request arrived: one_click (RFC 8058 header), page, admin, import');
            $table->integer('created_at')->default(0)
                ->comment('Unix timestamp the request was recorded');

            $table->index(['email', 'list'], 'idx_emailoptouts_pair');
            $table->index(['email'], 'idx_emailoptouts_email');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('pramnos.emailoptouts');
    }
}
