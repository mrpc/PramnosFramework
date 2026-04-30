# UrbanWater → PramnosFramework: Backport Features

Αυτό το αρχείο περιγράφει **πολύ αναλυτικά** τι ακριβώς θα μεταφερθεί από το UrbanWater project στο PramnosFramework v1.2. Κάθε section περιέχει: τι υπάρχει σήμερα στο framework, πώς λειτουργεί στο UrbanWater, το **ακριβές SQL / κώδικα** από τη βάση/αρχεία, και τι ακριβώς χρειάζεται να γίνει για το backport.

> Το αντίστοιχο ROADMAP_1.2.md περιέχει τα ίδια items **συνοπτικά**. Αυτό το αρχείο είναι η αναλυτική προδιαγραφή που κατευθύνει την υλοποίηση.

---

## 1. Migration System Overhaul

### Τρέχουσα κατάσταση στο Framework

Το `Pramnos\Database\Migration` είναι abstract class με:
- `version` (string), `description` (string), `autoExecute` (bool), `queriesToExecute` (array)
- Abstract `up()` / `down()` — το `down()` **δεν καλείται ποτέ** στον τρέχοντα κώδικα
- Tracking table `#PREFIX#schemaversion` με **τρία fields**: `when TIMESTAMP, key VARCHAR(255) PK, extra VARCHAR(255)`
- Registry file `APP_PATH/migrations.php` — απλό associative array `version => ClassName`
- Εκτέλεση μέσω `Application::upgrade()` → `Application::runMigration()` χωρίς ordering, χωρίς dependencies, χωρίς batch tracking
- **Δεν υπάρχει**: priority, dependencies, datetime ordering, rollback, dry-run, migration_cutoff, scope διαχωρισμός

### Τι φέρνει το UrbanWater

#### 1.1 Ακριβής δομή tracking table (DDL from UrbanWater)

```sql
-- Το UrbanWater χρησιμοποιεί αυτή τη δομή στο schemaversion του:
CREATE TABLE "schemaversion" (
  "when" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "key" VARCHAR(255) PRIMARY KEY,
  "extra" VARCHAR(255) DEFAULT NULL
);
```

**Νέα δομή framework_migrations** (αντικαθιστά και αναβαθμίζει το παραπάνω):

```sql
CREATE TABLE framework_migrations (
    id               SERIAL PRIMARY KEY,
    migration        VARCHAR(255) NOT NULL UNIQUE,  -- όνομα κλάσης/αρχείου
    scope            VARCHAR(255) NOT NULL DEFAULT 'app',     -- 'app' | 'framework'
    feature          VARCHAR(255) NULL,              -- 'auth' | 'queue' | NULL
    batch            INTEGER NULL,                  -- ομαδοποίηση εκτέλεσης
    execution_time   DOUBLE PRECISION NULL,          -- seconds
    result           SMALLINT NOT NULL DEFAULT 1,   -- 1=success, 0=failed
    error_message    TEXT NULL,
    description      VARCHAR(255) NULL,
    ran_at           TIMESTAMP NOT NULL DEFAULT NOW()
);
```

#### 1.2 Νέα δομή Migration class

```php
abstract class Migration
{
    public string  $version      = '';
    public string  $description  = '';
    public string  $feature      = '';       // feature key ή '' για app migrations
    public int     $priority     = 50;       // μικρότερο = τρέχει πρώτο (default: 50)
    public array   $dependencies = [];       // slugs άλλων migrations
    public bool    $autorun      = true;     // ΜΕΤΟΝΟΜΑΣΙΑ από autoExecute

    abstract public function up(): void;
    abstract public function down(): void;   // ΕΝΕΡΓΟΠΟΙΕΙΤΑΙ για rollback
}
```

**Ονοματολογία αρχείων:** `YYYY_MM_DD_HHmmss_<slug>.php`
- Παράδειγμα: `2024_03_15_143022_create_users_table.php`

#### 1.3 Sorting algorithm

1. **Topological sort** — επίλυση `$dependencies`
2. **Priority ascending** — Framework core: priority=1, auth: priority=10, app: priority=50
3. **Datetime από filename** ascending — tie-breaker

#### 1.4 Auto-run mechanism

Κατά το bootstrap:
1. Φορτώνει framework migrations (μόνο active features) + app migrations
2. Ελέγχει **`migration_cutoff`** από settings — migrations με timestamp στο filename **παλιότερο** από cutoff παραλείπονται σιωπηλά
3. Εξαιρεί `autorun = false` migrations
4. Εξαιρεί migrations που ήδη καταγράφονται στο history table (έχουν τρέξει)
5. Αποτυχία: καταγράφεται, δεν σταματά bootstrap, dependent migrations παραλείπονται

#### 1.5 Στρατηγική για υπάρχουσες εγκαταστάσεις (π.χ. UrbanWater production)

Τα framework **baseline** system migrations φέρουν σκόπιμα παλιά timestamps — π.χ. `2020_01_01_000000_create_sessions_table.php`. Αυτό σημαίνει:

- **Fresh install** (νέο project): δεν ορίζεται `migration_cutoff` → τα baseline migrations τρέχουν και φτιάχνουν όλους τους πίνακες από μηδέν.
- **Υπάρχουσα εγκατάσταση** (π.χ. UrbanWater που αναβαθμίζεται σε framework v1.2): ο διαχειριστής θέτει `migration_cutoff = '2026-01-01 00:00:00'` (ή οποιαδήποτε ημερομηνία μετά τα `2020_*` timestamps). Ο runner παρακάμπτει όλα τα baseline migrations — οι πίνακες ήδη υπάρχουν από τα UW migrations που έχουν τρέξει σε production.

**Τα UrbanWater migrations δεν αλλάζουν ποτέ.** Δεν απενεργοποιούνται, δεν αφαιρούνται, δεν σημαίνονται ως `autorun=false`. Είναι production history — αγγίζονται μόνο αν υπάρχει bug σε αυτά.

#### 1.5 CLI Commands

```bash
pramnos migrate                                    # όλα τα pending
pramnos migrate --scope=framework|app
pramnos migrate --feature=auth
pramnos migrate CreateUsersTable                   # by class name
pramnos migrate 2024_03_15_143022_create_users_table
pramnos migrate --force                            # περιλαμβάνει autorun=false
pramnos migrate --pretend                          # dry run
pramnos migrate:rollback                           # τελευταίο batch
pramnos migrate:rollback --batch=3
pramnos migrate:rollback CreateUsersTable
pramnos migrate:reset                              # αντίστροφη σειρά
pramnos migrate:refresh [--seed]                   # reset + επανεκτέλεση
pramnos migrate:status                             # Migration | Scope | Feature | Status | Batch | Ran At | Time
pramnos migrate:export CreateUsersTable --format=sql|php
pramnos db:seed [UsersSeeder]
```

#### 1.6 Τι πρέπει να γίνει για το backport

- [ ] Νέο schema για tracking table (migration από παλιό `schemaversion`)
- [ ] Νέα abstract `Migration` base class
- [ ] `MigrationRunner` class: φόρτωση, sorting, execution, history tracking
- [ ] Ενσωμάτωση `migration_cutoff` στο Settings system
- [ ] Auto-run στο `Application::init()` (αντικαθιστά το `upgrade()`)
- [ ] Όλα τα CLI commands
- [ ] BC: `autoExecute` → `autorun` (deprecated notice)
- [ ] BC: παλιό `schemaversion` auto-migrate στο `framework_migrations`

---

## 2. Feature Registry

### Τρέχουσα κατάσταση στο Framework

Δεν υπάρχει κανένα σύστημα feature flags. Το `app.php` δεν έχει `features` key.

### Τι φέρνει το UrbanWater

```php
// app.php
'features' => [
    'auth',        // Basic Auth System
    'authserver',  // OAuth Server
    'messaging',   // Messaging
    'queue',       // Queue System
],
```

#### 2.1 Πώς λειτουργεί

Κατά το `Application::init()`:
1. Διαβάζει `applicationInfo['features']`
2. Φορτώνει `FeatureServiceProvider` για κάθε feature: `register()` + `boot()`
3. `MigrationRunner` φορτώνει μόνο τα migrations των active features

#### 2.2 Features και system tables

| Feature key | System Tables | Notes |
|---|---|---|
| *(implicit core)* | `sessions`, `settings`, `framework_migrations` | Πάντα φορτωμένο |
| `auth` | `users`, `userdetails`, `userlog`, `usernotes`, `usertokens`, `tokenactions`, `urls` | Basic auth |
| `authserver` | `applications` extensions, `authserver.*`, GDPR tables, 2FA tables | Απαιτεί `auth` |
| `messaging` | `messages`, `massmessages`, `massmessagerecipients`, `mails`, `mailtemplates` | Απαιτεί `auth` |
| `queue` | `queueitems` (+ ENUM type) | — |

#### 2.3 Τι πρέπει να γίνει για το backport

- [ ] `ServiceProvider` interface με `register()` / `boot()`
- [ ] `FeatureRegistry` class: validation, loading, exception για unknown key
- [ ] Ένας `ServiceProvider` ανά feature
- [ ] Ενσωμάτωση στο `Application::init()`
- [ ] Ενημέρωση `pramnos init` wizard

---

## 3. OAuth Server

### Τρέχουσα κατάσταση στο Framework

`JWT.php`: token encoding/decoding (HS256/RS256). `Token.php`: αποθήκευση tokens στη βάση χωρίς OAuth flow.

### Τι φέρνει το UrbanWater

**Library**: `league/oauth2-server` (PHP League)

**Grant types (από `OAuth2ServerFactory.php`):**
- `ClientCredentialsGrant` — access token 1h
- `PasswordGrant` — access token 1h, refresh token 1 month
- `AuthCodeGrant` — auth code 10min, access token 1h, refresh token 1 month
- `RefreshTokenGrant` — new refresh token 1 month

**Key files στο `auth/src/OAuth2/`:**
- `OAuth2ServerFactory.php` — factory για Authorization + Resource server
- `Repositories/ClientRepository.php` — ApplicationID → ClientEntity
- `Repositories/ScopeRepository.php` — scope validation
- `Repositories/AccessTokenRepository.php` — token persistence via `usertokens`
- `Repositories/AuthCodeRepository.php` — auth code persistence via `usertokens`
- `Repositories/RefreshTokenRepository.php` — refresh token via `usertokens`
- `Repositories/UserRepository.php` — password grant user verification
- `Entities/ClientEntity.php`, `UserEntity.php`, `ScopeEntity.php`, `AuthCodeEntity.php`, `RefreshTokenEntity.php`, `AccessTokenEntity.php`
- `Middleware/OAuth2Middleware.php` — PSR-7 middleware για resource validation

**Key files στο `auth/src/Controllers/`:**
- `Oauth.php` — authorize + token endpoints
- `Session.php` — login/logout flow για Authorization Code
- `Device.php` — device authorization flow (RFC 8628)
- `Discovery.php` — OAuth2 discovery endpoint
- `Gdpr.php` — GDPR endpoints (export, delete, consents)
- `TwoFactorAuth.php` — 2FA setup + challenge endpoints
- `Dashboard.php` — user dashboard για authorized apps

**Key files στο `auth/src/Helpers/`:**
- `Scopes.php` — scope definitions
- `OAuthPolicyHelper.php` — policy checks
- `TOTPHelper.php` — TOTP algorithm helper

**Key files στο `auth/src/Services/`:**
- `TwoFactorAuthService.php` — TOTP 2FA service
- `WebhookService.php` — webhook delivery service

#### 3.1 RSA Key pair generation

```php
// OAuth2ServerFactory::generateKeyPair()
$privateKey = openssl_pkey_new([
    'digest_alg' => 'sha256',
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
// Stored at: ROOT/app/keys/private.key (chmod 0600), public.key (chmod 0644)
```

#### 3.2 PKCE Support (RFC 7636) — DDL

```sql
-- ALTER TABLE usertokens additions:
ALTER TABLE public.usertokens 
ADD COLUMN code_challenge VARCHAR(128) DEFAULT NULL,
ADD COLUMN code_challenge_method VARCHAR(10) DEFAULT NULL;

-- Constraints:
ADD CONSTRAINT chk_code_challenge_method 
  CHECK (code_challenge_method IS NULL OR code_challenge_method IN ('plain', 'S256'));
ADD CONSTRAINT chk_code_challenge_format 
  CHECK (code_challenge IS NULL OR 
    (length(code_challenge) >= 43 AND length(code_challenge) <= 128 
    AND code_challenge ~ '^[A-Za-z0-9\-._~]+$'));

-- Indexes:
CREATE INDEX idx_usertokens_code_challenge ON usertokens (code_challenge) WHERE code_challenge IS NOT NULL;
CREATE UNIQUE INDEX idx_usertokens_auth_code_unique ON usertokens (token, code_challenge) 
  WHERE tokentype = 'auth_code' AND code_challenge IS NOT NULL;
```

#### 3.3 Token column → TEXT (for JWTs)

```sql
-- usertokens.token changes from VARCHAR(255) to TEXT
-- to accommodate JWTs of any size (RS256/RS512 with any number of claims)
```

#### 3.4 Device Authorization (RFC 8628) — DDL

```sql
CREATE TABLE IF NOT EXISTS authserver.device_authorizations (
    -- (exact DDL at lines 142014+ of urbanwater_db.sql)
    -- Stores device_code, user_code, verification_uri, expires_at, 
    -- scope, client_id, status, user_id (after approval)
);
```

#### 3.5 JWT Replay Prevention — DDL

```sql
CREATE TABLE authserver.jwt_replay_prevention (
    -- Stores JWT jti claims to prevent token replay attacks
);
```

#### 3.6 OAuth2 Client Auth Methods — DDL

```sql
CREATE TABLE authserver.oauth2_client_auth_methods (
    -- Stores supported client authentication methods per application
);
```

#### 3.7 Applications table additions

```sql
ALTER TABLE applications ADD COLUMN public_key TEXT;    -- για JWT client auth
ALTER TABLE applications ADD COLUMN jwks_uri VARCHAR(500);  -- JWKS endpoint
```

#### 3.8 Webhook system — DDL

```sql
CREATE TABLE authserver.oauth2_webhook_endpoints (
    -- endpoint URL, events, client_id, active, secret
);
CREATE TABLE authserver.oauth2_webhook_events (
    -- event_type, payload, delivered, attempts, created_at
);
-- 5 PL/pgSQL functions: create_webhook_event, deauthorize_user_from_app,
--   deauthorize_device, create_gdpr_request, notify_user_profile_changed
-- Trigger: token_revocation_webhook AFTER UPDATE ON usertokens
```

#### 3.9 OAuth2 Views

```sql
-- authserver.oauth2_application_permissions VIEW
-- authserver.oauth2_active_tokens VIEW (active tokens + client + user info)
-- applications.slow_api_calls VIEW (execution_time_ms > 5000 last 7 days)
```

#### 3.10 Τι πρέπει να γίνει για το backport

- [ ] Μεταφορά του `auth/src/OAuth2/` directory ως `src/Pramnos/Auth/OAuth2/`
- [ ] Μεταφορά Repositories + Entities
- [ ] `AuthServerServiceProvider` με route registration
- [ ] System migrations για OAuth tables + PKCE columns + device_authorizations
- [ ] RSA key generation στο `pramnos init`
- [ ] Ενσωμάτωση `OAuth2Middleware` στο framework middleware system
- [ ] WebhookService backport

---

## 4. Authentication System

### Τρέχουσα κατάσταση στο Framework

`Auth.php`: addon-based, χωρίς native logic. `User.php`: `verifyPassword()` (bcrypt + legacy md5).

### Τι φέρνει το UrbanWater

#### 4.1 Login Lockout — Progressive (από `auth/src/Models/Loginlockout.php`)

```
3 αποτυχίες → 60s lockout
5 αποτυχίες → 300s lockout (5 λεπτά)
7 αποτυχίες → 900s lockout (15 λεπτά)
10+ αποτυχίες → 3600s lockout (1 ώρα)
```

**3 scopes**: user, identifier, ip

**Public API:**
- `recordFailedAttempt($scope, $identifier)` — καταγράφει αποτυχία
- `getLockoutStatus($scope, $identifier)` → `['locked' => bool, 'remaining' => int]`
- `clearSuccessfulLoginState($scope, $identifier)` — καθαρίζει μετά από επιτυχή login

#### 4.2 Two-Factor Authentication (TOTP)

**Service**: `auth/src/Services/TwoFactorAuthService.php`
**Helper**: `auth/src/Helpers/TOTPHelper.php`

**DDL για 2FA tables:**

```sql
-- user_twofactor: ένα record ανά user (PK = userid)
-- Columns: userid, enabled BOOLEAN, secret VARCHAR(32), backup_codes JSONB,
--          last_used TIMESTAMP, setup_completed_at TIMESTAMP

-- twofactor_setup: temporary setup tokens (15-min expiry)
-- Columns: userid, setup_token, created_at TIMESTAMP

-- twofactor_attempts: hypertable (TimescaleDB)
-- Chunk: 7 days | Compress after: 7 days | Retain: 2 years
-- Columns: userid, success BOOLEAN, ip_address INET, 
--          attempt_time TIMESTAMP (time dimension)

-- Cleanup job (via add_job): runs every hour
```

#### 4.3 GDPR Tables (TimescaleDB hypertables)

```sql
-- user_activity_log
-- Chunk: 1 day | Compress after: 30 days | Retain: 24 months
-- Tracks user actions for audit/GDPR purposes

-- user_privacy_settings
-- Per-user privacy preference flags

-- user_consents
-- Chunk: 1 month | Compress after: 6 months | Retain: 7 years
-- GDPR consent records with consent type, timestamp, version

-- data_processing_records
-- Chunk: 1 week | Compress after: 90 days | Retain: 36 months
-- Records of data processing activities per user

-- gdpr_requests
-- Chunk: 1 month | Compress after: 1 year | Retain: 7 years
-- GDPR right-to-erasure, right-to-access requests

-- daily_activity_summary: continuous aggregate over user_activity_log
```

#### 4.4 Users table additions

```sql
-- GDPR columns added to users table (ALTER TABLE):
-- gdpr_consent BOOLEAN, gdpr_consent_date TIMESTAMP,
-- gdpr_data_export_requested BOOLEAN,
-- gdpr_deletion_requested BOOLEAN, gdpr_deletion_date TIMESTAMP
-- (exact columns at lines ~138xxx of urbanwater_db.sql)
```

#### 4.5 Auth Controllers (authserver subproject)

- `Session.php` — login (form → OAuth2 password grant or auth code redirect), logout
- `TwoFactorAuth.php` — TOTP challenge, backup codes, setup flow
- `Gdpr.php` — data export, account deletion, consent management
- `Dashboard.php` — authorized apps list, token revocation
- `Config.php` — user profile, password change
- `Logout.php` — session termination + token revocation

**Views available** (at `auth/src/Views/`):
- `login/login.html.php`, `login/login_2fa.html.php`, `login/forgotpassword.html.php`, `login/newpassword.html.php`, `login/message.html.php`
- `twofactor/twofactor.html.php`, `twofactor/setup.html.php`, `twofactor/backup.html.php`
- `OAuth2/authorize.html.php`, `OAuth2/authorized_applications.html.php`, `OAuth2/security.html.php`, `OAuth2/privacy_settings.html.php`, `OAuth2/delete_account.html.php`
- `device/device.html.php`, `device/confirmation.html.php`, `device/success.html.php`, `device/deny.html.php`
- `register/register.html.php`, `sso/sso.html.php`, `profile/profile.html.php`

#### 4.6 BC Considerations

- Υπάρχον addon hook interface (`onAuth()`, `onLogout()`, `onAuthCheck()`) **πρέπει να παραμείνει**
- `Pramnos\Auth\Auth` API παραμένει — νέα implementation πίσω από το ίδιο interface
- `Pramnos\User\User` API παραμένει — additive only

#### 4.7 authserver RBAC Schema

```sql
CREATE SCHEMA IF NOT EXISTS authserver;

CREATE TABLE IF NOT EXISTS authserver.permissions (
    permissionid SERIAL PRIMARY KEY,
    subject_type VARCHAR(20) NOT NULL CHECK (subject_type IN ('user', 'role', 'application')),
    subject_id BIGINT NOT NULL,
    object_type VARCHAR(50) NOT NULL,
    object_id BIGINT,         -- NULL = applies to all objects of type
    action VARCHAR(20) NOT NULL CHECK (action IN ('create', 'read', 'update', 'delete', '*')),
    grant_type VARCHAR(5) NOT NULL DEFAULT 'allow' CHECK (grant_type IN ('allow', 'deny')),
    priority INTEGER NOT NULL DEFAULT 100,  -- deny gets +1000 automatically
    granted_by BIGINT,
    granted_at TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP,     -- NULL = never expires
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    description TEXT
);

CREATE TABLE IF NOT EXISTS authserver.roles (
    roleid SERIAL PRIMARY KEY,
    role_name VARCHAR(100) NOT NULL,
    description TEXT,
    deyaid INTEGER,           -- NULL = system-wide role
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    is_active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE TABLE IF NOT EXISTS authserver.user_deyas (
    userid BIGINT NOT NULL,
    deyaid INTEGER NOT NULL,
    granted_by BIGINT,
    granted_at TIMESTAMP NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMP,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (userid, deyaid)
);

CREATE TABLE IF NOT EXISTS authserver.user_roles (
    userid BIGINT NOT NULL,
    roleid INTEGER REFERENCES authserver.roles(roleid) ON DELETE CASCADE,
    -- ... + DEYA membership validation
    PRIMARY KEY (userid, roleid)
);

-- + permission_templates, role_templates, permission_inheritance tables
-- + audit_log table (full audit trail of all permission changes)

-- VIEW: authserver.effective_permissions
-- Logic: deny takes priority (deny_count > 0 → no permission, regardless of allow_count)

-- 7 PL/pgSQL functions for common operations
```

#### 4.8 Τι πρέπει να γίνει για το backport

- [ ] `Loginlockout` model → `src/Pramnos/Auth/Loginlockout.php`
- [ ] `TwoFactorAuthService` + `TOTPHelper` → `src/Pramnos/Auth/`
- [ ] 2FA controllers + views → framework auth module
- [ ] System migrations για: 2FA tables, GDPR hypertables, authserver schema + functions
- [ ] GDPR controller + views
- [ ] authserver schema migrations (RBAC tables + functions + views)

---

## 5. Queue System

### Τρέχουσα κατάσταση στο Framework

Δεν υπάρχει τίποτα.

### Τι φέρνει το UrbanWater

#### 5.1 Database Schema

```sql
-- PostgreSQL ENUM type (create before table):
CREATE TYPE queue_status AS ENUM ('pending', 'processing', 'completed', 'failed', 'warning');

-- Main table:
CREATE TABLE queueitems (
    taskid      BIGSERIAL PRIMARY KEY,
    type        VARCHAR(50) NOT NULL,
    payload     JSON NOT NULL,
    status      queue_status NOT NULL DEFAULT 'pending',
    priority    SMALLINT NOT NULL DEFAULT 10,          -- lower = higher priority
    attempts    INTEGER NOT NULL DEFAULT 0,
    maxattempts INTEGER NOT NULL DEFAULT 3,
    createdat   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedat   TIMESTAMP NULL DEFAULT NULL,
    startedat   TIMESTAMP NULL DEFAULT NULL,
    completedat TIMESTAMP NULL DEFAULT NULL,
    error       TEXT DEFAULT NULL,
    lockedby    VARCHAR(100) DEFAULT NULL,             -- hostname:pid or hostname:workerid
    lockexpires TIMESTAMP NULL DEFAULT NULL,
    -- Added via ALTER TABLE:
    task_hash   VARCHAR(64) NULL,                      -- SHA-256 for deduplication
    execution_time DECIMAL(10,3) NULL,                 -- seconds
    success_message TEXT NULL
);

-- Indexes:
CREATE INDEX idx_status_priority_created ON queueitems (status, priority, createdat);
CREATE INDEX idx_task_type ON queueitems (type);
CREATE INDEX idx_locked_by ON queueitems (lockedby, lockexpires);
CREATE INDEX idx_task_hash ON queueitems (task_hash);
CREATE INDEX idx_processing_lockexpires ON queueitems (status, lockexpires, attempts, maxattempts);
```

#### 5.2 Class Hierarchy (from `src/Services/Queue/`)

**`TaskInterface`** (`Urbanwater\Services\Queue\TaskInterface`):
```php
interface TaskInterface {
    public function execute(Queueitem $queueItem): mixed;  // true|false|['message'=>...]|['warning'=>...]
    public function getDescription(Queueitem $queueItem): string;
    public function validate(Queueitem $queueItem): bool;
    public function handleFailure(Queueitem $queueItem, \Throwable $exception): bool;
}
```

**`AbstractTask`** (`Urbanwater\Services\Queue\AbstractTask implements TaskInterface`):
- `$name = ''` — task type name
- `$lastMessage = ''` — last log message
- `getPayload(Queueitem): object|array` — JSON decode of payload
- `validate(Queueitem): bool` — default: payload non-empty
- `handleFailure(Queueitem, Throwable): bool` — default: retry if `attempts < maxattempts`
- `log(string, Queueitem): void` — logs to `Pramnos\Logs\Logger`

**`QueueManager`** (`Urbanwater\Services\Queue\QueueManager`):
```php
// Constructor: ($controller, $workerId = null)
// workerId stored as: hostname:workerId or hostname:pid

public function addTask($taskType, $data, $priority = 10, $maxAttempts = 3, $unique = false): ?int;
    // $unique: SHA-256 hash deduplication — returns null if duplicate pending/processing

public function getNextTask($taskTypes = null, $lockSeconds = 300, $reverse = false, $startfrom = 0): Queueitem|false;
    // Atomic lock: sets status='processing', attempts++, lockedby, lockexpires
    // Priority: recent high-priority (<=10) tasks if $startfrom set
    // Falls back to stalled tasks (status=processing, lockexpires < now)

public function getPendingTasks($limit = 10, $taskTypes = null): Queueitem[];

public function markTaskAsProcessing(Queueitem &$task): void;
public function markTaskAsCompleted(Queueitem $task, $successMessage = null, $executionTime = null): void;
public function markTaskAsWarning(Queueitem $task, $warningMessage, $executionTime = null): void;
public function markTaskAsFailed(Queueitem $task, $errorMessage = null, $executionTime = null): void;
    // If attempts < maxattempts: resets to 'pending' (retry), else: 'failed'

public function retryTask($taskId): bool;
    // Resets to pending, clears attempts

public function getStats(): array;
    // Returns: [pending, processing, completed, warning, failed, total, totalcompleted, percentcompleted, percentremaining]

public function purgeOldTasks($hours = 24, $statuses = ['completed', 'failed'], $limit = 0): int;
    // Deletes old completed/failed tasks, returns affected row count

public function getTaskTypes(): array;
    // Scans src/Services/Queue/Tasks/ for AbstractTask subclasses
```

**`Worker`** (`Urbanwater\Services\Queue\Worker`):
```php
// Constructor: ($controller, $workerId = null) — creates QueueManager internally

public function processNextTask($taskTypes = null): array|false;
    // Returns: ['id', 'type', 'status', 'message', 'execution_time'] or false
    // Result handling:
    //   execute() returns true  → markTaskAsCompleted
    //   execute() returns false → markTaskAsFailed
    //   execute() returns ['message' => '...'] → markTaskAsCompleted
    //   execute() returns ['warning' => '...'] → markTaskAsWarning
    //   Throwable → handleFailure() → markTaskAsFailed

public function run($maxRuntime = 60, $maxTasks = 0, $sleepTime = 5, $taskTypes = null): int;
    // Simple loop wrapper, returns task count

public function registerTaskHandler($taskType, $handlerClass): self;
// Default handlers: echo, getNetworkserverData, updateSupplyDailyConsumption, recalcDeviceStats
```

#### 5.3 CLI Command: `queue:process` (ProcessQueue.php)

```
queue:process [options]
  -d, --daemon              Run continuously
  -r, --runtime=N           Max runtime in seconds (0=unlimited)
  -s, --sleep=N             Seconds to sleep when no tasks available [5]
  -l, --limit=N             Max tasks per run (0=unlimited)
  -b, --batch=N             Tasks per batch [20]
  -t, --type=TYPE           Process only specific type(s), comma-separated
  -f, --force               Run even if another instance is running
  -w, --worker-id=ID        Unique worker identifier for concurrent processing
      --start-from=DATE     Process tasks from date (YYYY-MM-DD HH:MM:SS)
      --reverse-order       Process newest-first
```

Features:
- **Interactive dashboard** (full-screen terminal UI): queue stats, processing info, recent tasks, status messages
- **Heartbeat**: touches lock file every 30s
- **DB reconnect**: auto-reconnect every 5 minutes via `tryReconnect()` / `refresh()`
- **DB failure recovery**: keeps retrying on `isDatabaseFailure()` (list of error substrings)
- **Game mode**: playful "adventure" terminal screen during reconnect
- **Stop signal**: watch for `ROOT/var/QUEUE_PROCESSOR_<id>.stop` file

#### 5.4 Τι πρέπει να γίνει για το backport

- [ ] `queue_status` ENUM migration (PostgreSQL) / TINYINT for MySQL equivalent
- [ ] System migration για `queueitems` table
- [ ] `Pramnos\Queue\TaskInterface` interface
- [ ] `Pramnos\Queue\AbstractTask` base class
- [ ] `Pramnos\Queue\QueueManager` class
- [ ] `Pramnos\Queue\Worker` class
- [ ] `Pramnos\Queue\QueueServiceProvider`
- [ ] `queue:process` CLI command (with full dashboard)
- [ ] Model: `Pramnos\Queue\Queueitem` (extends `Pramnos\Application\Model`)

---

## 6. Daemon Orchestrator

### Τρέχουσα κατάσταση στο Framework

Δεν υπάρχει τίποτα.

### Τι φέρνει το UrbanWater

**Class**: `Urbanwater\ConsoleCommands\DaemonOrchestrator extends CommandBase`

#### 6.1 CLI Command: `daemons:start`

```
daemons:start [options]
      --once                Single reconciliation cycle and exit
  -i, --interval=N          Seconds between cycles [10]
      --php-binary=PATH     PHP executable for spawning children [PHP_BINARY]
      --dry-run             Show planned actions without spawning
      --interactive         Live dashboard (full-screen)
      --verbose-health      Log every reconcile cycle even when healthy
```

#### 6.2 Πώς λειτουργεί

1. **Settings-driven**: reads `daemon_orchestrator_enabled` (yes/no), `daemon_queue_enabled`, `daemon_kafka_enabled`, `daemon_queue_processes` (int, 0-20), `daemon_queue_profiles` (multi-line config), `daemon_definitions_json` (JSON array)
2. **Reconcile loop** every N seconds:
   - Builds desired process list from settings
   - Compares against state file (`ROOT/var/daemon_orchestrator_state.json`)
   - For each desired: checks lock file liveness + PID liveness
   - **Spawns** missing: `nohup setsid php urbanwater.php queue:process --daemon ... >> ROOT/var/logs/queue-<id>.log 2>&1 &`
   - **Stops** removed: writes `.stop` file, waits 30s grace, then SIGTERM
   - **Dedup scan** every 3 cycles: scans `/proc` for duplicate `--worker-id` processes
3. **Git deploy detection**: compares `.git/HEAD` SHA every 60s — on change, graceful restart all
4. **Orchestrator lock**: `flock()` on `ROOT/var/DAEMON_ORCHESTRATOR.lock`

#### 6.3 Lock file convention

```
ROOT/var/QUEUE_PROCESSOR_<workerId>         — lock file with PID on line 1
ROOT/var/QUEUE_PROCESSOR_<workerId>.stop    — signal to gracefully stop
ROOT/var/KAFKA_CONSUMER_<topicSlug>_<id>   — kafka worker lock
ROOT/var/daemon_orchestrator_state.json    — state: [{id, daemon, pid, lockFile, updatedAt}]
ROOT/var/logs/<daemon>-<workerId>.log       — per-worker output
```

#### 6.4 Profile configuration formats (in settings)

**daemon_queue_profiles** (multi-line):
```
# Format 1: name|processes|params
email_worker|2|--type email --batch 10 --runtime 3600
# Format 2 (legacy): name|taskType|processes|runtime|sleep|batch
notifications|notify|1|3600|5|20
```

**daemon_definitions_json** (structured):
```json
[
  {"type": "queue", "name": "email", "enabled": true, "processes": 2, "options": {"type": "email", "batch": 10}},
  {"type": "kafka", "name": "iot", "enabled": true, "processes": 1, "options": {"topics": "iot-data", "runtime": 3600}},
  {"type": "custom", "name": "my-daemon", "enabled": true, "processes": 1, "customCommand": "/usr/bin/my-daemon"}
]
```

#### 6.5 Τι πρέπει να γίνει για το backport

- [ ] `Pramnos\Console\DaemonOrchestrator` command (framework-agnostic: no Urbanwater imports)
- [ ] `daemons:start` CLI command registration
- [ ] Framework docs για daemon process patterns

---

## 7. Messaging System

### Τρέχουσα κατάσταση στο Framework

Δεν υπάρχει τίποτα.

### Τι φέρνει το UrbanWater

#### 7.1 Database Schema

```sql
-- Email history + queue:
CREATE TABLE "mails" (
  "id" SERIAL PRIMARY KEY,
  "status" SMALLINT NOT NULL,         -- 0: failed, 1: success, 2: queued
  "frommail" VARCHAR(128) NOT NULL,
  "fromname" VARCHAR(255) NOT NULL,
  "tomail" VARCHAR(128) NOT NULL,
  "toname" VARCHAR(255) NOT NULL,
  "subject" VARCHAR(255) NOT NULL,
  "content" TEXT NOT NULL,
  "date" INTEGER NOT NULL,            -- Unix timestamp
  "module" VARCHAR(128) NOT NULL,
  "moduleinfo" VARCHAR(255) NOT NULL,
  "extrainfo" TEXT NOT NULL,
  "path" VARCHAR(255) NOT NULL,
  "hash" CHAR(32) NOT NULL
);

-- Email templates:
CREATE TABLE "mailtemplates" (
  "templateid" BIGSERIAL PRIMARY KEY,
  "title" VARCHAR(255) NOT NULL,
  "defaulttext" TEXT NOT NULL,
  "defaultsubject" VARCHAR(255) NOT NULL,
  "category" VARCHAR(100) NOT NULL,
  "language" VARCHAR(50) NOT NULL,
  "type" SMALLINT NOT NULL,           -- 0: Email, 1: SMS, 2: Push
  "sound" VARCHAR(255) NOT NULL,
  "sendmethod" SMALLINT NOT NULL,     -- 0: Default, 1: Amazon API
  "defaultaccount" INTEGER DEFAULT NULL,
  "emailtemplate" VARCHAR(20) NOT NULL DEFAULT 'default'
);

-- Internal messages (private + notifications):
CREATE TABLE "messages" (
  "messageid" SERIAL PRIMARY KEY,
  "massid" INTEGER NULL,              -- FK to massmessages
  "type" SMALLINT NOT NULL DEFAULT 0,
    -- 0: Read Message (copy in sender's sent box)
    -- 1: New Message (before user visits mailbox)
    -- 2: Sent Message
    -- 3: Inbox Archive
    -- 4: Outbox Archive
    -- 5: Unread message
    -- 6: Marked as read
    -- 7: Deleted message
    -- 8: Notification New
    -- 9: Notification Read
  "subject" VARCHAR(255) NOT NULL DEFAULT '0',
  "text" TEXT NOT NULL,
  "url" VARCHAR(255) NOT NULL,
  "urlcaption" VARCHAR(255) NOT NULL,
  "attachmenttext" TEXT NOT NULL,
  "image" VARCHAR(255) NOT NULL,
  "securitycode" VARCHAR(10) NOT NULL,
  "fromuserid" BIGINT DEFAULT NULL,
  "touserid" BIGINT DEFAULT NULL,
  "date" INTEGER NOT NULL DEFAULT 0,
  "ip" VARCHAR(15) NOT NULL DEFAULT '',
  "bbcode" SMALLINT NOT NULL DEFAULT 1,
  "html" SMALLINT NOT NULL DEFAULT 0,
  "smilies" SMALLINT NOT NULL DEFAULT 1,
  "signature" SMALLINT NOT NULL DEFAULT 1,
  "attachment" SMALLINT NOT NULL DEFAULT 0
);

-- Mass message (broadcast) headers:
CREATE TABLE massmessages (
    messageid SERIAL PRIMARY KEY,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    message TEXT NOT NULL,
    type INTEGER NOT NULL DEFAULT 1,   -- 0: Email, 1: SMS, 2: Push
    locationid TEXT NOT NULL,          -- location filter (stored as text, may be JSON array)
    deyaid INTEGER NULL,
    zoneid INTEGER NULL,
    sender BIGINT NULL,                -- FK to users
    status INTEGER NULL DEFAULT 0,    -- 0: Not sent, 1: Sent, 2: Scheduled
    created INTEGER NULL DEFAULT 0,   -- Unix timestamp
    scheduled INTEGER NULL DEFAULT 0,
    filters INTEGER NULL DEFAULT 0,   -- 0: specific user IDs, 1: filter-based
    totalrecipients INTEGER NOT NULL DEFAULT 0,
    request JSON NULL
);

-- Mass message recipients:
CREATE TABLE massmessagerecipients (
    recipientid SERIAL PRIMARY KEY,
    messageid INTEGER NOT NULL,
    userid BIGINT NOT NULL,
    status INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT massmessagerecipients_messageid_fk
        FOREIGN KEY (messageid) REFERENCES massmessages(messageid) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT massmessagerecipients_userid_fk
        FOREIGN KEY (userid) REFERENCES users(userid) ON DELETE CASCADE ON UPDATE CASCADE
);
```

#### 7.2 Message Model

**`Authserver\Models\Message`** extends `Pramnos\Application\Model`:
- Table: `#PREFIX#messages`
- PK: `messageid`
- Standard CRUD: `load($messageid)`, `save()`, `delete($messageid)`, `getList($filter, $order)`

#### 7.3 Τι πρέπει να γίνει για το backport

- [ ] System migrations για `mails`, `mailtemplates`, `messages`, `massmessages`, `massmessagerecipients`
- [ ] `MessagingServiceProvider`
- [ ] `Pramnos\Messaging\Message` model
- [ ] `Pramnos\Messaging\Mail` model (for mails table)
- [ ] `Pramnos\Messaging\MailTemplate` model
- [ ] `Pramnos\Messaging\MassMessage` + `MassMessageRecipient` models

---

## 8. CLI UX — CommandBase Dashboard System

### Τρέχουσα κατάσταση στο Framework

Symfony Console 5.4, ProgressBar στο `MigrateLogs` command. Χωρίς dashboard/formatted tables.

### Τι φέρνει το UrbanWater

**Class**: `Urbanwater\ConsoleCommands\CommandBase extends Command`
**Note**: `DaemonCommandBase` is just a deprecated alias for `CommandBase`.

#### 8.1 Lock File Management

```php
abstract protected function getJobName(): string;          // e.g. 'QUEUE_PROCESSOR'
protected function checkIfRunning(): bool;                 // stale after 2h
protected function beginJob(OutputInterface $output, bool $registerShutdown = true): bool;
protected function startJob(): void;                       // writes PID to lock file
protected function heartbeat(): void;                      // touches lock file mtime
public function endJob(): void;                            // deletes lock file
protected function getLockStaleSeconds(): int;             // 7200 (2 hours)
protected function getJobLockFilePath(): string;           // ROOT/var/<jobName>
```

Lock file format:
```
<PID>
Command started at: DD/MM/YYYY HH:MM.
```

#### 8.2 Dashboard Rendering

```php
// Full-screen dashboard with Unicode box-drawing characters:
// ┌────────────────────────────────────────┐
// │              TITLE                     │
// ├────────────────────────────────────────┤
// │ Row content                            │
// └────────────────────────────────────────┘

protected function renderDashboardFrame(OutputInterface $output, string $title, array $systemSegments, array $sections, int $terminalWidth): void;
protected function renderDashboardFrameAutoSystem(OutputInterface $output, string $title, array $sections, int $terminalWidth, ?array $systemSegments = null): void;

// System status auto-segments (uses reflection to read startTime/cpuUsage/memoryUsage):
// "Time: YYYY-MM-DD HH:MM:SS | Uptime: HH:MM:SS | CPU: X.X | Memory: XMB"

protected function buildDashboardHeader(string $title, int $borderLen): string;
protected function buildDashboardFooter(int $borderLen): string;
protected function buildDashboardSectionSeparator(int $borderLen): string;
protected function buildDashboardRows(array $segments, int $borderLen): string;
protected function buildDashboardHelpSection(int $borderLen, string $helpText = '...'): string;
protected function buildCommandStateSection(int $borderLen, string $mode, string $state, array $extraSegments): string;
protected function buildDashboardAdventureSection(int $borderLen, string $title, string $statusText, int $countdown): string;
```

#### 8.3 Terminal Control

```php
protected function clearScreen(OutputInterface $output): void;   // \033c + \033[2J + \033[H
protected function hideCursor(OutputInterface $output): void;    // \033[?25l
protected function showCursor(OutputInterface $output): void;    // \033[?25h\033[?0c
protected function detectTerminalSize(): array;                  // [height, width], fallback 24x80
protected function initializeInteractiveTerminal(OutputInterface $output, bool $registerShutdown = true): void;
```

#### 8.4 Signal Handling

```php
protected function configureInterruptHandling(OutputInterface $output, string $manualHandlerMethod = 'handleInterruptSignal'): void;
    // If orchestrated (ppid has 'daemons:start' in cmdline): SIGINT = SIG_IGN
    // Otherwise: SIGINT → custom handler
    // Falls back if pcntl unavailable

protected function isRunningUnderOrchestrator(): bool;
    // Reads /proc/<ppid>/cmdline for 'daemons:start'

public function handleInterruptSignal(int $signal = 0): void;
    // endJob() + exit(130)
```

#### 8.5 Utilities

```php
protected function formatBytes($bytes, $precision = 2): string;  // 1024→KB→MB→GB→TB
protected function formatTime($seconds): string;                  // HH:MM:SS
protected function visibleLength($string): int;                   // strips ANSI codes, mb-aware
protected function truncateText(string $text, int $maxLen): string; // with "..."
protected function wrapDashboardText(string $text, int $maxWidth): array;

protected function currentTimestamp(): int;    // time()
protected function now(): int;                 // alias
protected function nowFloat(): float;          // microtime(true)
protected function getLoadAvg(): array;        // sys_getloadavg()
```

#### 8.6 Process Utilities

```php
protected function isProcessStillRunning(int $pid): bool;
    // posix_kill($pid, 0) → /proc/$pid dir → ps -p $pid | grep -c
protected function readPidFromLockFile(string $file): int;
protected function exitProcess(int $exitCode): void;
    // Can be intercepted in tests via shouldInterceptExit()
```

#### 8.7 Τι πρέπει να γίνει για το backport

- [ ] `Pramnos\Console\CommandBase` abstract class (στο framework, namespace-agnostic)
- [ ] Remove all UrbanWater-specific strings from dashboard title
- [ ] Ενσωμάτωση στο framework CLI application

---

## 9. Token Action Tracking

### Τρέχουσα κατάσταση στο Framework

`Token.php`: `addAction()` + `updateAction()` (μόνο PostgreSQL). Fragile auto-create-columns pattern.

### Τι φέρνει το UrbanWater

#### 9.1 Complete Schema (DDL evolution)

```sql
-- Original table:
CREATE TABLE "tokenactions" (
  "actionid" SERIAL PRIMARY KEY,
  "tokenid" INTEGER NOT NULL,
  "urlid" INTEGER NOT NULL,
  "method" VARCHAR(6) NOT NULL,
  "params" TEXT NOT NULL,
  "servertime" INTEGER NOT NULL
);

-- Phase 2: Added via ALTER TABLE:
ALTER TABLE tokenactions ADD COLUMN IF NOT EXISTS return_status INTEGER;
ALTER TABLE tokenactions ADD COLUMN IF NOT EXISTS execution_time_ms NUMERIC(10,3);
ALTER TABLE tokenactions ADD COLUMN IF NOT EXISTS return_data JSONB;
ALTER TABLE tokenactions ADD COLUMN IF NOT EXISTS action_time TIMESTAMP WITH TIME ZONE;
ALTER TABLE tokenactions ALTER COLUMN action_time SET DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE tokenactions ALTER COLUMN action_time SET NOT NULL;

-- Sync trigger (bidirectional servertime ↔ action_time):
CREATE OR REPLACE FUNCTION sync_tokenactions_time() RETURNS TRIGGER AS $$
BEGIN
  IF NEW.servertime IS NOT NULL THEN
    NEW.action_time = TO_TIMESTAMP(NEW.servertime);
  ELSE
    NEW.action_time = CURRENT_TIMESTAMP;
    NEW.servertime = EXTRACT(EPOCH FROM NEW.action_time)::INTEGER;
  END IF;
  RETURN NEW;
END; $$ LANGUAGE plpgsql;
CREATE TRIGGER sync_tokenactions_time BEFORE INSERT OR UPDATE ON tokenactions
  FOR EACH ROW EXECUTE FUNCTION sync_tokenactions_time();

-- Phase 3: Converted to TimescaleDB hypertable:
ALTER TABLE public.tokenactions DROP CONSTRAINT tokenactions_pkey;
ALTER TABLE public.tokenactions ADD CONSTRAINT tokenactions_pkey PRIMARY KEY (actionid, action_time);
SELECT create_hypertable('public.tokenactions', 'action_time',
    chunk_time_interval => INTERVAL '14 days', migrate_data => true);

-- Compression:
ALTER TABLE public.tokenactions SET (
    timescaledb.compress,
    timescaledb.compress_segmentby = 'tokenid, urlid, method',
    timescaledb.compress_orderby = 'action_time DESC'
);
SELECT add_compression_policy('public.tokenactions', INTERVAL '60 days');

-- Additional indexes:
CREATE INDEX idx_tokenactions_time_tokenid ON tokenactions(action_time DESC, tokenid);
CREATE INDEX idx_tokenactions_time_urlid ON tokenactions(action_time DESC, urlid);
CREATE INDEX idx_tokenactions_time_status ON tokenactions(action_time DESC, return_status);
```

#### 9.2 urls table (lookup for URL hashing)

```sql
CREATE TABLE "urls" (
  "urlid" SERIAL PRIMARY KEY,
  "url" VARCHAR(255) DEFAULT NULL,
  "hash" BIGINT NOT NULL   -- crc32 hash of the url
);
```

#### 9.3 View: `applications.slow_api_calls`

```sql
CREATE OR REPLACE VIEW applications.slow_api_calls AS
SELECT a.name AS app_name, ut.token, ta.method, ta.urlid,
    ta.execution_time_ms, ta.return_status, ta.action_time, ta.params
FROM public.tokenactions ta
JOIN public.usertokens ut ON ut.tokenid = ta.tokenid
JOIN public.applications a ON a.appid = ut.applicationid
WHERE ta.execution_time_ms > 5.0   -- queries slower than 5 seconds
AND ta.action_time >= CURRENT_TIMESTAMP - INTERVAL '7 days'
ORDER BY ta.execution_time_ms DESC;
```

#### 9.4 Τι πρέπει να γίνει για το backport

- [ ] System migration για `tokenactions` + `urls` tables με ολόκληρη τη δομή
- [ ] Πλήρης `updateAction()` για MySQL
- [ ] TimescaleDB hypertable migration (μόνο όταν TimescaleDB feature είναι active)
- [ ] `slow_api_calls` view migration (για PostgreSQL/TimescaleDB)
- [ ] Cleanup του fragile auto-create-columns pattern στο `Token.php`

---

## 10. System Migration DDL Reference

Ακολουθεί το **πλήρες SQL** για κάθε generic table που πρέπει να αποτελεί framework system migration. Αυτή η ενότητα είναι η αλήθεια — τα framework migrations πρέπει να έχουν 100% parity.

### 10.1 Core (πάντα φορτώνονται)

#### `sessions`
```sql
CREATE TABLE "sessions" (
  "visitorid" TEXT PRIMARY KEY,
  "uname" VARCHAR(128) NOT NULL DEFAULT '',
  "time" INTEGER NOT NULL,
  "host_addr" VARCHAR(39) NOT NULL DEFAULT '',
  "guest" INTEGER NOT NULL DEFAULT 0,
  "agent" VARCHAR(255) NOT NULL,
  "userid" BIGINT DEFAULT NULL,
  "url" VARCHAR(255) NOT NULL,
  "history" TEXT NOT NULL,
  "logout" SMALLINT NOT NULL DEFAULT 0,
  "sid" VARCHAR(32) NOT NULL
);
```

#### `settings`
```sql
CREATE TABLE "settings" (
  "setting_id" SERIAL PRIMARY KEY,
  "setting" VARCHAR(128) NOT NULL DEFAULT '',
  "value" TEXT NOT NULL,
  "delete" SMALLINT NOT NULL DEFAULT 1
);
```

### 10.2 Feature: `auth`

#### `users`
```sql
CREATE TABLE "users" (
  "userid" BIGSERIAL PRIMARY KEY,
  "username" VARCHAR(50) NOT NULL DEFAULT '',
  "password" VARCHAR(100) NOT NULL DEFAULT '',
  "email" VARCHAR(150) NOT NULL DEFAULT '',
  "lastname" VARCHAR(128) NOT NULL DEFAULT '',
  "firstname" VARCHAR(128) NOT NULL DEFAULT '',
  "regdate" INTEGER NOT NULL DEFAULT 0,
  "regcompletion" INTEGER DEFAULT NULL,
  "lasttermsagreed" INTEGER DEFAULT NULL,
  "lastlogin" INTEGER NOT NULL DEFAULT 0,
  "active" SMALLINT NOT NULL DEFAULT 1,
  "validated" SMALLINT NOT NULL DEFAULT 1,
  "language" VARCHAR(50) NOT NULL DEFAULT '',
  "timezone" CHAR(3) NOT NULL DEFAULT '',
  "dateformat" VARCHAR(15) NOT NULL DEFAULT 'd/m/Y H:i',
  "usertype" SMALLINT NOT NULL,         -- 0: Simple, 1: Salesman, 2: Administrator
  "sex" SMALLINT NOT NULL,              -- 0: female, 1: male
  "birthdate" BIGINT NOT NULL,
  "photo" INTEGER DEFAULT NULL,         -- usageid
  "phone" VARCHAR(50) NOT NULL,
  "fax" VARCHAR(50) NOT NULL,
  "mobile" VARCHAR(50) NOT NULL,
  "vat" VARCHAR(15) NOT NULL DEFAULT '',
  "website" VARCHAR(255) NOT NULL,
  "modified" INTEGER NOT NULL,
  "fbauth" BIGINT DEFAULT NULL,
  "locationid" INTEGER DEFAULT NULL
);
```

#### `userdetails` (EAV)
```sql
CREATE TABLE "userdetails" (
  "userid" BIGINT NOT NULL,
  "fieldname" VARCHAR(35) NOT NULL,
  "value" TEXT NOT NULL,
  PRIMARY KEY ("userid", "fieldname")
);
```

#### `userlog`
```sql
CREATE TABLE "userlog" (
  "logid" SERIAL PRIMARY KEY,
  "userid" BIGINT NOT NULL,
  "date" INTEGER NOT NULL,
  "log" VARCHAR(255) DEFAULT NULL,
  "logtype" SMALLINT NOT NULL,
  "details" TEXT NOT NULL
);
```

#### `usernotes`
```sql
CREATE TABLE "usernotes" (
  "userid" BIGINT NOT NULL,
  "admin" BIGINT DEFAULT NULL,
  "note" TEXT NOT NULL,
  "date" INTEGER NOT NULL
);
```

#### `usertokens`
```sql
CREATE TABLE "usertokens" (
  "tokenid" SERIAL PRIMARY KEY,
  "userid" BIGINT NOT NULL,
  "tokentype" VARCHAR(20) NOT NULL,
  "token" TEXT NOT NULL,                      -- TEXT (changed from VARCHAR(255) for JWT)
  "created" INTEGER NOT NULL,
  "notes" VARCHAR(255) NOT NULL,
  "lastused" INTEGER NOT NULL,
  "status" SMALLINT NOT NULL,                 -- 0: inactive, 1: active, 2: removed
  "parentToken" INTEGER DEFAULT NULL,
  "applicationid" INTEGER DEFAULT NULL,
  "actions" INTEGER NOT NULL,
  "removedate" INTEGER NOT NULL,
  "deviceinfo" TEXT NOT NULL,
  "scope" TEXT NOT NULL,
  "expires" INTEGER,
  "ipaddress" INET,
  -- PKCE (RFC 7636):
  "code_challenge" VARCHAR(128) DEFAULT NULL,
  "code_challenge_method" VARCHAR(10) DEFAULT NULL,
  CONSTRAINT chk_code_challenge_method
    CHECK (code_challenge_method IS NULL OR code_challenge_method IN ('plain', 'S256')),
  CONSTRAINT chk_code_challenge_format
    CHECK (code_challenge IS NULL OR
      (length(code_challenge) >= 43 AND length(code_challenge) <= 128
       AND code_challenge ~ '^[A-Za-z0-9\-._~]+$'))
);
COMMENT ON TABLE "usertokens" IS 'Various user tokens';

-- Foreign keys:
ALTER TABLE usertokens ADD CONSTRAINT fk_usertokens_userid
  FOREIGN KEY (userid) REFERENCES users(userid) ON DELETE CASCADE ON UPDATE CASCADE;
ALTER TABLE usertokens ADD CONSTRAINT fk_usertokens_parentToken
  FOREIGN KEY ("parentToken") REFERENCES usertokens(tokenid) ON DELETE SET NULL ON UPDATE CASCADE;

-- Indexes:
CREATE INDEX idx_usertokens_code_challenge ON usertokens (code_challenge)
  WHERE code_challenge IS NOT NULL;
CREATE UNIQUE INDEX idx_usertokens_auth_code_unique ON usertokens (token, code_challenge)
  WHERE tokentype = 'auth_code' AND code_challenge IS NOT NULL;
```

#### `tokenactions` (becomes hypertable on TimescaleDB)
```sql
CREATE TABLE "tokenactions" (
  "actionid" SERIAL,
  "tokenid" INTEGER NOT NULL,
  "urlid" INTEGER NOT NULL,
  "method" VARCHAR(6) NOT NULL,
  "params" TEXT NOT NULL,
  "servertime" INTEGER NOT NULL DEFAULT 0,
  "return_status" INTEGER,
  "execution_time_ms" NUMERIC(10,3),
  "return_data" JSONB,
  "action_time" TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (actionid, action_time)           -- composite PK for hypertable compat
);

-- MySQL: use standard PK, no hypertable
-- PostgreSQL + TimescaleDB: convert to hypertable after creation
SELECT create_hypertable('tokenactions', 'action_time',
    chunk_time_interval => INTERVAL '14 days');
ALTER TABLE tokenactions SET (
    timescaledb.compress,
    timescaledb.compress_segmentby = 'tokenid, urlid, method',
    timescaledb.compress_orderby = 'action_time DESC'
);
SELECT add_compression_policy('tokenactions', INTERVAL '60 days');
```

#### `urls`
```sql
CREATE TABLE "urls" (
  "urlid" SERIAL PRIMARY KEY,
  "url" VARCHAR(255) DEFAULT NULL,
  "hash" BIGINT NOT NULL       -- crc32 hash of the url
);
```

### 10.3 Feature: `queue`

#### `queueitems`
```sql
-- PostgreSQL: create ENUM type first
CREATE TYPE queue_status AS ENUM ('pending', 'processing', 'completed', 'failed', 'warning');

CREATE TABLE queueitems (
    taskid         BIGSERIAL PRIMARY KEY,
    type           VARCHAR(50) NOT NULL,
    payload        JSON NOT NULL,
    status         queue_status NOT NULL DEFAULT 'pending',   -- PostgreSQL
    -- MySQL: status TINYINT NOT NULL DEFAULT 0              -- 0=pending,1=processing,2=completed,3=failed,4=warning
    priority       SMALLINT NOT NULL DEFAULT 10,
    attempts       INTEGER NOT NULL DEFAULT 0,
    maxattempts    INTEGER NOT NULL DEFAULT 3,
    createdat      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updatedat      TIMESTAMP NULL DEFAULT NULL,
    startedat      TIMESTAMP NULL DEFAULT NULL,
    completedat    TIMESTAMP NULL DEFAULT NULL,
    error          TEXT DEFAULT NULL,
    lockedby       VARCHAR(100) DEFAULT NULL,
    lockexpires    TIMESTAMP NULL DEFAULT NULL,
    task_hash      VARCHAR(64) NULL,
    execution_time DECIMAL(10,3) NULL,
    success_message TEXT NULL
);

CREATE INDEX idx_status_priority_created ON queueitems (status, priority, createdat);
CREATE INDEX idx_task_type ON queueitems (type);
CREATE INDEX idx_locked_by ON queueitems (lockedby, lockexpires);
CREATE INDEX idx_task_hash ON queueitems (task_hash);
CREATE INDEX idx_processing_lockexpires ON queueitems (status, lockexpires, attempts, maxattempts);
```

### 10.4 Feature: `messaging`

#### `mails`, `mailtemplates`, `messages`, `massmessages`, `massmessagerecipients`

_(See full DDL in Section 7.1)_

### 10.5 Feature: `authserver`

- `authserver.permissions` — RBAC core _(See Section 4.7)_
- `authserver.roles` _(See Section 4.7)_
- `authserver.user_deyas` _(See Section 4.7)_
- `authserver.user_roles` _(See Section 4.7)_
- `authserver.permission_templates`, `authserver.role_templates`, `authserver.permission_inheritance`
- `authserver.audit_log`
- `authserver.device_authorizations` (RFC 8628) _(See Section 3.4)_
- `authserver.jwt_replay_prevention` _(See Section 3.5)_
- `authserver.oauth2_client_auth_methods` _(See Section 3.6)_
- `authserver.oauth2_webhook_endpoints`, `authserver.oauth2_webhook_events` _(See Section 3.8)_
- 2FA tables: `user_twofactor`, `twofactor_setup`, `twofactor_attempts` (hypertable) _(See Section 4.2)_
- GDPR hypertables _(See Section 4.3)_
- `authserver.effective_permissions` VIEW + 7 PL/pgSQL functions _(See Section 4.7)_

---

## 11. Class & Method Mapping

Αυτή η ενότητα δείχνει **τι υπάρχει στο UrbanWater** και **τι αντίστοιχο πρέπει να δημιουργηθεί/ενημερωθεί στο framework**.

### 11.1 New classes — δεν υπάρχουν στο framework

| UrbanWater class | Framework target | Notes |
|---|---|---|
| `Urbanwater\Services\Queue\QueueManager` | `Pramnos\Queue\QueueManager` | Full copy, re-namespaced |
| `Urbanwater\Services\Queue\Worker` | `Pramnos\Queue\Worker` | Full copy, task handler registry generic |
| `Urbanwater\Services\Queue\AbstractTask` | `Pramnos\Queue\AbstractTask` | Full copy |
| `Urbanwater\Services\Queue\TaskInterface` | `Pramnos\Queue\TaskInterface` | Full copy |
| `Urbanwater\ConsoleCommands\CommandBase` | `Pramnos\Console\CommandBase` | Full copy, remove UW-specific strings |
| `Urbanwater\ConsoleCommands\DaemonOrchestrator` | `Pramnos\Console\DaemonOrchestrator` | Full copy, remove UW-specific imports |
| `Urbanwater\ConsoleCommands\ProcessQueue` | `Pramnos\Console\QueueProcessCommand` | Full copy, rename command string |
| `Authserver\OAuth2\OAuth2ServerFactory` | `Pramnos\Auth\OAuth2\OAuth2ServerFactory` | Full copy |
| `Authserver\OAuth2\Repositories\*` (6 files) | `Pramnos\Auth\OAuth2\Repositories\*` | Full copy |
| `Authserver\OAuth2\Entities\*` (6 files) | `Pramnos\Auth\OAuth2\Entities\*` | Full copy |
| `Authserver\OAuth2\Middleware\OAuth2Middleware` | `Pramnos\Auth\OAuth2\OAuth2Middleware` | Full copy |
| `Authserver\Models\Loginlockout` | `Pramnos\Auth\Loginlockout` | Full copy |
| `Authserver\Services\TwoFactorAuthService` | `Pramnos\Auth\TwoFactorAuthService` | Full copy |
| `Authserver\Helpers\TOTPHelper` | `Pramnos\Auth\TOTPHelper` | Full copy |
| `Authserver\Helpers\Scopes` | `Pramnos\Auth\Scopes` | Full copy |
| `Authserver\Helpers\OAuthPolicyHelper` | `Pramnos\Auth\OAuthPolicyHelper` | Full copy |
| `Authserver\Services\WebhookService` | `Pramnos\Auth\WebhookService` | Full copy |
| `Authserver\Models\Message` | `Pramnos\Messaging\Message` | Full copy |
| All auth Views (login, 2FA, OAuth, device, GDPR) | `src/Pramnos/Auth/Views/` | Copy as templates |

### 11.2 Existing framework classes — πρέπει να επεκταθούν

| Framework class | Νέες μέθοδοι / αλλαγές |
|---|---|
| `Pramnos\User\User` | GDPR columns (add to model properties), PKCE-aware token creation |
| `Pramnos\Auth\Token` | Complete `updateAction()` for MySQL; full `tokenactions` schema (remove auto-create-columns); `code_challenge` / `code_challenge_method` properties |
| `Pramnos\Application\Application` | `init()` triggers MigrationRunner; Feature Registry loading; `migration_cutoff` setting support |
| `Pramnos\Database\Migration` | New properties: `$feature`, `$priority`, `$dependencies`, `$autorun`; BC alias for `$autoExecute` |

### 11.3 CLI commands — new

| Command | Class | Registers As |
|---|---|---|
| `queue:process` | `Pramnos\Console\QueueProcessCommand` | `queue:process` |
| `daemons:start` | `Pramnos\Console\DaemonOrchestrator` | `daemons:start` |

---

## 12. Docker Infrastructure Requirements

### 12.1 PostgreSQL Extensions (from `0-enable-extensions.sql`)

```sql
CREATE EXTENSION IF NOT EXISTS timescaledb;
CREATE EXTENSION IF NOT EXISTS mysql_fdw;
CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS postgis_topology;
CREATE EXTENSION IF NOT EXISTS unaccent;
```

Framework Docker testing image **already has** `timescaledb` (from `docker-compose.yml`). For full parity, the framework test container needs:
- `timescaledb` ✅ (already present)
- `unaccent` — needed for search features
- `postgis`, `postgis_topology` — only needed for location-aware apps (not generic)
- `mysql_fdw` — only needed for MySQL → PostgreSQL bridge (not generic)

**Framework requirement**: only `timescaledb` and `unaccent` are needed for generic features.

### 12.2 PHP Extensions

For the authserver OAuth2 features:
- `ext-openssl` — RSA key generation (already available in most PHP builds)
- `ext-pcntl` — signal handling in daemons (needs explicit Docker build flag)
- `ext-posix` — PID/process management in daemons

### 12.3 Composer dependencies to add

```json
{
    "require": {
        "league/oauth2-server": "^8.5",
        "paragonie/random_compat": "^9.99"
    }
}
```

---

## 13. UrbanWater-Specific Items (NOT to backport)

Τα παρακάτω υπάρχουν στο UrbanWater αλλά είναι **application-specific** — δεν πάνε στο framework:

- `Urbanwater\Models\Queueitem` model → αντικαθίσταται από `Pramnos\Queue\Queueitem`
- `Urbanwater\Services\Queue\Tasks\*` (GetNetworkserverData, UpdateSupplyDailyConsumption, RecalcDeviceStats, EchoTask) → UW-specific
- `Urbanwater\Services\Kafka\*` → UW-specific Kafka integration
- `Urbanwater\Services\NetworkServers\*` → IoT network server adapters
- All `src/Controllers/*`, `src/Api/Controllers/*` — application-specific
- All `src/ConsoleCommands/*` except `CommandBase`, `DaemonCommandBase`, `ProcessQueue`, `DaemonOrchestrator` — UW-specific
- Database tables: alerts, bills, customers, datapoints, devices, locations, zones, etc. — application-specific

---

## 14. TimescaleDB Fallback Strategy

Το framework υποστηρίζει τρία database backends: **MySQL 8.0**, **PostgreSQL** (χωρίς TimescaleDB), και **PostgreSQL + TimescaleDB**. Κάθε TimescaleDB-specific feature (hypertables, continuous aggregates, compression/retention policies, `time_bucket()`) πρέπει να έχει graceful degradation για τα άλλα δύο backends. Κανένα migration ή query δεν επιτρέπεται να αποτύχει με fatal error σε non-TimescaleDB περιβάλλον — μόνο silent downgrade.

---

### 14.1 `DatabaseCapabilities` — Runtime Detection

```php
namespace Pramnos\Database;

class DatabaseCapabilities
{
    const TIMESCALEDB     = 'timescaledb';
    const MATERIALIZED_VIEWS = 'materialized_views';  // PG only
    const JSONB           = 'jsonb';                  // PG only
    const ENUMS           = 'enums';                  // PG only (native CREATE TYPE)

    private static array $cache = [];
    private \Pramnos\Database\Database $db;

    public function __construct(\Pramnos\Database\Database $db)
    {
        $this->db = $db;
    }

    /**
     * Check if a capability is available on the current connection.
     */
    public function has(string $capability): bool
    {
        if (isset(self::$cache[$capability])) {
            return self::$cache[$capability];
        }
        return self::$cache[$capability] = $this->detect($capability);
    }

    public function isMySQL(): bool
    {
        return $this->db->getType() === 'mysql';
    }

    public function isPostgreSQL(): bool
    {
        return in_array($this->db->getType(), ['postgres', 'postgresql', 'pgsql'], true);
    }

    public function hasTimescaleDB(): bool
    {
        return $this->has(self::TIMESCALEDB);
    }

    public function hasMaterializedViews(): bool
    {
        // Available on PostgreSQL (with or without TimescaleDB)
        return $this->isPostgreSQL();
    }

    private function detect(string $capability): bool
    {
        switch ($capability) {
            case self::TIMESCALEDB:
                if (!$this->isPostgreSQL()) {
                    return false;
                }
                $result = $this->db->prepareQuery(
                    "SELECT COUNT(*) FROM pg_extension WHERE extname = 'timescaledb'"
                );
                return (int)$result->fetchColumn() > 0;

            case self::MATERIALIZED_VIEWS:
                return $this->isPostgreSQL();

            case self::JSONB:
                return $this->isPostgreSQL();

            case self::ENUMS:
                return $this->isPostgreSQL();

            default:
                return false;
        }
    }
}
```

**Χρήση:**

```php
$caps = new DatabaseCapabilities($db);

if ($caps->hasTimescaleDB()) {
    // use time_bucket(), hypertables, retention policies
} elseif ($caps->isPostgreSQL()) {
    // use DATE_TRUNC(), MATERIALIZED VIEW for aggregates
} else {
    // MySQL: use DATE_FORMAT(), queue-based jobs, regular tables
}
```

---

### 14.2 Fallback Mapping Table

| TimescaleDB Feature | MySQL 8.0 Fallback | Plain PostgreSQL Fallback |
|---|---|---|
| `create_hypertable()` | Regular table (no-op) | Regular table (no-op) |
| Continuous Aggregate | Table + queue refresh job | `MATERIALIZED VIEW` + queue refresh |
| `add_compression_policy()` | No-op (data uncompressed) | No-op (data uncompressed) |
| `add_retention_policy()` | Queue DELETE job (daily) | Queue DELETE job (daily) |
| `time_bucket(interval, col)` | `DATE_FORMAT(col, format)` | `DATE_TRUNC(interval, col)` |
| `add_job()` scheduler | Queue-based schedule | Queue-based schedule |
| TimescaleDB-specific views (`timescaledb_information.*`) | Not available — skip | Not available — skip |

**Κανόνας:** Οι πίνακες δημιουργούνται κανονικά σε όλα τα backends. Μόνο τα time-series optimizations παραλείπονται όταν δεν είναι διαθέσιμα — η εφαρμογή δουλεύει (πιο αργά, με μεγαλύτερα tables), αλλά δεν σπάει.

---

### 14.3 Schema Builder Integration: `->ifCapable()`

Το `SchemaBuilder` αποκτά μέθοδο `ifCapable()` που εκτελεί ένα callback μόνο αν η capability είναι διαθέσιμη:

```php
$schema->createTable('tokenactions', function (Blueprint $table) {
    $table->bigIncrements('actionid');
    $table->string('action', 64);
    $table->integer('userid')->nullable();
    $table->text('data')->nullable();
    $table->timestampTz('action_time')->default('NOW()');
    // ... rest of columns
});

// Μετά τη δημιουργία του πίνακα:
$schema->ifCapable(DatabaseCapabilities::TIMESCALEDB, function (SchemaBuilder $schema) {
    $schema->createHypertable('tokenactions', 'action_time', [
        'chunk_time_interval' => '14 days',
    ]);
    $schema->enableCompression('tokenactions', ['segmentby' => 'action']);
    $schema->addCompressionPolicy('tokenactions', '60 days');
});

// Fallback για plain PostgreSQL: τίποτα — απλός πίνακας
// Fallback για MySQL: τίποτα — απλός πίνακας
// TimescaleDB: hypertable με compression
```

**API:**

```php
// Εκτελείται μόνο αν capability διαθέσιμη (no-op αλλιώς):
$schema->ifCapable(string $capability, callable $callback): void

// Εκτελείται σε TimescaleDB — αλλιώς εκτελείται το fallback:
$schema->ifCapableOrElse(
    string $capability,
    callable $ifTrue,
    callable $ifFalse
): void
```

---

### 14.4 `time_bucket()` Dialect Handling

Τα TimescaleDB `time_bucket()` expressions μεταφράζονται αυτόματα ανάλογα με το backend. Χρησιμοποιείται μέσω του `QueryBuilder`:

```php
// QueryBuilder helper — dialect-aware:
$qb->timeBucket('1 hour', 'action_time', 'bucket');

// TimescaleDB:    time_bucket('1 hour', action_time) AS bucket
// Plain PG:       DATE_TRUNC('hour', action_time) AS bucket
// MySQL:          DATE_FORMAT(action_time, '%Y-%m-%d %H:00:00') AS bucket
```

**Mapping ανά interval:**

| Interval | TimescaleDB | Plain PostgreSQL | MySQL |
|---|---|---|---|
| `'1 minute'` | `time_bucket('1 minute', col)` | `DATE_TRUNC('minute', col)` | `DATE_FORMAT(col, '%Y-%m-%d %H:%i:00')` |
| `'1 hour'` | `time_bucket('1 hour', col)` | `DATE_TRUNC('hour', col)` | `DATE_FORMAT(col, '%Y-%m-%d %H:00:00')` |
| `'1 day'` | `time_bucket('1 day', col)` | `DATE_TRUNC('day', col)` | `DATE_FORMAT(col, '%Y-%m-%d')` |
| `'1 week'` | `time_bucket('1 week', col)` | `DATE_TRUNC('week', col)` | `DATE_FORMAT(col, '%x-%v')` (ISO week) |
| `'1 month'` | `time_bucket('1 month', col)` | `DATE_TRUNC('month', col)` | `DATE_FORMAT(col, '%Y-%m-01')` |
| `'1 year'` | `time_bucket('1 year', col)` | `DATE_TRUNC('year', col)` | `DATE_FORMAT(col, '%Y-01-01')` |
| custom (e.g. `'5 minutes'`) | `time_bucket('5 minutes', col)` | `DATE_TRUNC('minute', ...) + rounding` | Raw expression μέσω `raw()` |

**Σημείωση:** Τα custom intervals (π.χ. `'5 minutes'`, `'15 minutes'`) δεν έχουν άμεση MySQL αντιστοίχιση. Για αυτές τις περιπτώσεις το QueryBuilder παράγει `raw()` expression και τεκμηριώνει ότι η ακρίβεια εξαρτάται από το backend.

---

### 14.5 Retention Policy Fallback

Στο TimescaleDB: `add_retention_policy(table, interval)` — ο TimescaleDB scheduler διαγράφει παλιά chunks αυτόματα.

**Fallback (MySQL + plain PG):** Ένα framework `RetentionCleanupTask` καταχωρείται στο Queue System ως daily scheduled task:

```php
// Framework-provided queue task (registered by the migration):
class RetentionCleanupTask extends AbstractTask
{
    public function execute(array $data): bool
    {
        $table    = $data['table'];
        $column   = $data['time_column'];
        $interval = $data['interval'];  // e.g. '24 months'

        $this->db->prepareQuery(
            "DELETE FROM {$table}
             WHERE {$column} < NOW() - INTERVAL '{$interval}'"
        );
        return true;
    }
}
```

Τα migrations που ορίζουν retention policy καταχωρούν αυτό το task αυτόματα:

```php
// Migration up():
$schema->ifCapable(DatabaseCapabilities::TIMESCALEDB, function ($schema) use ($table) {
    $schema->addRetentionPolicy($table, '24 months');
}, function () use ($queueManager, $table) {
    // Fallback: register daily cleanup task
    $queueManager->addTask('retentionCleanup', [
        'table'       => $table,
        'time_column' => 'action_time',
        'interval'    => '24 months',
    ], priority: 1, unique: true);
});
```

---

### 14.6 Continuous Aggregate Fallback

Στο TimescaleDB: `CREATE MATERIALIZED VIEW ... WITH (timescaledb.continuous)` + `add_continuous_aggregate_policy()`.

**Fallback — Plain PostgreSQL:** Κανονική `MATERIALIZED VIEW` (δεν refreshάρει αυτόματα). Ένα `MaterializedViewRefreshTask` στη queue το refreshάρει περιοδικά:

```php
// Plain PG: δημιουργία MATERIALIZED VIEW
$schema->ifCapableOrElse(
    DatabaseCapabilities::TIMESCALEDB,
    function ($schema) use ($viewName, $query) {
        // TimescaleDB path
        $schema->createContinuousAggregate($viewName, $query, '1 hour');
        $schema->addContinuousAggregatePolicy($viewName, '1 hour', '1 day', '7 days');
    },
    function ($schema, $caps) use ($viewName, $query, $queueManager) {
        if ($caps->hasMaterializedViews()) {
            // Plain PG: MATERIALIZED VIEW + queue refresh
            $schema->createMaterializedView($viewName, $query);
            $queueManager->addTask('refreshMaterializedView', [
                'view' => $viewName,
            ], priority: 5, unique: true);
        } else {
            // MySQL: regular table populated by queue job
            $schema->createTableFromQuery($viewName . '_cache', $query);
            $queueManager->addTask('rebuildCacheTable', [
                'table' => $viewName . '_cache',
                'query' => $query,
            ], priority: 5, unique: true);
        }
    }
);
```

---

### 14.7 Compression Policy Fallback

Στο TimescaleDB: `add_compression_policy()` συμπιέζει παλιά chunks αυτόματα.

**Fallback (MySQL + plain PG):** **No-op** — τα δεδομένα αποθηκεύονται ασυμπίεστα. Δεν γίνεται καμία ενέργεια, καμία warning. Ο κώδικας μετάβασης:

```php
$schema->ifCapable(DatabaseCapabilities::TIMESCALEDB, function ($schema) use ($table) {
    $schema->enableCompression($table, ['segmentby' => 'action']);
    $schema->addCompressionPolicy($table, '60 days');
});
// Για MySQL/plain PG: silently skipped — no error, no warning
```

---

### 14.8 Hypertable Creation Fallback

```php
// Migration up():
$schema->createTable('user_activity_log', function (Blueprint $table) {
    $table->bigIncrements('id');
    $table->integer('userid')->nullable();
    $table->string('action', 128);
    $table->jsonb('context')->nullable();   // falls back to TEXT on MySQL
    $table->timestampTz('logged_at')->default('NOW()');
    $table->primary(['id', 'logged_at']);   // composite PK needed for TimescaleDB hypertable
});

$schema->ifCapable(DatabaseCapabilities::TIMESCALEDB, function ($schema) {
    $schema->createHypertable('user_activity_log', 'logged_at', [
        'chunk_time_interval' => '1 day',
        'migrate_data'        => true,
    ]);
    $schema->enableCompression('user_activity_log', [
        'segmentby' => 'userid',
        'orderby'   => 'logged_at DESC',
    ]);
    $schema->addCompressionPolicy('user_activity_log', '30 days');
    $schema->addRetentionPolicy('user_activity_log', '24 months');
}, function ($caps, $queueManager) {
    // Fallback: register retention cleanup only (compression/hypertable: no-op)
    $queueManager->addTask('retentionCleanup', [
        'table'       => 'user_activity_log',
        'time_column' => 'logged_at',
        'interval'    => '24 months',
    ], priority: 1, unique: true);
});
```

**Σημαντικό:** Η composite PK `(id, logged_at)` χρειάζεται από το TimescaleDB για το partitioning. Σε MySQL/plain PG είναι απλώς ένα composite PK χωρίς ειδική σημασία — δουλεύει κανονικά.

---

### 14.9 JSONB → TEXT/JSON Fallback

Το `JSONB` είναι PostgreSQL-only. Στο Schema Builder:

```php
$table->jsonb('context');
// → PostgreSQL: JSONB (binary JSON, indexable)
// → MySQL:      JSON (native MySQL JSON type, available in 5.7.8+)
// Fallback MySQL < 5.7.8: LONGTEXT με CHECK constraint (αν supported)
```

---

### 14.10 ENUM Type Fallback

Το PostgreSQL `CREATE TYPE queue_status AS ENUM (...)` είναι DDL-level — δεν υπάρχει στη MySQL ως χωριστό type.

```php
// Schema Builder:
$table->enumType('status', ['pending','processing','completed','failed','warning']);
// → PostgreSQL + TimescaleDB: CREATE TYPE {table}_{col}_enum AS ENUM (...); + column reference
// → MySQL:                    TINYINT (0-4) + CHECK constraint + comment documenting values
//                             OR ENUM('pending','processing',...) inline (MySQL supports ENUM inline)
```

Για το `queueitems` συγκεκριμένα, όπου ο τύπος `queue_status` είναι shared type στο PostgreSQL:

```php
// Migration για PostgreSQL:
$schema->createEnumType('queue_status', ['pending','processing','completed','failed','warning']);
// Migration για MySQL: no separate type creation needed (ENUM inline στη στήλη)

$schema->ifCapable(DatabaseCapabilities::ENUMS, function ($schema) {
    $schema->createEnumType('queue_status', ['pending','processing','completed','failed','warning']);
});
// Στη createTable():
$table->column('status', 'queue_status')->default('pending');  // PG: references the type
// MySQL: auto-translates to ENUM('pending',...) inline
```

---

### 14.11 Migration Fallback Tests

Κάθε migration που χρησιμοποιεί TimescaleDB features πρέπει να τεστάρεται και στα τρία backends:

```php
// Παράδειγμα test για hypertable migration:
class CreateTokenactionsTableTest extends TestCase
{
    /** @dataProvider databaseProvider */
    public function testMigrationCreatesTable(string $driver): void
    {
        // Table must exist on all backends
        $this->assertTableExists('tokenactions');
        $this->assertColumnExists('tokenactions', 'action_time');
    }

    /** @dataProvider databaseProvider */
    public function testHypertableOnlyOnTimescaleDB(string $driver): void
    {
        if ($driver === 'timescaledb') {
            $this->assertIsHypertable('tokenactions');
        } else {
            // Regular table — must still be queryable
            $this->assertIsRegularTable('tokenactions');
        }
    }

    /** @dataProvider databaseProvider */
    public function testTimeBucketQueryWorks(string $driver): void
    {
        // Must return results on ALL backends (dialect translation must work)
        $results = $this->db->table('tokenactions')
            ->timeBucket('1 hour', 'action_time', 'bucket')
            ->groupBy('bucket')
            ->count();
        $this->assertIsArray($results);
    }

    public static function databaseProvider(): array
    {
        return [['mysql'], ['postgresql'], ['timescaledb']];
    }
}
```

---

*Αυτό το αρχείο ενημερώνεται κατά τη διάρκεια της ανάπτυξης. Κάθε ολοκληρωμένο feature μεταφράζεται σε concrete implementation task στο ROADMAP_1.2.md.*
*Το αντίστοιχο cleanup guide για το UrbanWater βρίσκεται στο `UrbanWater-Cleanup-Guide.md`.*
