---
date: 2026-08-21
categories: [Changelog]
---

# One daemon that only knew one app

App keys could come from the AuthServer's `applications` table — fifty rows, fifty secrets,
each rotatable on its own. The daemon verifying channel signatures held exactly one key and one
secret, from `app.php`. The registry stopped at the edge.

<!-- more -->

## Added

`Auth\AppRegistryAuthorizer` resolves the app — and therefore the signing secret — per
connection, from an `AppRegistryInterface`. `broadcast:serve` wires it automatically when
`broadcasting.apps.source` resolves to `authserver`, and says so in its banner.

**The app key comes out of the token.** A Pusher channel token is `"<appKey>:<hmac>"`, so this
needs no per-connection bookkeeping to know which secret to verify against: the client names
the app, in the one field it cannot lie about, because naming the wrong one produces an HMAC
that does not verify. That is why the protocol puts the key there, and it is what makes
multi-tenancy cost nothing at the wire level.

An unknown key, a disabled application, an app with no secret and a bad signature all return the
same refusal — a caller has no use for the difference, and distinguishing them would let
somebody probing keys learn which ones exist. An app with no secret is admitted at *connection*
time, though: the app exists, its public channels are legitimately usable, and refusing the
connection outright would report a signing misconfiguration as an authentication failure.

The daemon's registry carries a 60-second TTL where the web binding uses zero. Both directions
matter: a query per handshake would block a single-threaded select loop for its duration — and
after a deploy every client reconnects at once — while a TTL means revoking an application takes
effect within a minute rather than at the next restart.

A misconfigured `apps.source` now **stops the daemon** instead of starting it. Naming
`authserver` while the feature is off would otherwise fall back to the config registry and
authorize channels against a different secret than the operator asked for, with the daemon
reporting itself healthy the whole time.

## Documentation

`Pramnos_Realtime_Guide.md` gains **More than one app, at the edge** under Channel
authorization.
