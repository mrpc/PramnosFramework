<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the passkey_credentials table (WebAuthn / FIDO2).
 *
 * One row per registered passkey. Binary WebAuthn values (credential id, COSE
 * public key) are stored base64url/base64-encoded as text so the schema is
 * portable across MySQL and PostgreSQL (no bytea/blob divergence). The raw
 * decoding lives in the WebAuthn adapter, not the database.
 *
 * Non-breaking (framework rule §8, DB-safety §3): a brand-new table, guarded by
 * hasTable(); NO hard foreign key on userid (app-layer integrity, consistent
 * with the other authserver tables). Current-date timestamp (§9).
 */
class CreatePasskeyCredentialsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 63;
    public array  $dependencies = ['create_authserver_schema', 'create_users_table'];
    public $description  = 'Creates the passkey_credentials table (WebAuthn/FIDO2 credentials)';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasTable('authserver.passkey_credentials')) {
            return;
        }

        $schema->createTable('authserver.passkey_credentials', function ($table) {
            $table->comment('Registered WebAuthn/FIDO2 passkeys — one row per credential');

            $table->bigIncrements('credentialid')->comment('Auto-increment primary key');
            $table->bigInteger('userid')
                ->comment('Owner (users.userid) — no hard FK (app-layer integrity)');
            $table->string('credential_id', 512)
                ->comment('Base64url-encoded raw WebAuthn credential id (unique per credential)');
            $table->text('public_key')
                ->comment('COSE public key, base64-encoded, used to verify assertions');
            $table->bigInteger('sign_count')->default(0)
                ->comment('Signature counter; a non-increasing counter signals a cloned authenticator');
            $table->string('aaguid', 64)->nullable()
                ->comment('Authenticator AAGUID (model identifier); attestation=none may leave it zeroed');
            $table->text('transports')->nullable()
                ->comment('JSON array of transports (usb, nfc, ble, internal, hybrid)');
            $table->string('name', 255)->nullable()
                ->comment('User-supplied label for managing the passkey in the dashboard');
            $table->boolean('backup_eligible')->default(false)
                ->comment('Authenticator flag: credential is eligible for backup (syncable passkey)');
            $table->boolean('backup_state')->default(false)
                ->comment('Authenticator flag: credential is currently backed up');
            $table->boolean('is_active')->default(true)
                ->comment('Soft-delete/revocation flag; inactive passkeys are ignored at authentication');
            $table->timestamp('created_at')->nullable()
                ->comment('When the passkey was registered');
            $table->timestamp('last_used_at')->nullable()
                ->comment('When the passkey was last used to authenticate');

            $table->unique(['credential_id'], 'uq_passkey_credential_id');
            $table->index(['userid'], 'idx_passkey_userid');
        });
    }

    public function down(): void
    {
        $this->application->database->schema()->dropTableIfExists('authserver.passkey_credentials');
    }
}
