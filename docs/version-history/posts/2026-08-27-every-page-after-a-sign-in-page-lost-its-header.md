---
date: 2026-08-27
categories: [Changelog]
---

# Every page after a sign-in page lost its header

The chromeless login layout is selected by writing to the theme object. The theme object is
cached for the whole process.

<!-- more -->

## Fixed

**`Theme::reset()`, called from `Document::reset()`.**

`Theme::getTheme()` caches by name, so one theme object serves every request in the process.
`Pramnos\Auth\Controllers\Account` calls `setContentType('login')` on it to select the
chromeless `login.php` layout — correctly, for the sign-in page — and nothing put it back.
So every page rendered after a sign-in page in the same process came out with **no header
and no footer**, and with the login layout's asset list: the navigation simply absent, status
200, nothing in any log.

One process, one request hides it completely, which is why it lasted. A worker, a daemon and
every test that visits `/login` and then anything else see it — and that is how it was found:
a test asserting what the public header contains failed only when an earlier test in the same
class had rendered a login page. The page under test rendered perfectly; the header was gone.

This is the fourth of the same family, and the shape is worth naming: **per-request state on
an object that outlives the request.** The others were the document's static content buffer
(2026-08-27), the captured flash bag, and the raw request body. `Document::reset()` now
clears the theme too, because a document carries one — resetting documents while leaving the
theme was resetting half of it.

## Documentation

- `Pramnos_Theme_Guide.md` — under *`login.php` — the standalone layout*.
