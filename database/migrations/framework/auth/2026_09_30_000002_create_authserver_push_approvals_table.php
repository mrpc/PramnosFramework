<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates authserver.push_approvals — "Is it you trying to sign in?", one row per ask.
 *
 * A sign-in waiting on its second factor asks the account's trusted devices by push. The
 * row is bound to the waiting browser's session (by hash), carries what the phone is
 * shown about the attempt, and records the receipt and the answer. When the attempt looks
 * unfamiliar the phone must pick `number` out of `choices`, which is what stops a
 * notification tapped by reflex from letting somebody in.
 */
class CreateAuthserverPushApprovalsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 40;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.push_approvals table for sign-in approval by push';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.push_approvals')) {
            return;
        }

        $schema->createTable('authserver.push_approvals', function ($table) {
            $table->comment('Sign-in approvals asked of trusted devices by push');

            $table->increments('approval_id')
                ->comment('Auto-increment approval identifier');
            $table->bigInteger('userid')
                ->comment('users.userid of the account signing in');
            $table->string('token_lookup', 64)
                ->comment('Token::lookup() of the token in the notification — never stored itself');
            $table->string('session_lookup', 64)
                ->comment('sha256 of the waiting browser\'s session id: only it can finish with this');
            $table->smallInteger('number')->nullable()
                ->comment('The number to pick on the phone; NULL = a plain yes or no');
            $table->string('choices', 32)->default('')
                ->comment('The numbers the phone offers, comma-separated, the right one among them');
            $table->string('user_agent', 255)->default('')
                ->comment('The browser asking to sign in, as the phone describes it');
            $table->string('ip', 45)->default('')
                ->comment('Where the attempt came from');
            $table->string('country', 2)->default('')
                ->comment('Country of the attempt, when known');
            $table->bigInteger('created_at')
                ->comment('Epoch seconds when it was asked');
            $table->bigInteger('expires_at')
                ->comment('Epoch seconds after which it can no longer be answered');
            $table->bigInteger('delivered_at')->nullable()
                ->comment('Epoch seconds when a device reported receiving it');
            $table->bigInteger('decided_at')->nullable()
                ->comment('Epoch seconds when it was answered');
            $table->string('decision', 16)->nullable()
                ->comment('approved | denied');
            $table->bigInteger('decided_device_id')->nullable()
                ->comment('authserver.trusted_devices.device_id that answered');
            $table->bigInteger('consumed_at')->nullable()
                ->comment('Epoch seconds when the approval signed the waiting browser in');

            $table->unique(['token_lookup'], 'uq_authserver_push_approvals_token');
            $table->index(['userid', 'created_at'], 'idx_authserver_push_approvals_user');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.push_approvals');
    }
}
