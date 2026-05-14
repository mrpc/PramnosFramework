# PramnosFramework vs UrbanWater Migration Audit

## Existing PramnosFramework Migrations (v1.2-dev)

### Core
- ✅ 000000: create_sessions_table
- ✅ 000001: create_settings_table  
- ✅ 000002: create_framework_policies_table
- ✅ 000015: create_pramnos_schema

### Auth
- ✅ 000010: create_users_table
- ✅ 000011: create_userdetails_table
- ✅ 000012: create_userlog_table
- ✅ 000013: create_usernotes_table
- ✅ 000014: create_usertokens_table
- ✅ 000015: create_urls_table
- ✅ 000016: create_tokenactions_table (with hypertable support)
- ✅ 000017: create_loginlockout_table
- ✅ 000018: create_user_twofactor_table
- ✅ 000019: create_twofactor_setup_table
- ✅ 000020: create_twofactor_attempts_table (hypertable)
- ✅ 000021: create_user_activity_log_table (hypertable)
- ✅ 000022: create_user_privacy_settings_table
- ✅ 000023: create_user_consents_table (hypertable)
- ✅ 000024: create_data_processing_records_table (hypertable)
- ✅ 000025: create_gdpr_requests_table (hypertable)
- ✅ 000026: create_daily_activity_summary_view (continuous aggregate)

### AuthServer
- ✅ 000020: create_authserver_schema
- ✅ 000021: create_authserver_roles_table
- ✅ 000022: create_authserver_permissions_table
- ✅ 000023: create_authserver_user_roles_table
- ✅ 000024: create_authserver_audit_log_table
- ✅ 000025: create_applications_table
- ✅ 000026: create_device_authorizations_table
- ✅ 000027: create_jwt_replay_prevention_table
- ✅ 000028: create_oauth2_client_auth_methods_table
- ✅ 000029: create_oauth2_webhooks_tables
- ✅ 000030: create_slow_api_calls_view
- ✅ 000031: create_authserver_user_organizations_table
- ✅ 000032: create_authserver_permission_templates_table
- ✅ 000033: create_authserver_role_templates_table
- ✅ 000034: create_authserver_permission_inheritance_table
- ✅ 000035: create_authserver_effective_permissions_view
- ✅ 000036: create_authserver_rbac_functions
- ✅ 000037: create_applications_schema
- ✅ 000038: create_organizations_table
- ✅ 000039: create_oauth2_application_grants_table
- ✅ 000040: create_oauth2_helper_functions
- ✅ 000041: create_oauth2_device_codes_table
- ✅ 000042: create_oauth2_user_consents_table
- ✅ 000043: add_systemuser_to_applications

### Messaging
- ✅ 000030: create_mails_table
- ✅ 000031: create_mailtemplates_table
- ✅ 000032: create_messages_table
- ✅ 000033: create_massmessages_table
- ✅ 000034: create_massmessagerecipients_table

### Queue
- ✅ 000040: create_queueitems_table

---

## Missing Migrations (UrbanWater Schema Elements)

### Applications Schema (Priority: HIGH)
- ❌ 000037a: create_application_settings_table
  - rate_limit_requests, rate_limit_window_seconds, rate_limit_burst
  - enforce_pagination, max_page_size, default_page_size
  - ip_lock_enabled, allowed_ips[], blocked_ips[]
  - require_https, cors_enabled, cors_origins[]
  - with update trigger for updated_at
  
- ❌ 000037b: create_application_stats_table (hypertable)
  - time (partition key)
  - appid, total_requests, successful_requests, failed_requests
  - avg_response_time, min_response_time, max_response_time (NUMERIC 10,3)
  - status_2xx, status_3xx, status_4xx, status_5xx
  - rate_limited_requests, rate_limit_violations
  - bytes_sent, bytes_received
  - unique_ips_approx, country_code
  - 14-day chunks, compression enabled

### AuthServer Schema (Priority: HIGH)
- ❌ 000037c: add_user_app_authorizations_table
  - user_app_authorizations table for OAuth consent tracking
  - columns: id, userid, appid, scope, status, revoked_at, granted_at, requested_by

---

## Missing Foreign Keys in Existing Tables

### ✅ usertokens (000014)
**Current FKs:**
- userid → users.userid (CASCADE)

**Missing FKs (from UrbanWater):**
- parentToken → usertokens.tokenid (SET NULL)
- applicationid → applications.appid (SET NULL)
  *(Note: applicationid column exists, but FK is missing)*

### ✅ tokenactions (000016)
**Current FKs:**
- None explicitly defined in migration

**Missing FKs (from UrbanWater):**
- tokenid → usertokens.tokenid (CASCADE)
- urlid → urls.urlid (CASCADE)

### ✅ applications (000025)
**Current FKs:**
- None explicitly defined

**Missing FKs (from UrbanWater):**
- owner → users.userid (SET NULL)

### ✅ users (000010)
**Current FKs:**
- None explicitly defined

**Missing FKs (from UrbanWater):**
- locationid → locations.locationid (SET NULL)  *[if locations table exists in app]*

### ✅ All GDPR tables (000021-000025)
- user_activity_log, user_privacy_settings, user_consents, data_processing_records, gdpr_requests
- All missing: userid → users.userid (CASCADE) explicit FK

---

## Missing Views (UrbanWater Schema)

- ✅ 000030: authserver.slow_api_calls (exists)
- ❌ applications.webhook_delivery_status (aggregates webhook event stats per endpoint)
- ❌ applications.rate_limit_status (current rate limiting state per app)

---

## Missing Triggers/Functions (UrbanWater Schema)

- ✅ authserver.set_permission_priority() (exists)
- ✅ authserver.check_user_deya_membership() (exists)
- ✅ applications.create_webhook_event() (exists)
- ❌ applications.update_updated_at_column() (needed for application_settings.updated_at)
- ❌ authserver.sync_consent_timestamp() (for oauth2_user_consents.updated_at)

---

## Summary of Required Work

| Category | Count | Priority |
|----------|-------|----------|
| Missing tables | 3 | HIGH |
| Missing FKs in existing tables | 8+ | HIGH |
| Missing views | 2 | MEDIUM |
| Missing triggers/functions | 2 | MEDIUM |
| Missing indexes | 5+ | MEDIUM |

