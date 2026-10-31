---
use_cases:
  - Deciding what each user type may do, and changing it
  - Seeing and editing a user from the administration screen, with an audit trail
  - Letting people register themselves: open, by domain or by invitation
  - Exporting a person's data, section by section
---

# Users: types, the administration screen, registration

Part of the [authentication guide](Pramnos_Authentication_Guide.md).

## What a usertype is, what each one may do, and how to change them

`users.usertype` is an **integer read as a threshold**, not an enum. Every framework
administration screen opens at **98** — `AdminAccess::defaultUsertype()`, the `super` role,
moved with `roles` or the `admin_default_usertype` setting — and the administration area's own floor is whatever
`admin.min_usertype` says. A comparison, not an equality, is what the framework's own guards
are written in.

Below 98, an account is given the screens it needs **through a role**: set
`admin_access` to `mixed` and allow the role `admin.users`, `admin.logs`… on its page. That is
the intended way to give somebody part of the area — see
[Authorization](Pramnos_Authorization_Guide.md#granting-screens) — rather than a usertype in
between, which grants every screen at once.

The account `init` and `user:create --admin` create is **99, Root**
(`UserCreate::ADMIN_USERTYPE`): on a fresh installation that account is the owner of
the deployment, and 99 is the only tier granted `*` rather than a list that has to be
revisited when a capability is added above it.

### The framework's own types, and what each may do

| Value | Type | Tone | Gains, on top of everything below it |
| --- | --- | --- | --- |
| `99` | Root | danger | `*` — every capability, including ones added later |
| `98` | Super Administrator | danger | `admin.area` and every administration screen, `admin.users`, `admin.settings`, `admin.permissions`… |
| `90` | Administrator | warning | no administration screen unless a role grants it |
| `1` | System User (Client Credentials Grant) | neutral | `api.client_credentials` — **and nothing else**, see below |
| `0` | Simple User | primary | `account.self` |

**`/admin/Users/types` renders this table from the registry**, so it is always the running
answer rather than a copy of it, and an application that declared its own sees its own. The
`admin.*` capabilities are **read from the screens themselves** — each menu item's floor, which
is the floor its controller applies — so `UserTypes::can()` cannot say a screen opens when it
does not. `admin.area` is the area's `min_usertype`, `devpanel` the developer panel's.

Three rules make the numbers behave:

- **A value is a threshold.** `95` is an Administrator and has an administrator's
  label; `label(95)` says so. Everything between `2` and `89` is a Simple User,
  because a framework has no basis for inventing roles — those are an application's, and it
  declares them.
- **Capabilities accumulate downwards.** An Administrator has `account.self` too.
- **`1` is matched exactly and inherits nothing.** It is the machine account a Client
  Credentials grant authenticates as, not a very senior person: giving it `account.self`
  would invent a human for a token.

```php
\Pramnos\User\UserTypes::label(95);            // 'Administrator'
\Pramnos\User\UserTypes::can(90, 'admin.settings');   // false — the screen opens at 98
\Pramnos\User\UserTypes::capabilities(98);     // the resolved list
\Pramnos\User\UserTypes::tone(99);             // 'danger' — a meaning, for a theme to colour
\Pramnos\User\UserTypes::options();            // value => label, for Html\Select::addOptions()
```

### Moving the lines: `roles`

The framework's screens and tools never compare a usertype with a number of their own. They ask
`Pramnos\User\Roles` for a role, so an installation moves every line in one place:

```php
// app/app.php — administrators at 97, one root account
'roles' => ['admin' => 97, 'super' => 98, 'root' => 99],
```

| Role | Default | What asks for it |
| --- | --- | --- |
| `manager` | 80 | a dashboard widget's default `allowed()`; one user's tokens, outside the area |
| `admin` | 90 | `phpinfo` on the Health screen, the GDPR requests screen's administrator view, `auth_trusted_devices_exclude_admins` |
| `super` | 98 | every administration screen's floor and the superuser (`AdminAccess::defaultUsertype()`, `superuserUsertype()`) |
| `root` | 99 | the developer tools: the DevPanel in development and production, its link in the debug bar, Adminer, the debug bar's grants |

```php
\Pramnos\User\Roles::level('admin');               // 97 with the config above
\Pramnos\User\Roles::atLeast($user, Roles::ROOT);   // whether this person holds it
```

A role nobody defined throws rather than answering 0, because a typo must not open a screen to
everybody.

**A key a tool already has still wins over its role:** `devpanel.min_usertype`,
`devpanel.adminer_min_usertype`, `devpanel.production_min_usertype`, `debug.grant_min_usertype`,
and the `admin_default_usertype` and `admin_superuser_usertype` settings. A screen's own
`$requiredUserType`, set in an application's subclass, wins too. `0` there means "the
administration floor", never "anybody".

A test, `RolesTest::testNoBundledCodeWritesAUsertypeNumber`, fails when bundled code compares a
usertype with a literal, so a new screen cannot quietly keep a line of its own.

### Declaring your own

```php
// app/app.php
'usertypes' => [
    99 => 'Root',
    98 => 'Super Administrator',
    90 => 'Administrator',
    50 => 'Management User',     // scoped to an organization in this application
    20 => 'Installer',
    1  => 'System User (Client Credentials Grant)',
    0  => 'Simple User',
],
'usertype_tones' => [50 => 'warning'],          // this application treats 50 as privileged
'usertype_capabilities' => [                    // replaces the map — see the note
    99 => ['*'],
    90 => ['admin.area', 'admin.users', 'admin.logs'],
    50 => ['admin.area', 'admin.organizations'],
    0  => ['account.self'],
],
```

Keyed by the type's **floor** and read highest-first; declare them in any order, because
they are sorted before use — a config listing them lowest-first would otherwise label an
administrator "Simple User".

`usertype_capabilities` **replaces** the framework's own list rather than merging with it —
a capability list is what the application's own `can()` checks mean, and quietly adding
defaults underneath would grant things it did not ask for. The administration screens are
added whatever it says: they are not grants but a description of what each screen does.

Every bundled screen — the badge on a user, the label and filter in the list, the select on
the edit form, the reference screen — reads this registry. **No view carries its own copy**, so every screen gives the same answer
to "what is 85?" and the same opinion about which number is alarming.

### Capabilities are not permissions, and neither is the area's floor

- A **capability** answers *may this kind of account reach this kind of screen*.
- A **permission** answers *may this account touch this record* — per user, in
  `authserver.permissions`, editable on the user's own screen. See
  [Authorization](Pramnos_Authorization_Guide.md#which-layer-a-question-belongs-to), which
  puts the three side by side and gives the test for telling them apart.
- The **administration area's `min_usertype`** is a third thing: what stops the area being
  browsable at all, applied before any screen's own check. The scaffolded value is `98`, the
  screens' floor. Under `admin_access = mixed` or `permissions`, somebody below it whom a role
  opens a screen to is let into the area — otherwise the floor would refuse the role before
  the screen could honour it.

## What the administration screen shows about a user

The framework records a user's history in **ten** stores, all shown on the user's
administration screen:

| Store | What it holds | Where it appears |
| --- | --- | --- |
| `authserver.user_activity_log` | sign-ins, logouts, whatever the application records with `ActivityLog::record($userId, $action, $details)`, which writes wherever the table exists, whichever features are on | *Activity* panel, and `users/activity/{id}` for the whole of it |
| `authserver.gdpr_requests` | export and erasure requests | *Data requests* panel |
| `authserver.loginlockouts` | failed attempts and any active lockout | *Login security* panel, with **Clear lockout** |
| `authserver.user_twofactor` | whether a second factor is on | *Second factor* panel, with **Disable** |
| `authserver.passkey_credentials` | registered passkeys | same panel, with **Revoke** per key |
| `authserver.user_privacy_settings` | what the user chose about their data | *Privacy choices* panel |
| `usertokens` | issued tokens | *Recent tokens*, and the Tokens screen |
| `tokenactions` | what was done with them | *Token actions* panel |
| `authserver.user_organizations` | memberships | *Organizations* panel |
| `mails` | the mail this address was sent | *Emails received* panel, each subject linking to the mail |

**The mail panel matches on the address, because `mails` has no `userid`.** So it answers
"was this person actually sent the code" — which was otherwise a question an operator could
only take to a mail log indexed by address, with an address copied off this page by hand,
and therefore usually answered with "it must have been sent". The limit is the other half of
that: mail sent to an address the account used *before* it was changed does not appear, and
nothing pretends otherwise.

**The panels are the tailwind theme's.** `scaffolding/themes/tailwind/views/users/view.html.php`
draws all ten; the bootstrap and plain-css themes ship a simpler user screen with none of them.
The controller collects the records either way, so a project on those themes has the data and
has to render what it wants from it.

**Every read is guarded on its own.** These tables arrive with features — an application
without `authserver` has none of the `authserver.*` ones — so a panel with nothing behind
it renders empty rather than taking the page down, and an empty panel is still rendered:
"no GDPR requests" is an answer, and a section silently omitted is indistinguishable from
one that never existed.

Three operator actions come with them, and each is recorded in the activity log because
each is exactly what an audit needs to show:

```
users/unlocklogin/{id}        clear a login lockout — the answer to most "I cannot sign in"
users/disabletwofactor/{id}   turn off 2FA for somebody who lost their phone
users/revokepasskey/{id}?credential={n}   remove a credential bound to a device that is gone
```

`disabletwofactor` goes through `TwoFactorAuthService::disableForOperator()`, the **named**
unchecked path: the user's own `disable()` requires their password, and an operator cannot
be asked for somebody else's. `revokepasskey` matches on the user *and* the credential, so
a request naming another account's key deletes nothing.

## The audit trail: what operators did

The activity log is a person's own history. `authserver.audit_log` is the operators' record of
actions, for an administrator or an auditor: who did what, to which account or record.

```php
use Pramnos\Auth\AuditLog;

AuditLog::record('role.granted', $adminId, $userId, ['role' => 'editor']);        // about an account
AuditLog::record('admin.page', $adminId, $userId, [], 'page', 'users/edit');       // …through a screen
AuditLog::change('client.updated', $adminId, 'application', $appId, $old, $new);   // a record changed
AuditLog::change('user.updated', $adminId, 'user', $userId, $old, $new, [], $userId); // …of an account
AuditLog::record('report.sent', null);                                            // by the system

$rows = AuditLog::query(['event' => 'role.*', 'actor' => $adminId, 'since' => strtotime('-7 days')], 50);
$rest = AuditLog::query(['not_event' => ['admin.page*']], 50);                    // all but page views
$next = AuditLog::query(['event' => 'role.*', 'before' => end($rows)['auditid']], 50);
```

- **Secrets are redacted** in the context, the old values and the new: a key whose words name
  one (`password`, `client_secret`, `accessToken`, `api_key`, `otp`, `pin`, `cookie`,
  `Authorization`…) keeps its place and loses its value, so the trail says a password changed
  without holding it. Only whole words count, so `spin` and `keyword` are kept. A number, a
  boolean or null under such a key is kept too, so a count of tokens is still a count.
  `AuditLog::redact($array)` is the same rule for code of your own, and
  `ActivityLog::record()` applies it to its details.
- **The reader** takes `event` (exact, or a prefix: `role.*`), `not_event` (a list of the same,
  left out), `actor`, `target` (a userid), `object_type`, `object_id`, `since`/`until` (Unix
  times) and `before` (an `auditid`, for the next page). It answers newest first, at most 500 at
  a time, with the JSON columns decoded.
- **The moment** is written and compared as a Unix time the database converts
  ([`Instant`](Pramnos_QueryBuilder_Guide.md#writing-and-reading-a-moment-instant)), so it is
  right whatever zone the session is in. Each row read carries `at`, the moment as a Unix time;
  `event_timestamp` is as the database returns it, in the session's zone on MySQL and with an
  offset on PostgreSQL. Format `at` for display.
- **It never breaks the action it audits.** With no table, or a database that fails, nothing is
  written, the reader answers `[]` and the reason goes to the `auth` log. It writes wherever the
  table exists, on MySQL and PostgreSQL alike. On TimescaleDB the table is a hypertable,
  compressed after 90 days.
- **Nothing is deleted unless asked.** How long an audit trail is kept is a decision of law or
  policy. `audit:prune` runs daily on the framework schedule and deletes nothing until a
  retention is set: `'audit' => ['retention_days' => 730]` in `app/app.php`, or
  `audit:prune --days=N` by hand. A retention per event is a map of events or prefixes, with
  `*` for every event no other entry names: `['admin.page' => 180, 'role.*' => 3650, '*' => 730]`.
  Without `*`, an event no entry names is kept.

## Editing a user: what the screen can change

The edit form covers **every column the framework's own schema gives a user** — username,
email, names, phone, mobile, language, timezone, usertype, active, validated, password —
plus the two things that are *about* an account rather than *on* it:

**Per-user settings.** `usersettings` is a key/value store per user, and the value is JSON:

```php
$user->setSetting('notifications.email', true, $operatorId);
$user->getSetting('notifications.email', false);   // decoded — true, not "true"
$user->listSettings();                              // what the admin screen lists
$user->deleteSetting('notifications.email');        // the default applies again
```

There were two places to keep something about a user and neither fits an operator-visible
switch: `users` columns are the schema every application shares, and `$otherinfo` is a blob
with no list, no per-key delete and nothing an administrator can read. Deleting is not the
same as setting `null`: no row means the application's default applies, a null value means
somebody deliberately set it to nothing.

Two things to know about how the four behave when the store is unreachable — a project that
has not run the migration, or a database that is down. **The readers degrade and the writers
report failure.** `getSetting()` answers the default and `listSettings()` answers `[]`,
because a project with no settings table has no settings, and a framework upgrade should not
take down every page that consults a preference. `setSetting()` and `deleteSetting()` return
`false` instead, and both log: a caller told a write succeeded will tell somebody the switch
was changed, and «removed» about a row that is still there is the one answer an operator acts
on and is wrong about. So check the return value on a write and ignore it on a read.

Values keep their type through the round trip — a list stays a list and `false` stays `false`,
which matters because a store that flattened to text would hand back `"0"` for a switch
somebody turned off, and `"0"` is truthy. A row whose value is *not* valid JSON comes back as
the raw string, because rows do get written by hand in a database client and refusing to read
one is the store deciding an operator's edit did not happen.

**Per-user permissions.** The grants written directly to this account, with revoke, and a
form to add one (`object_type`, `object_id`, `action`, allow/deny). Only the *direct*
grants are listed: the resolver also answers from usertype and group membership, and a
screen mixing them would offer a revoke button for a permission that has no row.

Everything is three separate forms on one screen, because they are three separate
decisions — saving a name should not resubmit a permission, and a rejected permission
should not lose a typed name.

## Fields the users table does not have

`User` keeps anything outside its own columns in `$otherinfo`, reached with plain property
syntax:

```php
$user->notifyByEmail = '1';
$user->notifyByEmail;                  // '1'
isset($user->notifyByEmail);           // true
$user->neverSet ?? 'default';          // 'default'
```

All four magic methods read that one store. That is worth stating because the third and
fourth lines are not obvious: **`??` asks `__isset()` first** and only calls `__get()` when a
class declares no `__isset()`. A class whose `__isset()` answered from a different store than
its `__get()` would return the fallback for a value that is present — with no error, no
warning, and the value correctly in the database the whole time.

`load(null)` returns `false` rather than looking anything up: `new User($record->userid)` on
a record that did not load is a normal path, and `0` already means "load whoever is in the
session", which is a different question.

## Self-service registration

`/register` is a real route with the `auth` feature enabled, and it is **closed
until you open it**:

```php
// app settings
auth_allow_registration = 1
```

Off is the default because a scaffolded application should not gain a public
sign-up page by being upgraded, and most applications on this framework create
their accounts by some other route entirely. With it off the page still renders —
it says registration is closed rather than 404-ing a page the bundled views link
to.

What the endpoint enforces, in this order:

1. Already signed in → redirected away.
2. Registration closed → refused before the request body is read at all, so a
   crafted POST to a closed server cannot write a row. A pending invitation opens it for
   that one address.
3. CSRF token → checked before validation, so a form with no token is not even an
   account-existence oracle.
4. The human check, when `auth.security.human_check` lists `register`. This is a
   public write that creates a row and sends a mail, which makes it the form on the site
   most worth pricing.
5. Username 3–60 characters, `[A-Za-z0-9._-]` only — the set that needs no
   escaping in a URL, a log line or an email subject.
6. A valid email address — with an invitation, the invited address
   (`invite_email_mismatch`); without one, an address at a domain in
   `auth_registration_domains` when that list is set (`email_domain_not_allowed`).
7. The same password policy `resetpassword` uses: 8 characters, a digit, a
   symbol, and a matching confirmation.
8. A unique username (`username_taken`) and a unique address (`email_unavailable`),
   and only then a write.
9. After the write: an invitation is accepted, or — with
   `auth_registration_verify_email` on — a confirmation link is mailed and the account
   cannot sign in until it is opened (`/register/confirmemail`). See
   [Registration: open, by domain, by invitation](#registration-open-by-domain-by-invitation).

A refused submission comes back with the username and the address filled in — retyping an
address after a failed check is how people give up — and **never with the password**: the form
is rendered into HTML that ends up in a browser's history, a proxy log and the occasional bug
report screenshot.

The new account is active at the lowest privilege level. Nothing in the flow can
grant a usertype: `createUser()` names the five fields it sets, so a submission carrying
`usertype` or `userid` is ignored rather than obeyed.

**What it tells an attacker.** "That username is taken" confirms an account
exists, and there is no way to both refuse a duplicate and not confirm it — a form
that has to let somebody pick another name has to say why. So it reveals that,
and the mitigations are the ones that work: leave registration off when you do not
need it, and keep the login lockout in place, because the value of an enumerated
username is what you do with it next. The email case is worded so that it does not
add a second, independent confirmation.

Invitations and a domain allow-list are built in (see
[Registration: open, by domain, by invitation](#registration-open-by-domain-by-invitation)).
To open registration on some other condition — a shared invite code, an organization
policy — override one method, and keep the parent's answer so invitation links still work:

```php
class Register extends \Pramnos\Auth\Controllers\Account
{
    protected string $routeBase = 'register';

    public function display()
    {
        return $this->register();
    }

    protected function registrationIsOpen(): bool
    {
        return parent::registrationIsOpen()
            || $this->post('invite') === $this->setting('signup_invite_code');
    }
}
```

`createUser()`, `usernameExists()` and `validateRegistration()` are seams on the
same controller if you need to change how an account is stored or what a username
may look like.

## The single sign-on status page

`/sso` answers "does this server already know me, and what have I authorized?".
Public, because for a signed-out visitor that negative answer is the useful half —
it is what another application sends somebody here to find out.

## Registration: open, by domain, by invitation

Self-service registration at `/register` is **off** unless something opens it. Three things can:

Both settings below are fields in **System Settings → Security → Registration**, unless `app.php`
keeps them: `'auth' => ['registration_admin_editable' => false]` shows them read-only
(`RegistrationPolicy::editableInAdmin()`). A value in `app/config/settings.php` still wins over
the screen, as for every setting.

| Setting | Effect |
| --- | --- |
| `auth_allow_registration` | open to anybody |
| `auth_registration_domains` | with the above, only addresses at these domains — `example.com, example.org`; whole domains, so `evil-example.com` is not `example.com` |
| an **invitation** | open for one address, while everything else stays closed |

**Confirming the address.** `auth_registration_verify_email` holds a self-registered account back
until it follows a link mailed to it (`register/confirmemail`, valid `auth_email_verify_ttl_hours`,
48). It is **always on when a domain list is set** — otherwise anybody could type an address at the
domain and hold an account relying parties believe belongs to it. Until then sign-in answers
`email_unverified` rather than signing in, and signing in again after ten minutes mails a new link.
Only accounts that were held back this way are stopped; an older account with `validated = 0` is
not. `validated` is what the ID token's `email_verified` is read from, so it is true only when
somebody checked.

### Invitations

```php
use Pramnos\Auth\Invitations;

$made = (new Invitations())->invite('maria@example.com', [
    'invitedBy'      => $admin->userid,
    'organizationId' => 7,        // joins on acceptance
    'roleId'         => 3,        // and is given this role
    'note'           => 'Finance team',
    'metadata'       => ['plan' => 'gold'],
]);
$made['link'];   // https://…/register?invite=… — shown once; only Token::lookup() of it is stored
```

- The link opens registration for **that address only** (`invite_email_mismatch` otherwise), and
  following it confirms the address, so an invited account is not asked to.
- It works **once** — the row is claimed by an update conditioned on it being unused, so two
  registrations racing on one link cannot both succeed — and for `auth_invitation_ttl_hours`
  (**48**).
- One live invitation per address: a new one withdraws the old. None for an address that has an
  account. A role belonging to an organisation goes only with an invitation into it.
- `resend()` issues a new token (the old one cannot be recovered from its hash) and `revoke()`
  withdraws; `all()` lists with a `state` — waiting, accepted, revoked, expired.
- Accepting fires **`invitation.accepted`** with the row and the new account's id — the place for
  whatever an application attached as `metadata`:

```php
\Pramnos\Event\Event::listen(Invitations::EVENT_ACCEPTED, function (array $invitation, int $userId): void {
    $extra = json_decode((string) $invitation['metadata'], true) ?? [];
    // …
});
```

The **Invitations** administration screen (`admin.invitations`; usertype 98 under
`admin_access = usertype`) invites, shows the link once, resends and withdraws. Only somebody who
may open Roles can attach a role. The mail's text is the `auth.invitation` template; the
confirmation mail's is `auth.verify_email`.

#### Attaching your own data from the screen

The screen passes `invitedBy`, `organizationId`, `roleId` and `note`. To attach `metadata` (or
any other option `invite()` takes), override `inviteOptions()` in your wrapper controller and add
the form fields by overriding the `invitations` view in your theme:

```php
class Invitations extends \Pramnos\Auth\Controllers\InvitationsController
{
    protected function inviteOptions(): array
    {
        return ['metadata' => ['capabilities' => array_keys((array) ($_POST['off'] ?? []))]];
    }
}
```

What it returns is merged **under** the screen's own options, so it cannot change who invited,
the organisation, the role or the note — those the screen decided and checked.

#### The mail

The `auth.invitation` template gets `{link}`, `{hours}`, `{days}`, `{inviter}`, `{note}` and
`{sitename}`. `{days}` is the whole number of days when the lifetime is one (a 21-day link is
`21`, where `{hours}` says `504`), otherwise the hours as a fraction of a day; the built-in text
says days whenever it can.

A stored template has no preheader, so without one the mail client shows the body's first
sentence. To choose it, override `notification()` in a subclass of `Invitations` — it builds the
mail from the link and the stored row, `metadata` included — and return it through your
controller's `service()`:

```php
class Invitations extends \Pramnos\Auth\Invitations
{
    protected function notification(string $link, array $row): InvitationNotification
    {
        return parent::notification($link, $row)
            ->withPreheader('Publishing and analytics for your channels. Your link is inside.');
    }
}
```

#### When an account is erased

`Account::eraseUserData()` calls `Invitations::forgetUser()`. The invitations the account sent
that nobody accepted hold other people's addresses and are deleted; the ones that were accepted
stay, with `invited_by` cleared, because they belong to the account they created; the invitation
the erased account itself came from is deleted.

## The data export, section by section

`buildExportData()` reads a table per section, several belonging to features an installation may
not have enabled. Each section is resolved on its own:

```php
foreach ([...'privacy_settings' => fn () => $this->getPrivacySettings($userId)...] as $section => $read) {
    try {
        $export[$section] = $read();
    } catch (\Throwable $unreadable) {
        $export[$section] = [];
        Logger::log('Data export: the ' . $section . ' section could not be read …', 'auth');
    }
}
```

Each section is read on its own so that one unreadable table cannot abort the **whole export**.
Somebody exercising a data-subject request would otherwise get an error, and every readable
section would go with it. For a document an installation is legally obliged to produce, that is
the wrong failure: partial is worth more than nothing.

Two details of the recovery are deliberate:

- **The section stays, empty.** A missing key reads as *this framework has no such concept*; an
  empty one reads as *you have none of it*. Only the second is something the installation is
  entitled to say.
- **The failure is logged.** In the file, "you have none" and "we could not tell" are
  indistinguishable — so they have to be distinguishable to the operator, who is the only person
  who can do anything about it.

A listener contributes its own sections through `account.data_export`, and cannot overwrite a
core one: `array_key_exists` before writing, so an export's `profile` always comes from the
framework.

### Erasure: `account.data_erase`

The other half of Article 17, and it fires **before** the framework deletes anything:

```php
\Pramnos\Event\Event::listen('account.data_erase', function (int $userId) {
    // This application's rows for $userId.
    return true;      // false stops the erase, with nothing deleted
});
```

**Before, not after, and that is not a preference.** An application's rows almost always
carry a foreign key to `users`; if the framework deleted the user first, its own
`DELETE FROM users` would fail on the children and what is left is an account half erased
— which is worse than one that was never started.

A listener returning `false` stops the erase: `Event::fire()` short-circuits at the first
one, so the listeners after it do not run either, and `eraseUserData()` raises.
`deleteaccount()` already turns that into an error on the page. Use it for a retention
obligation the application cannot ignore, not for "this is inconvenient".

Two things the framework deliberately does not decide:

- **Whose data it is.** In a multi-tenant application the rows belong to the
  *organisation*, not to the person, and deleting the last member of a tenant is a
  different act from deleting a colleague's login. There is no default that is right for
  both, so the listener owns it.
- **What cascades.** Nothing cascades into a table with no foreign key, which is most
  hypertables — a metrics table keyed by a tenant id has no constraint to follow. Delete
  those explicitly; they are usually the largest thing left behind and the easiest to
  forget.

#### What the framework deletes

The erase is `Pramnos\Auth\AccountErasure`, which `Account::eraseUserData()` calls and which
any code that removes an account can call too:

```php
(new \Pramnos\Auth\AccountErasure($database))->erase($userId);
```

After the listeners, it deletes the account's rows from each of these tables, explicitly,
because none of them has a cascading foreign key to `users` on every installation:

| What | Tables |
|---|---|
| Sign-in | `usertokens`, `authserver.user_twofactor`, `authserver.twofactor_setup`, `authserver.passkey_credentials`, `authserver.trusted_devices` |
| Consent and privacy | `authserver.oauth2_user_consents`, `authserver.user_privacy_settings`, `authserver.user_activity_log` |
| What the person held | role assignments (`Role::assignmentTable()`), organisation memberships (`Role::membershipTable()`), `userstogroups` |
| Notifications | `notifications` addressed to a user class, `pramnos.pushsubscriptions`, `pramnos.pushlog`, `pramnos.pushtests` |
| The assistant | `chat_conversations` with every message in them, `chat_artifacts`, `chat_shares`, `chat_memory`, `chat_asks`, `ai_consents`; the assistant's profile of the person (`chat_dossier`) with every earlier version of it in `pramnos.changelog_events` (entity `chat_dossier`), and its calendar (`chat_calendar`); its scheduled tasks (`pramnos.ai_routines`) with their runs and readers, and its own report subscriptions. `llm_calls` keeps each call's cost and loses its `userid`, prompt, messages and reply |
| Elsewhere | its settings (`usersettings`, the dashboard's layout among them), invitations it sent or came from, its mailing-list rows, and finally `users` |

**Scheduled tasks meant to outlive the account are handed over first.** The delete-account page
(`Account::deleteaccount()`) asks, organisation by organisation, who takes over the person's
tasks there. Reports the organisation receives are proposed for the first member listed. The
person's own tasks go only if they tick it, and with nobody to take them, they are deleted. The
choice travels with the deletion form (`tasks[org][to]`, `tasks[org][personal]`), and the
hand-over runs before the erase, so what was handed over is no longer theirs to delete. See
[Scheduled tasks](Pramnos_LLM_Scheduled_Tasks_Guide.md#scheduled-tasks-questions-reminders-reports).

A table the installation does not have is skipped and logged. A notification addressed to
something else with the same id, such as an organisation, is left alone. The change log is
not touched: its `userid` is the author of a change to some *other* record.
