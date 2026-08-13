---
use_cases:
  - A request came back wrong and I need to find out why
  - A page or an API call is slow and I need to know where the time went
  - It worked and then stopped working, or a user is suddenly signed out
  - A deep link 404s in the single-page application
  - Something broke in the browser and the server logs look fine
---

# Using the debug toolbar

This page is organised by **what has gone wrong**, not by what each tab contains. For how
the toolbar works, how it is delivered and how to switch it on, see the
[Debugging Guide](Pramnos_Debugging_Guide.md).

Open it with the `⚙ Pramnos` bar along the bottom of the page. Click a tab to open its
panel, click the same tab again to close it, drag the panel's top edge to resize it — the
height is remembered.

---

## The request came back wrong

**Start in `requests`.** Every request this page has made is listed there, newest first,
with its status, server time and query count — the page's own request included. Click one
and **every other tab switches to it**, so you read the same tab for one request after
another rather than hunting for the right panel.

Then, in order of how often it is the answer:

| Tab | What it settles |
| --- | --- |
| **SQL** | What was actually asked of the database. A query returning nothing usually looks obviously wrong once you read it. |
| **Route** | Which controller and action ran. If this is not what you expected, nothing after it will be. |
| **Domain** | Which models and services the request touched. An empty Domain tab on a request that should have saved something is the finding. |
| **Exceptions** | What the server raised. It turns red with a ⚠ as soon as anything did, so you do not have to open it to check. |

**What the page sent** is shown above whichever tab is open, collapsed, for any request
that had a body — "what did I send" is usually the first question, and a form-encoded body
is decoded into the structure it encodes.

**A request whose response carried nothing** still gets a row, with `—` where its numbers
would be, and its row is red across the full width. That is often the finding by itself:
the call happened, and the server never answered it.

---

## It is slow

**Time.** Two subtractions nobody does by hand:

- **client versus server** — `client 210ms = server 42ms + 168ms elsewhere`. If most of it
  is "elsewhere", the server is not your problem: it is the network, the queue, or the
  browser.
- **SQL as a share of server time.** "24ms of 40ms was the database" is an indexing
  problem; "2ms of 400ms" is not.

**Then the timeline column in `requests`.** Every request is drawn on one shared axis, so
what a single-request view cannot show becomes obvious: three calls that each take 200 ms
are a 200 ms page if they overlap and a 600 ms page if they do not. A polling loop looks
like a comb.

---

## It worked, and then it stopped

Almost always **Auth**. It answers four things about the request in view: who the server
identified, which credential did it (`apiKey`, `accessToken`, cookie), where that
credential came from, and **how long it has left** — counted from the token's own expiry
rather than from when the response was made, because the answer may have been sitting in
the browser for a while. The tab turns red once the credential has expired, which explains
every 401 above it in the list at once.

A **logout** reports that nobody is signed in any more, even though the request itself was
authenticated — it has to be, to revoke anything.

If Auth says the credential is fine, look in **Client → what the browser has stored**: a
stale token survives a deploy, the server then signs with a new key, and every call fails
in a way that looks like a server problem.

---

## The deep link 404s

**Client → where the application thinks it is.** The router base is printed next to the
current path, because that pair *is* the failure: an application served under `/app` whose
router base is empty resolves every deep link to its home screen and says nothing about
it. When the path does not start with the base, the panel says so rather than leaving two
values side by side.

On a server-rendered page the same section tells you the server decided which page this
URL is, and points at the **Route** tab — there is no client-side route to report, and
that is not a fault.

---

## Something broke in the browser

**Errors**, which appears — red, with a ⚠ — only once something has been thrown. It holds
what `window.onerror` and `unhandledrejection` saw, plus anything the application handed
over deliberately: an `ApiError` a screen turned into a message, a component failure caught
by `<svelte:boundary>`. Those never reach a global handler, and they are exactly what you
are looking for when the screen says something went wrong and the network tab looks fine.

Each row names **the request it happened after**, and identical failures collapse into one
row with a `×4` — a render loop throws the same error thousands of times.

The server's own exceptions are in **Exceptions**. Two tabs, because they have different
fields and different lifetimes, and mixing them would make "where did this come from"
ambiguous in the one table you read while something is broken.

---

## I want to try an endpoint

**API.** It lists the endpoints in the project's own OpenAPI document, gives you a field
per documented parameter, and sends a **real** request — same server, same middleware, same
authentication. The call is recorded in the requests list like any other, so Time, SQL and
Logs answer for it too.

The response appears directly under the Send button, with the status in words as well as
numbers. Its own `_debug` payload is left out of that view: it is what every other tab is
already showing for the same call.

Credentials: the application's `apiKey`, cookies (so a signed-in browser session
authenticates the call), and a stored bearer token if there is one — named by its key,
never printed, and refusable in one click, which is how "is this endpoint actually public?"
gets answered.

---

## The response could not carry its own detail

An error page is not a JSON object, so a request that died brings back a summary in
headers rather than a payload. The toolbar knows: **Logs** and **Exceptions** show a row
per such request with a button that asks the server for the detail by request id.

Every request has an id. Hover its row to copy it — it is what to paste into a bug report,
and what `GET /devpanel/logs?request=<id>` takes.

---

## Housekeeping

- **`clear the list`** in the requests tab empties it. In a SPA that polls, the call you
  care about scrolls away otherwise.
- **`✕`** hides the whole bar, leaving a small `⚙` in the corner to bring it back. The
  choice is remembered, and shared between the server-rendered toolbar and the SPA panel.
- **The bar is not there at all in production**, because nothing attaches debug data — no
  data, no DOM, no panel.
