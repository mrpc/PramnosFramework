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

The built-in login lifecycle, session tracking, activity logging **and remember-me** now cover
what the legacy addons provided. Remember-me is one rotating token per sign-in in
`remember_tokens` (`Auth\RememberTokens`). `Auth::authCheck()` re-establishes a remembered login
through `Auth::rememberLogin()` when no auth addon is registered. `SessionTrackingMiddleware`
already calls it, and an application in production uses it through its own addon.

**Left:**

- New scaffolds register no auth addon, and a characterization test signs in from the cookies with
  none registered, on all three databases. What remains is the same in a real application, across
  new requests and browser restarts.

*Done when:* a new scaffold registers no auth addon, and remember-me works there across browser
restarts.

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
