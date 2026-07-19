# Roadmap & Open Items

A running list of planned work and known gaps in the framework. Shipped features
live in the [Changelog](version-history/index.md); this page tracks what is
**not yet done**.

> Items here are deliberately not started or intentionally deferred. Each notes
> *why* and what "done" requires. Anything touching live authentication is
> verified in a real application before it is merged.

## Authentication & Auth Server

### Retire the deprecated `auth` / `session` addons

The built-in login lifecycle, session tracking and activity logging now cover
what the legacy `auth` and `session` addons provided — **except** cookie-based
"remember me" re-authentication, which still relies on the `UserDatabase`
addon's `onAuthCheck()`.

**Planned:**

- A built-in remember-me `authCheck` (validate the `auth`/`username` cookies and
  re-establish the session) to replace `UserDatabase::onAuthCheck()`.
- Point `SessionTrackingMiddleware` at the built-in check.
- Drop the deprecated addons from new scaffolds, keeping backward compatibility
  for applications that still register them.

*Done when:* remember-me login persistence is verified across new requests /
browser restarts in a real application.

### Record the login method in the activity log

Two-factor and passkey logins are currently recorded as a generic `login` in the
activity log. They should be distinguishable (`password` / `twofactor` /
`passkey`).

**Constraint:** the natural fix — threading the method through
`Auth::loginById()` — would add a parameter to a public method that subclasses
override, which PHP rejects as an incompatible signature (a backward-compat
break). It needs a mechanism that does not change the public signature (for
example a value set on the `Auth` instance by `LoginFlow` before the session is
established), then `LoginFlow` sets it on the two-factor and passkey completion
paths.

---

_Have a request or found a gap? Open an issue on
[GitHub](https://github.com/mrpc/PramnosFramework)._
