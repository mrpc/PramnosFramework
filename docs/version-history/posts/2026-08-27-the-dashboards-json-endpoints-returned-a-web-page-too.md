---
date: 2026-08-27
categories: [Changelog]
---

# The dashboard's JSON endpoints returned a web page too

Six endpoints set `Content-Type: application/json`, echoed their payload, and then let the
request render the theme. `r.json()` throws on that, so every AJAX widget on the admin
dashboard was quietly failing.

<!-- more -->

## Fixed

**A JSON action must switch the document, not only the header.**
`DashboardController::activeusers()`, `apistats()`, `dbstats()`, `cacheitem()`,
`clearcache()` and `ServicesController::status()` now call
`Factory::getDocument('json')` before echoing.

Without it the action echoes, returns, and the request goes on to render the theme — so the
response is the JSON followed by a complete HTML page. The status is 200 and the body
*begins* with exactly the right JSON, which is why nothing looked wrong from the server side.
What a person saw was a widget whose numbers never appeared.

It is also why a unit test did not catch it: those tests capture the echo with `ob_start()`,
and the page that follows is emitted later, by the application. The new test asserts on the
document type instead — the mechanism that prevents the page.

**The cache browser's View button never worked.**

`cacheitem()` read its key with `Cache::load($key)`, and the two keys are not the same thing:
`getAllItems()` reports the key an entry is **stored** under, while `load()` builds a storage
key out of a *logical* id and the instance's category. So the endpoint was handed the first
and looked up the second, and answered `Item not found or expired` for every entry listed on
the page — a screen whose whole purpose is to show what is in the cache, unable to show any
of it.

It now reads through the adapter, by storage key, with the namespace the row was listed
under (`?key=…&namespace=…`, added to the three bundled themes' cache view). Without the
namespace the adapter falls back to splitting the key on `_` to find the directory, which is
right only for a single-word category — `schema_columns_users` resolves to `schema`.

The read passes a timeout of `0` so it applies no expiry of its own: this is a viewer, an
expired entry is exactly what somebody is looking for when they open it, and the list already
marks it as expired.

**Contract note.** `GET dashboard/cacheitem` now expects the storage key, as the browser
lists it. The previous behaviour cannot be preserved because it did not work: no key the page
displays was ever resolvable.

## Documentation

- `Pramnos_Document_Output_Guide.md` — "A JSON endpoint inside an MVC controller".
- `Pramnos_Cache_Guide.md` — what `getAllItems()` reports, and reading one back.
