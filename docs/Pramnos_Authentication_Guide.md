---
use_cases:
  - Signing a user in or out, or checking who is signed in
  - Creating or managing user accounts
  - Issuing, validating or revoking JWT / bearer tokens
  - Requiring authentication in a controller
  - Working with sessions or authentication addons
  - Giving a signed-in browser an API token for a SPA on the same origin
  - Identifying a caller who is present and has no account, such as a chat guest
  - Showing a user which devices and sessions their account has
---

# Pramnos Authentication & User Management Guide

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

### Login with Remember Me

```php
// Login with persistent session
$auth = \Pramnos\Auth\Auth::getInstance();
$result = $auth->auth('user@example.com', 'password', true); // Third parameter enables "remember me"

if ($result) {
    // User will stay logged in across browser sessions
    $user = \Pramnos\User\User::getCurrentUser();
}
```

### Encrypted Password Authentication

```php
// Login with pre-encrypted password (for API scenarios)
$auth = \Pramnos\Auth\Auth::getInstance();
$hashedPassword = password_hash('plaintext_password', PASSWORD_DEFAULT);
$result = $auth->auth('user@example.com', $hashedPassword, false, true); // Fourth parameter indicates encrypted password
```

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

## User Management

### What a usertype is, and how to change the bands

`users.usertype` is an **integer read as a threshold**, not an enum. `>= 90` is an
administrator (`UserCreate::ADMIN_USERTYPE`), and the administration area's floor is
whatever `admin.min_usertype` says — so a comparison, not an equality, is what the
framework's own guards are written in.

The bands have names, in one place:

```php
\Pramnos\User\UserTypes::label(85);     // 'Manager' — the band it falls in, not '85'
\Pramnos\User\UserTypes::labels();      // [90 => 'Admin', 80 => 'Manager', …]
\Pramnos\User\UserTypes::options();     // value => label, for Html\Select::addOptions()
```

An application renames or replaces them in `app/app.php`:

```php
'usertypes' => [
    100 => 'Owner',
    90  => 'Administrator',
    50  => 'Staff',
    10  => 'Customer',
    0   => 'Guest',
],
```

Keyed by the band's **floor** and read highest-first, so a value between two bands belongs
to the lower one. Declare them in any order — they are sorted before use, because a config
listing its bands lowest-first would otherwise label an administrator "Guest".

The bundled admin screens use this for the badge on a user, the label in the list and the
options in the list's type filter. Before it, each of those carried its own copy of the
mapping, so "what is 85?" had three answers.

**This is not a role system.** A usertype answers *how senior is this account*, in one
number, in a column every application on the framework shares. When the question is *may
they do X*, that is a permission — see the
[Authorization guide](Pramnos_Authorization_Guide.md).

### What the administration screen shows about a user

The framework records a user's history in **nine** stores, and until they were joined on
one screen most of it was invisible outside the DevPanel:

| Store | What it holds | Where it appears |
| --- | --- | --- |
| `authserver.user_activity_log` | sign-ins, logouts, whatever the application records | *Activity* panel, and `users/activity/{id}` for the whole of it |
| `authserver.gdpr_requests` | export and erasure requests | *Data requests* panel |
| `authserver.loginlockouts` | failed attempts and any active lockout | *Login security* panel, with **Clear lockout** |
| `authserver.user_twofactor` | whether a second factor is on | *Second factor* panel, with **Disable** |
| `authserver.passkey_credentials` | registered passkeys | same panel, with **Revoke** per key |
| `authserver.user_privacy_settings` | what the user chose about their data | *Privacy choices* panel |
| `usertokens` | issued tokens | *Recent tokens*, and the Tokens screen |
| `tokenactions` | what was done with them | *Token actions* panel |
| `authserver.user_organizations` | memberships | *Organizations* panel |

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

### Editing a user: what the screen can change

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

**Per-user permissions.** The grants written directly to this account, with revoke, and a
form to add one (`object_type`, `object_id`, `action`, allow/deny). Only the *direct*
grants are listed: the resolver also answers from usertype and group membership, and a
screen mixing them would offer a revoke button for a permission that has no row.

Everything is three separate forms on one screen, because they are three separate
decisions — saving a name should not resubmit a permission, and a rejected permission
should not lose a typed name.

### Telling an account it was used from somewhere new

`NewSignInAlert` compares the current sign-in's device fingerprint against
`user_activity_log` and emails the account when the combination is new. It reads history
that already exists, which is why switching it on does not notify everybody at once.

**The site's policy sits around the user's own preference** — `auth_newsignin_policy` on
the settings screen:

| Value | Meaning |
| --- | --- |
| `optin` (default) | the account's own preference decides |
| `always` | every account is notified, whatever it chose |
| `off` | nobody is, whatever they chose |

`always` exists because for a service where the account *is* the product, telling somebody
their credentials were used from a new device is closer to an obligation than a setting.
`off` exists for the incident where the alert stops being a security feature and becomes
the outage's own mailing list. The default is the behaviour every installation had before
the setting existed, so upgrading starts and stops nobody's mail.

The per-account state is on the user's admin screen, with a toggle when the policy leaves
the decision to the user — and a sentence instead of a switch when it does not, because a
control that decides nothing is worse than none.

### Fields the users table does not have

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

### Self-service registration

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
   crafted POST to a closed server cannot write a row.
3. CSRF token → checked before validation, so a form with no token is not even an
   account-existence oracle.
4. Username 3–60 characters, `[A-Za-z0-9._-]` only — the set that needs no
   escaping in a URL, a log line or an email subject.
5. A valid email address.
6. The same password policy `resetpassword` uses: 8 characters, a digit, a
   symbol, and a matching confirmation.
7. Uniqueness, and only then a write.

The new account is active at the lowest privilege level. Nothing in the flow can
grant a usertype.

**What it tells an attacker.** "That username is taken" confirms an account
exists, and there is no way to both refuse a duplicate and not confirm it — a form
that has to let somebody pick another name has to say why. So it reveals that,
and the mitigations are the ones that work: leave registration off when you do not
need it, and keep the login lockout in place, because the value of an enumerated
username is what you do with it next. The email case is worded so that it does not
add a second, independent confirmation.

To decide on something other than a global flag — an invite code, a domain
allow-list, an organization policy — override one method:

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
        return $this->post('invite') === $this->setting('signup_invite_code');
    }
}
```

`createUser()`, `usernameExists()` and `validateRegistration()` are seams on the
same controller if you need to change how an account is stored or what a username
may look like.

### The single sign-on status page

`/sso` answers "does this server already know me, and what have I authorized?".
Public, because for a signed-out visitor that negative answer is the useful half —
it is what another application sends somebody here to find out.

### Creating Users

```php
// Create a new user
$user = new \Pramnos\User\User();
$user->username = 'johndoe';
$user->email = 'john@example.com';
$user->password = password_hash('password', PASSWORD_DEFAULT);
$user->firstname = 'John';
$user->lastname = 'Doe';
$user->status = 1; // Active
$user->save();
```

### Loading Users

```php
// Load user by ID
$user = new \Pramnos\User\User(123);

// Load user by username/email
$user = new \Pramnos\User\User();
$user->loadByUsername('johndoe');

// Get current logged-in user
$currentUser = \Pramnos\User\User::getCurrentUser();
if ($currentUser) {
    echo "Welcome, " . $currentUser->firstname;
}
```

#### `getCurrentUser()` is a read

It returns the signed-in user, or `false`, and **writes nothing**. Call it as
often as a request needs to — the theme header and the controller both asking is
the normal case, and the first call caches the answer on the application so the
rest are free.

> **Changed 2026-08-23.** It did write. On every call after the first in a
> request it compared `users.language` with the interface language and, when
> they differed, overwrote the column and saved the user. Two things followed: a
> stored language preference was reverted by the act of viewing a page rendered
> in another language, and on an account with no email address the save could
> raise from the address validation in `_save()` — so a lookup ended the
> request. Both are gone. If your application worked around this by keeping its
> language preference in a column of its own, `users.language` is now safe to
> use again.

### `users.language` is the user's preference

Nothing in the framework writes `users.language`. It holds whatever your
application put there, and it is yours to interpret — typically as the language
the account prefers the interface in.

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

// Delete user
$user->delete();
```

## JWT Token Authentication

### Generating JWT Tokens

```php
// Create JWT token for user
$payload = [
    'userId' => $user->userid,
    'username' => $user->username,
    'exp' => time() + 3600, // Expires in 1 hour
    'iat' => time(), // Issued at
];

$secret = 'your-secret-key';
$token = \Pramnos\Auth\JWT::encode($payload, $secret, 'HS256');
```

### Validating JWT Tokens

```php
try {
    $secret = 'your-secret-key';
    \Pramnos\Auth\JWT::$leeway = 60; // Allow 60 seconds clock skew
    
    $decoded = \Pramnos\Auth\JWT::decode($token, $secret, ['HS256']);
    
    // Token is valid, load user
    $user = new \Pramnos\User\User($decoded->userId);
    
} catch (\Exception $e) {
    // Token validation failed
    echo "Invalid token: " . $e->getMessage();
}
```

### API Authentication with JWT

```php
// In API controllers, JWT is validated by the auth middleware before the
// controller runs. Ask who the request is — never read $_SESSION.
class ApiController extends \Pramnos\Application\Controller
{
    public function secureEndpoint()
    {
        $user = \Pramnos\User\User::getCurrentUser();
        if (!is_object($user) || (int) $user->userid < 2) {
            return ['status' => 401, 'message' => 'Authentication required'];
        }

        return ['status' => 200, 'data' => 'Protected data for user ' . $user->userid];
    }
}
```

!!! warning "Do not read `$_SESSION` in an API controller"
    An application that serves a website and an API from one origin shares a
    session cookie between them. `$_SESSION['user']` therefore answers with
    *whoever is signed in to the website*, which is not the caller — a request
    that presented no credential at all would be authenticated, and `logout`
    could not work, because revoking the token would leave the cookie answering.

    `User::getCurrentUser()` asks the request instead: an API request seals its
    own identity (see below), and a sealed answer — including "nobody" — stops
    the session being consulted at all.

### Where a request's identity comes from

The framework keeps two ideas apart, and the separation is what makes a hybrid
application safe:

| | website | API |
|---|---|---|
| credential | session cookie | token on the call |
| established by | login, stored in the session | auth middleware, per request |
| read with | `User::getCurrentUser()` | `User::getCurrentUser()` |
| may write the session | yes | **no** |

`Pramnos\Http\RequestIdentity` is how a middleware says "this call is user X, or
nobody, and nothing else may answer":

```php
RequestIdentity::seal($user, 'accessToken');   // this request is $user
RequestIdentity::seal(null);                   // this request is anonymous
```

Both `ApiAuthMiddleware` and `UnifiedAuthMiddleware` do this for you. Sealing is
request-scoped and writes nothing that outlives the call, so an API request can
never change who the browser's next page belongs to — and an anonymous API call
can never sign the browser out, which is what used to happen when the API
"cleared" the ambient session to protect itself.

**An API that genuinely needs both** — an authserver whose own web UI calls its
own endpoints — should use `UnifiedAuthMiddleware`, which accepts a Bearer token
*or* a session cookie **plus an `X-CSRF-Token` header**. The CSRF token is not
optional there: a cookie is sent by the browser automatically, so without it any
site could make authenticated calls on the user's behalf.

## Token Management

### User Tokens

The framework supports various token types for different purposes:

```php
$user = new \Pramnos\User\User(123);

// Add authentication token
$token = $user->addToken('auth', bin2hex(random_bytes(32)), 'API access token');

// Add Apple Push Notification token
$apnsToken = $user->addToken('apns', $deviceToken, 'iPhone device');

// Add OAuth2 access token
$accessToken = $user->addToken('access_token', $oauthToken, 'OAuth2 access', $refreshTokenId);
```

### Token Operations

```php
$user = new \Pramnos\User\User(123);

// Get user's active auth token
$authToken = $user->getToken();

// Get all user tokens
$allTokens = $user->getAllTokens();

// Load user by token
$user->loadByToken($tokenString, 'auth');

// Clean up old tokens (older than 30 days)
\Pramnos\User\User::cleanupAllAuthTokens(30);
```

### Web-session tokens, and how they stop

A `web_session` token is created on every successful web login and lives in
`$_SESSION['usertoken']`, so same-origin AJAX is authenticated without a Bearer token.
One login, one row.

That row now carries an expiry — **30 days** by default, set at creation:

```php
// app settings
'web_session_lifetime' => 2592000,   // seconds; 0 = never expires
```

Generous next to the PHP session it belongs to, whose own idle timeout
(`session.gc_maxlifetime`) is 24 minutes out of the box, and short enough that the table
stops being append-only.

And something retires them. `auth:token-cleanup` marks every session-bearing token idle
for more than a month as `status = 2` — kept for the audit trail, no longer accepted — and
the framework schedules it daily:

```
php pramnos auth:token-cleanup             # the same thing by hand
php pramnos auth:token-cleanup --days=90   # be more patient
```

`lastused` is updated on every request that presents a token, so "idle for a month" means
nothing has used it for a month.

> **Added 2026-08-20.** Neither existed: `createWebSessionToken()` set no expiry,
> `loadByToken()` reads 0 and NULL as "never", and `cleanupAllAuthTokens()` covered only
> `auth` and `access_token` — and had no caller anywhere in the framework. A two-day-old
> development installation with a single user had **7,255** `web_session` rows, none of
> them expiring, arriving at about 230 an hour. It is also the table `tokenactions` points
> a foreign key at, which is how a buffered write outlives the row it references.

### What a logged request records

`Token::addAction()` holds a row and the request completes it. Two paths do that:

| Path | Completed by | Records |
| --- | --- | --- |
| API | `updateAction()`, once the response is known | status, duration, response body |
| Web | the shutdown flush | status (`http_response_code()`), duration since `addAction()` |

The endpoint is stored as a **path** — `/api/v1/stations`, not
`https://host/api/v1/stations?token=…`. `urls` is a deduplicated registry, and a query
string that carries an id or a hash gives every call a row of its own. The query is not
lost: it goes into `params`, where a request's inputs belong, whenever `params` would
otherwise be empty — which is every GET.

A negative `$return_status` still means "this happened, do not record what it returned":
the row is written with no status and no duration.

> **Corrected 2026-08-20.** The web path recorded neither status nor duration — only the
> API path calls `updateAction()`, and the shutdown flush wrote the held row exactly as it
> was held. Every page view in the audit log had `execution_time_ms` empty, so the
> DevPanel's slowest-endpoints report read `0.0 ms` on every row. And the endpoint was the
> absolute URL with its query, so that same report was twenty distinct URLs of one call
> each. Both reported from the same screen.

### Working with Token Objects

```php
// Create token object
$token = new \Pramnos\User\Token();
$token->userid = $user->userid;
$token->tokentype = 'auth';
$token->token = bin2hex(random_bytes(32));
$token->notes = 'Mobile app access';
$token->expires = time() + (30 * 24 * 60 * 60); // 30 days
$token->save();

// Load existing token
$existingToken = new \Pramnos\User\Token($tokenId);
$details = $existingToken->getDetails();

// Track token usage
$existingToken->addAction(); // Logs the current request
```

## Permissions System

There is **one** permission store: `authserver.permissions`. It is created by
the `auth` feature's migrations, so every installation that has users has it —
running an OAuth server is not a prerequisite. Two APIs read it, and which one
you want depends on how much of the model you need:

| | `Pramnos\Auth\Permissions` | `Pramnos\Auth\PermissionResolver` |
|---|---|---|
| Shape | one question, one answer | the user's whole effective grant list |
| Reads | grants for a user or role | grants, roles, priorities, expiry, audience, conditions |
| Writes | yes — `allow()` / `deny()` | no |
| Use it for | a check in a controller | anything conditional, scoped or audited |

`Permissions` is the simple face; it cannot express what it cannot ask about.
Grants carrying ABAC conditions are **skipped** by it rather than treated as
unconditional — the resolver hands conditions to the application to evaluate
against its own request context, and this API has no way to receive one.

### Setting Permissions

`allow()`, `deny()` and `removePermission()` are instance methods; get the
shared instance from the factory.

```php
$permissions = \Pramnos\Auth\Permissions::getInstance();

// Grant a user the right to create articles
$permissions->allow(
    $userId,           // Subject — a user id
    'articles',        // Resource      → object_type
    'create',          // Privilege     → action
    '',                // Element: '' means all articles → object_id NULL
    'module',          // Resource type (not stored; see below)
    'user'             // Subject type: user | group
);

// Grant a group — stored as a role, which is what the new model calls it
$permissions->allow($editorsRoleId, 'articles', 'edit', '', 'module', 'group');

// Deny — stored above allow so it wins a tie
$permissions->deny($userId, 'admin', 'access');

// Remove — absence is not the same answer as deny
$permissions->removePermission($userId, 'admin', 'access');
```

Two mappings worth knowing: the `admin` privilege is stored as the `*` action
(everything on this object), and `resourceType` has no column in the new model —
`object_type` already carries that distinction.

A subject type other than `user` or `group` cannot be represented. Rather than
store it under the wrong `subject_type`, the call is refused and logged.

### Checking Permissions

```php
$permissions = \Pramnos\Auth\Permissions::getInstance();

// Default: an unknown permission is "no"
if ($permissions->isAllowed($userId, 'articles', 'create')) {
    $this->showCreateForm();
} else {
    throw new \Exception('Insufficient permissions', 403);
}
```

Pass `false` as the last argument when you need to tell "denied" apart from
"nobody ever said":

```php
$verdict = $permissions->isAllowed(
    $userId, 'articles', 'create', '', 'module', 'user', false
);

// true = allowed, false = explicitly denied, null = no rule at all
```

That distinction matters. Collapsing `null` into `false` is how a screen ends up
refusing a user for whom no rule was ever written — which is exactly what the
class did before it knew which store it was reading.

### Roles

The new model expresses groups as roles, and `PermissionResolver` folds a user's
active roles into their effective permissions automatically. Assign one by
inserting into `authserver.user_roles`; role definitions live in
`authserver.roles`.

For the full picture — priorities, expiry, audience scoping and ABAC conditions
— use the resolver directly:

```php
$resolver = new \Pramnos\Auth\PermissionResolver($database);
$effective = $resolver->resolve($userId, null)['permissions'];
```

Each entry carries `object_type`, `object_id`, `action`, `grant` and
`conditions`, with deny-over-allow already applied.

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

## Handing the browser's user an API token

A hybrid application — a session-authenticated site and a token-authenticated SPA on one
origin — has two credentials with two lifetimes. The symptom is always reported the same
way: *"I am signed in on the site; if I leave it a while and then open the panel, it asks
me to log in again."* The site knows who they are; the panel has no way to ask.

```php
use Pramnos\Auth\SessionExchange;

$token = SessionExchange::issue(minimumUserType: 90, ttl: 43200);
if ($token === null) {
    return \Pramnos\Http\Response::redirect(sURL . 'login');
}

return \Pramnos\Http\Response::redirect(
    SessionExchange::redirectUrl(sURL . 'panel/', $token)
);
```

Put that on a session-authenticated route. It answers `null` when nobody is signed in,
when the minimum is not met, or when there is no usable signing key — a route that
redirects sensibly either way is the intended caller.

### Only a session may be exchanged

A request whose identity was proved by anything other than a session is refused, whatever
role it holds.

That is not a formality. `User::getCurrentUser()` prefers a *sealed* identity over the
session, so without the check an API request carrying a bearer token reached the minting
path and received a fresh one — a **refresh**, with rotation and revocation questions this
method answers neither of. Every twelve-hour token good for another twelve hours on
request, forever, from a method documented as exchanging a session.

If your application wants refresh tokens, that is a separate mechanism with a separate
policy. This is not it.

### The signing key

The token is signed with the same key the API verifier uses, resolved in this order:

1. the current application's `authenticationKey`, if it declares one — `Api` computes its
   own in the constructor, and an application may set one explicitly;
2. otherwise `Api::deriveAuthenticationKey()`, which is the identical derivation the API
   itself performs.

The fallback is the **normal** path, not the exceptional one, and the reason is worth
knowing if you are reading this because an exchange returned `null`: `authenticationKey` is
declared on `Api`, not on `Application`. A session-authenticated MVC route — which is the
only kind that can call this — has an `Application`, so reading the property alone found
nothing every single time.

There is one case with no answer: **no declared key and no `sURL`**. The derivation then
reduces to `md5('edge')`, a constant every installation in that state would share, so a
token from any of them would verify against all of them. That is refused rather than
signed. A real request always has `sURL`.

A declared key must also be long enough for `JWT::encode()`, which rejects a short one
outright — the exchange reports that as `null` and logs the reason.

### Not `UnifiedAuthMiddleware`

That solves the **other** direction: it lets an API endpoint accept a cookie plus a CSRF
token. Reaching for it here has a cost worth naming — it makes the API authenticate with
cookies, which quietly invalidates every decision an application made *because* it does
not. A permissive CORS default is the usual one, and it was introduced a long way from
where it would break.

An exchange goes one way, at one moment, and the API still never reads a cookie.

### The four decisions it makes for you

Three are only wrong in ways nobody notices.

| Decision | Why it is not the caller's to make |
| --- | --- |
| **The role is re-read from the database**, not taken from the session | a remember-me cookie can outlive a demotion by a fortnight, and a token minted from that session is then good for its whole lifetime |
| **The token travels in the URL fragment** | a fragment is never sent to a server. `?token=` works, reviews identically, and writes the credential into the access log of every hop and into `Referer` |
| **Nothing is issued for an anonymous caller, a guest, or user id 0/1** | no implicit token, no partial credential; ids 0 and 1 are the guest by convention, and a token minted for one would authenticate as "somebody" everywhere |
| **Failure is `null`, not an exception** | the caller is a route that has to redirect somewhere either way |

The claim set matches the API login's, so an exchanged token is indistinguishable to
every verifier. The row is recorded with `notes = 'session_exchange'`, so a session list
can say where the credential came from, and the exchange is written to the activity log.

### The one decision that stays yours

An SPA that bounces to the exchange route when it has no token **must record that it has
bounced before redirecting**, not after. The route redirects back without a fragment when
it cannot help, so a flag written afterwards is an infinite bounce — on the one page an
operator opens when something is already wrong.

And clear the fragment once adopted (`history.replaceState`), or the token survives in
browser history and in whatever a visitor pastes when asking for help.

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
branch in `Token::load()` is a **reader** for rows written by an older path, not a
second format anything produces today.

**It stores the fingerprint, not the raw user agent**, and that is the same decision as
in the section below: keeping the agent string would make every session look like a new
device after any browser update, turning a list meant for recognition into a list of
strangers. The `ip` is recorded for an administrator investigating an incident — it is
never used to decide anything.

> Until 2026-08-16 this column was written as an empty string at every call site, while
> its own comment described it as holding *"browser, OS, IP at token creation"*. The
> reader had existed for years; there was simply never anything in it.

## New sign-in alerts

Opt-in. When a user has asked for it, an account signed in to from a browser/platform
combination that account has not used before triggers one email.

```php
use Pramnos\Auth\NewSignInAlert;

NewSignInAlert::setEnabledFor($userId, true);
NewSignInAlert::isEnabledFor($userId);          // false unless asked for
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

Mail only. A database notification would put the warning in the panel of the session
that triggered it, which in the case worth warning about is the wrong person.

## Authentication Addons

`Addon::load($name)` takes a file name **or** a fully-qualified class name, and refuses
anything that is neither — a `null` or an empty string returns `false` before the name is used
for anything. `isActive()` and `getAddon()` do the same.

That is not defensive coding for its own sake: the `addons` setting holds a serialized list,
and an entry saved from an admin screen with no addon selected stores a null name. One
installation had 19 such rows out of 24, so a request asked 19 impossible questions before
this refused them.

### User Database Addon

The framework includes a user database addon for standard username/password authentication:

```php
// The UserDatabase addon is automatically triggered during authentication
// It checks credentials against the users table

// Custom validation can be added by extending the addon
class CustomUserAuth extends \Pramnos\Addon\Auth\UserDatabase
{
    public function onAuth($username, $password, $remember, $encryptedPassword, $validate)
    {
        // Add custom validation logic
        $result = parent::onAuth($username, $password, $remember, $encryptedPassword, $validate);
        
        if ($result['status']) {
            // Additional checks (e.g., account verification, 2FA)
            if (!$this->isTwoFactorVerified($result['uid'])) {
                return [
                    'status' => false,
                    'message' => 'Two-factor authentication required',
                    'statusCode' => 401
                ];
            }
        }
        
        return $result;
    }
}
```

### Creating Custom Authentication Addons

```php
class LdapAuth extends \Pramnos\Addon\Addon
{
    public function onAuth($username, $password, $remember, $encryptedPassword, $validate)
    {
        // LDAP authentication logic
        $ldapConnection = ldap_connect($this->config['ldap_server']);
        
        if (ldap_bind($ldapConnection, $username, $password)) {
            // Authentication successful
            $userInfo = $this->getLdapUserInfo($ldapConnection, $username);
            
            return [
                'status' => true,
                'username' => $username,
                'uid' => $userInfo['uid'],
                'email' => $userInfo['email'],
                'auth' => password_hash($password, PASSWORD_DEFAULT)
            ];
        }
        
        return [
            'status' => false,
            'message' => 'LDAP authentication failed'
        ];
    }
}
```

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

## Advanced Features

### Login Lockout (Brute-Force Protection)

`Pramnos\Auth\Loginlockout` — progressive brute-force lockout for login endpoints.

Tracks failed login attempts per scope+identifier pair. Three scopes are supported: `'user'` (by user ID), `'identifier'` (by normalized email/username), and `'ip'` (by remote address).

#### Default thresholds

| Failures | Lockout duration |
|---|---|
| 3 | 60 s (1 minute) |
| 5 | 300 s (5 minutes) |
| 7 | 900 s (15 minutes) |
| 10+ | 3600 s (1 hour) |

A **sliding window** of 900 seconds applies: if the gap between the previous failure and the current attempt exceeds the window, the counter resets to 1. This prevents indefinite accumulation from past brute-force campaigns.

#### Configuring the ladder

Two application settings, both editable from the settings screen:

| Setting | Meaning |
|---|---|
| `loginlockoutsteps` | JSON map, `{"attempts": seconds}` — e.g. `{"3":60,"5":300}` |
| `loginlockoutwindowseconds` | the sliding window, 60–86400 |

Both are read on every attempt. Until 2026-08-27 they were read *nowhere*:
`calculateDuration()` consulted `DEFAULT_STEPS` and the window arithmetic used
`DEFAULT_WINDOW_SECONDS`, so the whole progressive-lockout section of the settings
screen — the editor, its validation, and its "adjusted to safe defaults" warning —
configured nothing. An operator tightened the ladder, the page confirmed the save,
and every account kept locking on the shipped 3/5/7/10.

An unusable `loginlockoutsteps` (not JSON, empty, or no usable pair) falls back to
`DEFAULT_STEPS`, and a window outside 60–86400 falls back to 900 — never to no
lockout. A malformed setting must not be a way to switch brute-force protection
off, and a window of zero would reset the counter on every attempt.

#### Lifting a lockout while developing

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

#### Usage

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

#### API

| Method | Description |
|---|---|
| `recordFailedAttempt(string $scope, string $identifier): void` | Increment failure counter; apply threshold; creates row if absent |
| `getLockoutStatus(string $scope, string $identifier): array` | Returns `['locked' => bool, 'remaining' => int]`; 0 remaining when not locked |
| `clearSuccessfulLoginState(string $scope, string $identifier): void` | Deletes the tracking row — fully resets counter and lockout |

#### Constants

| Constant | Value |
|---|---|
| `Loginlockout::DEFAULT_WINDOW_SECONDS` | `900` |
| `Loginlockout::DEFAULT_STEPS` | `[3=>60, 5=>300, 7=>900, 10=>3600]` |

### Two-Factor Authentication (TOTP)

Two classes: `TOTPHelper` (pure static, no DB) and `TwoFactorAuthService` (stateful, DB-backed).

Compatible with Google Authenticator, Authy, and any RFC 6238 TOTP app.

#### TOTPHelper — setup and verification

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

#### TwoFactorAuthService — full setup flow

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
$svc->cleanupExpiredSessions();                               // removes used/expired setup rows
```

**Backup codes come from enrolment, not from setup.** `startSetup()` deliberately
returns none. It used to return a generated set, and the setup screen listed them under
"save these, they will not be shown again" — but `completeSetup()` generates and stores
its *own* set, so the codes on screen were dead before the user finished reading them.
The account's real recovery codes were known to nobody, and the user found out the first
time they lost their phone. `takeNewBackupCodes()` returns what enrolment stored, once
(it survives one redirect, in the session, and is cleared on read).

It is also the right moment to show them: somebody who abandons setup halfway should not
walk away holding recovery codes for an account with no second factor.

**The password is required, and the unchecked path has a name.** `disable()` and
`regenerateBackupCodes()` both take the account password and verify it. Both used to take
one parameter while the controller in front of them collected a password and passed it —
and PHP discards an extra argument to a userland function, so nothing was checked. Any
signed-in session could strip the second factor, or rotate the backup codes (which
invalidates every code the owner wrote down and prints ten new ones to whoever asked).

| Call | Meaning |
| --- | --- |
| `disable($userId, $password)` | the user's own action — refused on a wrong **or empty** password |
| `disableForOperator($userId)` | administrative — an operator clearing 2FA off an account whose owner cannot reach it |
| `regenerateBackupCodes($userId, $password)` | the user's own action |
| `regenerateBackupCodesForOperator($userId)` | administrative, and destructive: it invalidates every code the owner holds |

The password is **not optional**, deliberately. An optional parameter fixes the call site
that had the bug and leaves the hole open for the next one — omit the argument and the check
silently does not happen. A step-up check in front of *removing* the second factor is not
something to skip by accident, so skipping it has to be spelled. An empty string counts as
wrong rather than absent, so a form that submitted nothing does not pass.

Whoever exposes the `…ForOperator` methods decides who may call them; they are not the
user's own action, and the codes the second one returns have to reach the account's owner
rather than the operator.

**Replay protection:** `verifyCode()` compares the current 30-second window against `last_used`. If the same window was already used, the code is rejected even if cryptographically valid.

**Backup codes are one-time:** the matching hash is removed from storage after successful verification.

#### Database tables

| Table | Description |
|---|---|
| `user_twofactor` | One row per user — enabled flag, secret, backup code hashes, last_used |
| `twofactor_setup` | Temporary setup sessions (15-min TTL) |
| `twofactor_attempts` | Append-only attempt log (TimescaleDB hypertable where available) |

### Password Security

```php
class SecureUser extends \Pramnos\User\User
{
    public function setPassword($plainPassword)
    {
        // Validate password strength
        if (!$this->isPasswordStrong($plainPassword)) {
            throw new \Exception('Password does not meet security requirements');
        }
        
        // Hash with current best practices
        $this->password = password_hash($plainPassword, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,
            'time_cost' => 4,
            'threads' => 3
        ]);
    }
    
    public function verifyPassword($plainPassword)
    {
        return password_verify($plainPassword, $this->password);
    }
    
    private function isPasswordStrong($password)
    {
        // Implement password strength requirements
        return strlen($password) >= 8 
            && preg_match('/[A-Z]/', $password)
            && preg_match('/[a-z]/', $password) 
            && preg_match('/[0-9]/', $password)
            && preg_match('/[^A-Za-z0-9]/', $password);
    }
}
```

### OAuth2 Scopes and Policy

`Pramnos\Auth\Scopes` — static scope registry for OAuth2 consent screens and validation.

```php
use Pramnos\Auth\Scopes;

// All scopes grouped by category (consent screen)
$grouped = Scopes::getScopes();
// ['Personal User Data' => ['profile' => [...], 'email' => [...]], ...]

// Flat scope → description map
$descriptions = Scopes::getScopeDescriptions();

// Scopes implicitly granted to all clients
$defaults = Scopes::getDefaultScopes(); // ['profile', 'email', 'user']

// Validate a scope string
[$hasInvalid, $invalidList] = Scopes::hasInvalidScopes('profile email unknown_scope');

// Resolve inherited scopes transitively
$resolved = Scopes::resolveInheritedScopes('system:notifications_write');
// ['system:notifications_read', 'system:notifications_write']
```

Standard scopes: `profile`, `email`, `phone`, `address`, `user`, `openid`, `offline_access`, `system:admin`, `system:audit_read`, `system:health`, `system:notifications_read/write`.

`Pramnos\Auth\OAuthPolicyHelper` — server-wide OAuth2 policy defaults.

```php
use Pramnos\Auth\OAuthPolicyHelper;

$methods = OAuthPolicyHelper::getDefaultAllowedAuthMethods();
// ['client_secret_basic', 'client_secret_post', 'private_key_jwt']

$grants = OAuthPolicyHelper::getDefaultAllowedGrantTypes();
// ['authorization_code', 'client_credentials', 'device_code', 'refresh_token', 'exchange_token']
// 'password' grant excluded (deprecated per RFC 9126 / OAuth 2.1)
```

### OAuth2 Integration

```php
// OAuth2 server capabilities are built into the API system
class OAuth2Controller extends \Pramnos\Application\Controller
{
    public function authorize()
    {
        // Handle OAuth2 authorization requests
        $clientId = $_GET['client_id'];
        $redirectUri = $_GET['redirect_uri'];
        $scope = $_GET['scope'] ?? 'read';
        
        // Validate client application
        $app = new \Pramnos\Application\Api\Apikey($clientId);
        if ($app->appid == 0 || $app->callback !== $redirectUri) {
            throw new \Exception('Invalid client application');
        }
        
        // If user is not logged in, redirect to login
        if (!\Pramnos\Http\Session::staticIsLogged()) {
            $this->redirect('/login?return_to=' . urlencode($_SERVER['REQUEST_URI']));
            return;
        }
        
        // Generate authorization code
        $user = \Pramnos\User\User::getCurrentUser();
        $authCode = bin2hex(random_bytes(32));
        
        $user->addToken('auth_code', $authCode, 'OAuth2 authorization code', null);
        
        // Redirect back to client with code
        $this->redirect($redirectUri . '?code=' . $authCode . '&state=' . ($_GET['state'] ?? ''));
    }
}
```

## Configuration

### Authentication Settings

```php
// In your application configuration
return [
    'authentication' => [
        'jwt_secret' => env('JWT_SECRET', 'your-secret-key'),
        'jwt_expiry' => env('JWT_EXPIRY', 3600), // 1 hour
        'session_timeout' => env('SESSION_TIMEOUT', 1800), // 30 minutes
        'remember_me_duration' => env('REMEMBER_ME_DURATION', 2592000), // 30 days
        'password_hash_algo' => PASSWORD_ARGON2ID,
        'require_email_verification' => env('REQUIRE_EMAIL_VERIFICATION', true),
        'enable_mfa' => env('ENABLE_MFA', false),
    ],
    
    'permissions' => [
        'cache_permissions' => env('CACHE_PERMISSIONS', true),
        'default_user_permissions' => [
            'profile' => ['read', 'update'],
            'content' => ['read']
        ]
    ]
];
```

### Database Setup

The authentication system requires several database tables. Use the framework's migration system to set them up:

```sql
-- Users table
CREATE TABLE `users` (
    `userid` int NOT NULL AUTO_INCREMENT,
    `username` varchar(255) NOT NULL,
    `email` varchar(255) NOT NULL,
    `password` varchar(255) NOT NULL,
    `firstname` varchar(255),
    `lastname` varchar(255),
    `status` tinyint DEFAULT 1,
    `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`userid`),
    UNIQUE KEY `username` (`username`),
    UNIQUE KEY `email` (`email`)
);

-- User tokens table
CREATE TABLE `usertokens` (
    `tokenid` int NOT NULL AUTO_INCREMENT,
    `userid` int NOT NULL,
    `tokentype` varchar(50) NOT NULL,
    `token` varchar(255) NOT NULL,
    `created` int NOT NULL,
    `lastused` int DEFAULT 0,
    `expires` int DEFAULT NULL,
    `status` tinyint DEFAULT 1,
    `notes` text,
    PRIMARY KEY (`tokenid`),
    KEY `userid` (`userid`),
    KEY `token` (`token`),
    FOREIGN KEY (`userid`) REFERENCES `users` (`userid`) ON DELETE CASCADE
);

```

> **Permissions are not in this list on purpose.** They live in
> `authserver.permissions`, created by the `auth` feature's migrations — there
> is nothing to create by hand. An older revision of this guide printed a
> `CREATE TABLE permissions` statement for a table no migration has ever
> created; if you built one from it, see
> [Legacy Permissions Migration](Pramnos_Legacy_Permissions_Migration.md) for
> the SQL that moves its rows across.

## Best Practices

### Security Guidelines

1. **Always use prepared statements** - The framework's `prepareQuery()` method prevents SQL injection
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
        \Pramnos\Logs\Logger::logWarning('Failed login attempt', [
            'username' => $username,
            'ip' => $_SERVER['REMOTE_ADDR'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'],
            'reason' => $response['message']
        ]);
        
        return ['status' => 401, 'message' => 'Authentication failed'];
    }
    
} catch (\Exception $e) {
    \Pramnos\Logs\Logger::logError('Authentication error', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
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