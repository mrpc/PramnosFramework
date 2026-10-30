---
use_cases:
  - Turning on a second factor (TOTP, email code) and choosing who must use it
  - Asking for the second factor on sensitive actions, not only at sign-in
  - Trusting a device, or approving a sign-in from another one
  - Locking out repeated wrong passwords, and alerting a person to a sign-in from somewhere new
  - Letting a trusted phone approve sign-ins ("Is it you trying to sign in?")
  - Offering "Don't ask again on this device", or listing and revoking trusted devices
---

# Second factors, lockout and new-device alerts

Part of the [authentication guide](Pramnos_Authentication_Guide.md).

## Second factors: which ones exist, and who decides

Four, and they are not equally strong. The order is the policy, and it is the order a
step-up offers them in:

| Method | What it is | Strength |
|--------|-----------|----------|
| `passkey` | WebAuthn — a device holding a key | strongest; also a *primary* method |
| `totp` | an authenticator app | strong; needs enrolling in advance |
| `email` | a six-digit code, mailed | weakest; needs nothing set up in advance |
| `push` | "Is it you trying to sign in?" on a trusted phone | offered first when the account has one — see below |

**Wrong codes are limited.** Each wrong second-factor code (TOTP, a backup code, a mailed code,
any registered factor) counts against the pending login, and against the account's lockout like a
wrong password. After `'auth' => ['second_factor_attempts' => 5]` of them, five by default, the
pending login is ended and the next try starts again from the password. If the failures set off
the lockout, the result is `LOCKED`, with its remaining seconds. Without the limit, a password was
enough to guess a six-digit code at leisure. A factor whose gateway is down refuses without
counting: that is an outage, not a guess.

## The second factor everywhere, not only on the login form

The login form asks for the second factor. A site's own sign-in form, a social login and a
remember-me cookie do not. Where one PHP session serves a site, an administration and an API, a
password alone then reaches all three through whichever door does not ask. Turn on:

```php
'auth' => ['security' => ['second_factor_everywhere' => true]],
```

Every session is then checked once, in `Session::staticIsLogged()`, which every "is somebody
signed in" path goes through: pages, controllers' guards, `/oauth/authorize`, and the API's
session path.

- **A session the login flow finished** is marked (`SecondFactorGate::SESSION`) and passes from
  then on.
- **A session signed in any other way**, for an account with a second factor, is not counted as
  signed in. It becomes a pending login, and the login page shows the second step. The
  wrong-code limit applies to it as to any other, and its wrong codes count against the
  account's lockout under its username, as the form's would. An account already locked out gets
  no pending login: it waits until the lockout lifts, and the login page says it is locked and
  for how long (`SecondFactorGate::lockedFor()`).
- **A stopped session stays stopped.** It reads as signed out, but it is not a guest: while its
  login waits for the step, or its account is locked out, every page it opens is sent to the
  step again, at most fifteen minutes. Finishing the login, abandoning it or letting it expire
  ends that. Asked before the application exists, as a front controller deciding on its page
  cache does, the session counts as owed and nothing is forgotten. The question asked once the
  application is up decides. An application that throws away a guest's session on every request loses the
  pending login with it, and the person signs in again.
- **An account without a second factor, or a browser it trusts,** is marked and passes. A
  remember-me cookie in a browser trusted with "Don't ask again on this device" is therefore not
  asked again, as the form would not ask.
- **An administrator signing in as somebody else** settled their own factor, and the account
  they become is not asked for its own.

**Where the person is sent.** A page opened in a browser (a GET asking for HTML, not an XHR or
a JSON call) is remembered, and the account screens return to it once the second step is done,
where the request names no `return` of its own. To send the browser straight to the step, name
it:

```php
'auth' => ['security' => [
    'second_factor_everywhere' => true,
    'second_factor_url'        => 'login',   // or an area's own sign-in page, or a full URL
]],
```

Without it, the page simply sees nobody signed in and sends the person to sign in as it would
anyone. A form post, an API call or a script is never redirected: it is refused, as signed out.

**The application's own login flow.** The gate runs the same `LoginFlow` as the account screens:
`LoginFlow::make()`, which is the class `'auth' => ['login_flow' => App\Auth\LoginFlow::class]`
names, or the framework's own. A subclass with its own trusted devices, lockout or factors
therefore decides what a session owes as well as what the form asks.

Off by default. With it on, a remember-me cookie no longer skips the second factor: "Don't ask
again on this device" is what skips it. Nothing of this is inside `Auth::auth()`, which stays
the password check it is.

## Trusted devices and sign-in approval

Google's two sign-in conveniences, for every application on the framework. Both are **off until
an administrator turns them on** in System Settings → Security, because each changes what a
password can do.

**"Don't ask again on this device"** (`auth_trusted_devices`). The two-step page shows the box,
**ticked by default**. Finishing a real second factor with it ticked trusts the browser for
`auth_trusted_device_days` (30, 1–365): a password sign-in from it skips the second step until
then. Use does not extend it. `auth_trusted_devices_exclude_admins` (off) makes usertype ≥ 90
always take the second step — including browsers trusted before it was switched on, which also
stop receiving and answering phone prompts.

**The server trusts only a box it was sent.** `pf-auth.js` copies the box into whichever form is
submitted, as `trust_device=1` or `0` (and onto the passkey's address as `?trust=`); the step-up
remembers it with `LoginFlow::chooseTrust()`. A page that never sends it trusts nothing — no
JavaScript, or an application's own copy of `login/login_2fa` from before the box. Shown ticked is
not chosen: an application that copied the two-step view must add the box (and load `pf-auth.js`)
before its users can trust a device at all.

**A new password forgets every trusted device.** `Account::updatePassword()` — where both the
change and the reset arrive — calls `revokeAll()`: people change a password because somebody else
may have it, and that person's browser must not keep skipping the second step.

- `Pramnos\Auth\TrustedDevices` — `trust()`, `isTrusted()`, `current()`, `forUser()`, `revoke()`,
  `revokeAll()`. The cookie (`pf_trusted_device`, HTTP-only, Lax) holds a random token;
  `authserver.trusted_devices` holds only `Token::lookup()` of it.
- `/account/security` lists them, marks "This device", and forgets one or all
  (`account/revokedevice`, POST with the session token).
- A password alone, a trusted browser, or an emailed sign-in link never trusts anything.

**Approval from a trusted phone** (`auth_push_approval`). Needs push working — VAPID keys and
`minishlink/web-push` (see the Push guide); the settings screen says when it is not. An account
with a trusted device that is **subscribed to notifications** gets the `push` factor, offered
first. The step-up sends it as it begins — nothing to start, as with Google — and the page waits:

1. The phone's service worker reports receipt (`POST /push/ack?token=…`) and the page says
   "Delivered to your phone ✓". After **20 seconds without it** the page opens "Try another way".
2. **A number, unless the attempt is plainly the account's own.** The waiting page shows it; the
   page the notification opens (`account/approve`) offers three and the phone must pick the right
   one, and the notification's only button is **No, it's not me**. The exception is a plain
   **Yes / No** — on the notification and on the page — for a browser the account **trusted
   before** (`TrustedDevices::known()`: its cookie outlives the trust, `KNOWN_DAYS` = 365) signing
   in with **nothing unusual** (`SignInRisk`). An attempt that merely *looks* familiar earns
   nothing: User-Agent and country are what whoever holds the password chooses, and a lock-screen
   Yes is what prompt bombing counts on. `auth_push_number_matching`: `risk` (default) as above,
   `always` for the number every time.
3. Approved, the waiting page submits by itself (`method=push`, through `requestSubmit()` so the
   box goes with it) and signs in.

The rules: only **trusted** devices are asked, never every subscribed browser; answering needs a
browser **signed in as the account and carrying its trust cookie**, so a forwarded link approves
nothing; the wrong number is a refusal, and a Yes without the number an ask needs changes
nothing; **the first answer stands** (a No and a Yes
arriving together are settled by the row, so a refusal is never overwritten); only the **waiting
browser's session** can use an approval, once, within **60 s of the answer**
(`PushApprovals::CONSUME_WINDOW`); each ask can be answered for **120 s**; at most **3 asks in 10
minutes** per account. "No, it's not me" records `signin_denied`, and mails
`SecurityChangeNotifier::SIGNIN_DENIED` — the password is known to somebody else.

**Does it satisfy enrolment? The site decides.** `push` scores 70 so that it is offered first.
Whether it also counts towards `require_factor_enrolment_from_usertype` is
`auth_push_counts_for_enrolment` (`PushApprovalSecondFactor::countsForEnrolment()`), because a
browser can be trusted after a mailed code:

| Value | Counts when |
| --- | --- |
| `strong` (default) | a phone that would be asked was trusted with a factor that counts itself — an authenticator, a passkey, an adaptor of strength ≥ 40 (`authserver.trusted_devices.trusted_via`, `FactorEnrolment::methodIsStrong()`) |
| `always` | there is any phone to ask, as Google counts its prompt — for a site that does not need the stricter bar |
| `never` | never: the account needs its own authenticator, passkey or adaptor |

A browser trusted through the phone prompt itself, or before `trusted_via` existed, counts as not
strong.

**Never the only way.** The page always has "Try another way", and an account whose only other
option would be nothing is also offered a mailed code: switching the prompt on lets `email`
through the factor list whatever `twofactor_methods` says.

| Endpoint | Who | What |
| --- | --- | --- |
| `GET account/pushstatus` | the waiting page | `{state: sent\|delivered\|approved\|denied\|expired\|none, number, devices, expires_in}` |
| `GET/POST account/approve?token=` | the phone, signed in and trusted | the question, and the answer |
| `POST push/ack?token=` | the phone's worker | the receipt; the token is the credential |
| `POST push/respond?token=&decision=` | the notification's buttons | No always; Yes only on an ask that needs no number |
| `POST push/subscribe` | the page | now also links the subscription to its trusted device |

## Telling an account it was used from somewhere new

`NewSignInAlert` compares the current sign-in's device fingerprint against
`user_activity_log` and emails the account when the combination is new. It reads history
that already exists, which is why switching it on does not notify everybody at once.

**The site's policy sits around the user's own preference** — `auth_newsignin_policy` on
the settings screen:

| Value | Meaning |
| --- | --- |
| `optin` (default) | the account's own preference decides; silence means no |
| `optout` | the same, except silence means **yes** — on unless the account turned it off |
| `always` | every account is notified, whatever it chose |
| `off` | nobody is, whatever they chose |

`always` exists because for a service where the account *is* the product, telling somebody
their credentials were used from a new device is closer to an obligation than a setting.
`off` exists for the incident where the alert stops being a security feature and becomes
the outage's own mailing list. `optin` is the framework's default so that an upgrade starts
nobody's mail.

A **new project starts at `optout`**: `pramnos init` writes it into `app.php`, where it acts as
the default the setting overrides.

```php
'auth' => ['newsignin_policy' => 'optout'],
```

`optin` stays the *framework's* default because existing installations rely on it — an upgrade
that begins mailing a user base would be a surprise delivered by a patch release. A newly
scaffolded project has no such user base.

**`optout` is the one to reach for on a real user base.** Under `optin`, the people who most
need this mail are the ones who will never find the checkbox, so the feature ends up
protecting the users who were already careful. `optout` keeps the choice with the account and
changes only where it starts from. It works because the preference stores `'0'` rather than
deleting its row: "chose no" and "never chose" are different states, and this policy is the
difference between them.

Nothing about it needs a consent record. The mail goes to the address the account signed up
with, about that account's own sign-in, and it can be turned off in one click — a security
notification, not marketing. If your installation sends it under `optout`, the unsubscribe
link and `List-Unsubscribe` headers described in the [Email Guide](Pramnos_Email_Guide.md) are
what keep it that way in a mailbox provider's eyes.

The per-account state is on the user's admin screen, with a toggle when the policy leaves
the decision to the user — and a sentence instead of a switch when it does not, because a
control that decides nothing is worse than none.

### …and making it do something about it

Notifying is the weakest useful response: by the time the mail arrives, whoever had the
password is already inside. `auth_newsignin_action`, beside the policy on the same screen,
is what such a sign-in must **satisfy** before it continues:

| Value | What a sign-in from an unrecognised device must do |
| --- | --- |
| `notify` (default) | nothing — the alert is sent and the login proceeds |
| `authlink` | wait for a single-use link mailed to the account |
| `require_2fa` | pass a second factor, even if the account would not normally be asked |
| `require_passkey` | pass a passkey — the one factor that cannot be phished or read out of a mailbox |

**None of them can lock a user base out, and that is the design constraint rather than a
side note.** A demand the account cannot meet — "use a passkey" to somebody who has none —
would turn this setting into an outage with a checkbox, so each strict reading falls back
to the strongest factor the account actually has, and to a mailed code last, because a
mailbox is the one thing every account has. `require_2fa` therefore imposes a mailed code
on an account with no factor at all, regardless of that account's own email-factor
switch: the demand is the site's, not the account's, and an account with nothing set up is
exactly the one a stolen password threatens most.

**A mailbox is only a factor when the installation can send mail.** With `smtp_host` empty,
`authlink` resolves as `require_2fa` does, and the mailed-code fallback becomes the passkey
when the account has one and nothing when it has neither. That is weaker than a code, and it
is the only answer that is not a wall in front of every account on the installation.

A device the account has used before is never questioned by any of them, so this costs a
step on unrecognised browsers and nothing on the rest.

**When the action applies** is a separate setting, `auth_newsignin_trigger`
(`NewSignInAlert::TRIGGER_SETTING`):

| Value | The action applies to |
| --- | --- |
| `new_device` (default) | any browser this account has not been used from |
| `suspicious` | only a sign-in with a harder-to-explain signal (`SignInRisk`): a country the account has never used, two places at once, a different country too soon to have travelled, or a success straight after a run of failed attempts |

"A device this account has not used" fires constantly on a real user base — new phones,
cleared cookies, a borrowed laptop — so a demand attached to it is a step everybody pays
regularly. `suspicious` spends the friction where there is something to be suspicious about.
`new_device` is the default because it is the conservative reading: it asks more often, not
less.

**The link is a link in an authentication email, which the alert deliberately refuses to
be.** The difference is that this one is *expected*: the person submitted a password
seconds ago and is looking at a page telling them it is coming. It is useless to somebody
who has the password but not the mailbox — which is the entire point — it expires in
fifteen minutes, and it works once. `LoginFlow::sendAuthLink()` refuses when no login is
pending, so the endpoint cannot be used to mail an arbitrary account a way in.

It completes in a browser that never saw the password leg, because people read mail on a
phone: the token is the authorisation, and it is spent before a session is established.

```php
// What the flow exposes
$flow->sendAuthLink($returnUrl);   // false with nothing pending
$flow->completeAuthLink($token);   // tagged `authlink`
```

### The four rules, and where they live

All four are in `Auth\NewDeviceAuthLink`, not in the callers — what makes the method safe is
not the token but these:

| Rule | Constant | Why it is the whole security when the others are absent |
| --- | --- | --- |
| Single use | — | The mail stays in the inbox afterwards, and a provider's link-preview fetch counts as an open. The hash is cleared **before** an id is returned. |
| Fifteen minutes | `TTL` | A link is a credential with no owner; the window is what bounds the loss. |
| One at a time | — | Issuing again replaces the stored hash, so "send it again" cannot leave two live ways in — the older being the one nobody is watching. |
| A rate limit | `RESEND_INTERVAL`, `MAX_SENDS`, `SEND_WINDOW` | The button sits behind a correct password, so anybody who phished one can hold it down. |

Two decisions worth knowing before you rely on them:

- **With no `authserver.user_activity_log` table, a send is allowed.** The accounting reads the
  same rows that audit the sends, and refusing every link when the table is missing would refuse
  the login itself — a half-migrated installation must not lock everybody out.
- **A failed delivery is not recorded as a send.** A dead mail server would otherwise tell the
  person to watch an inbox nothing is coming to, and spend their rate limit doing it. The stored
  token is deliberately left behind: it is unreachable, and clearing it would revoke a link an
  earlier successful send may still have out there.

`notifier()` is a protected seam, so a test can run `send()` without a mail server. The same
idiom as `Controllers\Me::resolveUser()` — override it, assert what was handed over.

## New sign-in alerts

When enabled for an account, a sign-in from a browser/platform combination that account has
not used before triggers one email. Whether it is enabled follows the site policy,
`auth_newsignin_policy` (see
[Telling an account it was used from somewhere new](#telling-an-account-it-was-used-from-somewhere-new)):
under `optin` (the framework default) it is off until the account turns it on; under `optout`
(what `pramnos init` writes for a new project) it is on until the account turns it off;
`always` and `off` override the account's choice.

```php
use Pramnos\Auth\NewSignInAlert;

NewSignInAlert::setEnabledFor($userId, true);   // the account's own preference
NewSignInAlert::isEnabledFor($userId);          // the preference read through the site policy
```

The built-in Account controller exposes it as a checkbox on the privacy page, beside
the analytics and marketing consents, in all three scaffolded themes.

### What counts as "new", and what deliberately does not

`Pramnos\Auth\SignInFingerprint` reduces a `User-Agent` to a **browser family and a
platform family** — `chrome|windows`, `safari|ios` — and nothing else.

| Signal | Used? | Why |
| --- | --- | --- |
| Browser family | **yes** | changes when a person actually uses a different browser |
| Platform family | **yes** | changes when they use a different kind of device |
| Browser **version** | no | Chrome and Firefox ship a major version about every four weeks. Including it is a monthly alarm for every user |
| Operating system point release | no | changes without the person doing anything |
| **IP address** | **no** | dynamic on most consumer connections. An alarm on a changed address fires on a router reboot, and by the second week nobody reads it |

**The cost, stated plainly:** two Chrome-on-Windows machines are indistinguishable, so
signing in from a colleague's identical laptop raises nothing. That is the price of an
alarm that stays rare, and rarity is the whole value — a security notification people
learn to ignore is worse than none. If you need finer granularity, add a signed device
cookie; that is the tool built for the job, and narrowing this fingerprint is not.

### The current sign-in is excluded once, not everywhere

The history is read **after** the login lifecycle has logged this sign-in, so the current
fingerprint is already in it and would always match itself. It is therefore excluded — but only
**one** row of it.

Excluding every row carrying it looks equivalent and is not. An account that uses a laptop and a
phone has both in its history: signing in from the laptop removed *all* the laptop's rows and left
the phone's, which is a non-empty history missing the current device — the definition of "new". So
both devices alerted on every single sign-in, forever, which is exactly the alert-nobody-reads
failure this feature exists to avoid.

One row is what the current sign-in contributes, so one is what is dropped. If it has not been
logged yet, the row dropped is an earlier sign-in from the same device and any others still mark it
familiar; only a device being used for the second time ever can still look new, which is the
conservative direction.

### Where the history comes from

`authserver.user_activity_log`, which has recorded a user agent against every `login`
since the auth feature shipped.

That is load-bearing rather than incidental. A device detector with **no** history says
everything is new, so on the day it is switched on, every user who opted in is notified
at once — about a sign-in they are performing right now. Reading an audit trail that
already holds months of user agents means the first sign-in after upgrading is
recognised as familiar, which it is.

For the same reason, an account with no history at all is treated as **not** new.

Only successful `login` rows count. `login_failed` carries a user agent too, and letting
a failed attempt make a browser familiar would turn the log into a way of switching the
alarm off.

### Storage

The preference is a `userdetails` row (`fieldname = 'notify_new_signin'`), not a column
on `user_privacy_settings` — so **no migration is needed** and the feature works on
every installation the moment the framework is upgraded, including those whose
`migration_cutoff` skips baseline migrations. It inherits that table's cascade on user
deletion, which a GDPR-relevant preference needs anyway.

### The email

Names the browser and the kind of device, and the time. It does **not** print the IP
address: nobody recognises their own, and printing one invites exactly the
compare-with-last-time habit this feature refuses to encourage. The address is in the
audit log, for an administrator investigating an incident — the right audience for it.

It offers one action — *if this was not you, change your password* — and no link. A link
in an unexpected security email is the shape of the attack it warns about.

Mail, and a push where the account has a subscribed browser. **Not** a database
notification: that would put the warning in the panel of the session that triggered it,
which in the case worth warning about is the wrong person. Push passes the same test — a
browser receives one only because somebody granted permission in it earlier, so the
subscriptions on an account are the owner's devices rather than the one the sign-in just
happened on. See the [Web Push Guide](Pramnos_Push_Guide.md) for the channel itself.

It is also the one notification the framework sends with an unsubscribe list
(`unsubscribeList()` returns `newsignin`), because it is the one that belongs to a list:
the account can already turn these alerts off on its privacy screen, so honouring an
unsubscribe flips that same checkbox. Everything else — a password reset, a security
change — is transactional, and offering to unsubscribe from a password reset is offering
to disable the only way back into the account.

## Login Lockout (Brute-Force Protection)

`Pramnos\Auth\Loginlockout` — progressive brute-force lockout for login endpoints.

Tracks failed login attempts per scope+identifier pair. Three scopes are supported: `'user'` (by user ID), `'identifier'` (by normalized email/username), and `'ip'` (by remote address).

### Default thresholds

| Failures | Lockout duration |
|---|---|
| 3 | 60 s (1 minute) |
| 5 | 300 s (5 minutes) |
| 7 | 900 s (15 minutes) |
| 10+ | 3600 s (1 hour) |

A **sliding window** of 900 seconds applies: if the gap between the previous failure and the current attempt exceeds the window, the counter resets to 1. This prevents indefinite accumulation from past brute-force campaigns.

The OAuth password grant shares the `'identifier'` counter with the login form: a wrong
password at `/oauth/token` counts toward it, a success clears it, and a locked account is
refused before its password is checked — `400 invalid_grant` with a `Retry-After` header in
seconds. Otherwise the token endpoint would be a way round the form's lockout.

### Configuring the ladder

Two application settings, both editable from the settings screen:

| Setting | Meaning |
|---|---|
| `loginlockoutsteps` | JSON map, `{"attempts": seconds}` — e.g. `{"3":60,"5":300}` |
| `loginlockoutwindowseconds` | the sliding window, 60–86400 |

Both are read on every attempt, so a change on the settings screen applies to the next
failed sign-in.

An unusable `loginlockoutsteps` (not JSON, empty, or no usable pair) falls back to
`DEFAULT_STEPS`, and a window outside 60–86400 falls back to 900 — never to no
lockout. A malformed setting must not be a way to switch brute-force protection
off, and a window of zero would reset the counter on every attempt.

### Lifting a lockout while developing

The lockout is doing its job when it locks somebody out — and that is no help
when you have mistyped a fixture password three times and cannot test the login
flow you are working on:

```bash
php pramnos auth:unlock admin                # this identifier, every scope
php pramnos auth:unlock 2 --scope=user       # by user id
php pramnos auth:unlock --list               # who is locked, and for how long
php pramnos auth:unlock --all                # everything (development only)
```

It clears the failure counter and nothing else — a wrong password is still a
wrong password afterwards. `--all` refuses to run outside development: "clear
every lockout on this server" is precisely what somebody working through a
password list would want.

Four behaviours worth knowing before you rely on them, each one asserted:

- **A scope narrows it.** The same value can be locked twice — `10.0.0.5` as an
  identifier and as an IP — and `--scope=ip` clears only that one. Without a scope,
  every scope for that identifier goes.
- **An unknown scope is refused, with the valid ones named.** A typo that silently
  unlocked nothing would read as *that account was not locked*, and the operator
  would move on believing the lockout had gone.
- **`--list` clears nothing**, and reports the remaining time in a form somebody
  reads (`1h 1m`). "Locked" without a duration does not tell an operator whether to
  wait or to act, which is the only decision they have.
- **An expired lockout is not listed.** The row stays — it is the failure history
  the progressive backoff counts — but listing it would send somebody to unlock an
  account that is already usable.

And unlocking something that is not locked **succeeds**: it is "is this account
locked?" asked in the imperative, and the state afterwards is the state the
operator wanted either way.

### Usage

```php
use Pramnos\Auth\Loginlockout;

$lockout = new Loginlockout();

// Check BEFORE processing the login attempt
$status = $lockout->getLockoutStatus('identifier', strtolower($email));
// Returns: ['locked' => bool, 'remaining' => int]

if ($status['locked']) {
    return ['status' => 429, 'retry_after' => $status['remaining']];
}

// Attempt authentication ...

if ($authFailed) {
    // Record failure for all applicable scopes
    $lockout->recordFailedAttempt('identifier', strtolower($email));
    $lockout->recordFailedAttempt('user', (string) $userId);
    $lockout->recordFailedAttempt('ip', $ipAddress);
} else {
    // Clear state on success
    $lockout->clearSuccessfulLoginState('identifier', strtolower($email));
    $lockout->clearSuccessfulLoginState('user', (string) $userId);
    $lockout->clearSuccessfulLoginState('ip', $ipAddress);
}
```

### API

| Method | Description |
|---|---|
| `recordFailedAttempt(string $scope, string $identifier): void` | Increment failure counter; apply threshold; creates row if absent |
| `getLockoutStatus(string $scope, string $identifier): array` | Returns `['locked' => bool, 'remaining' => int]`; 0 remaining when not locked |
| `clearSuccessfulLoginState(string $scope, string $identifier): void` | Deletes the tracking row — fully resets counter and lockout |

### Constants

| Constant | Value |
|---|---|
| `Loginlockout::DEFAULT_WINDOW_SECONDS` | `900` |
| `Loginlockout::DEFAULT_STEPS` | `[3=>60, 5=>300, 7=>900, 10=>3600]` |

## Two-Factor Authentication (TOTP)

Two classes: `TOTPHelper` (pure static, no DB) and `TwoFactorAuthService` (stateful, DB-backed).

Compatible with Google Authenticator, Authy, and any RFC 6238 TOTP app.

### TOTPHelper — setup and verification

```php
use Pramnos\Auth\TOTPHelper;

// Generate a new shared secret (once per user, during setup)
$secret = TOTPHelper::generateSecret(); // e.g., 'JBSWY3DPEHPK3PXP'

// Verify a user-submitted code (±1 window drift tolerance)
$valid = TOTPHelper::verifyCode($secret, $userCode); // bool

// QR code as an inline data URI — no external API calls, CSP-safe
$dataUri = TOTPHelper::getQRCodeDataUri($secret, 'user@example.com', 'MyApp');
// Returns 'data:image/svg+xml;base64,…' (requires chillerlan/php-qrcode ^5.0) or null

// Build the provisioning URI
$uri = TOTPHelper::buildOtpAuthUri($secret, 'user@example.com', 'MyApp');
// 'otpauth://totp/MyApp:user%40example.com?secret=…&issuer=MyApp'

// Backup codes
$codes   = TOTPHelper::generateBackupCodes(10);        // ['ABCD2345', ...]
$hash    = TOTPHelper::hashBackupCode($codes[0]);       // bcrypt hash for storage
$isMatch = TOTPHelper::verifyBackupCode($code, $hash);  // bool, case-insensitive

// Utilities
$remaining = TOTPHelper::getRemainingTime();    // seconds until window expires [1, 30]
$isValid   = TOTPHelper::isValidSecret($secret); // validate base32 format
```

> **Security note:** `getQRCodeUrl()` sends the TOTP secret to an external API and is deprecated. Always use `getQRCodeDataUri()` instead.

### TwoFactorAuthService — full setup flow

```php
use Pramnos\Auth\TwoFactorAuthService;

$svc = new TwoFactorAuthService(); // uses Factory::getDatabase()

// Step 1 — generate secret and show QR code to user
$info = $svc->startSetup($userId, $userEmail);
// ['secret', 'qr_code_data_uri', 'qr_code_url', 'manual_entry_key']
// No backup codes here — see below

// Step 2 — user scans QR, enters first code to confirm
$success = $svc->completeSetup($userId, $submittedCode); // bool

// Step 3 — NOW show the backup codes, once
$codes = $svc->takeNewBackupCodes();       // string[]; [] when there are none

// Verify on login
$valid = $svc->verifyCode($userId, $code); // accepts TOTP or backup code

// State queries
$enabled   = $svc->isEnabled($userId);   // bool
$remaining = $svc->getRemainingBackupCodes($userId); // int
$status    = $svc->getStatus($userId);
// ['enabled' => bool, 'setup' => bool, 'backup_codes_remaining' => int]

// Management — the password is required; the unchecked path has its own name
$svc->disable($userId, $password);                            // refused when wrong or empty
$newCodes = $svc->regenerateBackupCodes($userId, $password);  // string[]|false
$svc->disableForOperator($userId);                            // administrative
$svc->regenerateBackupCodesForOperator($userId);              // administrative, destructive
$svc->cleanupExpiredSessions();                               // removes used/expired setup rows — `used` integer or boolean, `expires_at` Unix time or timestamp
```

**The issuer is the name the authenticator app files the account under.** `startSetup()`
takes it as a third argument and defaults to `Pramnos`, which names the framework rather than
the site. The `TwoFactorAuth` controller passes `auth_brand_name`, then `sitename`, then the
application's `name` from `app.php` — the same order the sign-in card brands itself with, so the
name on the card and the name in the app agree. Override `issuer()` to choose another. It is only
a label: changing it later renames nothing in an app that has already scanned the code.

**Backup codes come from enrolment, not from setup.** `startSetup()` deliberately
returns none: `completeSetup()` generates and stores the account's codes, so they can only be
shown after enrolment — codes shown any earlier would not be the ones stored.
`takeNewBackupCodes()` returns what enrolment stored, once (it survives one redirect, in the
session, and is cleared on read).

It is also the right moment to show them: somebody who abandons setup halfway should not
walk away holding recovery codes for an account with no second factor.

**The password is required, and the unchecked path has a name.** `disable()` and
`regenerateBackupCodes()` both take the account password and verify it. Without that check,
any signed-in session could strip the second factor, or rotate the backup codes (which
invalidates every code the owner wrote down and prints ten new ones to whoever asked).

| Call | Meaning |
| --- | --- |
| `disable($userId, $password)` | the user's own action — refused on a wrong **or empty** password |
| `disableForOperator($userId)` | administrative — an operator clearing 2FA off an account whose owner cannot reach it |
| `regenerateBackupCodes($userId, $password)` | the user's own action |
| `regenerateBackupCodesForOperator($userId)` | administrative, and destructive: it invalidates every code the owner holds |

The password is **not optional**, deliberately. PHP discards an extra argument to a userland
function, and with an optional parameter a caller that omits it would silently skip the
check. A step-up check in front of *removing* the second factor is not
something to skip by accident, so skipping it has to be spelled. An empty string counts as
wrong rather than absent, so a form that submitted nothing does not pass.

Whoever exposes the `…ForOperator` methods decides who may call them; they are not the
user's own action, and the codes the second one returns have to reach the account's owner
rather than the operator.

**Replay protection:** `verifyCode()` compares the current 30-second window against `last_used`. If the same window was already used, the code is rejected even if cryptographically valid.

**Backup codes are one-time:** the matching hash is removed from storage after successful verification.

### Database tables

| Table | Description |
|---|---|
| `authserver.user_twofactor` | One row per user — enabled flags (TOTP and email), secret, backup code hashes, last_used |
| `authserver.twofactor_setup` | Temporary setup sessions (15-min TTL) |
| `authserver.twofactor_attempts` | Append-only attempt log (TimescaleDB hypertable where available) |
| `authserver.twofactor_email_codes` | Live mailed codes for the email factor — one row per user per purpose, hashed, with an expiry and an attempt cap |

## Password Security

There is nothing to override. `User::setPassword()` hashes with the preferred scheme and
`User::verifyPassword()` reads every scheme `PasswordHash` knows (see
[How a stored password is hashed, and read](Pramnos_Authentication_Guide.md#how-a-stored-password-is-hashed-and-read)).
Replacing either with a plain `password_hash()` / `password_verify()` pair would stop the
account's existing peppered hashes from verifying, and `verifyPassword(string $password): bool`
cannot be overridden without its return type in any case.

The password **policy** — 8 characters, a digit, a symbol, a matching confirmation — is
`Account::validatePasswordPolicy()`, used by registration, the password change and the reset
alike. Override it on your `Account` subclass to change the rules in all three places.
