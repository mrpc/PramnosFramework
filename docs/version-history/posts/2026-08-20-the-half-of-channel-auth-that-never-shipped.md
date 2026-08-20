---
date: 2026-08-20
categories: [Changelog]
---

# The half of channel auth that never shipped

`PusherAuthorizer` could **verify** a channel signature. Nothing could **produce** one, and
nothing anywhere said which user may join which channel — so every application wrote its own
`/broadcasting/auth` endpoint and its own HMAC. Production security code, rewritten per
project, and the same code every time.

<!-- more -->

## Added

**`ChannelRegistry`** — the rules. Patterns without the protocol prefix, placeholders as
callback arguments:

```php
$channels->channel('order.{id}', fn (?object $user, string $id): bool
    => $user !== null && Order::load((int) $id)?->userid === $user->userid);

$channels->channel('room.{room}', fn (?object $user, string $room): array|bool
    => $user === null ? false : ['user_id' => (string) $user->userid]);
```

An unmatched channel is **denied**. A missing rule is not an open channel — otherwise every
misspelled pattern is a hole, and the misspelled rule still looks registered. A placeholder
matches one segment and never a dot, so `order.{id}` does not quietly cover `order.42.items`.

**`PusherAuthSigner`** — the other half of `PusherAuthorizer`, over one string-to-sign
definition, which is the only way the pair cannot drift. The tests assert the round trip
rather than the bytes. `channel_data` is signed as the exact JSON string that is sent, never
re-encoded on the far side: re-encoding changes key order or escaping and invalidates a token
nobody tampered with.

**`Broadcasting` controller** — `POST /broadcasting/auth`, the path pusher-js, Laravel Echo
and `pramnos-echo.js` all call by default. Applications reach it by scaffolding a thin wrapper
in their own namespace, the same opt-in as the framework's auth controllers.

403 is deliberately one answer for four causes — rule denied, no rule matched, public channel,
unknown app key. Telling them apart lets a caller enumerate which channels have rules and
which keys are real. A missing secret is the exception and returns **500**: that is the
operator's misconfiguration, and reporting it as "forbidden" sends whoever debugs it to the
wrong file.

A malformed `socket_id` is refused before anything is signed. The id is signed verbatim into a
colon-delimited string, so one containing a colon could shift a field boundary and make a
token for one channel verify for another.

**App registries** — `broadcasting.apps.source` is `auto` | `config` | `authserver`.

With the **`authserver` feature enabled**, realtime app keys are AuthServer applications:
`apikey` is already UNIQUE, `apisecret` is already 32 random bytes stored in the clear, and
`ApplicationsController::rotate()` already rotates it. Without the feature, the simple config
implementation runs — byte-identical to before. `auto` follows the feature list, so the two
travel together.

Naming `authserver` explicitly while the feature is off **throws**. A silent fallback would
authorize channels against a different secret than the operator asked for.

Resolution is a pure function of two plain arrays rather than a `FeatureRegistry` lookup, and
that is not fastidiousness: until [the console fix](2026-08-20-features-that-were-off-on-the-command-line.md)
landed in the same session, the registry was empty inside every command — so the daemon
verifying a signature and the web request that produced it would have read one `app.php` and
reached opposite conclusions about where app keys come from, silently. A security decision
should not depend on bootstrap order having been fixed.

**Migration** `2026_08_20_000001_add_broadcast_secret_to_applications` — nullable, additive,
idempotent, gated on the `authserver` feature.

Why a separate column rather than reusing `apisecret`: a WebSocket daemon is a long-running
process holding every connected app's secret in memory for the life of the connection, while an
OAuth2 token exchange reads one and exits. Sharing the secret means a core dump from the daemon
leaks OAuth2 client credentials too. Nullable with `apisecret` as the fallback, so an
installation that never runs it keeps working — and the realtime key can be rotated without
invalidating OAuth2 clients.

## Note

The controller action is `postAuth`, not `auth`. `Controller::auth($action)` is the framework's
per-action authorization gate, called by `exec()` on every dispatch, so no controller can have
an action by that name — PHP refuses the incompatible signature, which is how this was found.
The name is also exactly what the dispatcher looks for: for a non-GET request `exec()` resolves
`strtolower(METHOD . ucfirst($action))`.

## Documentation

`Pramnos_Realtime_Guide.md` gains **Channel authorization**, covering the rules, the endpoint,
the app registries and the AuthServer mapping, with three `use_cases:` entries.
