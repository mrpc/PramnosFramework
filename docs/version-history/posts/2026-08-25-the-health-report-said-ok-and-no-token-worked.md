---
date: 2026-08-25
categories: [Changelog]
---

# The health report said ok, and no token worked

An authorization server that has lost its private key answers every page normally. The
database is reachable, the disk has room, memory is fine — and every `/oauth/token`
request returns a 500. `/health/check` reported `ok` on exactly that server, because
nothing in it was looking at the one file the whole feature depends on.

<!-- more -->

## Added

- **`Pramnos\Auth\Health\SigningKeysCheck`**, registered automatically by
  `AuthServerServiceProvider` when the `authserver` feature is on. Nothing to wire: it
  appears in `/health/check`, on the dashboard, and in `health:check` on the command line.

  It does not ask whether the key files exist. `file_exists()` is true in every state
  below, and the server cannot issue a usable token in any of them:

  | State | Result |
  |---|---|
  | A key missing, unreadable, or a directory where a file should be | down, naming which half |
  | A key present but unparseable — a truncated write, a mangled PEM | down |
  | Two valid keys **from different pairs** | down |
  | A matching pair below 2048 bits | degraded |

  The mismatched pair is the case that justifies the rest. Both files parse, both are real
  keys, every file test passes, and no token this server signs can be verified by anybody —
  so the failure surfaces in *somebody else's* application, days later, as "your tokens are
  invalid". The check signs a constant and verifies it, which rules that out for the cost of
  one small signature.

  Undersized keys report **degraded** rather than down on purpose. A 1024-bit key signs
  RS256 perfectly well; calling that an outage pages somebody about a working server, and
  calling it `ok` means it never gets rotated.

- **`OAuth2ServerFactory::defaultPrivateKeyPath()` / `defaultPublicKeyPath()`** — the
  default key locations, which were an expression inside the constructor. Now that a second
  thing needs to know where the keys are, a second copy of that expression would be a copy
  that drifts, and the drift would show up as a health check reporting confidently on a file
  the server does not sign with.

## Documentation

- **New guide: [Health checks](../../Pramnos_Health_Guide.md).** The health system had no
  guide at all — it was described only in the frozen v1.2 reference, which is precisely the
  state rule 1 exists to prevent. The page covers the endpoints and their status codes, what
  is registered without writing anything, how to write and register a check, why `run()` must
  never throw, how to choose between degraded and down, and the signing-key check in detail.

  One thing in there is worth repeating outside it: **degraded answers 503**. A monitor that
  reads only the status code treats reduced capacity as an outage. That is the safer default,
  but read `status` from the body if you want the two apart.
