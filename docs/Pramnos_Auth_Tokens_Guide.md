---
use_cases:
  - Issuing and checking API tokens, and revoking them
  - Handing the signed-in browser user an API token
  - Using JWT tokens
  - Building a password-reset link that cannot be reused
  - Reading which URLs a token was used on
---

# Tokens: API tokens, JWT and password resets

Part of the [authentication guide](Pramnos_Authentication_Guide.md).

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

**OAuth2 access tokens get the same 60 seconds.** The resource server that
`OAuth2ServerFactory::createResourceServer()` builds accepts a token whose `iat` or `nbf`
is up to `OAuth2ServerFactory::CLOCK_LEEWAY_SECONDS` ahead of its own clock. Without that
allowance, a token is refused as "not yet valid" whenever the server that checks it is a
second behind the one that issued it. That happens with a resource server on another host,
or on the same host after its clock is corrected backwards, which a virtual machine's
clock routinely is. Expiry is checked with the same allowance.

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
can never sign the browser out.

**An API that genuinely needs both** — an authserver whose own web UI calls its
own endpoints — should use `UnifiedAuthMiddleware`, which accepts a Bearer token
*or* a session cookie **plus an `X-CSRF-Token` header**. The CSRF token is not
optional there: a cookie is sent by the browser automatically, so without it any
site could make authenticated calls on the user's behalf.

## The password-reset token

`Auth\Controllers\Account` stores it in `userdetails` rather than in a table of its own — the
same shape the new-device auth link uses, and for the same reason: it is the same kind of thing
with the same lifetime, and a second mechanism would be a second place to get the expiry check
wrong.

| | |
| --- | --- |
| Stored | `password_reset_hash` = `sha256(token)`, `password_reset_expires` = a unix time |
| Lifetime | one hour |
| Live tokens per account | **one** — `upsert` on `(userid, fieldname)`, so "send it again" replaces the previous link rather than adding a second |
| Cleared | when the password is actually changed, and when an expired one is resolved |

**Only the hash is stored.** A leaked `userdetails` row hands out no working links, which matters
more here than for most tokens: this one lets the holder *choose a new password*.

### Resolving a token does not spend it

Easy to assume otherwise, and the assumption would be the wrong design:

```php
$userId = $this->consumeResetToken($token);   // resolves — does not clear
// … validate the new password …
$this->updatePassword($userId, $new);
$this->clearResetToken($userId);              // spent here, after the change
```

`resetpassword` needs to know *whose* link it is before it can validate anything. Burning the
token at that point would mean somebody who mistypes their confirmation loses their only link and
has to start again from the forgot-password form — and the order matters the other way too: the
token is cleared **after** the password is written, so a failed write does not leave an account
with neither a password change nor a live link.

The single-use property is real. It lives in the flow, not in the lookup.

### The mail is in the recipient's language

Not the language of the request. The forgot-password form can be submitted by anybody from any
page — a Greek visitor asking for an English speaker's reset — and the person who reads the mail
is the account holder.

```php
\Pramnos\Translator\Language::using($account->language, fn () => $this->composeAndSend(…));
```

`Notifier` does this for every notification; this mail is composed by hand, so it asks for the
same thing itself. A language the installation has no catalogue for is not switched to and the
mail still goes: there being nothing to switch to is not a reason to withhold somebody's reset
link.

**A catalogue defines `$lang`; it does not `return` an array.**

```php
<?php
$lang['Password reset'] = 'Αλλαγή κωδικού';
```

`Language::load()` includes the file and then checks `isset($lang)` — so a `return [...]` file
loads without error, defines nothing, and `load()` answers false. Nothing raises, nothing is
logged, and every string renders as itself, which looks like a site written in English rather
than a catalogue that was never loaded.

### The forgot form answers the same either way

`forgotpassword()` renders the same "if that address has an account, we have sent a link"
whether or not one was found. Any difference — a different message, an error only one path can
produce, a redirect on one and a render on the other — turns the form into a way to ask the site
who its users are, one address at a time.

The difference is on the inside: a token is written and a mail composed for the address that
exists, and nothing happens for the one that does not.

Two refusals come before that, and neither issues anything:

- **the anti-CSRF token**, because this form mails an address the submitter chose. Without it, a
  page anybody writes can make a signed-out visitor's browser ask this site to send mail, as often
  as it likes;
- **the human check** (`auth.security.human_check`), for the same reason at scale — a form
  that mails a stranger on demand is the cheapest way to use a site to deliver unwanted mail. The
  typed address is kept in the re-rendered form: a refusal that also clears the field trains
  people to give up rather than to try again.

### A refusal must not spend the link

Every refusal in `resetpassword()` leaves the token usable, and that is a property worth stating
because two of them are easy to get wrong in opposite directions:

| What happened | The link afterwards |
| --- | --- |
| no anti-CSRF token | **still usable** — the token is not resolved before the check |
| password fails the policy | **still usable** — resolved, but only cleared on success |
| password changed | spent |
| link already spent, or invented | nothing to spend |

The failure mode of getting either wrong is quiet: the account holder is told "that link is not
valid", the link in their mailbox was valid a moment ago, and nothing in the message explains why
one mistyped confirmation cost them a second trip through the mailbox.

### Who cannot be sent one

`findUserIdByEmail()` answers null at or below userid 1 — 0 is anonymous and 1 is the system
user, which exists to own rows rather than to be signed into. A reset link for it would be a
password-choosing link for the account the framework itself acts as.

## Token Management

### User Tokens

The framework supports various token types for different purposes:

```php
$user = new \Pramnos\User\User(123);

// addToken($tokentype, $token, $notes = '', $parentToken = null, $expires = null)
// returns $user, so calls chain; read tokens back with getToken() / getAllTokens().

// Add authentication token
$user->addToken('auth', bin2hex(random_bytes(32)), 'API access token');

// Add Apple Push Notification token
$user->addToken('apns', $deviceToken, 'iPhone device');

// Add a token issued under a parent token
$user->addToken('access_token', $oauthToken, 'OAuth2 access', $parentToken);
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

That row carries an expiry — **30 days** by default, set at creation:

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

**And a login retires what it replaces, immediately** — a token superseded a month ago is
still a valid bearer credential until the cleanup reaches it, which is not good enough for
a credential that has been replaced. `createWebSessionToken()` retires two things before it
writes the new row:

- the token this request arrived with, from `$_SESSION['usertoken']`;
- every other live `web_session` token from **this same browser**, matched on the device
  fingerprint inside `deviceinfo`.

The fingerprint and nothing else. `deviceinfo` also carries the address the token was
issued from, and matching on the whole stored value would leave the older token valid after
a router reboot between two sessions — the dynamic-address problem that `SignInFingerprint`
exists to avoid. Tokens from *other*
devices are left alone: signing in on a laptop must not sign you out on a phone.

`loadByToken()` reads an expiry of 0 or NULL as "never". Without the expiry and the
cleanup, `usertokens` grows by a row per login for ever — and it is the table `tokenactions`
points a foreign key at.

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

Both matter to the DevPanel's slowest-endpoints report: it needs a duration on every row,
and it groups by endpoint, so an absolute URL with its query would make every call an
endpoint of its own.

#### `updateAction()` repairs a `tokenactions` older than the columns it writes

An installation whose `tokenactions` predates `return_status`, `execution_time_ms`, `return_data` and
`action_time` gets them added on the first API call after the upgrade, from inside the `catch` that
handled the failed `UPDATE`. Then the write is retried.

You need to do nothing. It is documented because of *when* it runs: once, on a production database,
during a request, in an error path — so it is worth knowing it exists, and it is tested on both
engines.

Two details, both of the kind only a real database reveals:

- **It asks the schema builder per column and per index.** `ADD COLUMN IF NOT EXISTS` is MariaDB,
  not MySQL — MySQL 8 rejects it as a syntax error, which inside this `catch` would fail the request,
  lose the audit row and leave the table unrepaired.
- **On PostgreSQL the sync trigger treats only `servertime > 0` as a real instant.** `servertime` is
  declared `DEFAULT 0`, so a row written without one arrives as a zero rather than a null; reading
  that as a time would stamp the row 1 January 1970, and «no time was given» would be
  indistinguishable from «this happened at the epoch».

The PostgreSQL branch also installs a `plpgsql` function and a `BEFORE INSERT OR UPDATE` trigger that
keep `servertime` and `action_time` in step **both** ways, so code that writes only one of them does not
leave the other stale. The order inside it is load-bearing: the back-fill from `servertime` has to
happen before `action_time` becomes `NOT NULL`, or the `ALTER` fails on every existing row — after three
other `ALTER`s have already gone through.

#### `deleteToken()` revokes one device, and the cascade is MySQL-only

```php
(new User($userid))->deleteToken($tokenid);
```

Status `2` with a `removedate`, not a deleted row — a revoked token is evidence. «This credential
stopped working at 14:12» is the answer to «was my account used after I signed out of that laptop», and
a deleted row cannot answer it.

The `WHERE` carries the **user id as well as the token id**, and that is not defensive tidying: token
ids are sequential integers that appear in URLs, so a method trusting the id alone would let any
authenticated request sign any account out, repeatedly, by counting.

**One difference between the engines, and it is deliberate rather than an oversight.** On MySQL the
revocation also takes any token whose `parentToken` is the one named; on PostgreSQL it takes only the
named token. `parentToken` is how a refreshed credential remembers the one it replaced, so cascading is
the behaviour you want — revoking what a device holds should take the refresh chain hanging off it, or
the device renews itself straight back in. If you rely on that, rely on it on MySQL only.

#### «Sign out everywhere else» keeps the credential you are holding

```php
$user->revokeOtherSessions(md5(session_id()));   // ends every other session and web_session token
```

`revokeOtherSessions(?string $keepSid = null): int` spares the caller **only when it is given the
current session's id** (`md5(session_id())`, as the `sessions` table stores it). With it, the
session row with that id is kept, and so is the `web_session` token in `$_SESSION['usertoken']` —
the session is the only place that knows which credential *this* request arrived with. **Called
with no argument it ends every session and revokes every `web_session` token of the account,
the caller's included.** It returns how many session rows it ended.

Passing the id is what a "sign out everywhere else" button needs: a button that signed the caller
out as well is the bug a user notices immediately — they press it, they are ejected, and the
natural conclusion is that the button is broken rather than that it worked.

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

### Scopes on a token, and the shape of the column

`$token->scope` is always a **list of strings**, whatever the row holds.

```php
$token = new \Pramnos\User\Token($tokenId);

if (in_array('mcp:db_read', $token->scope, true)) {
    // …
}
```

To write scopes, either set the property and `save()`, or issue the token in one call:

```php
$user->addScopedToken('access_token', $jwt, ['mcp', 'mcp:logs'], 'notes', $expires);
```

**Four shapes are in that column across installations:**

| Shape | Where it came from |
|---|---|
| `profile email` | [RFC 6749 §3.3](https://www.rfc-editor.org/rfc/rfc6749#section-3.3), and what `AccessTokenRepository` writes |
| `["profile","email"]` | a JSON list, from older `Token::save()` rows |
| `profile,email` | comma-separated, from older rows |
| `[profile email]` | brackets round a space list — a stringified array somewhere upstream |

The rule is asymmetric on purpose:

| | |
|---|---|
| Reading | accepts all four, permanently — those rows exist and nobody is migrating somebody else's database |
| Writing | emits the standard, space-separated form only |

`Token::parseScopes()` is the one parser, and it is public so that anything reading the
column directly uses the same one rather than writing a second:

```php
$scopes = \Pramnos\User\Token::parseScopes($row['scope']);
```

**Do not `explode(' ', …)` a raw column value.** That reads one of the four shapes, and
which one you get depends on how the row was written rather than on anything the caller
did. Every reader in the framework delegates to `parseScopes()`, so one installation gives
one answer whichever code path reaches the row first.

### Which values get the forgiving parse, and which do not

| | |
|---|---|
| A **column**, or a stored token's scope | `Token::parseScopes()` |
| An OAuth **request parameter** (`$_GET['scope']`, the token endpoint's `scope`) | space-delimited, as the RFC defines |

The forgiveness exists for rows already in the column. It is not a licence for a client to invent a syntax: a request is a live conversation with
somebody who can be told they are wrong, so `Scopes::hasInvalidScopes()` still reads the
standard form only and reports `profile,email` as one unknown scope.

A value that is not a scope list at all — `null`, an object, a bare number — parses to an
empty list rather than a list containing it. `json_decode('123')` is a valid document and
an `int`; a scalar where a list belongs would make `in_array($needed, $scopes, true)` a
`TypeError` on PHP 8 — a 500 where a scope check should be. An empty list makes it a plain
refusal.

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

### The key it signs with is the API's, not its own front controller's

`sURL` is `dirname(SCRIPT_NAME)`, so it is not one string: the API front controller
(`www/api/index.php`) sees `https://host/api/` and every other request sees `https://host/`.
The signing key is derived from it, so an exchange running on a session-authenticated MVC
route derived a **different key from the one the API verifies with** — every exchanged token
refused with `403 InvalidAccessToken`, which names neither the key nor the mistake.

`Api::baseUrl()` is the answer to that: the API's own base in every context — `sURL`
unchanged inside an API request, and `sURL` plus the API's directory anywhere else. Both
`SessionExchange` and `mcp:token` derive through it, so a token minted outside an API request
verifies inside one.

A project that serves its API from somewhere other than `www/api/` says so:

```php
// app/app.php
'api' => [
    'prefix'    => '/api/1.0',
    'directory' => 'api',   // where www/<directory>/index.php lives
],
```

The directory is deliberately not guessed from `prefix`: the prefix is a route namespace and
the directory is a place on disk, and a project is free to make them differ.

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
only kind that can call this — has an `Application`, so reading the property alone would
find nothing.

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
can say where the credential came from.

**The activity-log entry is written once per session, not once per exchange.** Every other
entry in that log is a decision somebody made — signed in, changed a password, added a
passkey — and each occurrence is worth a row. An exchange is what happens when a page opens
without a token, so a new tab, a hard refresh, a private window and an expiry would each
produce one, and the entries a person can act on would drown in them. What is audit-worthy is that *this
session* obtained an API credential, and that is one fact however often the page asks again.

**Every issued token carries a `jti`.** The other claims — `iss`, `aud`, `iat`, `nbf`, `exp`
— name no user and are identical for anything minted in the same second, so without it two
tokens issued in the same second would be the same string, for two different people as readily
as for one. `usertokens.token_lookup` is unique, so the second insert would fail and that
sign-in would do nothing.
Nothing verifies the `jti`; a token is still resolved to its user by its `usertokens` row.

### The one decision that stays yours

An SPA that bounces to the exchange route when it has no token **must record that it has
bounced before redirecting**, not after. The route redirects back without a fragment when
it cannot help, so a flag written afterwards is an infinite bounce — on the one page an
operator opens when something is already wrong.

And clear the fragment once adopted (`history.replaceState`), or the token survives in
browser history and in whatever a visitor pastes when asking for help.

## The URL registry behind token actions

Every token action records *which URL* the token was used on, and `urls` is a deduplicated registry
— one row per endpoint, not one per call. `Token::urlId()` resolves a URL to its id, inserting the
row the first time that URL is ever seen on the installation.

It is called from the spool drain, which is a long-running process, so the cache in front of it is
the design rather than an optimisation: a site serves a few hundred distinct URLs, a worker learns
them in its first minutes, and after that every action resolves from memory.

Three properties worth knowing when reading a report built on this table:

- **The cache is bounded at `Token::URL_CACHE_LIMIT` (2,000), and drops its oldest half when it
  fills.** A site that puts an id in the path or a search term in the query string generates URLs
  without limit, and a process that never restarts would otherwise grow the cache without limit
  too. The half that survives is the most recently used, because on a worker those are the URLs it
  is currently busy with — clearing everything would make the next minute of work re-resolve exactly
  what it had just learned.
- **The cache is per process; the registry is not.** A second worker resolving a URL the first one
  registered finds the existing row rather than inserting a duplicate, so ids are stable across
  processes and a report can group on them.
- **A URL longer than the column is kept cut.** `urls.url` holds 255 characters, and a call with a
  list of ids in its path can be longer. The row stores the first 255; the lookup is by the hash of
  the *whole* URL, so it is still one row per URL and found again by the next call.
- **An unreachable registry resolves to `0`.** `urlId()` itself never throws. Where
  `tokenactions.urlid` carries its foreign key to `urls`, an action row with `0` cannot be written
  either, so the spool keeps the batch and retries it — which is what a registry that is down for a
  moment needs.

A consequence for anyone reading `urls`: a query string is **not** part of the key. The registry is
per endpoint, so `/orders?id=1` and `/orders?id=2` are one row. That is deliberate — the alternative
gave a busy page a new row on every call — and it means a report cannot tell those two apart from
this table alone.
