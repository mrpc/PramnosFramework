---
use_cases:
  - Letting a user connect their Google, Meta or TikTok account to this application
  - Calling a third-party API on a user's behalf and keeping the token alive
  - Diagnosing a connection that stopped working or a feed that went quiet
  - Configuring a provider whose OAuth2 differs from the specification
  - Storing third-party access and refresh tokens safely
---

# OAuth2 Client — signing in to somebody else's platform

This is the **client** half of OAuth2: the application asks a user to authorise it against
Google, Meta, TikTok or an internal identity provider, and then holds a token it can use on
that user's behalf.

It is the opposite direction from the rest of `Pramnos\Auth\OAuth2`, which is the **server**
half — `league/oauth2-server` issuing tokens to *other* applications, with `usertokens`
recording what it has issued. The two share a namespace and nothing else. If you are
building something that other applications sign in to, you want
[Third-Party Integration](Pramnos_AuthServer_Integration_Guide.md); if you are the one
signing in, you are in the right place.

## The shape of it

Four pieces, and the split between the first two is the important one:

| Class | What it does |
|---|---|
| `Provider` | One platform, as configuration: endpoints, credentials, scopes, and the handful of places it is not quite OAuth2 |
| `OAuthClient` | The conversation. Builds the authorize URL, exchanges a code, exchanges a refresh token. **No database, no session** |
| `Connection` | One stored authorisation: this user, this provider, this account, these tokens — and whether it is still usable |
| `ConnectionStore` | Where connections live, and the one place a refresh is written down |

`OAuthClient` holds no state because it is the half where a mistake is a security bug
rather than a bug, and it is much easier to be sure of a class that reads nothing and
writes nothing. Everything that persists is the store's.

## Connecting an account

Two controller actions: one that sends the user away, one that receives them back.

```php
use Pramnos\Auth\OAuth2\Client\OAuthClient;
use Pramnos\Auth\OAuth2\Client\ConnectionStore;

public function connect()
{
    [$url, $state, $verifier] = (new OAuthClient($this->google()))->authorizationUrl();

    // Keep both until the user returns. The session is the usual place.
    $_SESSION['oauth_state']    = $state;
    $_SESSION['oauth_verifier'] = $verifier;

    $this->redirect($url);
}

public function callback()
{
    $client = new OAuthClient($this->google());

    // Before anything else, and never skipped.
    if (!$client->verifyState($_SESSION['oauth_state'] ?? '', $_REQUEST['state'] ?? '')) {
        throw new \RuntimeException('That sign-in did not start here.');
    }
    unset($_SESSION['oauth_state']);

    $tokens = $client->exchange($_REQUEST['code'], $_SESSION['oauth_verifier'] ?? '');

    (new ConnectionStore())->save(
        $this->application->currentUser->userid,
        'google',
        $tokens,
        accountId: $profile['id'],
        accountName: $profile['name'],
    );
}
```

**The `state` check is not decoration and it is not optional.** Without it, anyone can hand
a signed-in user a link that completes *the attacker's* authorization flow, and the
application attaches the attacker's account to the victim's user. Every step succeeds, so
there is nothing in any log. `verifyState()` also refuses an empty expectation, so a flow
whose stored state has been lost — an expired session, a different browser, a callback that
arrived twice — fails rather than comparing `'' === ''` and passing.

`accountId` matters more than it looks. It is part of the unique key, because one user may
connect two pages, two channels or two advertising accounts on one platform; a key of
(user, provider) silently loses the first the moment the second is connected.

## Using a connection

```php
$store      = new ConnectionStore();
$connection = $store->find($userId, 'google');

if ($connection === null || $connection->isDead()) {
    // Never connected, or disconnected. Different sentences to show a user.
}

if ($connection->needsRefresh()) {
    $connection = $store->refresh($connection, new OAuthClient($this->google()));
}

$response = \Pramnos\Http\Client::get('https://www.googleapis.com/…')
    ->bearerToken($connection->accessToken)
    ->send();
```

`refresh()` is on the store rather than on the client because a refresh is a request **and**
a write, and separating them produces a specific bug: the provider rotates the refresh
token, the response is used, the row is not updated, and the next refresh presents a token
the provider has already retired. One call, one write.

## Keeping connections alive

```php
\Pramnos\Scheduling\Scheduler::command('oauth:refresh')->everyFifteenMinutes();
```

**Refreshing when a token is used keeps a busy connection alive and does nothing at all for
an idle one** — and an idle connection is the one that dies, because several providers
expire the *refresh* token if it is never exercised. Instagram's long-lived token lasts
sixty days. A connection nothing called for sixty-one days is gone, and the first anybody
hears of it is a feed that has been empty for a while, which looks like a quiet month.

The command needs to know your providers, which the framework cannot:

```php
class OauthRefresh extends \Pramnos\Console\Commands\OauthRefresh
{
    protected function providers(): array
    {
        return ['google' => $this->google(), 'instagram' => $this->instagram()];
    }
}
```

A connection whose provider is not in that list is **skipped and counted**, never marked
dead: a provider missing from the configuration is a deployment that has not finished, and
killing live connections over it would turn an unset environment variable into every user
having to authorise again.

`oauth:refresh --dry-run` lists what it would touch. `--provider=google` narrows it.

## When a connection dies

A refresh can fail two ways, and they need different people:

- **Terminal** — `invalid_grant` and friends. The user changed their password, removed the
  application, or the refresh token expired. The store marks the connection `dead` with the
  provider's own reason and the time, and it drops out of the scheduled job.
- **Transient** — a timeout, a 503. The connection is left alone and the next run tries again.

Getting that distinction backwards is expensive in both directions: treating a 503 as
terminal makes every affected user re-authorise over somebody else's bad afternoon, and
treating a revoked grant as transient retries it every quarter of an hour for ever until
the provider rate-limits your live traffic too.

**A dead connection is kept, not deleted.** The row is the evidence of what stopped working
and when, and without it an interface cannot tell "never connected" from "disconnected in
July".

## `APP_KEY` is required

Tokens are encrypted at rest through `Security\Encrypter`, and this subsystem **refuses to
store one without a key**:

```
Refusing to store a third-party OAuth token without APP_KEY — it is a credential for an
account this application does not own. Run: php pramnos key:generate
```

Other callers of the Encrypter are offered `isAvailable()` so they can degrade to plaintext
with a warning. This one does not take that option. A third-party refresh token is a
long-lived credential for an account on somebody else's service, held on behalf of a user
who authorised an application rather than a database — a dump that leaks one is a breach of
an account nobody here owns and nobody here can revoke.

Check `Encrypter::isAvailable()` before offering the connect button if you would rather
find out early than after the user has completed an authorisation they will have to repeat.

## Providers that are not quite OAuth2

Every large platform deviates somewhere, and the deviations are almost all *data*. They are
options on `Provider` rather than subclasses:

| Option | Why it exists |
|---|---|
| `authMethod: Provider::AUTH_BODY` | Credentials as form fields instead of a `Basic` header. Several providers accept only this, and answer a `Basic` header with `invalid_client` — which reads as a wrong secret rather than a correct one in the wrong place |
| `clientIdParam: 'client_key'` | TikTok's name for `client_id`. Used in the authorize URL and the token request alike |
| `scopeSeparator: ','` | The specification says space; a handful use commas |
| `refreshUrl`, `refreshMethod: 'GET'`, `refreshGrantType: 'ig_refresh_token'` | Instagram refreshes with a GET to a different endpoint under a different grant name — three deviations at once |
| `usePkce: true` | Sends an S256 challenge. Required by some providers, harmless everywhere else |
| `authorizeParams`, `tokenParams` | Anything else the provider wants — Google's `access_type=offline`, `prompt=consent` |

**What is deliberately absent.** Some providers have a second *step* rather than a different
parameter — Instagram exchanges the short-lived token the code flow returns for a long-lived
one, at another endpoint with another grant. That is not a setting, and inventing an
abstraction for it against providers this framework cannot test would be guessing at the
shape. Perform that exchange in your application and store the result through
`ConnectionStore::save()`.

## Two notes on the edges

**A provider on a private address works.** `Security\OutboundUrl` — the guard that refuses
URLs resolving inside your network — is deliberately **not** applied to a token endpoint.
That guard is for a URL a visitor supplied; a token endpoint is configuration, and an
identity provider on a private address is an ordinary arrangement: Keycloak on an internal
host, an enterprise directory behind a private endpoint, a staging provider on a VPN.

The case where the guard *would* be right is an application that lets an untrusted party
configure a provider — a tenant bringing its own identity provider. Then the URL is input,
and your application validates it before constructing the `Provider`, because that is where
it knows the URL is untrusted and the framework does not.

**The session holds a half-finished flow.** `state` and the PKCE verifier have to survive a
redirect to the provider and back, which for a browser means the session. A flow begun in
one context and finished in another — a different browser, a session that expired — fails
the state check, which is the correct outcome rather than a bug to work around.

## See also

- [Third-Party Integration](Pramnos_AuthServer_Integration_Guide.md) — the server half, for
  applications that other applications sign in to
- [HTTP Client](Pramnos_Http_Client_Guide.md) — what the token exchange runs on, and what to
  call an API with
- [Workers & Daemons](Pramnos_Workers_And_Daemons_Guide.md) — running the scheduler that
  drives `oauth:refresh`
