# UrbanWater Cleanup Guide — Post-Backport

Αυτό το αρχείο είναι **οδηγός υλοποίησης** για τη μετατροπή των UrbanWater classes σε thin wrappers, αφού το αντίστοιχο framework class ολοκληρωθεί.

> **Task tracking** (τι εκκρεμεί, τι έγινε) → `ROADMAP_1.2.md` Φάση 2.

**Στόχος**: Κάθε UW class που έχει μεταφερθεί στο framework γίνεται얇ό wrapper που καλεί τη `\Pramnos\*` έκδοση:
1. Δεν σπάει κανένα υπάρχον UrbanWater test
2. Ο κώδικας δεν διπλοτυπείται
3. Bug fixes στο framework επωφελούν αυτόματα και το UrbanWater

> Η σειρά: framework class → UW wrapper → UW tests.

---

## Phase 1: Queue System

### 1.1 Αφαίρεση `src/Services/Queue/QueueManager.php`

Αντικατάσταση με wrapper:
```php
// src/Services/Queue/QueueManager.php (AFTER backport)
namespace Urbanwater\Services\Queue;

class QueueManager extends \Pramnos\Queue\QueueManager
{
    // No overrides needed — full parity achieved
}
```

### 1.2 Αφαίρεση `src/Services/Queue/Worker.php`

Αντικατάσταση με wrapper — προσοχή στα task handlers που είναι UW-specific:
```php
// src/Services/Queue/Worker.php (AFTER backport)
namespace Urbanwater\Services\Queue;

class Worker extends \Pramnos\Queue\Worker
{
    public function __construct($controller, $workerId = null)
    {
        parent::__construct($controller, $workerId);
        // Register UW-specific handlers:
        $this->registerTaskHandler('getNetworkserverData', Tasks\GetNetworkserverDataTask::class);
        $this->registerTaskHandler('updateSupplyDailyConsumption', Tasks\UpdateSupplyDailyConsumption::class);
        $this->registerTaskHandler('recalcDeviceStats', Tasks\RecalcDeviceStats::class);
        $this->registerTaskHandler('echo', Tasks\EchoTask::class);
    }
}
```

### 1.3 Αφαίρεση `src/Services/Queue/AbstractTask.php`

```php
// src/Services/Queue/AbstractTask.php (AFTER backport)
namespace Urbanwater\Services\Queue;

abstract class AbstractTask extends \Pramnos\Queue\AbstractTask
{
    // BC: keep namespace alias for existing UW tasks
}
```

### 1.4 Αφαίρεση `src/Services/Queue/TaskInterface.php`

```php
// src/Services/Queue/TaskInterface.php (AFTER backport)
namespace Urbanwater\Services\Queue;

// Type alias only
interface TaskInterface extends \Pramnos\Queue\TaskInterface {}
```

### 1.5 Αντικατάσταση `Models/Queueitem.php`

**Σημείωση:** Το framework class είναι `Pramnos\Queue\QueueItem` (camelCase), όχι `Queueitem`.

Εάν υπάρχει ως UW class, αντικαθίσταται από `\Pramnos\Queue\QueueItem`.
Αν υπάρχουν UW-specific properties/methods, subclass:
```php
namespace Urbanwater\Models;

class Queueitem extends \Pramnos\Queue\QueueItem {}
```

---

## Phase 2: CLI Commands

### 2.1 Αφαίρεση `src/ConsoleCommands/CommandBase.php`

```php
// src/ConsoleCommands/CommandBase.php (AFTER backport)
namespace Urbanwater\ConsoleCommands;

abstract class CommandBase extends \Pramnos\Console\CommandBase
{
    // UW-specific overrides (if any) only
}
```

### 2.2 Αφαίρεση `src/ConsoleCommands/DaemonCommandBase.php`

```php
// Already a deprecated alias — just extend new CommandBase:
namespace Urbanwater\ConsoleCommands;

/** @deprecated Use CommandBase instead */
abstract class DaemonCommandBase extends CommandBase {}
```

### 2.3 Αφαίρεση `src/ConsoleCommands/ProcessQueue.php`

```php
// src/ConsoleCommands/ProcessQueue.php (AFTER backport)
namespace Urbanwater\ConsoleCommands;

class ProcessQueue extends \Pramnos\Console\Commands\ProcessQueue
{
    protected function configure()
    {
        parent::configure();
        // UW-specific title override:
        $this->setDescription('Process the UrbanWater task queue');
    }

    // Override renderDashboard title if needed:
    protected function getDashboardTitle(): string
    {
        return ' URBANWATER QUEUE PROCESSOR ';
    }
}
```

### 2.4 Αφαίρεση `src/ConsoleCommands/DaemonOrchestrator.php`

```php
// src/ConsoleCommands/DaemonOrchestrator.php (AFTER backport)
namespace Urbanwater\ConsoleCommands;

class DaemonOrchestrator extends \Pramnos\Console\DaemonOrchestrator
{
    protected function getDashboardTitle(): string
    {
        return ' URBANWATER DAEMON ORCHESTRATOR ';
    }
}
```

---

## Phase 3: Auth Subproject (`auth/src/`)

### 3.1 Αφαίρεση `auth/src/OAuth2/OAuth2ServerFactory.php`

```php
// auth/src/OAuth2/OAuth2ServerFactory.php (AFTER backport)
namespace Authserver\OAuth2;

class OAuth2ServerFactory extends \Pramnos\Auth\OAuth2\OAuth2ServerFactory
{
    // No overrides needed — identical
}
```

### 3.2 Αφαίρεση `auth/src/OAuth2/Repositories/*.php`

Κάθε repository γίνεται thin alias:
```php
namespace Authserver\OAuth2\Repositories;

class ClientRepository extends \Pramnos\Auth\OAuth2\Repositories\ClientRepository {}
class AccessTokenRepository extends \Pramnos\Auth\OAuth2\Repositories\AccessTokenRepository {}
class AuthCodeRepository extends \Pramnos\Auth\OAuth2\Repositories\AuthCodeRepository {}
class RefreshTokenRepository extends \Pramnos\Auth\OAuth2\Repositories\RefreshTokenRepository {}
class ScopeRepository extends \Pramnos\Auth\OAuth2\Repositories\ScopeRepository {}
class UserRepository extends \Pramnos\Auth\OAuth2\Repositories\UserRepository {}
```

### 3.3 Αφαίρεση `auth/src/OAuth2/Entities/*.php`

```php
namespace Authserver\OAuth2\Entities;

class ClientEntity extends \Pramnos\Auth\OAuth2\Entities\ClientEntity {}
class AccessTokenEntity extends \Pramnos\Auth\OAuth2\Entities\AccessTokenEntity {}
// ... κλπ για όλα τα 6 entities
```

### 3.4 Αφαίρεση `auth/src/OAuth2/Middleware/OAuth2Middleware.php`

```php
namespace Authserver\OAuth2\Middleware;

class OAuth2Middleware extends \Pramnos\Auth\OAuth2\OAuth2Middleware {}
```

### 3.5 Αφαίρεση `auth/src/Models/Loginlockout.php`

```php
// auth/src/Models/Loginlockout.php (AFTER backport)
namespace Authserver\Models;

class Loginlockout extends \Pramnos\Auth\Loginlockout {}
```

### 3.6 Αφαίρεση `auth/src/Services/TwoFactorAuthService.php`

```php
namespace Authserver\Services;

class TwoFactorAuthService extends \Pramnos\Auth\TwoFactorAuthService {}
```

### 3.7 Αφαίρεση `auth/src/Helpers/TOTPHelper.php`

```php
namespace Authserver\Helpers;

class TOTPHelper extends \Pramnos\Auth\TOTPHelper {}
```

### 3.8 Αφαίρεση `auth/src/Helpers/Scopes.php`

```php
namespace Authserver\Helpers;

class Scopes extends \Pramnos\Auth\Scopes {}
```

### 3.9 Αφαίρεση `auth/src/Services/WebhookService.php`

```php
namespace Authserver\Services;

class WebhookService extends \Pramnos\Auth\WebhookService {}
```

### 3.10 `auth/src/Models/Message.php`

```php
// auth/src/Models/Message.php (AFTER backport)
namespace Authserver\Models;

class Message extends \Pramnos\Messaging\Message {}
```

---

## Phase 4: Messaging

### 4.1 Messaging models (αν υπάρχουν σε UW)

Εάν υπάρχουν UW-specific messaging models:
```php
namespace Urbanwater\Models;

class MassMessage extends \Pramnos\Messaging\MassMessage {}
class MassMessageRecipient extends \Pramnos\Messaging\MassMessageRecipient {}
```

---

## Phase 5: Database Migrations

### 5.1 UrbanWater migrations — δεν αλλάζουν

Τα UrbanWater migrations **δεν αφαιρούνται, δεν απενεργοποιούνται, δεν τροποποιούνται**. Έχουν ήδη τρέξει σε production. Η βάση υπάρχει. Αγγίζεις ένα UW migration μόνο αν υπάρχει bug σε αυτό — όχι λόγω backport.

Το πρόβλημα που λύνουμε δεν είναι «πώς εμποδίζουμε τα UW migrations να ξαναφτιάξουν πίνακες», αλλά «πώς εμποδίζουμε τα **framework** baseline migrations να προσπαθήσουν να φτιάξουν πίνακες που ήδη υπάρχουν».

### 5.2 Πώς λειτουργεί το `migration_cutoff`

Τα framework baseline system migrations φέρουν **σκόπιμα παλιά timestamps** στο όνομά τους — π.χ.:

```
2020_01_01_000000_create_sessions_table.php
2020_01_01_000100_create_settings_table.php
2020_01_01_000200_create_users_table.php
2020_01_01_000300_create_usertokens_table.php
...
```

Κατά το upgrade ενός υπάρχοντος UrbanWater installation σε framework v1.2, θέτεις:

```
migration_cutoff = '2026-01-01 00:00:00'   ← οποιαδήποτε ημερομηνία μετά τα 2020_* timestamps
```

Ο migration runner διαβάζει το `migration_cutoff` και **παραλείπει σιωπηλά** κάθε framework migration με timestamp παλιότερο από αυτό. Επομένως:

- Τα `2020_01_01_*` framework migrations → **παραλείπονται** (pre-cutoff) ✓
- Τα νέα UW app migrations (π.χ. `2026_05_*`) → **τρέχουν κανονικά** (post-cutoff) ✓
- Τα ήδη καταγεγραμμένα UW migrations → **παραλείπονται** γιατί ήδη υπάρχουν στο history table ✓

**Αποτέλεσμα:** Σε fresh install δεν υπάρχει cutoff → τα framework baseline migrations τρέχουν και φτιάχνουν τους πίνακες. Σε υπάρχον installation → το cutoff τα παρακάμπτει.

### 5.3 Ποιοι πίνακες καλύπτονται από framework baseline migrations

Αυτοί οι πίνακες υπάρχουν ήδη στο UW production — οι αντίστοιχοι framework migrations θα τους φτιάχνουν μόνο σε fresh install:

- `sessions`, `settings`
- `users`, `userdetails`, `userlog`, `usernotes`
- `usertokens` (with PKCE columns + TEXT token)
- `tokenactions`, `urls`
- `queueitems` (+ ENUM type)
- `mails`, `mailtemplates`, `messages`, `massmessages`, `massmessagerecipients`
- `authserver.*` schema + tables
- GDPR hypertables, 2FA tables
- `framework_migrations` (αντικατάσταση `schemaversion` — μόνο σε fresh install)

---

## Phase 6: DevPanel (Dashboard, Cache Browser, User Activity, Git Info)

*Αφορά τον κώδικα που θα αντικατασταθεί από την υλοποίηση της Φάσης 14 (DevPanel) στο framework.*

---

### 6.1 `src/Controllers/Home.php` — διαγραφή, αντικατάσταση με routes στο DevPanel

Όλες οι παρακάτω μέθοδοι γίνονται **thin routes** που απλώς ανακατευθύνουν στο framework DevPanel. Αφού η Φάση 14 ολοκληρωθεί:

| Μέθοδος | Τι κάνει | Αντικατάσταση |
|---|---|---|
| `display()` | Overview + DB stats + system info + cache stats + queue stats | `/devpanel` |
| `timescale()` | TimescaleDB hypertables, continuous aggregates, job schedules | `/devpanel/timescale` |
| `phpinfo()` | Wrapper phpinfo() | `/devpanel/phpinfo` |
| `activeUsers()` | Logged-in users + tokens | `/devpanel/sessions` |
| `cache()` | Cache item browser | `/devpanel/cache` |
| `cacheitem()` | AJAX: inspect cache item | `/devpanel/cache/item` |
| `clearcache()` | AJAX: flush cache | `/devpanel/cache/clear` |
| `performance()` | Slowest endpoints + users | `/devpanel/performance` |
| `kafka()`, `kafkaPostMessage()`, `kafkaGetMessages()` | Kafka-specific — **παραμένουν στο UW** | — |
| `formatBytes()`, `getServerMemoryInfo()`, `getCpuUsagePercentage()` | Helpers — μεταφέρονται στο framework | `Pramnos\DevPanel\SystemInfo` |

**Μετά την αντικατάσταση:** το `Home` controller διατηρείται μόνο για Kafka-specific actions. Το `app/routes.php` ανακατευθύνει:
```php
// Redirect legacy UW dashboard routes → framework DevPanel
$router->get('/home',            fn() => redirect(sURL . 'devpanel'));
$router->get('/home/timescale',  fn() => redirect(sURL . 'devpanel/timescale'));
$router->get('/home/cache',      fn() => redirect(sURL . 'devpanel/cache'));
$router->get('/home/phpinfo',    fn() => redirect(sURL . 'devpanel/phpinfo'));
$router->get('/home/activeUsers',fn() => redirect(sURL . 'devpanel/sessions'));
$router->get('/home/performance',fn() => redirect(sURL . 'devpanel/performance'));
```

---

### 6.2 `src/Controllers/Users.php` — μερική αντικατάσταση

| Μέθοδος | Τι κάνει | Αντικατάσταση |
|---|---|---|
| `logs($userid)` | User action log (itemlog) | `/devpanel/users/{id}/logs` |
| `tokenDetail()` | Token + paginated action history | `/devpanel/sessions/token/{id}` |
| `security()` | Login lockout monitor (active lockouts, policy) | `/devpanel/security` |
| `unlockLogin()` | Manual unlock of locked user/IP | `/devpanel/security/unlock` |
| `getUsers()` | JSON endpoint — λίστα users | παραμένει στο UW (app-specific) |
| `display()`, `view()`, `save()`, `delete()`, `edit()` | User CRUD | παραμένουν στο UW |
| `sendnotification()`, `notify()` | Notifications | παραμένουν στο UW |
| `deleteToken()`, `deactivateToken()`, `expireToken()` | Token management | `/devpanel/sessions/token/{id}/action` |
| `saveUserSetting()`, `deleteUserSetting()` | User settings | παραμένουν στο UW |
| `getUserActionsData()`, `getTokensData()` | AJAX endpoints | αντικαθίστανται από DevPanel AJAX routes |
| `findByToken()` | Token lookup | αντικαθίσταται από DevPanel |

---

### 6.3 `app/themes/main/footer.php` — Git Info widget

Ο κώδικας στο footer που διαβάζει `.git/HEAD` και εμφανίζει branch + commit + modal:

```php
// ΣΗΜΕΡΑ (inline στο footer.php):
$headFile = ROOT . '/.git/HEAD';
$ref = trim(file_get_contents($headFile));
// ... 120 γραμμές PHP/HTML για το modal
```

**Μετά τη Φάση 14:**
```php
// footer.php (AFTER framework backport)
$gitInfo = \Pramnos\Framework\GitInfo::read(ROOT);
echo $gitInfo->renderFooterWidget(); // branch + hash, click → modal
```

Το modal HTML παράγεται από το framework. Το UW footer.php κρατάει μόνο τη δική του markup.

---

### 6.4 `src/Views/home/` — Views

Οι παρακάτω views αντικαθίστανται από τα framework DevPanel views (τα οποία μπορεί να override-αριστούν με UW-specific views αν χρειαστεί):

- `home.html.php` → `devpanel/overview.html.php` (framework)
- `timescale.html.php` → `devpanel/timescale.html.php` (framework)
- `cache.html.php` → `devpanel/cache.html.php` (framework)
- `phpinfo.html.php` → `devpanel/phpinfo.html.php` (framework)
- `activeUsers.html.php` → `devpanel/sessions.html.php` (framework)
- `performance.html.php` → `devpanel/performance.html.php` (framework)
- `kafka.html.php` → **παραμένει στο UW** (Kafka-specific)

---

## Phase 7: Tests

### 6.1 Tests που πρέπει να ενημερωθούν

Αφού κάθε UW class γίνει wrapper, τα αντίστοιχα tests:
- **Πρέπει να συνεχίσουν να περνούν** χωρίς αλλαγή (αυτό επαληθεύει το BC)
- Εάν ένα test mock-άρει internal implementation details που δεν υπάρχουν πια, ενημέρωσε το test

### 6.2 Νέα framework tests (από backport)

Κάθε framework class πρέπει να έχει:
- Unit tests στο `PramnosFramework/tests/Unit/`
- Integration tests στο `PramnosFramework/tests/Integration/` (× 3 databases)

---

---

## Σημείωση για Migration Cutoff

Τα framework baseline migrations φέρουν σκόπιμα παλιά timestamps (`2020_01_01_*`). Κατά το upgrade ενός υπάρχοντος UrbanWater installation, θέτεις `migration_cutoff = '2026-01-01 00:00:00'` ώστε ο runner να παρακάμπτει τα pre-cutoff framework migrations — χωρίς να αγγίξει τα UW migrations που έχουν ήδη τρέξει σε production.

*Αυτό το αρχείο περιέχει τα **wrapper code patterns** για κάθε UrbanWater class. Task tracking → `ROADMAP_1.2.md` Φάση 2.*
