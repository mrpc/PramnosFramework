---
use_cases:
  - Signing a user in or out, or checking who is signed in
  - Moving who counts as an administrator, a superuser or root, in one place
  - Creating or managing user accounts
  - Requiring authentication in a controller
  - Working with sessions or authentication addons
  - Identifying a caller who is present and has no account, such as a chat guest
  - Migrating an old password table, or sharing one with another writer
---

# Pramnos Authentication & User Management Guide

The authentication pages:

- **Authentication** (this page)
- [Users: types, the administration screen, registration](Pramnos_Auth_Users_Guide.md)
- [Second factors, lockout and new-device alerts](Pramnos_Auth_Second_Factors_Guide.md)
- [Passkeys](Pramnos_Auth_Passkeys_Guide.md)
- [Tokens: API tokens, JWT and password resets](Pramnos_Auth_Tokens_Guide.md)
- [Permissions, OAuth2 scopes and addons](Pramnos_Auth_Permissions_Guide.md)

## Overview

The Pramnos Framework provides a comprehensive authentication system that supports multiple authentication methods, user management, permissions, JWT tokens, session management, and OAuth2 capabilities. The system is modular and extensible through addons.

> **v1.2 New Features:** 
> - **Database Authentication Driver** — Native auth without addons
> - **Two-Factor Authentication (2FA/TOTP)** — Time-based one-time passwords
> - **Login Lockout** — Brute-force protection with exponential backoff
> - **Session Tracking** — Bot detection and session monitoring
> - **OAuth2 Server** — Full `league/oauth2-server` integration
> - **Security Hardening** — CSRF, session cookie, view escaping
> - **Auto-Login Lifecycle** — Built-in login/logout handling
>
> See related guides: [Pramnos_Security_Guide.md](Pramnos_Security_Guide.md), [Pramnos_Authorization_Guide.md](Pramnos_Authorization_Guide.md)

## Architecture

### Core Components

1. **Auth System** (`Pramnos\Auth\Auth`) - Main authentication controller
2. **User Management** (`Pramnos\User\User`) - User data and operations
3. **JWT Support** (`Pramnos\Auth\JWT`) - JSON Web Token implementation
4. **Permissions** (`Pramnos\Auth\Permissions`) - Access control system
5. **Session Management** (`Pramnos\Http\Session`) - Session handling
6. **Token Management** (`Pramnos\User\Token`) - User tokens and API access

### Authentication Flow

```php
// Basic authentication flow
$auth = \Pramnos\Auth\Auth::getInstance();
$success = $auth->auth($username, $password, $rememberMe);

if ($success) {
    // User is authenticated, session is set
    $user = \Pramnos\User\User::getCurrentUser();
} else {
    // Authentication failed
    $response = $auth->lastResponse;
    echo $response['message'];
}
```

## User Authentication

### Basic Login

```php
// Simple login
$auth = \Pramnos\Auth\Auth::getInstance();
$result = $auth->auth('user@example.com', 'password');

if ($result) {
    echo "Login successful";
    // User session is automatically created
} else {
    echo "Login failed: " . $auth->lastResponse['message'];
}
```

### Where a guest is sent to sign in

A guest who reaches a protected action is sent to the site's `login`, with `?return=` naming the
page they wanted. The guest may come from `addAuthAction()`, from a screen behind
`requireMinUserType()`, or from the Ask, messages, passkey, second-factor or OAuth pages. An
application whose sign-in is somewhere else names it once:

```php
'auth' => ['login_url' => 'account/login'],   // under the site; '/sso/login' or a whole URL as written
```

`SecurityPolicy::loginUrl($return)` builds the address every one of those places uses. If
`login_url` already has a query string, the return is added with `&`. An administration area
with its own sign-in (`AdminArea::useLogin()`) keeps it. The framework's own sign-in follows
`return` only to a page on the same site (`Account::sanitizeReturnUrl()`).

### Remember me

`auth($username, $password = '', $remember = true, …)` — the third parameter **defaults to
`true`**, so the basic login above already sets the persistent login cookie. Pass `false` for a login that
lasts only as long as the browser session:

```php
// Session-only login: no persistent cookie
$auth = \Pramnos\Auth\Auth::getInstance();
$result = $auth->auth('user@example.com', 'password', false);
```

**What the browser carries.** Every sign-in gets its own token, `Pramnos\Auth\RememberTokens`,
in the form `selector.validator`. It goes in `$_SESSION['auth']`, in the `auth` cookie, and in the
login response's `auth` key, whichever path established the session, an application's own user
addon included. It is random and unrelated to the password. The `authserver.remember_tokens` table keeps
only the selector and a hash of the validator, so the table alone signs nobody in.

A token ends in each of these cases:
- it expires (`'auth' => ['remember_days' => 30]`);
- this browser signs out (`logout()`);
- the person signs out everywhere (`$auth->signOutEverywhere($userId)`), which also flags each
  live session to sign out on its next request;
- the password changes, from any path, because each token remembers the password hash it was
  issued under.

Signing in again from the cookies, `$auth->rememberLogin()`, uses the token once and issues a new
one. A copied cookie therefore stops working as soon as the browser it came from returns.
`authCheck()` calls `rememberLogin()` when no auth addon is registered, so an application needs
no addon for remember-me, and `init` scaffolds none. The framework's `UserDatabase` addon, where an
application still registers it, does the same.

**Never print `$_SESSION['auth']` into a page**, not as a JavaScript variable and not in a
header the page reads. It signs its holder in. An API call from the page authenticates with the
session cookie and the page's CSRF token (`X-CSRF-Token`, from `<meta name="csrf">`), or with an
access token.

Code that signs in again by handing `auth` back where the stored hash was expected keeps working
with the token: `auth($username, $auth, true, true)`, as an application's own auth addon's
`onAuthCheck()` does, or a mobile client re-authenticating with the `auth` it was given. The
driver accepts this account's unexpired token there. `auth()` then spends it, because the sign-in
handed back its replacement: a remembered visit leaves no live token behind, and a client reads the
new `auth`. Signing in again in a session that had a sign-in, as an administrator's "log in as"
does, ends the token it replaces.

Until `migrate` has created `authserver.remember_tokens` there is nowhere to keep a token. A sign-in then
keeps the credential it had, and the log says to migrate.

An `auth` cookie from before the tokens, which held the password hash, signs in once the old way
and is replaced with a token, so the upgrade signs nobody out. After the cookies have had time
to turn over (they last 14 days and are refreshed while used), refuse the old ones:

```php
'auth' => ['legacy_remember' => false],
```

### A cookie that says somebody is signed in

A CDN rule, a service worker and the page cache's early path cannot open a session, so they
cannot tell a signed-in visitor from an anonymous one. A login marker tells them:

```php
'session' => ['login_marker' => 'logged_in'],
```

- **What it is.** Its value is `1` and it holds no secret, so it is not HttpOnly: it has to be
  readable.
- **When it is set.** Every sign-in sets it, after the addon or the built-in path, so an
  application's own user addon gets it too. A remembered sign-in keeps it as long as the
  remember-me cookies.
- **When it is cleared.** Signing out clears it, and so does the session tracking when it finds the
  marker with nobody signed in, because the session that set it has ended.
- **Why `logged_in`.** The name begins with `logged`, which the page cache's default
  `bypassCookies` already bypasses on. That pattern is anchored at the start, so a name like
  `pramnos_logged` would not match.

It is off until it is named.

### Signing in as somebody else, and back

Support often needs to see what a customer sees. `Auth::impersonate($userId)` (or
`Impersonation::start()`) signs the administrator in as another account, and
`Auth::endImpersonation()` brings them back without a password:

```php
$why = \Pramnos\Auth\Auth::getInstance()->impersonate($customerId);   // null, or why not
// the framework's own: POST Users/loginas/{id} from the administration

// on every page, in the theme: who this is, and the way back (a POST with the token)
echo \Pramnos\Auth\Impersonation::banner(sURL . 'Impersonation');
```

- **Nothing that signs the administrator in stays in the browser.** Their token ends when the
  other sign-in begins. The way back is a one-time key held only in the impersonated session. Its
  hash is kept in `authserver.impersonations` while the way back is open — who, as whom, until when — and the
  row goes when it is used, signed out of, refused, or left past its expiry when the next begins.
- **Coming back** (`POST /Impersonation`) ends the impersonated sign-in's token and signs the
  administrator in with a fresh one. The way back is then spent.
- **Ending it otherwise.** The way back is open for eight hours. Signing out while impersonating
  closes it, so neither account stays signed in.
- **What happened is in the logs, not the table.** The audit log records
  `user.impersonation_started` and `user.impersonation_ended` with how it ended (`return`,
  `logout`, `expired`, `refused`); each person's activity log records `impersonate`/`impersonated`
  and `impersonation_end`.
- **A shortcut of the application's own undoes it.** Code that signs the administrator back in
  on an admin request (from a cookie of its own, say) does so a moment after the impersonation
  starts. Remove it, or skip it while `Impersonation::current()` is not null.
- **Who may.** From the `super` role up, and only as an account of a lower usertype: never one's
  own, an equal or a higher one. Configure it with:

```php
'auth' => ['impersonation' => ['min_role' => 'admin', 'hours' => 4]],
```

### Encrypted Password Authentication

```php
// Login with the stored hash instead of the plaintext password
$auth = \Pramnos\Auth\Auth::getInstance();
$storedHash = $user->password; // exactly as stored in users.password
$result = $auth->auth('user@example.com', $storedHash, false, true); // Fourth parameter: password is the stored hash
```

The driver compares the value with `users.password` as a plain string. Pass the hash exactly as
it is stored; a newly computed `password_hash()` has its own random salt and never matches.

### Logout

```php
// Logout current user
$auth = \Pramnos\Auth\Auth::getInstance();
$auth->logout();
// This triggers the 'Logout' addon events and clears the session
```

### Credential Verification (Without Session)

If you need to verify a user's credentials (username/password) without actually logging them in (e.g. for API tokens, OAuth2 Password Grants, or verifying a password before sensitive actions), use the central static method `User::validateUserCredentials()`:

```php
$credentials = \Pramnos\User\User::validateUserCredentials($username, $password);

if ($credentials !== false) {
    // Credentials match!
    $userId = $credentials['userid'];
    $username = $credentials['username'];
    $email = $credentials['email'];
} else {
    // Invalid credentials
}
```

This method delegates to `Auth::getInstance()->verifyCredentials($username, $password)` which resolves the active authentication logic in the proper priority order (respecting any registered addons like `UserDatabase` or custom `AuthDriverInterface` drivers).

### Two audiences for one refusal: `revokeapplication`

`Account::revokeapplication()` answers both an XHR and a plain form post, and the difference is
decided once by `sendRevokeResponse()`:

| caller | answer |
|---|---|
| `X-Requested-With: XMLHttpRequest` | a JSON body, then the response ends |
| a browser form | a flash message, then a redirect to `applicationsUrl()`, by default `<routeBase>/applications` |

Worth stating because getting it wrong is invisible from one side. An XHR handed an HTML flash page
fails at `response.json()` and surfaces as «something went wrong» with no clue that the request was
simply missing a field. A form post handed a bare JSON body shows the visitor `{"success":false,…}`
as the entire page.

**The redirect belongs in `sendRevokeResponse()`, not at the end of the action.** The action has
early returns — «client_id is required», «Application not found» — and every one of them answers
through `sendRevokeResponse()`, so a browser form gets its redirect on every path rather than a flash
queued for a page that is never rendered. If you override `sendRevokeResponse()`, it owns ending the
response. An area that serves the screen elsewhere (`/admin/…`) overrides `applicationsUrl()`, not
the whole response. The messages go through `t()`.

### The applications a person has authorized: `AuthorizedApplications`

The listing and the revoking both live in `Pramnos\Auth\AuthorizedApplications`, so the person's
own screen and an administrator acting on their account do the same thing:

```php
$apps = new \Pramnos\Auth\AuthorizedApplications();

$apps->forUser($userId);
// [['appid' => 4, 'name' => 'Assistant', 'apikey' => '…', 'active' => true, 'token_count' => 2,
//   'last_used' => 1760000000, 'consented_at' => '2026-01-02 03:04:05', 'scope' => 'mcp offline_access', …]]

$apps->revoke($userId, $appId, byUserId: $administratorId);   // how many tokens were revoked
```

- **Listed: a live token, or a consent.** A consent whose tokens have all expired is still listed,
  with `token_count` 0. It would approve the next authorization without asking, so it must be
  visible to be withdrawn. `last_used` is the latest of a token's last use and its creation.
- **A deactivated application is listed and revocable.** Its `active` is `false`, but its refresh
  tokens are as live as anybody's. `byClientId()` finds it by client id whatever its status.
- **Revoking** sets every live token the application holds for the person, access and refresh, to
  status 3, kept for the audit trail with its `removedate`. It deletes the consent, so the next
  authorization asks again, and records `application_revoked` in the person's activity. When
  `byUserId` is somebody else, the record carries `revoked_by`. Status 3 is also what
  `EndSession` writes when a client signs a person out.

### `exportdata` is a confirmation on `GET` and the file on `POST`

GDPR Article 20, and the split is the security property rather than a UX preference. A `GET` that
produced the file would put every fact the site holds about somebody behind a URL — shareable, in
browser history, in a proxy log, and fetchable by any `<img src>` on any page they visit. So the `GET`
renders what is about to be exported, and only a `POST` with a valid session token builds it.

The order inside the `POST` matters too: the data is built *before* the download headers go out. The
other way round hands the browser a file called `user_data_export_<date>.json` containing an error
page.

## User Management

### Creating Users

```php
// Create a new user
$user = new \Pramnos\User\User();
$user->username  = 'johndoe';
$user->email     = 'john@example.com';
$user->firstname = 'John';
$user->lastname  = 'Doe';
$user->active    = 1;
$user->regdate   = time();
$user->save();                      // assigns the userid

$user->setPassword('password1!');   // hashed with the account's pepper, so it needs the id
$user->save();
```

Two saves, in that order: `setPassword()` keys the hash with the account's id, so a hash
written before the id exists is one no login can match. Assigning `$user->password` directly
stores whatever string you give it.

`save()` writes `active` as the object holds it, on every database: `0` and `1`, or an
application's own states (`2`, `3`, `5`…), into the framework's `smallint`. A PostgreSQL schema
that declared it `boolean` gets a boolean, and one without the column is left alone.

### Loading Users

```php
// Load user by ID
$user = new \Pramnos\User\User(123);

// Load user by username or email
$userId = \Pramnos\User\User::getuserid('johndoe', 'username');   // false when there is none
$userId = \Pramnos\User\User::getuserid('john@example.com', 'email');
$user   = new \Pramnos\User\User((int) $userId);

// Get current logged-in user
$currentUser = \Pramnos\User\User::getCurrentUser();
if ($currentUser) {
    echo "Welcome, " . $currentUser->firstname;
}
```

#### A user that did not load is a new one

`new User($id)` for an id with no row gives a **new** user: `save()` inserts it, with an id of
its own. Check `$user->userid > 1` after constructing when the id should exist. A save that finds
no row to update — the user was deleted after it loaded — writes nothing, details included, and
leaves `There is no user N to save: it does not exist.` in `$user->_errors`.

#### A second load of the same user comes from memory

`load()` keeps what it read for the process, up to a thousand users, and a second
`new User($id)` is built from that, with no query. It is the same object a load from the database
gives: the columns, the details (`otherinfo`), `originalOtherinfo` (so a save writes only the
details that changed) and `_initialUserData`, the baseline `save()` compares with.
`User::clearUserCache()` empties it, and a save of that user drops their entry.

#### `getCurrentUser()` is a read

It returns the signed-in user, or `false`, and **writes nothing**. Call it as
often as a request needs to — the theme header and the controller both asking is
the normal case, and the first call caches the answer on the application so the
rest are free.

**For `?->`, use `User::current()`.** `getCurrentUser()` answers `false` for
nobody, and the nullsafe operator short-circuits only `null`, so
`getCurrentUser()?->userid` throws `Attempt to read property "userid" on false`
from every cron job and CLI command. `User::current()` is the same lookup and
answers `null`:

```php
$userId = \Pramnos\User\User::current()?->userid;   // null when nobody is signed in
```

`getCurrentUser()` keeps `false`, because code compares it with `=== false` and
would read `null` as somebody being signed in.

**The cache is per request, and a sign-in clears it.** `Auth::login()`, `loginById()` and
`logout()` all call `User::forgetCurrentUser()`, so a process that signs in a second user — a
test, a worker acting for several accounts — is told about that user, not the first. Code that
changes `$_SESSION['uid']` by any other route calls `User::forgetCurrentUser()` itself.

### How a stored password is hashed, and read

Everything that hashes or checks a password goes through `Pramnos\Auth\PasswordHash`.
`User::setPassword()`, `User::verifyPassword()` and `DatabaseAuthDriver` all call it, so
the front door and a step-up in the middle of an account screen agree about what a stored
hash is.

```php
$user->setPassword($plain);            // writes the preferred scheme
$user->verifyPassword($plain);         // true for any scheme it can read
```

**The preferred scheme is bcrypt over an HMAC-SHA-256 digest of the password**, keyed by a
per-account pepper (`md5(securitySalt . userid)`). The digest is there because bcrypt stops
at 72 bytes: the `pepper` scheme (still read) appends a 32-character pepper to the plaintext,
so everything typed past the 40th character is discarded and two long passwords sharing a
40-character prefix verify against each other. A fixed-length digest has no such ceiling.

The per-account pepper means the same password held by two accounts is two different
hashes, so one leaked hash cannot be tested against every row.

#### `verify()` reads more than one scheme, and says which

```php
$scheme = \Pramnos\Auth\PasswordHash::verify($plain, $storedHash, $userId);
// 'hmac' | 'pepper' | 'plain' | 'md5' | null
```

| Scheme | What it is | Accepted |
|--------|-----------|----------|
| `hmac` | the preferred scheme above | always |
| `pepper` | pepper appended to the plaintext | always |
| `plain` | `password_hash($plain, PASSWORD_DEFAULT)`, no pepper | always |
| `md5` | a raw md5 from a very old table | only with `$allowMd5 = true` |

`plain` is there because **an application sharing this user table may write the row itself**,
with a bare `password_hash()`. Without it, such an account could not pass the framework's own
password step-up — the correct password would be refused, with nothing in the refusal to
suggest that hashing was the reason. Verification reads both, so either side may create a row.

It returns the scheme's *name* rather than a boolean because a caller cannot know whether
to rewrite the row without knowing what it is holding.

#### Upgrading on the next sign-in

A successful sign-in is the only moment the plaintext exists, so it is the only moment a
row can be rewritten in a stronger scheme. That happens automatically, and how far it goes
is yours to choose:

```php
// app/app.php
'auth' => [
    'rehash_on_login' => 'modern',   // 'off' | 'modern' | 'all'
],
```

- **`off`** — never rewrite. For a table whose rows another writer must keep reading in
  the scheme it wrote them in.
- **`modern`** (the default) — rewrite the pepper-suffix scheme, the preferred scheme when
  its cost has moved past the stored hash, and md5. md5 needs no second opt-in: a row can
  only be *read* as md5 when `auth.legacy_md5` is on, which already says the table has md5
  rows in it. **Leaves a plain `password_hash()` row alone**, because such a row may belong
  to another application sharing this table, and rewriting it would leave that application
  unable to verify a password it wrote itself.
- **`all`** — that row too. This is how an application says the table is its own; for
  migrating one, where the alternative is asking every user to reset a password that is
  correct.

The same key governs the login driver and `User::verifyPassword()`, so a sign-in and a
step-up never disagree about whether a row may be rewritten. `auth.auto_upgrade` (boolean) is
still read, as an older name for the same decision.

Each rewrite is recorded as a `password_hash_upgraded` activity entry, so a migration is
something you can watch finish rather than assume.

#### Ordering a step-up: cheap checks first

Where a screen asks for more than one thing — a password *and* a 2FA code, as disabling
two-factor does — check the password first and the single-use code last. Reversed, a
mistyped password consumes the code: the user is told their password was wrong, and then
has to wait for a new code before they can retype it. The cost only appears in somebody's
hands, and the wrong order is the natural one to write.

### `users.language` is the user's preference

The framework never writes `users.language` on its own — only an operator's edit on the
administration user form, or your application. It is yours to interpret — typically as the
language the account prefers the interface in.

**It is read at sign-in.** A login carries the stored language into the session, so the
page after the login form is already in it — see
[Which language a request is served in](Pramnos_Internationalization_Guide.md#which-language-a-request-is-served-in)
for the whole order of precedence. Setting the column is all an application has to do.

The framework does not keep it in step with the interface language, and that is
deliberate: the two answer different questions. `$lang->currentlang()` is the
language *this request* is being rendered in, which a `?lang=` parameter or a
session can change for one page view; `users.language` is what the account
chose. If you want a change of interface language to become the stored
preference, write it where the choice is made:

```php
// Wherever your application lets a user pick their language.
$user = \Pramnos\User\User::getCurrentUser();
if ($user) {
    $user->language = $chosen;
    $user->save();
}
```

One place, visible in a diff, and it does not fire on an account that was merely
looked at.

### User Data Management

```php
$user = new \Pramnos\User\User(123);

// Get user data as array
$userData = $user->getData();

// Update user information
$user->email = 'newemail@example.com';
$user->save();

// Delete the users row only
$user->deleteuser();

// Erase the account and everything the framework holds about it (GDPR)
(new \Pramnos\Auth\AccountErasure($database))->erase((int) $user->userid);
```

`deleteuser()` removes the `users` row and nothing else. For a person's data, use
`AccountErasure` — see [Erasure](Pramnos_Auth_Users_Guide.md#erasure-accountdata_erase).

**A property that is not a column is a `userdetails` row.** `$user->kept_note = 'x'` reaches
`__set()` and `save()` writes it as a `userdetails` row for that user. A name that begins with
`_` never is, whether or not it was loaded: it is the place for a subclass's private cache (a
loaded model, a computed value), which must not become a row on every save.

### `userlog` is deprecated

`userlog` holds one row per event about a user. It records the user the row is about but
never who acted, so an administrator's change to an account is indistinguishable from the
user's own. It is also a second store beside
[`pramnos.changelog_events`](Pramnos_Change_Feed_Guide.md#recording-an-event-a-diff-cannot-express),
which records the entity, the actor and the source, with `details` as JSON, for users and
everything else alike. Two stores for the same events means every reader has to consult
both.

- **No framework migration creates it.** A new installation has no `userlog`.
- **An installation that already has it keeps it.** The framework never drops it, and the
  DevPanel's user log shows its rows wherever the table exists.
- **The framework does not write to it.** Record user events in `changelog_events`, through
  `Model::logEvent()`, with the `changelog` feature enabled.

An application that still writes to `userlog` keeps working on its existing databases. On a
new installation it has to create the table in one of its own migrations, or move those
writes to `changelog_events`.

## Session Management

### Basic Session Operations

```php
$session = \Pramnos\Http\Session::getInstance();

// Check if user is logged in
if ($session->isLogged()) {
    $userId = $_SESSION['uid'];
    $username = $_SESSION['username'];
}

// Create snapshot for post-login redirect
$session->snapshot($_SERVER['REQUEST_URI']);

// Get and clear snapshot
$returnUrl = $session->getSnapshot();
if ($returnUrl) {
    $this->redirect($returnUrl);
}
```

### Session Security

```php
$session = \Pramnos\Http\Session::getInstance();

// Get session token for CSRF protection
$token = $session->getToken();

// Validate CSRF token
if ($session->checkToken('post', 'csrf_')) {
    // Token is valid, process request
} else {
    // Invalid token, possible CSRF attack
    throw new \Exception('Invalid security token', 403);
}

// Reset session (for logout)
$session->reset();
```

## Requests that are somebody without being an account

`RequestIdentity::seal()` models *an account* or *nobody*. That is right for an API,
where anonymous means no identity at all, and not enough for an application whose
unauthenticated callers are people: a chat participant with a nickname and a session,
present in a room, mutable, bannable, addressable, and the same person across requests
for as long as they stay.

```php
RequestIdentity::sealGuest($presenceId, 'presence');

RequestIdentity::isGuest();    // true
RequestIdentity::user();       // null — an account is still an account
RequestIdentity::guestId();    // the opaque id
RequestIdentity::subject();    // the id, whichever kind of identity this is
```

`subject()` is the reason to use this rather than a parallel mechanism: one question, one
answer, for all three states. Without it an application keeps a second notion of who the
caller is, and every consumer has to know which of the two to consult.

The id is opaque and the framework does not interpret it. A presence row, a signed
cookie, a hash of a nickname and a session are all yours.

**Three rules the framework enforces**, because each is only wrong invisibly:

- **A guest never replaces an account.** `sealGuest()` on an already-authenticated
  request is refused and logged. A middleware that seals a guest unconditionally,
  ordered after the one that authenticates, would otherwise demote the caller and every
  later permission check would answer for the wrong person.
- **An account does replace a guest**, because that is a real login — and a request must
  not end up holding both identities.
- **`user()` keeps returning null.** A guest is not a `users` row, and code asking for a
  user is not handed something that resembles one. That is why `isGuest()` is a separate
  question.

An empty id is refused: every such guest would be indistinguishable, so a mute, a ban or
a rate limit keyed on it would apply to all of them at once.

## What a session record contains

Every token in `usertokens` carries a `deviceinfo` column, JSON-encoded:

```json
{"device": "chrome|windows", "label": "Chrome on Windows", "ip": "203.0.113.9"}
```

Written for every token type at creation — session, API, OAuth access and refresh — so
the active-sessions list can show a person which of their devices a session belongs to.

`Token` decodes it into an array. Both writers agree on the format — `User::addToken()`
at creation and `Token::save()` afterwards both `json_encode()`. The `unserialize()`
branch in `Token::fillProperties()` is a **reader** for rows written by an older path, not a
second format anything produces today.

**It is written once, at creation, and never rewritten.** It records the device the token
was *issued to*, which is what makes it comparable later — and what makes a token used from
a browser it was not issued to visible as such.

`Token::addAction()` does not touch it. A column rewritten on use would hold a different
shape depending on whether a token had ever been used, the session retirement could not match
a used token, and a token used from a browser it was not issued to would have its record
rewritten to say the new browser — destroying the evidence it appears to collect.

**It stores the fingerprint, not the raw user agent**, and that is the same decision as
in the section below: keeping the agent string would make every session look like a new
device after any browser update, turning a list meant for recognition into a list of
strangers. The `ip` is recorded for an administrator investigating an incident — it is
never used to decide anything.

## Controller Authentication

### Protecting Controller Actions

```php
class ArticleController extends \Pramnos\Application\Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // Define public actions (no authentication required)
        $this->addAction(['display', 'view']);
        
        // Define authenticated actions (login required)
        $this->addAuthAction(['create', 'edit', 'delete', 'save']);
        
        parent::__construct($application);
    }
    
    public function display()
    {
        // Public action - anyone can access
        return $this->getView('article')->display();
    }
    
    public function create()
    {
        // Authenticated action - user must be logged in
        // Framework automatically checks authentication
        return $this->getView('article')->display('create');
    }
}
```

### API Authentication

```php
class ApiController extends \Pramnos\Application\Controller
{
    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        // API endpoints typically require authentication
        $this->addAuthAction(['list', 'create', 'update', 'delete']);
        parent::__construct($application);
    }
    
    public function list()
    {
        // Who this request is — established by whatever authenticated it, and
        // not by a session cookie the caller never meant as a credential.
        $user = \Pramnos\User\User::getCurrentUser();
        if (!is_object($user) || (int) $user->userid < 2) {
            return ['status' => 401, 'error' => 'Authentication required'];
        }

        // Return data specific to the authenticated user
        return ['status' => 200, 'data' => $this->getUserData($user->userid)];
    }
}
```


## Configuration

### Authentication Settings

Two places configure authentication, and each key is described in the section that owns it.

**Application settings** — editable on the settings screen, or fixed in `app/config/settings.php`:

| Setting | Section |
| --- | --- |
| `web_session_lifetime` | [Web-session tokens](Pramnos_Auth_Tokens_Guide.md#web-session-tokens-and-how-they-stop) |
| `loginlockoutsteps`, `loginlockoutwindowseconds` | [Login Lockout](Pramnos_Auth_Second_Factors_Guide.md#login-lockout-brute-force-protection) |
| `auth_allow_registration`, `auth_registration_domains`, `auth_registration_verify_email`, `auth_invitation_ttl_hours` | [Registration](Pramnos_Auth_Users_Guide.md#registration-open-by-domain-by-invitation) |
| `auth_newsignin_policy`, `auth_newsignin_action`, `auth_newsignin_trigger` | [Telling an account it was used from somewhere new](Pramnos_Auth_Second_Factors_Guide.md#telling-an-account-it-was-used-from-somewhere-new) |
| `auth_trusted_devices`, `auth_trusted_device_days`, `auth_push_approval`, `auth_push_number_matching` | [Trusted devices and sign-in approval](Pramnos_Auth_Second_Factors_Guide.md#trusted-devices-and-sign-in-approval) |

**`app/app.php`** — fixed per deployment, versioned with the code:

```php
'auth' => [
    'rehash_on_login'             => 'modern',   // 'off' | 'modern' | 'all'
    'newsignin_policy'            => 'optout',   // the default the setting overrides
    'registration_admin_editable' => true,
    'security'                    => ['human_check' => ['register']],
],
'authserver' => [
    'default_grants'       => [...],
    'default_auth_methods' => [...],
    'default_scopes'       => [],
],
```

See [Upgrading on the next sign-in](#upgrading-on-the-next-sign-in) and
[OAuth2 Scopes and Policy](Pramnos_Auth_Permissions_Guide.md#oauth2-scopes-and-policy) for the `rehash_on_login` and
`authserver` keys.

Email confirmation at registration is a setting rather than a configuration key — see
[Registration](Pramnos_Auth_Users_Guide.md#registration-open-by-domain-by-invitation).

### Database Setup

The tables are created by the framework's migrations — there is nothing to create by hand:

```bash
php <cli>.php migrate --scope=framework
```

The `auth` feature creates `users`, `usertokens`, `authserver.permissions` and the other
`authserver.*` tables this guide names; the `authserver` feature adds the OAuth2 server's own.
Do not build these tables from hand-written SQL: `usertokens.token` holds an encrypted value and
authentication finds a token through `usertokens.token_lookup`, which a hand-made table would not
have.

> If your installation has a hand-made `permissions` table, see
> [Legacy Permissions Migration](Pramnos_Legacy_Permissions_Migration.md) for the SQL that moves its
> rows into `authserver.permissions`.

## Best Practices

### Security Guidelines

1. **Use the query builder** - `$db->queryBuilder()` binds parameters instead of interpolating them, and resolves `authserver.*` table names per driver
2. **Validate JWT tokens properly** - Set appropriate expiry times and validate all claims
3. **Use strong passwords** - Implement password complexity requirements
4. **Enable CSRF protection** - Use session tokens for form submissions
5. **Implement rate limiting** - Prevent brute force attacks on login endpoints
6. **Use HTTPS** - Always transmit authentication data over secure connections
7. **Log security events** - Monitor failed login attempts and permission violations

### Performance Considerations

1. **Cache permissions** - Use the caching system for frequently checked permissions
2. **Optimize token queries** - Index token tables properly
3. **Clean up expired tokens** - Regularly remove old authentication tokens
4. **Use efficient session storage** - Consider Redis for session storage in production

### Error Handling

```php
try {
    $auth = \Pramnos\Auth\Auth::getInstance();
    $result = $auth->auth($username, $password);
    
    if (!$result) {
        $response = $auth->lastResponse;
        \Pramnos\Logs\Logger::log(
            'Failed login attempt for ' . $username . ': ' . ($response['message'] ?? ''),
            'auth'
        );

        return ['status' => 401, 'message' => 'Authentication failed'];
    }

} catch (\Throwable $e) {
    \Pramnos\Logs\Logger::logError('Authentication error', $e);   // message, then the exception

    return ['status' => 500, 'message' => 'Internal authentication error'];
}
```

This authentication system provides a robust foundation for securing your Pramnos Framework applications with support for modern authentication patterns, comprehensive user management, and flexible permission systems.

---

## Related Documentation

- **[Framework Guide](Pramnos_Framework_Guide.md)** - Core framework concepts and MVC patterns
- **[Database API Guide](Pramnos_Database_API_Guide.md)** - Database operations for user data management
- **[Cache System Guide](Pramnos_Cache_Guide.md)** - Caching user sessions and permissions
- **[Console Commands Guide](Pramnos_Console_Guide.md)** - CLI tools for user management
- **[Logging System Guide](Pramnos_Logging_Guide.md)** - Logging authentication events and security monitoring
- **[Email System Guide](Pramnos_Email_Guide.md)** - Password reset and notification emails
- **[Internationalization Guide](Pramnos_Internationalization_Guide.md)** - Multi-language authentication flows

---

For additional information on implementing authentication in your controllers and APIs, see the [Framework Guide](Pramnos_Framework_Guide.md#authentication-and-authorization) section on authentication patterns.
