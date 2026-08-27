---
title: The HTTP tests that were all testing the home page
date: 2026-08-26
categories:
  - Testing
  - Routing
---

# The HTTP tests that were all testing the home page

`TestClient` is the documented way to test an endpoint. For any project routing the
classic MVC way, it had been answering every request with the site's home page.

<!-- more -->

## What was happening

`TestClient::call()` set up the request the way you would expect: `REQUEST_METHOD`,
`REQUEST_URI`, the headers, `$_POST`, the query string parsed into `$_GET`. Then it
built a `Request` and asked it for the controller.

`Request` derives the controller from `$_GET['r']` and only from there, because
that is what the scaffolded `.htaccess` rewrites every URL into:

```apache
RewriteRule ^(.*)$ index.php?r=$1 [QSA,L]
```

Nothing set it. So `calcParams()` never ran, `getController()` came back empty, and
the classic-MVC fallback ran `$this->app->defaultController` — the home page — for
every path you could ask for. Status 200, a full page of HTML, assertions passing.

That last part is what makes it worth a post. The failure had no symptom. A test
written to prove that `/admin/users` refuses a guest asked for `/admin/users`, got
the public home page, found no admin content in it, and passed. So did a test
asserting a 404 for a route that does not exist, and one checking that a
signed-out visitor cannot see somebody's profile. They were all describing the
home page.

Attribute-routed projects were fine — `Router::dispatchSafe()` reads the URI
directly, and that path ran first.

## Five more, found on the way

**A request to `/` served the previous request's controller.** `calcParams()` only
runs when there is a path to route, so with no path the routing statics kept what
the last request had put there. In a one-request web process that cannot happen;
in a client making several calls it happens immediately. `TestClient` now calls
`Request::resetInstance()` per request — which also fixes `Request::getInstance()`
handing back the first request's object forever.

**The administration area was decided once.** `Application::__construct()` calls
the detection, which is correct for a process that serves one request and wrong for
anything else: the second request to `/admin/...` was not recognised as being
inside the area, so the prefix stayed in the route and the usertype floor did not
apply — and a first request to `/admin` left the admin theme selected for every
public page after it.

That is now `Application::beginRequest()`, which restores what the area overrides
(theme, default controller), resets `AdminArea` and detects again. The constructor
calls it, so a single-request process behaves exactly as before.

**The area's usertype floor was never applied.** It lives in `Application::exec()`,
and `TestClient` resolved the controller itself rather than going through `exec()`.
So every `/admin/...` request in a test was served with no floor at all — and tests
written to prove the floor works passed, because the *screens* have their own
checks. The suite would have kept passing right up to the first screen that forgot
one, which is the entire reason the floor exists. `allowAdminAreaRequest()` is now
public and `TestClient` calls it where `exec()` does; a refusal is a pending
redirect, read back with the new `Application::getRedirect()`.

**No theme was loaded, so no response was a page.** `Application::exec()` loads the
configured theme before running the controller; `TestClient` did not load one at
all. Responses were the controller's own output with no header, no navigation and
no footer, so nothing a test said about a *page* was true, and a theme that fails
to render was invisible to the whole suite.

**Every response carried the ones before it.** The document is per-request and
everything on it appends — content, and the `header`/`head`/`foot` that `render()`
adds the theme's to on each call. Response 2 arrived with response 1's page in
front of its own and its `<head>` twice; by the fifth request in one test the theme
had been concatenated five times and the run died on a 34 MB output buffer.

The content half of this went deeper than the instances and was not finished here —
see [reset() left the page where the next request would find
it](2026-08-27-reset-left-the-page-where-the-next-request-would-find-it.md).
`assertSee()` passing on a page the test had already left is the quieter half of
the same bug. `TestClient` now resets the document per request.

**Every 404 was a 500.** `Application::notFound()` and `showError()` end the
request through `close()`, which throws under `PRAMNOS_TESTING` — as a bare
`\Exception`, so a not-found, a maintenance stop and a genuine fault were
indistinguishable and all three rendered as 500s. There was no way to assert that a
URL is not found. They now carry the status they decided on, in a typed
`ApplicationClosedException`.

`close()` itself is untouched: applications subclass `Application` and override
`close($msg = '')`, so a new parameter on it is a signature break in every one of
them — this framework's own suite has such a subclass, which is how that was
established rather than guessed. The status goes through a new
`closeWithStatus()`.

## And two that were never wired up

**`loginUser()` signed nobody in.** It set `$_SESSION['auth']` and
`$_SESSION['user_id']`. Nothing reads either: `Session::staticIsLogged()` wants
`logged` and a `uid` above 1, and `User::getCurrentUser()` builds the user from
`uid`. So every test that called it exercised the signed-out path while reading as
though it covered the signed-in one — a test named "an administrator can open this
screen" was testing the guest redirect. It now sets the keys the framework reads
(keeping the old ones, which the session-tracking middleware copies to a cookie),
clears the cached identity, and has a `logoutUser()` counterpart, because a
process-wide session with no way back leaks a sign-in into every test after it.

**The CSS selector assertions could not run downstream.**
`assertSelectorExists()`, `assertSelectorContains()` and `assertSelectorAttribute()`
need `symfony/dom-crawler`, which is a *dev* dependency of this framework — and a
dependency's dev dependencies are not installed. Three documented assertions threw
`Class "Symfony\Component\DomCrawler\Crawler" not found` in every project that
tried them: a true message and a useless one, naming an internal class rather than
the two packages, and surfacing as an error in the test that used it, which reads
as a fault in the page under test. Scaffolded projects now get both in
`require-dev`, and the assertion says what to install. They stay out of `require`:
nothing in production parses HTML.

**And `PRAMNOS_TESTING` reached only this framework.** `Application::close()` calls
`exit()` unless it is defined. This framework's own bootstrap defined it; the
bootstrap it scaffolds for a project did not. Under PHPUnit an `exit()` is not a
failing test — the process stops mid-run, the summary never prints, and whatever
the dying page wrote lands in the terminal looking like output. One database fault
truncated a project's entire suite, leaving a maintenance page where the results
should have been. It is now defined in `TestEnvironment::setup()`, which every
project already calls, including the ones already written.

## What to do

Nothing, to get the fix. But **re-read any HTTP test written before this**: it was
passing against the home page, and it may not pass against the endpoint it names.
That is not a regression in your code — it is the first time the test ran.

## Documentation

- `Pramnos_Testing_Guide.md` — a dated warning on the HTTP Testing section, and a new
  "One client, many requests" section on `beginRequest()` and what is per-request.
