# UrbanWater Cleanup Guide — Post-Backport

Αυτό το αρχείο περιγράφει **τι πρέπει να αφαιρεθεί / αντικατασταθεί** από το UrbanWater project και το `auth` subproject, αφού ολοκληρωθεί το backport στο PramnosFramework v1.2.

**Στόχος**: Κάθε μέθοδος στο UrbanWater που έχει μεταφερθεί στο framework να γίνει ένα얇ό wrapper που καλεί τη `\Pramnos\*` έκδοση. Έτσι:
1. Δεν σπάει κανένα υπάρχον UrbanWater test
2. Ο κώδικας δεν διπλοτυπείται
3. Bug fixes στο framework επωφελούν αυτόματα και το UrbanWater

> Η σειρά εκτέλεσης σημαίνει κάτι: πρώτα φτιάχνεις το framework class, μετά κάνεις το UrbanWater class wrapper, μετά τρέχεις τα UrbanWater tests για να επαληθεύσεις.

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

Εάν υπάρχει ως UW class, αντικαθίσταται από `\Pramnos\Queue\Queueitem`.
Αν υπάρχουν UW-specific properties/methods, subclass:
```php
namespace Urbanwater\Models;

class Queueitem extends \Pramnos\Queue\Queueitem {}
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

class ProcessQueue extends \Pramnos\Console\QueueProcessCommand
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

## Phase 6: Tests

### 6.1 Tests που πρέπει να ενημερωθούν

Αφού κάθε UW class γίνει wrapper, τα αντίστοιχα tests:
- **Πρέπει να συνεχίσουν να περνούν** χωρίς αλλαγή (αυτό επαληθεύει το BC)
- Εάν ένα test mock-άρει internal implementation details που δεν υπάρχουν πια, ενημέρωσε το test

### 6.2 Νέα framework tests (από backport)

Κάθε framework class πρέπει να έχει:
- Unit tests στο `PramnosFramework/tests/Unit/`
- Integration tests στο `PramnosFramework/tests/Integration/` (× 3 databases)

---

## Checklist ανά Phase

### Phase 1 — Queue System
- [ ] `Pramnos\Queue\QueueManager` υλοποιημένο και tested
- [ ] `Pramnos\Queue\Worker` υλοποιημένο και tested
- [ ] `Pramnos\Queue\AbstractTask` + `TaskInterface` υλοποιημένα
- [ ] `Pramnos\Queue\Queueitem` model υλοποιημένο
- [ ] System migration για `queueitems` πεπερασμένο
- [ ] `Urbanwater\Services\Queue\QueueManager` → wrapper
- [ ] `Urbanwater\Services\Queue\Worker` → wrapper
- [ ] UW tests for Queue still pass

### Phase 2 — CLI Commands
- [ ] `Pramnos\Console\CommandBase` υλοποιημένο
- [ ] `Pramnos\Console\QueueProcessCommand` υλοποιημένο
- [ ] `Pramnos\Console\DaemonOrchestrator` υλοποιημένο
- [ ] UW `ProcessQueue` → thin wrapper
- [ ] UW `DaemonOrchestrator` → thin wrapper
- [ ] UW CLI tests still pass

### Phase 3 — OAuth / Auth
- [ ] `Pramnos\Auth\OAuth2\OAuth2ServerFactory` υλοποιημένο
- [ ] Repositories (6) + Entities (6) μεταφερθεί
- [ ] `Pramnos\Auth\Loginlockout` υλοποιημένο
- [ ] `Pramnos\Auth\TwoFactorAuthService` υλοποιημένο
- [ ] System migrations για auth/oauth/2FA/GDPR tables
- [ ] `auth/src/OAuth2/*` → wrappers
- [ ] `auth/src/Models/Loginlockout` → wrapper
- [ ] Auth tests still pass

### Phase 4 — Messaging
- [ ] Messaging models υλοποιημένα
- [ ] System migrations για messaging tables
- [ ] UW messaging tests still pass

### Phase 5 — Migrations
- [ ] Framework baseline migrations έχουν timestamps `2020_01_01_*` (pre-cutoff)
- [ ] Fresh DB install: κανένα cutoff → framework migrations τρέχουν κανονικά
- [ ] Existing UW install: `migration_cutoff` set σε ημερομηνία μετά τα `2020_*` timestamps
- [ ] Επαλήθευση ότι κανένα UW migration δεν έχει αλλαχτεί

---

*Αυτό το αρχείο χρησιμοποιείται ως checklist κατά τη διάρκεια της v1.2 ανάπτυξης. Κάθε ολοκληρωμένο item σημαδεύεται `[x]`.*
