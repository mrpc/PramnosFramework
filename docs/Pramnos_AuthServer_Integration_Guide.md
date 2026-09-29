---
use_cases:
  - Connecting a third-party application to the auth server
  - Implementing an OAuth2 Authorization Code + PKCE flow against it
  - Registering a client's redirect URI, or debugging one the server refuses
  - Deciding whether a client is confidential or public, and why a public one needs a redirect URI
  - Finding out why the server refused a token or authorization request
  - Reading a user's permissions from another application
  - Reacting to instant invalidation webhooks
  - Letting webhooks reach a relying party on a VPN or private network
  - Seeing, adding or removing an application's webhook endpoints as an administrator
  - Letting a client (an MCP assistant) register itself with dynamic client registration
---

# Third-Party Integration Guide (Auth Server)

## Overview

This guide is for **developers of an external application** ("the client") that
needs to authenticate users against, and read authorization data from, an
authorization server built on Pramnos Framework. It covers the full lifecycle:
discovery → registration → the OAuth2/OIDC login flow → reading permissions →
declaring your capabilities → cache invalidation.

The design follows an **Atlassian-style** model:

- **Runtime is standard OAuth2 / OIDC.** You get lightweight, audience-scoped
  tokens carrying **identity only** — never permissions (so tokens stay small
  and change instantly).
- **Authorization is fetched, then cached.** Your app reads a user's effective
  permissions from a server-to-server endpoint and caches them, invalidating on
  a webhook.

> Endpoints below use the paths the server advertises by default. **Always read
> the discovery document first** rather than hard-coding paths.

---

## 1. Discovery

Fetch the server's metadata:

```
GET /.well-known/openid-configuration
GET /.well-known/oauth-authorization-server
GET /.well-known/oauth-protected-resource
```

### The two halves of discovery

The first two say where the **authorization server** is. The third — RFC 9728, Protected Resource
Metadata — says where the **resource** is and which authorization servers it trusts:

```json
{
  "resource": "https://example.com",
  "authorization_servers": ["https://example.com"],
  "scopes_supported": ["profile", "email", "phone", "address", "user", "openid", "offline_access"],
  "bearer_methods_supported": ["header"],
  "resource_documentation": "https://example.com/docs"
}
```

Both halves exist so a client can start from either end and find the other. With only the first, a
client has to be told the rest out of band — configuration somebody types into a file, gets wrong,
and has no way to verify.

**It is also where an MCP client starts.** The Model Context Protocol's authorization flow is: call
a protected endpoint, be refused with `401` and a `WWW-Authenticate` header naming this document,
read it, find the authorization server, then run the ordinary authorization-code-with-PKCE exchange
in §3. See the [MCP guide](Pramnos_MCP_Guide.md#connecting-claudeai-chatgpt-or-any-remote-mcp-client).

**One endpoint has a document of its own.** RFC 9728 §3.1 appends a resource's path to the
well-known one: `/.well-known/oauth-protected-resource/api/1.0/mcp` answers with
`"resource": "https://example.com/api/1.0/mcp"`. That is the form the MCP endpoint's `401` points
at, because a client checks that `resource` is the URL it started from. A suffix that is not a
plain path answers `404`.

Three things about that document are deliberate. `scopes_supported` is read from the server's own
scope table rather than written out, so it cannot drift from what the server will actually grant.
`bearer_methods_supported` is `["header"]` and only that — RFC 6750 also permits a token in a form
body and a query string, and the query form puts a credential in every access log and `Referer`
between you and here. And the identifiers carry **no trailing slash**, because `resource` is
compared as a string when a token's audience is checked, and `https://example.com` and
`https://example.com/` are the same address and different strings.


The document lists the real endpoints, e.g.:

| Key | Default |
|-----|---------|
| `authorization_endpoint` | `/oauth/authorize` |
| `token_endpoint` | `/oauth/token` |
| `userinfo_endpoint` | `/oauth/userinfo` |
| `device_authorization_endpoint` | `/oauth/deviceauthorization` |
| `registration_endpoint` | `/oauth/register` — only when dynamic registration is on |
| `jwks_uri` | `/.well-known/jwks.json` |

`code_challenge_methods_supported` and `token_endpoint_auth_methods_supported` (which includes
`none`, a public client) are in both documents; a public client reads them before it starts.

Validate ID tokens against the keys in `jwks_uri`.

### If discovery answers 404

These paths are fixed by specification, so they cannot be reached through the
framework's `controller/action` URL shape — the web server has to be told about
them. `init` writes the rules when the `authserver` feature is enabled:

```apache
RewriteRule ^\.well-known/openid-configuration$ index.php?r=Discovery/configuration [L]
RewriteRule ^\.well-known/openid_configuration$ index.php?r=Discovery/configuration [L]
RewriteRule ^\.well-known/jwks\.json$          index.php?r=Discovery/jwks [L]
RewriteRule ^\.well-known/oauth-authorization-server$ index.php?r=Discovery/oauth2Metadata [L]
RewriteRule ^\.well-known/oauth-protected-resource$ index.php?r=Discovery/oauthProtectedResource [L]
RewriteRule ^\.well-known/oauth-protected-resource/(.+)$ index.php?r=Discovery/oauthProtectedResource&resource_path=$1 [L]
RewriteRule ^\.well-known/health$               index.php?r=Discovery/health [L]
```

Two things about that block are worth knowing before you edit it.

**Order matters.** The catch-all rule below them matches every path, and
`mod_rewrite` runs rules in order — a discovery rule moved beneath the catch-all
never fires. On a SPA project the failure is worse than a 404: the shell
fallback answers with the application's HTML and a 200, so a client sees
malformed JSON rather than a missing endpoint.

**The underscore spelling is deliberate.** `openid_configuration` appears in no
specification and in a good number of clients. Answering it costs one line.

A project scaffolded before these rules existed keeps its own `.htaccess` —
version control does not update it for you. Add the block by hand, above the
catch-all.

### If a discovery response will not parse

Before 2026-08-26 it would not. Every endpoint here answered with valid JSON and
then a complete HTML page appended to it:

```
$ curl -s https://auth.example.com/.well-known/openid-configuration | wc -c
173927
```

The actions `echo`ed their body and returned. An echo writes to the output stream
and leaves the framework to render the page it was going to render anyway, so the
response was the document *and* the site's home page. `JSON.parse` fails on it;
`curl | head` does not, which is why it survived.

Fixed by answering with the framework's `raw` document instead of echoing, so the
body is the JSON and nothing follows it. All six were affected —
`openid-configuration`, `jwks.json`, `oauth-authorization-server`,
`.well-known/health`, `/Discovery/serverConfig` and the project-level `/config`.

Nothing to change in a client. If you built a workaround — reading up to the first
`}` at column 0, or a regex — you can drop it, and you should: the shape it relies
on is no longer there.

### A summary built for a person

The two documents above are built for a client library. When you want the one a
developer reads while integrating — the URLs, the grants that work here, the
scopes that exist, whether the device flow is on — ask for:

```
GET /Discovery/serverConfig
```

It is **not** a standards document and a client should not depend on it; read
`/.well-known/openid-configuration` for that. This is the page to paste into a
ticket. Every list in it is read from whatever actually decides it, so it cannot
drift out of agreement with the server the way a hand-written integration note
does.

### Client credentials, and the account behind the token

A `client_credentials` token has no end user — it represents your application. The
server still needs an account to hang it on, because `usertokens.userid` is a
foreign key, so each application gets one **system account**, created on first use
and reused afterwards.

You never see it directly, but it explains two things you will see:

```
POST /oauth/introspect → { "active": true, "sub": "4", "username": "sys_3a5c9a25…" }
```

`sub` is that account, not a person, and `username` is a generated `sys_*` name.
It is `usertype` 1 — below every administrative threshold — so a token issued to an
application can never be mistaken for one issued to an operator.

And the account is never `userid` 0 or 1. Those are the framework's guest and system
rows, so an application whose `systemuser` column holds either has a *gap* rather than
an account — and a token stored under one of them would sit beneath an identity shared
with every other application in the same state, which makes "what has this account been
doing" unanswerable. The check is `> 1`, in both places that could produce such an id:
the column when it is read, and the row creation when it answers.

Every refusal along the way returns no account rather than raising, and the token insert
then fails on its foreign key. That is deliberate: **a token for a client that cannot be
resolved is not stored under a user invented for it.** If a `client_credentials` grant
answers with a server error rather than a token, this is one of the things to check —
the log carries `Could not create a system user for application <id>` or `Could not
resolve a system user for client <id>`.

A client with a registered `public_key` can authenticate its `client_credentials` request with
a signed assertion instead of a secret (`client_assertion_type =
urn:ietf:params:oauth:client-assertion-type:jwt-bearer`, RFC 7523 §2.2). The token it receives
is signed RS256 with the server's own key — the one `jwks_uri` publishes. A server with no
signing key answers `server_error` and issues nothing.

If you need a token that acts *as* a particular person without that person signing
in, that is the JWT bearer grant (RFC 7523 §2.1) rather than this one — it must be
enabled per client, because its holder can obtain a token for any user.

### Signing out

Two endpoints, because there are two situations.

```
POST /oauth/logout      Authorization: Bearer <token>     → JSON
GET  /login/logout                                        → redirect
```

**`/oauth/logout`** is for your backend. It revokes the **token family**: the
access token you present and the refresh token issued with it, linked through
`usertokens.parentToken`. A token issued to another device belongs to another
family and is untouched — that is what separates this from "sign out of
everything".

```
POST /oauth/logout
Authorization: Bearer <access_token>

logoutwebsession=1        # optional — end the browser session as well

{ "success": true, "user_id": 42, "tokens_revoked": 2 }
```

Without `logoutwebsession=1` the browser session is left alone. That is usually
what a backend wants and rarely what a "sign out everywhere" button wants.

An unknown token still answers `{"success": true}`, in the spirit of RFC 7009: an
endpoint that distinguished a real token from an invented one would tell an
attacker which of their guesses exist.

**`/login/logout`** is for a browser. It reads the session cookie, needs no
header, and redirects afterwards. `?local=1` clears the session and leaves the
tokens valid — for "sign out of this browser" without breaking a running mobile
app.

### Is the server up?

```
GET /.well-known/health
```

```json
{
  "status": "healthy",
  "timestamp": "2026-08-25T14:46:08+00:00",
  "components": { "database": "ok", "signing_keys": "ok", "session": "ok" }
}
```

`503` when anything is wrong. The `components` map lists every check the server
has registered — including `signing_keys`, which is the one that catches a server
answering pages normally and refusing every token. See
[Health checks](Pramnos_Health_Guide.md) for what each check means and how to add
one.

### If a bearer token reads as no token

Apache does not hand the `Authorization` header to PHP-FPM or CGI unless it is
copied into the environment first:

```apache
RewriteCond %{HTTP:Authorization} .
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

`init` writes this for **every** project, not only authorization servers — any
REST API authenticated with `Authorization: Bearer …` needs it. Without it the
request arrives anonymous, which reads as a rejected credential; the time then
goes into the token, and the token was never the problem.

---

### Which scopes you may ask for

The list in `scopes_supported` is the list. Ask for one that is not in it and the
token endpoint answers `invalid_scope`:

```json
{
  "error": "invalid_scope",
  "error_description": "The requested scope is invalid, unknown, or malformed",
  "hint": "Check the `profile` scope"
}
```

> **Before 2026-08-26 that happened for scopes that *were* in it.** The token
> endpoint validated against four identifiers of its own — `read`, `write`,
> `admin`, `user` — while discovery published the framework's scope registry. Of
> twelve advertised scopes, eleven were refused, `openid` among them: OpenID
> Connect could not be used at all against a server whose own discovery document
> said it could. Both sides read from the registry now, and the four older
> identifiers are still accepted.

## 2. Registering your application

An administrator registers your application on the server and gives you a
**client_id** and **client_secret**, plus your registered **redirect URI(s)**. A public
client can instead register itself, where the server allows it — see
[Dynamic client registration](#dynamic-client-registration-rfc-7591).

Applications marked **trusted** (internal/first-party) skip the user consent
screen; untrusted (third-party) applications always show consent and receive
only the scopes the user approves.

### When a redirect URI is required

**Short version:** a client that runs on a server you control and keeps its secret there may
go without a registered redirect URI. Every other client — a browser app, a mobile app, a
desktop app, or any client with no secret stored — **must** have one, and until it does the
authorization endpoint refuses every sign-in for it. The admin screens warn about such a client
on its page and on its edit form.

#### Confidential and public, in practice

The **Client Type** switch on the application's edit form (`applications.is_confidential`)
records one fact: *can this client keep a secret?*

| | Confidential | Public |
|---|---|---|
| Where the client's code runs | A server you control | The user's device: a browser (SPA), a phone, a desktop |
| Who can read its client secret | Only you | Every user of the app — it is inside what you shipped |
| What the secret proves at `/oauth/token` | That the caller is the real client | Nothing: anybody who unpacked the app has it |
| What ties an authorization code to the client | The secret | The registered redirect URI, and PKCE |
| `client_credentials` grant | Allowed | Refused by the token endpoint |
| Registered redirect URI | Optional, recommended | **Required** |

When in doubt, ask where the code that calls `/oauth/token` runs. If it is on a machine the
user holds, the client is public, whatever else is true of it.

#### Which clients the server treats as unable to keep a secret

Two conditions, and either is enough:

1. **Client Type is public** (`is_confidential = 0`). The admin screen still issues such a
   client a secret when it is created, and the token endpoint still asks for it — but a
   secret shipped inside an app is known to everybody who has the app, so it protects nothing
   and the server does not count it.
2. **No secret is stored** (`apisecret` empty or NULL). The token endpoint then accepts a
   request that presents no secret at all, whatever Client Type says. Clients created through
   [dynamic client registration](#dynamic-client-registration-rfc-7591) are like this, and so
   are rows written before secrets existed.

`Pramnos\Auth\Application::cannotKeepASecret($row)` answers this question, and
`needsARegisteredCallback($row)` adds "…and has no usable registered redirect URI" — the exact
condition the authorization endpoint refuses and the admin screens warn about. A `callback`
holding only separators, or only a refused scheme such as `javascript:`, counts as none.

#### Why the redirect URI is what matters

An authorization code is delivered to the `redirect_uri` named in the request, and whoever holds
the code can exchange it for a token. For a confidential client the exchange needs the secret,
so a code that reaches the wrong address is useless. For a client that cannot keep a secret,
nothing at the token endpoint tells the real client from anybody else:

1. Somebody builds an `/oauth/authorize` link with the client's `client_id` — a public
   identifier, visible in every copy of the app — and a `redirect_uri` of their own.
2. A user who is signed in, and who has approved this app before, opens it. The consent
   screen is skipped, because the app is already approved.
3. The code goes to the address in the link, and its holder exchanges it with no secret, or
   with the one every user of the app has. They now hold a token for that user.

PKCE does not prevent this: whoever builds the link chooses the challenge, and so holds the
verifier. **Exact matching against a registered redirect URI** is the only thing that stops
the code from leaving for an address the client does not own. That is why RFC 6749 §3.1.2.2
makes registration a *MUST* for public clients, and why this server enforces it.

#### What the refusal looks like

The person signing in sees an authorization error page:

> This application cannot sign anyone in yet: it has no client secret it can keep, so it needs
> a registered redirect URI. Ask the administrator to add one.

`oauth.log` records it with the client id and the requested address:

```
refused: client cannot keep a secret and has no registered redirect URI | endpoint=authorize client_id=… redirect_uri=… ip=…
```

Nothing is issued and the `form-action` policy is not widened toward the requested address.

#### Fixing it

Open the application in the admin area and, on the **OAuth2** tab, enter every address the
application really receives its code at under **OAuth2 Redirect URI(s)** — one per line, each
exactly as the application sends it (see the matching rules below). The warning disappears once
there is one.

If the client is in fact a server-side application that keeps its secret, tick **Client Type**
instead. That is only correct if the secret never leaves your servers.

### Register your redirect URI — optional for a confidential client, and matched exactly

For a client that cannot keep a secret, registration is required — see
[When a redirect URI is required](#when-a-redirect-uri-is-required). For a **confidential**
client with a secret, registering its callback (`applications.callback`) is **optional and
strongly recommended**, the way it is on every large authorization server. It is what buys you
two things:

- `/oauth/authorize` **refuses any `redirect_uri` that is not on the list** — RFC 6749
  §3.1.2, and the reason the RFC asks for it is that the destination is a URL an
  authorization **code** is delivered to.
- The server widens its `form-action` policy for that origin, so a login that goes through
  the sign-in form completes (see the note below).

A confidential client with a secret and nothing registered keeps working, and **still gets
the `form-action` widening**: the endpoint accepted its `redirect_uri`, so the policy says so.
The condition is recorded in `oauth.log` with the recommendation. For this client, whether to
register is the operator's call.

!!! warning "A CSP cannot enforce what the layer below it does not"
    The widening was briefly gated on the registration, on the reasoning that the two
    layers should agree — and it had the agreement backwards. `form-action` can only answer
    *may the form post toward the place this request names*, not *should this request be
    allowed*. Gating it refused destinations the endpoint had just accepted, which added no
    security and caused an outage: a customer's localhost login stopped working while
    production kept working, with nothing server-side to see because the refusal happens in
    the browser.

    It was not even the protection it looked like. `form-action` governs form submissions,
    so a user who already holds a session takes `GET /oauth/authorize` → 302 → client with
    no form in it, and the code is delivered regardless. **A mismatch is worth a log line,
    never a refusal, while the layer that could refuse does not.**

#### One registration, several environments

The same client is developed on `http://localhost:3000`, tested on a staging host and run
in production, and **all three belong to one registration**. A field read as a single URI
names one environment and refuses the others — which is exactly what happened: one
production value in the column, and the customer's localhost login stopped working.

So `callback` holds a list, and the separator is permissive because a human types this
field. All of these are the same three registrations:

```
http://localhost:3000/callback https://staging.app.example/cb https://app.example/cb
http://localhost:3000/callback,https://staging.app.example/cb,https://app.example/cb
http://localhost:3000/callback
https://staging.app.example/cb
https://app.example/cb
```

Commas, spaces, tabs and newlines all separate; the admin screen's field is a textarea and
what it stores is normalised to one space-separated list. A single value is a list of one,
so nothing registered before reads differently.

**A comma inside a URI must be percent-encoded** as `%2C`, since a comma separates.

**A scheme that is only ever script is refused** — `javascript:`, `data:`, `vbscript:`,
`file:`, `blob:`, `about:`. The scheme is otherwise unrestricted, because
`myapp://oauth/callback` is a real registration for a native client; what is refused is the
set that has no other use. `javascript://x/%0aalert(1)` satisfies every structural rule a
URL parser applies, and this value reaches a `Location` header and a CSP `form-action`
source. The admin screen says so when you paste one; the endpoint refuses it on the request
as well, because a client with nothing registered has no registration to check.

!!! note "An older installation may still have a 255-character ceiling"
    `create_applications_table` declares `text`, but on a database whose `applications`
    table predates the migration system that migration is `Skipped (cutoff)` and never
    applied it — the column is `varchar(255)`, and three long callbacks plus separators is
    about 190 characters.

    `2026_09_08_000001_widen_applications_callback` fixes it, and it has to be a framework
    migration: PostgreSQL refuses to alter a column two framework views select from
    (*«cannot alter type of a column used by a view or rule»*), so widening means dropping
    and rebuilding those views. The migration drops them with `CASCADE` and re-runs the
    views migration, so their definitions stay in one place. Until you run it, the admin
    screen refuses an overflow with a message naming the ceiling rather than letting the
    driver truncate.

#### Two ways to stop `form-action` cancelling an SSO login

Both work; they differ in who names the origin and how narrowly.

| | 1. Register the callback | 2. List it in `app.php` |
| --- | --- | --- |
| Where | `applications.callback` on the client | `csp: form-action` in `app/app.php` |
| Scope | That client, that origin, on the pages of the OAuth flow | Every page of the application |
| Effect | The server widens the policy itself, per request | The origin is in the header everywhere |
| Also gives you | Exact-match refusal of an unregistered `redirect_uri` | Nothing beyond the policy |
| Needs a deploy | No — it is a database row | Yes — it is configuration |
| Recommended | **Yes**, for an authorization server | When registration is not an option |

**1 — register the callback.** Nothing else to do; this is the route the log line
recommends.

```sql
UPDATE applications SET callback = 'https://client.example/login-sso' WHERE apikey = '…';
```

**2 — name the origins in the application's own policy**, which is what an application with
no access to its clients' registrations does:

```php
// app/app.php
'csp' => [
    'form-action' => [
        'https://client-one.example',
        'https://client-two.example',
    ],
],
```

Route 2 is deliberately available and is **not** a workaround: it is the same extension
point `img-src` and `connect-src` have, and an application is entitled to widen its own
policy. What it does not do is check anything — the origin is trusted because you wrote it
down, not because a client registration vouches for it. That is the whole difference, and
it is why route 1 is the recommendation for a server whose job is issuing authorization
codes.

Doing both is harmless. The server appends to the list `app.php` provides, and an entry
identical to one already there is not added twice.

The comparison is an **exact string match**, so register the URI your application will
actually send, character for character. All of these are different registrations:

```
https://app.example/callback
https://app.example/callback/          ← trailing slash
https://app.example/callback?x=1       ← query string
http://app.example/callback            ← scheme
https://app.example:443/callback       ← explicit default port
```

Exact is the point rather than an inconvenience: a prefix comparison accepts
`https://app.example.attacker.test/callback`, and a host comparison accepts any path on
your domain — including one that reflects the query somewhere else. Each near miss is a
published attack, and the destination is a URL an authorization **code** is delivered to.

Register several by storing a comma-separated list or a JSON array; any one of them is
accepted, exactly. A native application registers its custom scheme URI in full
(`myapp://oauth`). An empty `callback`, a lone comma and `[]` all mean *not registered*.

---

### Deleting a client revokes its tokens

`applications.appid` cascades to `usertokens.applicationid`. Delete an OAuth client and every token
it issued goes with it.

It used to be `ON DELETE SET NULL`, which left the tokens in place with a null `applicationid` —
indistinguishable from a token that was never issued through OAuth at all. Each one silently changed
category and carried on authenticating. On one installation, 507 of 522 tokens had a null
`applicationid` and thirteen of those were still active and unexpired: thirteen live credentials
belonging to clients that had been deleted.

Deleting a client is the one action an operator takes precisely to stop it having access, so that is
what it does now.

**Tokens already detached are left alone.** The old rule destroyed the reference rather than
recording it anywhere, so nothing separates "issued by a client that is gone" from "never an OAuth
token" — and a sweep would have to guess, which here means revoking working credentials.

## 3. Logging a user in — Authorization Code + PKCE

Use the Authorization Code flow with **PKCE** (recommended for all clients).

**Step 1 — redirect the user to the authorization endpoint:**

```
GET /oauth/authorize
    ?response_type=code
    &client_id=YOUR_CLIENT_ID
    &redirect_uri=https://yourapp.example.com/callback
    &scope=openid profile email
    &state=RANDOM_STATE
    &code_challenge=BASE64URL(SHA256(verifier))
    &code_challenge_method=S256
```

The user authenticates (password + optional 2FA/passkey) and, for untrusted
clients, approves the requested scopes. The server redirects back to your
`redirect_uri` with `?code=…&state=…`.

!!! note "The server widens its own `form-action` for you"
    A browser applies the `form-action` directive to **every redirect a form submission
    passes through**, and the last hop here is your origin. The authorization server adds
    your registered callback's origin to the policy of the pages whose forms start the
    chain — the login screen, the second-factor screen and the consent screen — after it
    has checked the `redirect_uri` against your registration. Nothing is required of you
    beyond having registered the callback, and there is nothing to configure on the server.

    **This is the practical reason to register.** With no `callback` on file the server has
    nothing to vouch for the destination, so it leaves `form-action 'self'` in place and a
    sign-in that goes through the login form is cancelled at the last hop.

    Worth knowing because of how it fails if it is ever missing: the code is issued and
    the `Location` header is sent, and the browser then cancels the navigation. The server
    logs a completed authorization, the user sees a page that did not move, and the
    console reports a violation against a **same-origin** URL.

**Step 2 — exchange the code for tokens:**

```
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code
&code=THE_CODE
&redirect_uri=https://yourapp.example.com/callback
&client_id=YOUR_CLIENT_ID
&client_secret=YOUR_CLIENT_SECRET
&code_verifier=THE_ORIGINAL_VERIFIER
```

You receive an `access_token` (and an `id_token` when `openid` was requested).
Fetch profile claims from `GET /oauth/userinfo` with the access token.

The same token authenticates calls to this installation's API — send it as
`Authorization: Bearer <access_token>` — and `GET /oauth/userinfo`. The server stores an access
token from `/oauth/token` by its identifier (the `jti` claim) rather than its full text, so both
look it up by the text first and then by the `jti`, which they read only after the token has been
verified: the API middlewares from the claims `JWT::decode()` checked, `/oauth/userinfo` from
League's resource server, which also checks expiry and revocation. A token whose signature does
not verify is never looked up by its `jti`.

### Binding the token to one resource

Add `resource` to the token request (RFC 8707) when the token is for one endpoint of this server
— an MCP client always does:

```
POST /oauth/token
grant_type=authorization_code&code=…&code_verifier=…&redirect_uri=…&client_id=…
&resource=https://example.com/api/1.0/mcp
```

The access token's `aud` becomes `["<client_id>", "https://example.com/api/1.0/mcp"]`, and an
endpoint that checks its audience — the MCP endpoint does — refuses a token bound to anything
else. The resource must be an absolute URI on this server's own origin with no fragment;
anything else is `400 invalid_target`, not silently ignored. Without `resource` the token is
bound to no resource and `aud` is the client id alone.

### Your client secret is required, on every grant

If a secret is registered for your client, the token endpoint will not
authenticate you without it. This holds for every grant — `authorization_code`,
`refresh_token`, `password` and `client_credentials` — and for both ways of
presenting it, HTTP Basic or form field. An empty `client_secret=` counts as
absent.

The `client_id` alone is never enough. It is a public identifier: it travels in
redirect URLs and ships inside every SPA bundle and mobile binary, so anything
it could unlock on its own would be unlocked for everybody who has ever seen a
login link.

### Copy it when you are given it

The secret is shown once — on the screen that creates the registration, and again
if you rotate it. It is stored hashed, the same way a password is, so nobody can
read it back to you afterwards: not the operator, not a support request, not a
database query. Lose it and the only route forward is rotating to a new one.

### Public clients

A **public** client is one that cannot keep a secret: a single-page app or a mobile
binary, where whatever you ship inside it every user of it has. Register it with
**Client Type** unticked, and **register its redirect URI** — without one it cannot sign
anyone in; [When a redirect URI is required](#when-a-redirect-uri-is-required) explains why.

The admin screen issues every new client a secret, public or not, and the token endpoint asks
for whatever secret is stored. A client that must authenticate with no secret at all is one
whose `apisecret` is empty — which is what dynamic registration creates.

A public client authenticates its authorization code with **PKCE** instead, which is
why the `code_challenge` above is not optional advice. It cannot use
`client_credentials`, which authenticates the application itself and has nothing
but a secret to do it with — the token endpoint refuses that combination.

If your client runs on a server you control, leave it confidential. The secret is
worth having.

### Dynamic client registration (RFC 7591)

A client can register itself, with nobody on the server's side involved. It is how Claude.ai,
ChatGPT and other remote MCP clients connect: they register, then run §3 with PKCE, and the person
approves the consent screen. It is off unless the operator turns it on in `app/app.php`:

```php
'oauth_dynamic_registration' => true,
```

`init` writes that line for an `authserver` project. With it off, `/oauth/register` answers `404`
and the discovery documents publish no `registration_endpoint`.

```
POST /oauth/register
Content-Type: application/json

{
  "client_name": "Claude",
  "redirect_uris": ["https://claude.ai/api/mcp/auth_callback"],
  "grant_types": ["authorization_code", "refresh_token"],
  "response_types": ["code"],
  "token_endpoint_auth_method": "none"
}
```

The answer is `201` with the `client_id`, the metadata as registered, and no secret. What the
endpoint admits, and why:

| Field | Accepted | Why the limit |
|---|---|---|
| `token_endpoint_auth_method` | `none` only — the RFC's default, `client_secret_basic`, is refused | A confidential client could use `client_credentials`, which needs no person. Open registration would be open issuance of machine tokens |
| `grant_types` | `authorization_code`, `refresh_token` | The same reason; a public client cannot hold what the others need |
| `response_types` | `code` | The implicit flow puts the token in a URL |
| `redirect_uris` | One to five; `https`, or `http` on `localhost` / `127.0.0.1` / `[::1]`; no fragment, space or comma | A custom scheme proves nothing — any app on a phone can claim it. An operator can still register one by hand |
| `client_name` | Up to 100 characters; `Unnamed client` if absent | It is what the consent screen shows |

Refusals follow RFC 7591 §3.2.2: `400` with `invalid_client_metadata` or `invalid_redirect_uri`.
Each address may register twenty clients an hour; past that the answer is `429` with
`Retry-After`. Every registration is a line in `oauth.log`.

A registered client is an ordinary row in `applications` — untrusted, so consent is always shown,
public, and with no secret. An administrator sees it in the applications screen and deletes it the
same way as any other, which revokes its tokens.

### Lightweight tokens

Access/ID tokens carry **identity claims only** — user id, basic attributes,
audience, optionally roles. They **do not** carry permissions. Do not try to
derive what a user may do from the token; fetch it (next section) and cache it.

---

## When a sign-in fails: the decision log

`/oauth/authorize` and `/oauth/token` write what they decided to **`oauth.log`**, under
`LOG_PATH/logs`. Refusals are always written; the successful half is behind a setting.

```
token endpoint refused | endpoint=token status=400 grant_type=authorization_code
  client_id=3ad1d008… error=invalid_client error_description=Missing client_secret ip=…
authorize refused | endpoint=authorize error_description=The redirect_uri is not registered
  for this application. client_id=… redirect_uri=… ip=…
client has no registered redirect URI — recommended: set `applications.callback` to the exact
  URL this client sends… | endpoint=authorize client_id=… redirect_uri=… ip=…
consent form shown (no redirect) | endpoint=authorize client_id=… userid=… scope=…
denied by user | endpoint=authorize client_id=… userid=… scope=…
```

**No credential is ever written.** Not an authorization code, an access or refresh token, a
`client_secret` or a `code_verifier`: the file is readable by anyone with the server, and a
code in it is a code somebody can replay inside its lifetime. The line is built from a
fixed list of keys, so a call site cannot add one by accident. `client_id` and
`redirect_uri` are public by construction and already in the access log — they are what
makes a line worth reading.

### Turning the successful half on

An issued code or token is only logged when the `oauth_decision_log` setting is truthy
(`1`, `true`, `yes`, `on`):

| setting | what is written |
| --- | --- |
| absent / off (default) | refusals, consent shown, denials |
| on | the above, plus `code issued` and `token issued` |

The setting is read **forced on every request**, so it takes effect on the next request
rather than whenever a cache expires — a switch nobody can see take effect is a switch
nobody trusts. A scaffolded application already has a settings screen that edits the table
generically, so no interface work is needed to flip it.

Leave it off by default on purpose. `usertokens` already holds every issued row with its
timestamp, user, application and scope, and `user_activity_log` already holds
`application_authorized`; a user id and a destination per sign-in kept for ever is a
retention decision rather than a diagnostic. Turn it on for the afternoon a complaint is
open, and off again afterwards.

`oauth.log` is rotated automatically like every other log — see
[Logging](Pramnos_Logging_Guide.md#file-rotation).

## 4. Reading a user's permissions

Your application server (never the browser) calls the internal permissions
endpoint with its own **client credentials**, as HTTP Basic (RFC 6749 §2.3.1):

```
GET /api/internal/permissions?user_id={id}[&organization_id={id}]
Authorization: Basic base64({client_id}:{client_secret})
```

Response — the user's effective grants for your application (rows with
`app_id` = your application, or none):

```json
{
  "user_id": 42,
  "app_id": 7,
  "permissions": [
    { "object_type": "invoice", "object_id": "*", "action": "read",  "grant": "allow", "conditions": null },
    { "object_type": "invoice", "object_id": "*", "action": "write", "grant": "allow", "conditions": [{ "location_id": [1, 2] }] }
  ]
}
```

- `grant` is the resolved **allow/deny**: the higher priority decides, and a deny wins a tie.
- `conditions` is **ABAC** context your app evaluates against the current request — a list of
  predicates, any one of which is enough. `null` means unconditional.
- `organization_id` asks the tenant's question: a role that belongs to an organisation counts
  only within it, and only while the user is an active member. Without it every role the user
  holds counts. The response then carries `organization_id` too.
- A `client_id` parameter, when sent, must be your own; asking for another client's is `403`.

**Cache** this response per user. Do not call the endpoint on every request.

---

## 5. Declaring your capabilities (manifest)

So the server knows which resources/scopes and ABAC condition keys your app
understands, push a **capabilities manifest** (typically from CI/CD):

```
PUT /api/internal/clients/{client_id}/capabilities
Authorization: Bearer {client_credentials_access_token}
Content-Type: application/json

{
  "resources": {
    "invoices": {
      "description": "Customer invoices",
      "scopes": { "read": "View invoices", "write": "Edit invoices" }
    }
  },
  "conditions": {
    "location_id": { "value_type": "int[]", "description": "Restrict to locations" }
  }
}
```

The server computes an MD5 of the manifest and **short-circuits** if it is
unchanged. On change it **upserts** resources/scopes/conditions; anything absent
from the new manifest is **soft-deleted** (`is_active = false`), never hard
deleted. Send the stored `manifest_hash` to get a no-op `304` when nothing
changed.

Authenticate with HTTP Basic (`client_id:client_secret`) or with `client_id` and
`client_secret` as form fields. Either is fine — RFC 6749 §2.3.1 allows both.

`POST` is accepted as well as `PUT`, for a CI runner that has no `PUT`. The
operation is idempotent either way.

The response reports what it did, and the counts are worth checking in CI:

```json
{"status":"synced","resources":1,"scopes":2,"conditions":1,"deactivated":0}
```

> **A manifest that synced zero was possible before 2026-08-26, and reported
> success.** The normaliser dropped the map keys, so every entry arrived unnamed
> and every loop skipped it — `200 {"status":"synced","resources":0,…}`. Scopes were
> worse: `{"read": "View invoices"}` was read as a scope *named* "View invoices",
> so the server stored a permission keyed on prose and a client asking for `read`
> matched nothing. Both shapes are accepted now — the keyed map above, and a list
> whose entries carry their own `name` / `key`.
>
> **And Basic auth was refused where Apache runs as a module.** It decodes the
> header into `PHP_AUTH_USER` and does not pass the raw one on, so the extractor
> found nothing and answered `invalid_client` — which reads as a wrong secret. If
> you worked around it by moving to form fields, Basic works now.
>
> If your pipeline has been reporting success, check the counts: a manifest may
> have been accepted and stored as nothing.

### Seeing what a client declared

An administrator opens the client's own page — `/admin/Applications/view/{appid}` —
and reads its declared resources, the scopes on each, and the condition keys, with
the manifest's hash and when it last arrived.

That page is the answer to the question a grant raises: a permission names a
resource, so "which names does this client actually publish" has to be visible
before anybody can write one. It was not, until 2026-08-26 — the write side existed
alone, so a server accepted manifests and could show nobody what was in them.

Anything the client has stopped declaring is listed struck through rather than
removed. A grant may still refer to it, and that is exactly what somebody is
looking for when a permission has quietly stopped working.

A project that published the `applications` views before that date needs to
republish `applications/view` to get the section:

```bash
php bin/pramnos project:publish-views --group=applications --force
```

---

## 6. Instant invalidation — webhooks

When a user's permissions change — a grant, a role given or taken, a role edited or
deactivated, an organisation membership — the server queues a **`permissions_changed`**
webhook to your registered webhook URL:

```json
{ "subject_type": "user", "subject_id": 123, "operation": "role_assigned", "roleid": 7 }
```

On receipt, **drop that user's cached permissions** so the next request re-fetches
from `/api/internal/permissions`. When `subject_type` is `role`, drop the cache of every
user holding that role — or all of it, if you do not track who holds what. Webhook deliveries are HMAC-SHA256 signed and
retried — verify the signature before acting.

This is what makes lightweight tokens safe: permissions change instantly without
re-issuing or bloating tokens.

### Registering an endpoint

Authenticate with your client credentials — the same pair you use at the token
endpoint, as a Basic header or in the body:

```
POST /Webhook/register
Authorization: Basic base64(client_id:client_secret)

endpoint_url=https://your-app.example.com/hooks/auth
&webhook_type=token_revoked

{
  "webhook_type": "token_revoked",
  "endpoint_url": "https://your-app.example.com/hooks/auth",
  "secret": "…64 hex characters…",
  "signature": "X-Webhook-Signature: sha256=HMAC-SHA256(secret, body)"
}
```

**Store the secret.** It is returned once, by this call, and never shown again —
an endpoint that hands out its own signing secret to anyone who can reach it is
not signing anything. Lost it? Register the same type again; that replaces the URL
and issues a new secret.

`appid` comes from your credentials and is never read from the request, so an
application can only ever see and change its own endpoints.

| Route | Does |
|---|---|
| `POST /Webhook/register` | Register or replace an endpoint for one event type |
| `GET /Webhook/list` | Your endpoints — without the secrets |
| `GET /Webhook/stats` | Delivery counts by status |
| `POST /Webhook/test` | Queue a test event through the real pipeline |
| `POST /Webhook/delete` | Remove one endpoint |

The endpoint URL must be `https://` unless the server says otherwise (`require_https`,
below). The event describes a person and is signed with a shared secret; over
plaintext on the internet both are readable by anything on the path, which makes the
signature decorative.

Event types: `user_deauthorized`, `token_revoked`, `gdpr_request`,
`user_profile_changed`, `device_deauthorized`, `account_deleted`, `scope_changed`,
`permissions_changed`. One endpoint per type per application.

`POST /Webhook/test` queues an event for **the endpoint you named and no other**.
That matters because a real event does the opposite: `token_revoked` concerns
every application holding a token for that user, so the queue fans it out to
every endpoint subscribed to the type. A test ping is not a real event — it is
traffic you asked to have sent — so it stops at your own URL. If you need the
fan-out behaviour from your own code, call `queueEvent()` without the last
argument:

```php
$service = new \Pramnos\Auth\WebhookService($db);

$service->queueEvent('token_revoked', $userid, ['token_id' => 42]);       // every subscriber
$service->queueEvent('token_revoked', $userid, [...], null, null, $id);   // one endpoint
```

The endpoint id is still matched against the event type and `is_active`, so
naming one that does not subscribe queues nothing rather than queueing the wrong
event.

### Where a delivery may go

The relying party chooses the address and this server makes the request, so the address
is checked. What is allowed depends on who set it.

**An endpoint the application registered** through `/Webhook/register` is judged at
registration and again, pinned, at every delivery (`Http\Client::forUserSuppliedUrl()`):
the host must resolve to a public address or to a range this installation allows. By
default that includes the organisation's private network — RFC 1918, carrier-grade NAT
(which is what Tailscale hands out) and IPv6 unique-local — because the usual deployment is
an authorisation server and its relying parties on one VPN or LAN. It never includes
loopback or link-local, where this server's own ports and the cloud metadata address
(`169.254.169.254`) answer, unless a range naming them is listed.

**`allow_private => false` really does exclude carrier-grade NAT.** It is worth saying
because PHP's own address filters do not: `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` calls
`100.64.0.0/10` public, and the check is "public **or** in an allowed range" — so the
Tailscale block was tested against an allow-list it was not on and then admitted as public
anyway. `OutboundUrl::NEVER_PUBLIC_RANGES` is the rest of what those flags miss: CGNAT, the
IETF protocol block, the benchmarking block and multicast.

```php
// app/app.php
'authserver' => [
    'webhooks' => [
        'allow_private'        => true,              // the default
        'allow_private_ranges' => [],                // CIDR ranges allowed in addition
        'require_https'        => true,              // the default; false allows http://
    ],
],
```

| Configuration | A client may register |
|---|---|
| nothing set | public addresses and the private network |
| `'allow_private' => false` | public addresses only |
| `'allow_private' => false, 'allow_private_ranges' => ['10.8.0.0/24']` | public addresses and that VPN, nothing else private |
| `'allow_private_ranges' => ['127.0.0.1/32']` | the above, plus this host |
| `'require_https' => false` | any of the above over `http://` as well |

A refused registration answers `endpoint_url resolves to an address inside this network`. A
name that does not resolve yet is accepted, because every delivery resolves it again.

`require_https` applies to both kinds of endpoint. Turn it off where the network is
encrypted underneath — a VPN — or while receivers under development have no certificate.
It is a setting of its own rather than a consequence of this server's development mode on
purpose: the receivers are other people's applications, and one of them may be under
development on somebody's own machine while this server is the live one.

**Testing webhooks from an application on your own machine.** The server has to be able to
reach you, so the address is one of:

- **a tunnel** (ngrok, Cloudflare Tunnel) — a public `https://` URL, accepted as it is;
- **the VPN or LAN** you share with the server — a private address, accepted by default;
  add `'require_https' => false` if your receiver speaks plain `http`;
- **the server running on the same machine**, in Docker — register
  `http://host.docker.internal:<port>/…`, which resolves to a private address, with
  `require_https` off in that server's configuration.

**An endpoint an administrator entered** on the application's page is delivered to as
written — the operator's own statement about their network, needing no setting.

Either way, redirects are not followed: a receiver answering `30x` has failed the delivery,
and re-registers if it moved. A refused or failed delivery is recorded with
`Delivery refused or failed: …` as its reason.

### Managing endpoints from the administration area

The application's page — `/admin/Applications/view/{appid}` — lists its endpoints: the event
type, the URL, who set it (**Application** or **Administrator**), and how its deliveries have
gone (sent, pending, failed). From the same card an administrator can:

- **add an endpoint** for an event type, which replaces one already there. It is approved as
  entered and recorded as the administrator's; the signing secret is shown once, in the
  confirmation, and must be handed to the receiving application;
- **issue a new secret** for one endpoint — the old one stops verifying at once;
- **remove** one.

An application that later registers the same event type through the API replaces the
administrator's entry, and the address becomes the application's again — checked like any
other it registers.

### Verifying a delivery

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $yourSecret);

// hash_equals, not ===: this compares against attacker-supplied input.
if (!hash_equals($expected, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
```

`Pramnos\Auth\WebhookService::verifySignature()` does the same thing if you are
receiving on this framework.

### If nothing arrives

Deliveries are made by the **`auth:webhook-deliver`** schedule, every five
minutes — not at the moment the event happens. Check that the scheduler is
running on the server:

```bash
php pramnos schedule:list | grep webhook
php pramnos auth:webhook-deliver -v      # run it by hand
```

`GET /Webhook/stats` is the other half of that answer: a `pending` count that only
grows means events are being queued and nothing is sending them.

---

### What a 401 from the webhook endpoints tells you

Both failures are `invalid_client` with status `401`, and the difference between them is the whole
diagnostic:

```json
{"error": "invalid_client", "error_description": "Client credentials required"}
```

You sent nothing the endpoint could read — no `Authorization: Basic`, no `client_id`/`client_secret`
in the body. Check how your client is attaching them.

```json
{"error": "invalid_client"}
```

You sent credentials and they did not authenticate. **There is no description on purpose**: saying
whether the id or the secret was wrong would let a client id be confirmed by trying it, the same
reason a sign-in form does not say which half failed. Check the id and the secret together, and that
the application is still registered and enabled.

So: **a description means "nothing arrived", and no description means "what arrived was wrong".**

## 7. Putting it together

```
Discovery → Register → Authorize (PKCE) → Token (identity only)
   → Fetch /api/internal/permissions → cache
   → on permissions_changed webhook → invalidate cache
```

The effective access your app enforces is:

```
Licensing/entitlement (your app)  ∩  RBAC + ABAC (from the server)
```

The server returns the RBAC∩ABAC set; any licensing/entitlement gate is applied
by your application on top.

---

## What the site tells a machine that arrives uninvited

Two generated files, alongside the `.well-known` documents above.

### `/robots.txt`

Generated rather than shipped as a file, because the line that matters is derived from the
installation's own URL — a static file in a scaffold is a static file with somebody else's domain
in it.

It names **twelve AI crawlers one by one**: `GPTBot`, `ChatGPT-User`, `OAI-SearchBot`, `ClaudeBot`,
`Claude-User`, `Claude-SearchBot`, `PerplexityBot`, `Perplexity-User`, `Google-Extended`,
`Applebot-Extended`, `CCBot`, `meta-externalagent`. Every one reads `robots.txt` and honours it.

Absence is not neutrality. With nothing said each crawler decides for itself, and they decide
differently — `Google-Extended` opts a site out of model training while leaving Search untouched,
which is a distinction a site cannot express by staying silent.

```php
// app/settings.php — one setting flips all twelve
'ai_crawler_policy' => 'disallow',   // default: allow
```

The default is `allow`, because a framework must not choose a site's licensing posture. What it must
do is make the choice **visible and settable**.

The side-effect paths — `/admin/`, `/oauth/`, `/account/`, `/devpanel/`, `/adminer` — are
`Disallow`ed rather than left to `noindex`. `noindex` keeps a page out of an index *after* it has
been fetched; on an authentication server every one of those either costs a session, sends mail, or
answers differently per visitor.

### `/llms.txt`

The GEO counterpart of a sitemap: a short markdown document saying what this site is and where the
things worth reading are. A crawler follows links; a model arriving cold guesses, and guessing is
how a site gets described wrongly and confidently.

It is deliberately short — the format's premise is that it fits in a context window beside the
question somebody actually asked.

**And it is where the MCP endpoint is announced.** A model reading it learns the site has tools it
can call and where to authenticate for them. Without it the endpoint is a service nobody discovers.
It appears only once something has actually been offered through `PublicRegistry`, because an
endpoint serving an empty list is not worth pointing anybody at.

### Every project gets both, and the addresses in them are real

The rewrite rules and the controller behind them are scaffolded into **every** project, not only an
authorization server: what a crawler may read is not a question about issuing tokens, and a site
answering 404 at `/robots.txt` has not declined to have a policy — it has left the policy to
whichever crawler asked.

The three addresses these files advertise are derived rather than written down, because a
machine-readable file exists to be read by something that will not look around for the right URL — a
wrong address in one is worse than an absent one:

| Line | Where it points | How it is derived |
|---|---|---|
| `Sitemap:` in robots.txt | the site's sitemap | `sitemap_url` if set; otherwise the line is **omitted unless a file is actually served at `/sitemap.xml`**. The framework ships no sitemap generator, and a `Sitemap:` pointing at a 404 teaches a crawler that the site is broken |
| `[Documentation]` in llms.txt | the generated API docs | `Api::baseUrl()` + `docs/` — `<site>/api/docs/`, which is where `init` writes them |
| `MCP endpoint:` in llms.txt | the JSON-RPC endpoint | the API's own prefix + `/mcp` — `<site>/api/1.0/mcp`, which is where the scaffolded route puts it |

```php
// app/settings.php — when this site does generate a sitemap, or generates it elsewhere
'sitemap_url' => 'https://cdn.example.com/sitemap-index.xml',
```

## Related guides

- [Authentication & User Management](Pramnos_Authentication_Guide.md)
- [Authorization](Pramnos_Authorization_Guide.md)
- [API Guide](Pramnos_API_Guide.md)
- [Account & Security — End-User Guide](Pramnos_Account_Guide.md)
