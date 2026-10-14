---
use_cases:
  - Checking whether something is already planned before proposing it
  - Finding known gaps and open items in the framework
---

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

## Email

### Where a sent message's body is stored

Under design, written up separately in **[Mail body storage — design](Mail-Body-Storage-Design.md)**.

The short version: `mails.path` is where applications on this framework keep gzipped bodies,
and the framework neither writes nor reads it — there is no body store in the framework. The
schema also disagrees with itself: `mails.hash` is described as an MD5 of the content in the
migration's column comment, while `Email::send()` writes an MD5 of recipient, subject and date.

A first attempt at a body store was backed out whole, because it was written while the design
was still being argued and carried decisions nobody had agreed to.

**Done requires:** finishing the survey of what installations actually have; deciding whether the
archived body may stop being byte-identical to what was sent; and settling how deduplication and
orphan collection work at ten million rows. The design page has the measurements and the traps
found by prototyping.

## Search engines

### hreflang, and a sitemap

Under design, written up separately in **[hreflang and Sitemaps — design](Hreflang-And-Sitemap-Design.md)**.

Neither exists. The framework has a full language system and no way for a page to say which URL is
its equivalent in another language; there is no sitemap generator, route or `<link rel="sitemap">`.
`robots` has no home either — it is set in several places by different mechanisms (headers,
meta tags in individual controllers and views) and has no `Document` property. The generated
`robots.txt` carries a `Sitemap:` line only when the `sitemap_url` setting names one or a
`sitemap.xml` file exists in the web root.

They are one piece of work rather than two, because the same declaration has to serve both: if a
sitemap and a page head disagree about which pages are translations of each other, Google discards
the declaration. And a controller cannot be the place it is declared, because no controller runs
when a sitemap is generated.

**Done requires:** deciding whether hreflang is delivered in the head, in the sitemap, or both
under the all-or-nothing rule for language groups; localized route groups, which make reciprocity
structural and are available because `Routing\Route` already compiles through Symfony; and a
sitemap generator that shards, since the protocol caps a file at 50,000 URLs and the sites this is
for have more pages than that.

The design page carries the requirements taken from an application that already does this in
production across five locales, what its implementation gets wrong, and the conflict between a
lean sitemap and sitemap-delivered hreflang.

## Documentation

### Deduplicate the two Getting Started pages

`docs/index.md` and `docs/Getting_Started.md` start as the same document: the same
installation, Docker, structure and scaffolding sections. `Getting_Started.md` continues into
the `daemons` container, configuration and secrets, and `.gitignore`; `index.md` is a shorter
copy of its first half. The nav presents them as "Quickstart" and "Full Setup Guide", but
nothing keeps the shared half in step.

This matters because `docs/` is a retrieval corpus (rule 13): two pages with the same
content compete for the same question and neither is more authoritative than the other.

- Decide which one survives, or split them for real — a short quickstart that
  stops at "it runs", and a full guide that continues into Docker, structure and
  scaffolding.
- Update the `nav:` labels so they describe what each page actually is.
- Keep whichever URL is already linked from the README and the scaffolded
  projects, or add a redirect.

*Done when:* the two pages answer different questions, and the `use_cases:` of
each say which.

---

_Have a request or found a gap? Open an issue on
[GitHub](https://github.com/mrpc/PramnosFramework)._
