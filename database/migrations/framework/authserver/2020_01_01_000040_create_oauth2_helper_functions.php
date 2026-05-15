<?php

namespace Pramnos\Framework\Migrations\AuthServer;

use Pramnos\Database\Migration;

/**
 * Creates OAuth2 helper functions, the token-revocation trigger, and the
 * webhook status monitoring view (PostgreSQL only — no MySQL equivalents
 * because PL/pgSQL and partial triggers are not portable).
 *
 * Functions created in the applications schema:
 *
 *   deauthorize_user_from_app(user_id, appid, reason, revoked_by?)
 *     Revokes all active tokens for a user+app pair, logs the action to
 *     authserver.user_activity_log, and fires a 'user_deauthorized' webhook.
 *
 *   create_gdpr_request(user_id, request_type, requested_by?)
 *     Creates a row in authserver.gdpr_requests and fires a 'gdpr_request'
 *     webhook to every app that currently holds an active token for the user.
 *
 *   notify_user_profile_changed(user_id, changes JSONB)
 *     Fires a 'user_profile_changed' webhook with the supplied changes payload.
 *
 *   token_revocation_webhook() — trigger function
 *     Installed on public.usertokens AFTER UPDATE; fires a 'token_revoked'
 *     webhook whenever a token's status transitions from 1 (active) to 0.
 *
 * View created in the applications schema:
 *
 *   oauth2_webhook_status
 *     Aggregates delivery statistics per webhook endpoint: total / successful /
 *     failed / pending event counts, last successful delivery, average attempts.
 *
 * @package PramnosFramework
 */
class CreateOauth2HelperFunctions extends Migration
{
    public string $feature      = 'authserver';
    public string $scope        = 'framework';
    public int    $priority     = 28;
    public array  $dependencies = [
        'create_oauth2_webhooks_tables',
        'create_oauth2_application_grants_table',
        'create_authserver_user_organizations_table',
    ];
    public $description = 'Creates OAuth2 helper PL/pgSQL functions, token-revocation trigger, and webhook status view';

    public function up(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if (!$caps->isPostgreSQL()) {
            // All objects in this migration are PostgreSQL-specific; nothing to do on MySQL
            return;
        }

        // --- deauthorize_user_from_app() ---
        $db->query("CREATE OR REPLACE FUNCTION applications.deauthorize_user_from_app(
    p_user_id   INTEGER,
    p_appid     INTEGER,
    p_reason    VARCHAR(100),
    p_revoked_by INTEGER DEFAULT NULL
)
RETURNS INTEGER AS \$\$
DECLARE
    tokens_revoked   INTEGER := 0;
    payload          JSONB;
    app_name         VARCHAR(255);
    revoked_by_name  VARCHAR(255) DEFAULT NULL;
BEGIN
    SELECT name INTO app_name FROM public.applications WHERE appid = p_appid;

    IF p_revoked_by IS NOT NULL THEN
        SELECT username INTO revoked_by_name FROM public.users WHERE userid = p_revoked_by;
    END IF;

    UPDATE public.usertokens
       SET status = 0
     WHERE userid = p_user_id
       AND applicationid = p_appid
       AND status = 1;

    GET DIAGNOSTICS tokens_revoked = ROW_COUNT;

    INSERT INTO authserver.user_activity_log (userid, action, details, created_at)
    VALUES (
        p_user_id,
        'oauth_app_deauthorized',
        jsonb_build_object(
            'app_id',           p_appid,
            'app_name',         app_name,
            'reason',           p_reason,
            'revoked_by',       p_revoked_by,
            'revoked_by_name',  revoked_by_name,
            'tokens_revoked',   tokens_revoked
        )::text,
        NOW()
    );

    payload := jsonb_build_object(
        'event_type',     'user_deauthorized',
        'user_id',        p_user_id,
        'app_id',         p_appid,
        'reason',         p_reason,
        'tokens_revoked', tokens_revoked,
        'timestamp',      extract(epoch from now())
    );

    PERFORM applications.create_webhook_event('user_deauthorized', p_user_id, payload);

    RETURN tokens_revoked;
END;
\$\$ LANGUAGE plpgsql");

        $db->query("COMMENT ON FUNCTION applications.deauthorize_user_from_app(INTEGER,INTEGER,VARCHAR,INTEGER) IS
            'Revokes all active tokens for a user+app pair, logs to user_activity_log, fires user_deauthorized webhook'");

        // --- create_gdpr_request() ---
        $db->query("CREATE OR REPLACE FUNCTION applications.create_gdpr_request(
    p_user_id      INTEGER,
    p_request_type VARCHAR(20),
    p_requested_by INTEGER DEFAULT NULL
)
RETURNS INTEGER AS \$\$
DECLARE
    request_id   INTEGER;
    payload      JSONB;
    user_record  RECORD;
    app_record   RECORD;
BEGIN
    SELECT userid, username, email INTO user_record
      FROM public.users
     WHERE userid = p_user_id;

    IF NOT FOUND THEN
        RAISE EXCEPTION 'User not found: %', p_user_id;
    END IF;

    INSERT INTO authserver.gdpr_requests (userid, request_type, processed_by, request_details)
    VALUES (p_user_id, p_request_type, p_requested_by, 'Webhook-triggered GDPR request')
    RETURNING id INTO request_id;

    payload := jsonb_build_object(
        'event_type',   'gdpr_request',
        'request_id',   request_id,
        'user_id',      p_user_id,
        'username',     user_record.username,
        'email',        user_record.email,
        'request_type', p_request_type,
        'timestamp',    extract(epoch from now())
    );

    -- Fire webhook for every application that currently holds tokens for the user
    FOR app_record IN
        SELECT DISTINCT a.appid, a.name
          FROM public.applications a
          JOIN public.usertokens ut ON a.appid = ut.applicationid
         WHERE ut.userid = p_user_id AND ut.status = 1
    LOOP
        PERFORM applications.create_webhook_event(
            'gdpr_request',
            p_user_id,
            payload || jsonb_build_object('app_id', app_record.appid, 'app_name', app_record.name)
        );
    END LOOP;

    RETURN request_id;
END;
\$\$ LANGUAGE plpgsql");

        $db->query("COMMENT ON FUNCTION applications.create_gdpr_request(INTEGER,VARCHAR,INTEGER) IS
            'Creates a GDPR request row and notifies all applications holding active tokens for the user'");

        // --- notify_user_profile_changed() ---
        $db->query("CREATE OR REPLACE FUNCTION applications.notify_user_profile_changed(
    p_user_id INTEGER,
    p_changes JSONB
)
RETURNS VOID AS \$\$
DECLARE
    payload     JSONB;
    user_record RECORD;
BEGIN
    SELECT userid, username, email INTO user_record
      FROM public.users
     WHERE userid = p_user_id;

    IF NOT FOUND THEN
        RETURN;
    END IF;

    payload := jsonb_build_object(
        'event_type', 'user_profile_changed',
        'user_id',    p_user_id,
        'username',   user_record.username,
        'email',      user_record.email,
        'changes',    p_changes,
        'timestamp',  extract(epoch from now())
    );

    PERFORM applications.create_webhook_event('user_profile_changed', p_user_id, payload);
END;
\$\$ LANGUAGE plpgsql");

        $db->query("COMMENT ON FUNCTION applications.notify_user_profile_changed(INTEGER,JSONB) IS
            'Fires a user_profile_changed webhook to all applications with active tokens for the user'");

        // --- token_revocation_webhook() trigger function + trigger ---
        $db->query("CREATE OR REPLACE FUNCTION public.token_revocation_webhook()
RETURNS TRIGGER AS \$\$
DECLARE
    payload JSONB;
BEGIN
    -- Only fires when a token transitions from active (1) to revoked (0)
    IF OLD.status = 1 AND NEW.status = 0 THEN
        payload := jsonb_build_object(
            'event_type', 'token_revoked',
            'token_id',   NEW.tokenid,
            'user_id',    NEW.userid,
            'app_id',     NEW.applicationid,
            'token_type', NEW.tokentype,
            'timestamp',  extract(epoch from now())
        );

        PERFORM applications.create_webhook_event(
            'token_revoked'::VARCHAR(50),
            NEW.userid::INTEGER,
            payload,
            NULL::VARCHAR(128),
            NEW.tokenid::INTEGER
        );
    END IF;

    RETURN NEW;
END;
\$\$ LANGUAGE plpgsql");

        $db->query("COMMENT ON FUNCTION public.token_revocation_webhook() IS
            'Trigger function: fires token_revoked webhook when a usertokens row status changes 1→0'");

        $db->query("DROP TRIGGER IF EXISTS trigger_token_revocation_webhook ON public.usertokens");
        $db->query("CREATE TRIGGER trigger_token_revocation_webhook
    AFTER UPDATE ON public.usertokens
    FOR EACH ROW
    EXECUTE FUNCTION public.token_revocation_webhook()");

        $db->query("COMMENT ON TRIGGER trigger_token_revocation_webhook ON public.usertokens IS
            'Fires token_revoked webhook automatically when a token status transitions active→revoked'");

        // --- oauth2_webhook_status VIEW ---
        $db->query("CREATE OR REPLACE VIEW applications.oauth2_webhook_status AS
            SELECT
                wep.webhook_id,
                wep.appid,
                a.name         AS app_name,
                wep.webhook_type,
                wep.endpoint_url,
                wep.is_active,
                COUNT(we.event_id)                                           AS total_events,
                COUNT(CASE WHEN we.status = 'sent'    THEN 1 END)           AS successful_events,
                COUNT(CASE WHEN we.status = 'failed'  THEN 1 END)           AS failed_events,
                COUNT(CASE WHEN we.status = 'pending' THEN 1 END)           AS pending_events,
                MAX(we.sent_at)                                              AS last_successful_delivery,
                AVG(CASE WHEN we.status = 'sent' THEN we.attempts END)      AS avg_attempts_for_success
            FROM applications.oauth2_webhook_endpoints wep
            JOIN public.applications a ON wep.appid = a.appid
            LEFT JOIN applications.oauth2_webhook_events we ON wep.webhook_id = we.webhook_id
            GROUP BY wep.webhook_id, wep.appid, a.name,
                     wep.webhook_type, wep.endpoint_url, wep.is_active");

        $db->query("COMMENT ON VIEW applications.oauth2_webhook_status IS
            'Webhook delivery statistics per endpoint: total/successful/failed/pending events and last delivery'");
    }

    public function down(): void
    {
        $db   = $this->application->database;
        $caps = $db->schema()->getCapabilities();

        if (!$caps->isPostgreSQL()) {
            return;
        }

        $db->query("DROP VIEW IF EXISTS applications.oauth2_webhook_status");
        $db->query("DROP TRIGGER IF EXISTS trigger_token_revocation_webhook ON public.usertokens");
        $db->query("DROP FUNCTION IF EXISTS public.token_revocation_webhook()");
        $db->query("DROP FUNCTION IF EXISTS applications.notify_user_profile_changed(INTEGER, JSONB)");
        $db->query("DROP FUNCTION IF EXISTS applications.create_gdpr_request(INTEGER, VARCHAR, INTEGER)");
        $db->query("DROP FUNCTION IF EXISTS applications.deauthorize_user_from_app(INTEGER, INTEGER, VARCHAR, INTEGER)");
    }
}
