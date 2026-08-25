---
date: 2026-08-26
categories: [Changelog]
---

# A webhook queue with no consumer

`WebhookService` signs deliveries with HMAC-SHA256, retries them with exponential
back-off, and records every attempt. GDPR erasure, device deauthorization and
permission changes have all been queueing events into it. Nothing in the framework ever
called `processQueue()`.

<!-- more -->

## Added

- **`auth:webhook-deliver`**, registered in the framework schedule to run every five
  minutes. That is where the retry back-off starts, so a slower cadence would not delay
  only the first attempt — it would delay every one of them.

  The events had been written and had stayed `pending` forever. The failure is invisible
  from both ends: the server logs a successful queue write, and the relying party has
  nothing to notice the absence of. An application that registered an endpoint simply
  never heard anything, and no error was raised anywhere.

  The command is quiet on an empty queue — it runs 288 times a day, and a line per run
  buries the ones that matter. A failed delivery exits `0`: the event keeps its attempts
  and its back-off, and a non-zero exit would make a scheduler treat an unreachable
  relying party as a broken command. `--purge=N` drops settled events older than N days.

- **`Pramnos\Auth\Controllers\Webhook`** — the way in, which did not exist. The tables
  were there and the delivery worked, and the only route to a row in
  `oauth2_webhook_endpoints` was an `INSERT` by hand.

  ```
  POST /Webhook/register   endpoint_url, webhook_type  → { webhook_id, secret }
  GET  /Webhook/list                                   → this client's endpoints
  GET  /Webhook/stats                                  → delivery counts
  POST /Webhook/test       webhook_id                  → queue a ping
  POST /Webhook/delete     webhook_id                  → remove one
  ```

  Every action authenticates with **client credentials**, and `appid` is taken from those
  credentials rather than from the request — so there is no parameter pointing at another
  application's configuration. An endpoint that is not yours answers 404 rather than 403,
  because confirming that an id exists is exactly what somebody enumerating them wants.

  The signing secret is returned **once**, by `register`, and never again: an endpoint
  that hands out its own signing secret to anyone who can call it is not signing anything.
  Registering the same event type again replaces the URL and issues a new secret, which is
  what somebody does when they have lost it.

  `https://` is required. The event describes a person and is signed with a shared secret;
  over plaintext both are readable by anything on the path.

  `POST /Webhook/test` queues through the real pipeline rather than delivering inline — a
  test that took a shortcut would only prove the shortcut works.

- `init` scaffolds the thin `Webhook` controller for an authserver project, alongside the
  others.

## Documentation

- [Third-Party Integration](../../Pramnos_AuthServer_Integration_Guide.md) gains
  "Registering an endpoint", "Verifying a delivery" and "If nothing arrives" — the last of
  which starts with checking that the schedule is running, since that was the failure.
- [Console](../../Pramnos_Console_Guide.md) documents the command, its cadence, and why it
  exits `0` on a failed delivery.
