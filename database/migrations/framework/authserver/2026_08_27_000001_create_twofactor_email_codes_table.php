<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the twofactor_email_codes table — one live code per user, per purpose.
 *
 * The store for the email second factor. Deliberately **not** an append-only log:
 * asking for a code invalidates the previous one, so a row is replaced rather than
 * added. Two reasons. A person who clicks "send it again" three times must not end up
 * with three codes that all work — that multiplies the guessing surface by three for
 * no gain. And the audit trail already exists: `authserver.twofactor_attempts` records
 * every verification attempt, which is the question an investigation asks.
 *
 * The code itself is never stored. What is stored is an HMAC of it keyed by the
 * installation secret and the user id, so a leaked table does not hand out live codes,
 * and a hash from one account cannot be replayed against another. HMAC rather than
 * bcrypt on purpose: a six-digit code has 10^6 possibilities, so no password-grade KDF
 * makes offline guessing hard — what makes it hard is the ten-minute lifetime, the
 * attempt cap and single use, all of which are enforced here.
 *
 * Non-breaking: a brand-new table, guarded by hasTable(); no hard foreign key on
 * userid, consistent with the other authserver tables.
 */
class CreateTwofactorEmailCodesTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 64;
    public array  $dependencies = ['create_authserver_schema', 'create_users_table'];
    public $description  = 'Creates the twofactor_email_codes table (email second factor)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.twofactor_email_codes')) {
            return;
        }

        $schema->createTable('authserver.twofactor_email_codes', function ($table) {
            $table->comment('Live email second-factor codes — one row per user per purpose');

            $table->bigIncrements('id')->comment('Auto-increment primary key');
            $table->bigInteger('userid')
                ->comment('Owner (users.userid) — no hard FK (app-layer integrity)');
            $table->string('purpose', 32)->default('login')
                ->comment('What the code authorises: login, or a step-up an application defines');
            $table->string('code_hash', 128)
                ->comment('HMAC-SHA-256 of the code, keyed by the installation secret and userid');
            $table->integer('expires_at')
                ->comment('Unix timestamp after which the code is refused');
            $table->integer('attempts')->default(0)
                ->comment('Failed verification attempts against this code — capped, then the code dies');
            $table->integer('created_at')
                ->comment('Unix timestamp the code was issued');

            $table->index(['userid', 'purpose'], 'idx_2fa_email_user_purpose');
            $table->index(['expires_at'], 'idx_2fa_email_expires');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()
            ->dropTableIfExists('authserver.twofactor_email_codes');
    }
}
