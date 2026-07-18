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
