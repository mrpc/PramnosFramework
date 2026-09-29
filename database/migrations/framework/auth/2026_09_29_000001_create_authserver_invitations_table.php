<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates authserver.invitations — invitations to register, one row per invitation.
 *
 * An invitation opens registration for one address while it is otherwise closed, and the link
 * reaching that address is what proves the person holds it. The token is stored only as its
 * lookup hash (`Pramnos\User\Token::lookup()`), so the table alone cannot be used to register:
 * the link is shown once, when it is made, and mailed.
 *
 * Times are epoch seconds, as `usertokens.expires` is, so "still valid" is one comparison on
 * every driver.
 */
class CreateAuthserverInvitationsTable extends Migration
{
    public string  $feature      = 'auth';
    public string  $scope        = 'framework';
    public int     $priority     = 40;
    public array   $dependencies = ['create_authserver_schema'];
    public $description  = 'Creates the authserver.invitations table for invitation-only registration';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.invitations')) {
            return;
        }

        $schema->createTable('authserver.invitations', function ($table) {
            $table->comment('Invitations to register: one address, one link, used once');

            $table->increments('invitation_id')
                ->comment('Auto-increment invitation identifier');
            $table->string('email', 255)
                ->comment('The invited address, lowercased — registration is accepted for this address only');
            $table->string('token_lookup', 64)
                ->comment('Token::lookup() of the link token — the token itself is never stored');
            $table->integer('organization_id')->nullable()
                ->comment('Organisation the new account joins on acceptance; NULL = none');
            $table->integer('role_id')->nullable()
                ->comment('Role the new account is given on acceptance; NULL = none');
            $table->string('note', 255)->nullable()
                ->comment('Free text from the inviter, shown in the list and the mail');
            $table->text('metadata')->nullable()
                ->comment('JSON an application attaches, read by its invitation.accepted listener');
            $table->bigInteger('invited_by')->nullable()
                ->comment('users.userid of the person who sent it');
            $table->bigInteger('created_at')
                ->comment('Epoch seconds when it was made');
            $table->bigInteger('expires_at')
                ->comment('Epoch seconds after which the link no longer opens registration');
            $table->bigInteger('accepted_at')->nullable()
                ->comment('Epoch seconds when an account was created with it; NULL = not yet');
            $table->bigInteger('accepted_userid')->nullable()
                ->comment('users.userid of the account created with it');
            $table->bigInteger('revoked_at')->nullable()
                ->comment('Epoch seconds when it was withdrawn; NULL = not withdrawn');

            $table->unique(['token_lookup'], 'uq_authserver_invitations_token');
            $table->index(['email'], 'idx_authserver_invitations_email');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.invitations');
    }
}
