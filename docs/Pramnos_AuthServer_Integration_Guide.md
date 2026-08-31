---
use_cases:
  - Connecting a third-party application to the auth server
  - Implementing an OAuth2 Authorization Code + PKCE flow against it
  - Reading a user's permissions from another application
  - Reacting to instant invalidation webhooks
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
```

The document lists the real endpoints, e.g.:

| Key | Default |
|-----|---------|
| `authorization_endpoint` | `/oauth/authorize` |
| `token_endpoint` | `/oauth/token` |
| `userinfo_endpoint` | `/oauth/userinfo` |
| `device_authorization_endpoint` | `/oauth/deviceauthorization` |
| `jwks_uri` | `/.well-known/jwks.json` |

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
**client_id** and **client_secret**, plus your registered **redirect URI(s)**.

Applications marked **trusted** (internal/first-party) skip the user consent
screen; untrusted (third-party) applications always show consent and receive
only the scopes the user approves.

---

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

A client registered with no secret at all is a different case — it has none to
present, and the server does not ask for one. Such a client must not be given
`client_credentials`, which authenticates the application itself and therefore
has nothing but the secret to authenticate with.

### Lightweight tokens

Access/ID tokens carry **identity claims only** — user id, basic attributes,
audience, optionally roles. They **do not** carry permissions. Do not try to
derive what a user may do from the token; fetch it (next section) and cache it.

---

## 4. Reading a user's permissions

Your application server (never the browser) calls the internal permissions
endpoint using **client-credentials** (RFC 7523 JWT assertion):

```
GET /api/internal/permissions?user_id={id}&client_id={your_client_id}
Authorization: Bearer {client_credentials_access_token}
```

Response — the effective permission tree scoped to your application
(`app_id = your app OR global`):

```json
{
  "resources": {
    "invoices": {
      "read":  { "grant": "allow", "conditions": null },
      "write": { "grant": "allow", "conditions": { "location_id": [1, 2] } }
    }
  }
}
```

- `grant` is the resolved **allow/deny** after the server applies deny-over-allow.
- `conditions` is **ABAC** context your app evaluates against the current request
  (e.g. only allow `write` when the request's `location_id` ∈ [1,2]). A `null`
  means unconditional.

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

When an administrator changes a user's permissions, the server queues a
**`permissions_changed`** webhook to your registered webhook URL:

```json
{ "event": "permissions_changed", "user_id": 123, "client_id": 45 }
```

On receipt, **drop that user's cached permissions** so the next request re-fetches
from `/api/internal/permissions`. Webhook deliveries are HMAC-SHA256 signed and
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

The endpoint URL must be `https://`. The event describes a person and is signed
with a shared secret; over plaintext both are readable by anything on the path,
which makes the signature decorative.

Event types: `user_deauthorized`, `token_revoked`, `gdpr_request`,
`user_profile_changed`, `device_deauthorized`, `account_deleted`, `scope_changed`.
One endpoint per type per application.

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

## Related guides

- [Authentication & User Management](Pramnos_Authentication_Guide.md)
- [Authorization](Pramnos_Authorization_Guide.md)
- [API Guide](Pramnos_API_Guide.md)
- [Account & Security — End-User Guide](Pramnos_Account_Guide.md)
