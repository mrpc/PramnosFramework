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
