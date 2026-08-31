<?php

namespace Pramnos\Framework\Migrations\Auth;

use Pramnos\Database\Migration;

/**
 * Widens the two columns that hold a TOTP seed, so the seed can be stored encrypted.
 *
 * A base32 seed is 32 characters and `VARCHAR(64)` was ample for it. Encrypted with
 * {@see \Pramnos\Security\Encrypter} the same value becomes roughly 110 characters —
 * the `enc:v1:` marker, then base64 of a 24-byte nonce plus the ciphertext and its
 * 16-byte authentication tag. At 64 the write fails outright on MySQL
 * (`Data too long for column 'temp_secret'`), which is how this was found: the
 * integration test asserting the column holds ciphertext could not write one.
 *
 * 255 rather than exactly what fits, so a later format — a longer nonce, a second
 * key id in the marker — does not need another migration to store the same secret.
 *
 * Widening only. No value changes, nothing is truncated, and a row still holding a
 * plaintext seed is unaffected: it is read through `maybeDecrypt()`, which returns
 * anything without the marker unchanged, and converts itself when it is next written.
 */
class WidenTotpSecretColumns extends Migration
{
    public string $feature      = 'auth';
    public string $scope        = 'framework';
    // Above create_user_twofactor_table (80) and create_twofactor_setup_table (85):
    // the runner orders by priority, and an ALTER that runs before its table exists
    // finds no column, silently does nothing, and leaves the old width in place.
    public int    $priority     = 90;
    public array  $dependencies = [
        'create_user_twofactor_table',
        'create_twofactor_setup_table',
    ];
    public $description = 'Widens user_twofactor.secret and twofactor_setup.temp_secret to hold encrypted seeds';

    public function up(): void
    {
        $schema = $this->application->database->schema();

        if ($schema->hasColumn('authserver.user_twofactor', 'secret')) {
            $schema->alterTable('authserver.user_twofactor', function ($table) {
                $table->modifyColumn('secret', 'string', ['length' => 255])
                    ->nullable()
                    ->comment(
                        'TOTP seed, encrypted at rest (enc:v1:…); NULL when 2FA is disabled'
                    );
            });
        }

        if ($schema->hasColumn('authserver.twofactor_setup', 'temp_secret')) {
            $schema->alterTable('authserver.twofactor_setup', function ($table) {
                $table->modifyColumn('temp_secret', 'string', ['length' => 255])
                    ->comment(
                        'TOTP seed of an enrolment in progress, encrypted at rest (enc:v1:…)'
                    );
            });
        }
    }

    /**
     * Narrowing back to 64 would truncate any encrypted seed in the table, and a
     * truncated seed is an account locked out of its own second factor. A column
     * that is wider than it needs to be costs nothing, so the down migration
     * deliberately does nothing.
     */
    public function down(): void
    {
    }
}
