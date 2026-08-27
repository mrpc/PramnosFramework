<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Adds `email_enabled` to user_twofactor — the account's own choice of the email factor.
 *
 * Two switches decide whether an account can receive a code by mail, and they answer
 * different questions. The **application** decides whether the method exists at all
 * (`auth.twofactor_methods`); this column is the **account** saying it wants it. An
 * installation that never offers the method leaves every row at 0 and nothing changes.
 *
 * Separate from `enabled`, which means "this account has TOTP". They are independent on
 * purpose: an account may have an authenticator app and no email factor, an email factor
 * and no app, or both — and the row has to be able to say so. Folding them into one flag
 * would make "2FA is on" ambiguous at exactly the moment a login has to decide what to
 * ask for.
 *
 * Additive & non-breaking: a new column with a default, idempotent via hasColumn().
 */
class AddEmailFactorToUserTwofactor extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 64;
    public array  $dependencies = ['create_user_twofactor_table'];
    public $description  = 'Adds email_enabled to user_twofactor (email second factor, per account)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasTable('authserver.user_twofactor')) {
            return;
        }

        if ($schema->hasColumn('authserver.user_twofactor', 'email_enabled')) {
            return;
        }

        $schema->alterTable('authserver.user_twofactor', function ($table) {
            $table->tinyInteger('email_enabled')->default(0)
                ->comment('1 = this account accepts a second-factor code by email');
        });
    }

    public function down(): void
    {
        $schema = $this->application->database->schema();

        if (!$schema->hasColumn('authserver.user_twofactor', 'email_enabled')) {
            return;
        }

        $schema->alterTable('authserver.user_twofactor', function ($table) {
            $table->dropColumn('email_enabled');
        });
    }
}
