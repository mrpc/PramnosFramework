<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Adds the sync_consent_timestamp trigger to oauth2_user_consents.
 *
 * On INSERT or UPDATE the trigger sets updated_at = NOW() so the column
 * always reflects when the consent record was last modified.
 *
 * PostgreSQL: PL/pgSQL function authserver.sync_consent_timestamp() +
 *   trigger trg_sync_consent_timestamp BEFORE INSERT OR UPDATE.
 *
 * MySQL: Two separate BEFORE INSERT / BEFORE UPDATE triggers (MySQL does not
 *   support a single trigger for multiple events).  ON UPDATE CURRENT_TIMESTAMP
 *   on the column handles UPDATE, but not INSERT when the caller omits the
 *   field, so both triggers are needed for full parity.
 *
 */
class AddSyncConsentTimestampTrigger extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 32;
    public array  $dependencies = ['create_oauth2_user_consents_table'];
    public $description = 'Adds sync_consent_timestamp trigger to oauth2_user_consents for auto-updating updated_at';

    public function up(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->upPostgreSQL();
        } else {
            $this->upMySQL();
        }
    }

    // ------------------------------------------------------------------ //
    // PostgreSQL                                                           //
    // ------------------------------------------------------------------ //

    private function upPostgreSQL(): void
    {
        $this->DB()->query("
            CREATE OR REPLACE FUNCTION authserver.sync_consent_timestamp()
            RETURNS TRIGGER LANGUAGE plpgsql AS \$\$
            BEGIN
                NEW.updated_at = CURRENT_TIMESTAMP;
                RETURN NEW;
            END;
            \$\$
        ");

        $this->DB()->query("
            DROP TRIGGER IF EXISTS trg_sync_consent_timestamp
                ON authserver.oauth2_user_consents
        ");

        $this->DB()->query("
            CREATE TRIGGER trg_sync_consent_timestamp
            BEFORE INSERT OR UPDATE ON authserver.oauth2_user_consents
            FOR EACH ROW
            EXECUTE FUNCTION authserver.sync_consent_timestamp()
        ");
    }

    // ------------------------------------------------------------------ //
    // MySQL                                                                //
    // ------------------------------------------------------------------ //

    private function upMySQL(): void
    {
        $this->DB()->query("DROP TRIGGER IF EXISTS `trg_sync_consent_timestamp_insert`");
        $this->DB()->query("
            CREATE TRIGGER `trg_sync_consent_timestamp_insert`
            BEFORE INSERT ON `authserver_oauth2_user_consents`
            FOR EACH ROW SET NEW.updated_at = NOW()
        ");

        $this->DB()->query("DROP TRIGGER IF EXISTS `trg_sync_consent_timestamp_update`");
        $this->DB()->query("
            CREATE TRIGGER `trg_sync_consent_timestamp_update`
            BEFORE UPDATE ON `authserver_oauth2_user_consents`
            FOR EACH ROW SET NEW.updated_at = NOW()
        ");
    }

    // ------------------------------------------------------------------ //
    // Rollback                                                             //
    // ------------------------------------------------------------------ //

    public function down(): void
    {
        $caps = $this->DB()->schema()->getCapabilities();
        if ($caps->isPostgreSQL()) {
            $this->DB()->query(
                "DROP TRIGGER IF EXISTS trg_sync_consent_timestamp
                 ON authserver.oauth2_user_consents"
            );
            $this->DB()->query(
                "DROP FUNCTION IF EXISTS authserver.sync_consent_timestamp()"
            );
        } else {
            $this->DB()->query("DROP TRIGGER IF EXISTS `trg_sync_consent_timestamp_insert`");
            $this->DB()->query("DROP TRIGGER IF EXISTS `trg_sync_consent_timestamp_update`");
        }
    }
}
