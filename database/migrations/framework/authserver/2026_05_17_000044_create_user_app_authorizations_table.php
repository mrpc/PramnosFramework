<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates the authserver.user_app_authorizations table.
 *
 * Tracks OAuth consent and authorization scope grants per user/application
 * pair.  Enables users to revoke application access and stores the full
 * lifecycle of each authorization (granted → revoked / expired).
 *
 * On PostgreSQL: lives in the `authserver` schema as
 *   authserver.user_app_authorizations; scope is stored as TEXT[].
 *   A UNIQUE constraint on (userid, appid) ensures at most one active
 *   authorization record per pair.
 *
 * On MySQL: lives in the default database as
 *   authserver_user_app_authorizations; scope stored as JSON.
 *
 */
class CreateUserAppAuthorizationsTable extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 30;
    public array  $dependencies = [
        'create_authserver_schema',
        'create_users_table',
        'create_applications_table',
    ];
    public $description = 'Creates authserver.user_app_authorizations — OAuth consent tracking per user/app pair';

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
            CREATE TABLE IF NOT EXISTS authserver.user_app_authorizations (
                id              BIGSERIAL       PRIMARY KEY,
                userid          BIGINT          NOT NULL
                                    REFERENCES public.users(userid)
                                    ON DELETE CASCADE ON UPDATE CASCADE,
                appid           INTEGER         NOT NULL
                                    REFERENCES public.applications(appid)
                                    ON DELETE CASCADE ON UPDATE CASCADE,
                scope           TEXT[],
                status          VARCHAR(10)     NOT NULL DEFAULT 'granted'
                                    CHECK (status IN ('granted','revoked','pending','expired')),

                granted_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                revoked_at      TIMESTAMP,
                expires_at      TIMESTAMP,
                last_used_at    TIMESTAMP,

                requested_by    BIGINT
                                    REFERENCES public.users(userid)
                                    ON DELETE SET NULL ON UPDATE CASCADE,
                user_agent      VARCHAR(255),
                ip_address      VARCHAR(45),

                CONSTRAINT idx_user_app_auth_unique UNIQUE (userid, appid)
            )
        ");

        $this->DB()->query(
            "CREATE INDEX IF NOT EXISTS idx_user_app_auth_appid
             ON authserver.user_app_authorizations (appid)"
        );
        $this->DB()->query(
            "CREATE INDEX IF NOT EXISTS idx_user_app_auth_status
             ON authserver.user_app_authorizations (status)"
        );
        $this->DB()->query(
            "CREATE INDEX IF NOT EXISTS idx_user_app_auth_revoked_at
             ON authserver.user_app_authorizations (revoked_at)"
        );

        $this->DB()->query(
            "COMMENT ON TABLE authserver.user_app_authorizations IS
             'OAuth consent and authorization scope grants per user/application pair'"
        );
    }

    // ------------------------------------------------------------------ //
    // MySQL                                                                //
    // ------------------------------------------------------------------ //

    private function upMySQL(): void
    {
        $this->DB()->query("
            CREATE TABLE IF NOT EXISTS `authserver_user_app_authorizations` (
                `id`            BIGINT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `userid`        BIGINT          NOT NULL,
                `appid`         INT UNSIGNED    NOT NULL,
                `scope`         JSON,
                `status`        VARCHAR(10)     NOT NULL DEFAULT 'granted'
                                    CHECK (`status` IN ('granted','revoked','pending','expired')),

                `granted_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `revoked_at`    DATETIME,
                `expires_at`    DATETIME,
                `last_used_at`  DATETIME,

                `requested_by`  BIGINT,
                `user_agent`    VARCHAR(255),
                `ip_address`    VARCHAR(45),

                CONSTRAINT `idx_user_app_auth_unique` UNIQUE (`userid`, `appid`),
                KEY `idx_user_app_auth_appid` (`appid`),
                KEY `idx_user_app_auth_status` (`status`),
                KEY `idx_user_app_auth_revoked_at` (`revoked_at`),
                CONSTRAINT `fk_user_app_auth_userid`
                    FOREIGN KEY (`userid`) REFERENCES `users` (`userid`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_user_app_auth_appid`
                    FOREIGN KEY (`appid`) REFERENCES `applications` (`appid`)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_user_app_auth_requested_by`
                    FOREIGN KEY (`requested_by`) REFERENCES `users` (`userid`)
                    ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
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
                "DROP TABLE IF EXISTS authserver.user_app_authorizations"
            );
        } else {
            $this->DB()->query(
                "DROP TABLE IF EXISTS `authserver_user_app_authorizations`"
            );
        }
    }
}
