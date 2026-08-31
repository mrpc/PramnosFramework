---
use_cases:
  - Declaring which URL is the same page in another language
  - Deciding what belongs in a sitemap on a site with a hundred thousand pages
  - Understanding why a sitemap and a page head must not disagree about translations
  - Planning localized routes, so alternates do not have to be declared twice
---

# hreflang and sitemaps — design

**Status:** design. Nothing here is implemented.
**Last updated:** 31 August 2026

Two features that look separate and are not. They are written up together because the same
declaration has to serve both, and because a framework that lets them drift apart produces
something worse than having neither.

See also [Roadmap & Open Items](Roadmap.md).

---

## 1. What is missing today

- **`hreflang`: nothing.** The framework has a complete language system and no way for a page to
  say which URL is its equivalent in another language.
- **Sitemap: nothing.** No generator, no route, no `<link rel="sitemap">`.
- **`robots`: no home.** The concept exists in four places with three mechanisms — a raw `<meta>`
  string built in `Application.php`, and `header('X-Robots-Tag: …')` in two controllers. There is
  no `Document` property for it.

The third one matters here because sitemap membership and indexability keep being confused, and
the absence of one place to say either is part of why.

---

## 2. Requirements, taken from an application that already does this

One large application on this framework has hreflang in production across five locales — `el`,
`en`, `en-gb`, `en-ie`, `el-cy`. It is evidence of what the problem *is*, not a template for
solving it.

**What it proves:**

1. **Slugs are translated.** `/syxnes-erwthseis` ↔ `/faqs`. So alternates are **not always
   computable**: no rule derives that pair.
2. **Both explicit and derived are needed.** Seventy explicit declarations across five
   controllers, and a substitution rule covering everything else. Either alone is untenable —
   declaring thousands of pages by hand, or leaving arbitrary pairs uncovered.
3. **Region variants, not just languages.** `en-gb` and `en-ie` are different pages for different
   countries.
4. **It is per-request.** Which URLs are alternates depends on the page.

**What its implementation gets wrong** — recorded so nobody copies it:

- Emits raw HTML through `addHeadContent()`, bypassing escaping. The framework now has
  `Document::metaTag()` and `Html\Seo` for exactly this.
- Derives alternates with `str_replace` over the **whole path**, so a slug that appears as a
  substring elsewhere is rewritten too: `childcare` also matches `/blog/childcare-tips`.
- Has **two** states where three are needed. An empty declaration means "derive", so there is no
  way to say *this page exists in one language only* — and derivation then emits a link to a URL
  that does not exist, which is the worst of the three outcomes.
- Gates the whole thing on a `hreflang = yes` setting. Per-page correctness is not a feature
  toggle.
- Keeps the slug map inside `Application.php`: application knowledge in a framework-shaped place.

---

## 3. The router already uses Symfony, and that changes the design

`Pramnos\Routing\Route` wraps `Symfony\Component\Routing\Route` for compilation —
`SymfonyRoute::compile()` for the match regex — and carries `$routeName`, `$defaults` and
`$parameters`. `Pramnos\Routing\Router` does not extend Symfony's router and has **no concept of
locale**.

So the infrastructure is present and the missing piece is a **group**. Symfony's own mechanism is
`localizedPaths`: one route declared with a path per locale becomes N routes sharing a canonical
name. Translated here:

```php
$router->get(['el' => '/syxnes-erwthseis', 'en' => '/faqs'], 'Faqs@display');
```

Two routes, each knowing its own locale **and its group**. From that:

- **hreflang is free** for every static page. From the current route, find the group; from the
  group, the siblings; from the siblings, the URLs.
- **Reciprocity becomes structural.** Google ignores an hreflang set that is not reciprocal — if A
  names B as its Spanish version, B must name A as its English one. One declaration cannot
  disagree with itself, so this stops being a thing to verify.
- **The slug problem disappears.** No substitution, no derived layer, no `childcare-tips` trap.
  The router *is* the map.
- **The sitemap gets the static routes** for free, per locale, with alternates attached.

Django's `i18n=True` generates per-language URLs from one set of items, which works only where
URLs are structurally parallel — and the evidence above is that they are not. Symfony gets
alternates free because its routes carry locale variants. This is that, and it is available
because the compilation layer is already Symfony's.

---

## 4. Why the override belongs in a registry, not in a controller

A controller **never runs** when the sitemap is generated. `sitemap:generate` is a CLI command; no
controller dispatch happens.

So an override declared in a controller gives hreflang to the page head and **nothing** to the
sitemap — the two then disagree about which pages are translations of each other, which is
precisely the state Google discards the whole declaration for. This is not a matter of tidiness:
a registry is the only place both consumers can read from.

The idiom exists. `Search\Registry` already accepts `string|callable` for `filter` and
`permission`, and loads from `app/search.php` via `loadDefinitions()`. Same shape:

```php
// app/hreflang.php

// 1. A group — for what no rule can derive
Hreflang::group([
    'el'    => '/syxnes-erwthseis',
    'en'    => '/faqs',
    'en-gb' => '/uk/faqs',
]);

// 2. A rule for a family of URLs — runs in the request AND in the generator
Hreflang::resolver('Jobs@view', fn (array $p) => Ad::find($p['id'])?->urlsByLanguage() ?? []);

// 3. The third state, stated
Hreflang::singleLanguage('/oroi-xrisis');
```

**The resolver is the load-bearing part.** It covers the page whose slug comes from the database —
the case that would otherwise force a controller override. Because it is a callable, the framework
invokes it in both contexts from one declaration, so the head and the sitemap cannot diverge.

`x-default` is derived from the `default_language` setting, which is already the last fallback in
the framework's language resolution. It does not need declaring; it needs overriding.

---

## 5. Where a controller override is legitimate

On a page that is **not in the sitemap**. There is nothing for it to disagree with.

That case is real and must be supported: on a hundred-thousand-page site most pages are
deliberately absent from the sitemap (see §6). So the controller override stays — as the escape
hatch for exactly that, documented as **head-only**, rather than something to be discovered the
hard way.

And the framework can catch the dangerous combination in the request that creates it: a controller
that overrides alternates **without** excluding the page from the sitemap gets a development-mode
warning. Cheap, local, and it fires at the moment of the mistake instead of in a crawl three weeks
later. It forbids nothing — exclude the page and the warning goes.

---

## 6. A sitemap is a crawl-budget instrument, not an inventory

This reframing decides the shape of the whole sitemap side.

A sitemap tells a crawler what is **important and fresh**. On a site with a hundred thousand pages,
listing ninety-five thousand near-duplicate or stale URLs dilutes the signal for the five thousand
that matter.

So the question is not *which pages do we exclude* but **which do we include**. Exclusion is the
default at scale, not the exception — and a provider that yields "every advert" is the wrong
default. It yields **selected** ones, and the selection is the application's knowledge.

### Two consequences that are framework work

**The protocol's limits.** 50,000 URLs or 50 MB per file. At a hundred thousand pages a
**sitemap index** pointing at shards is mandatory, and the generator has to shard. None of this
exists yet.

**And a conflict between the two goals.** If hreflang is delivered *inside* the sitemap, then every
member of a language group must **be** in the sitemap for the declaration to hold. A lean sitemap
that omits the English version **breaks** the hreflang on the Greek one.

The rule that reconciles them:

> **A language group is all-or-nothing in the sitemap.** List one member and you list them all.

It is checkable at generation time, where every group is visible at once — and it is exactly the
kind of thing that otherwise surfaces in production months later.

The alternative is to keep hreflang in the `<head>` only, where every page gets it regardless of
sitemap membership, and have the sitemap carry none. Simpler; loses the one-declaration-per-group
economy that makes the sitemap form attractive at scale.

### `noindex` and sitemap membership are independent

They keep being conflated and they are different questions. Page 2 of a listing should be
**indexable and unlisted**, with its canonical pointing at page 1.

One implication survives, and it is minor: `noindex` → excluded, because asking for indexing while
declaring the page unindexable is a contradiction Google reports. That is a convenience, not the
mechanism. The mechanism is choosing what goes in.

---

## 7. The four layers

| Layer | Gives | Reciprocity |
|---|---|---|
| **Localized route groups** | hreflang for everything the router knows, with no declaration | **structural** |
| **`Hreflang` registry** — group / resolver / singleLanguage | database-backed families, deliberate single-language pages, overrides | one declaration, two consumers |
| **`Document`** — alternates, `x-default`, `robots`, `excludeFromSitemap` | emission, and per-request exceptions | warned about, §5 |
| **Sitemap registry** | providers yielding entries **lazily**; route groups as the first provider | verified globally here |

Derived default everywhere, override where only the declarer knows. That is not new machinery —
it is the framework's existing idiom for head values, where `og_title` falls back to the title and
`og_site_name` to the `sitename` setting.

**Providers return generators, not arrays.** Two thousand pages must stream to a file, not be
assembled in memory first.

**A registry rather than an event.** Symfony's sitemap bundle uses `SitemapPopulateEvent` because
its bundles are independent of one another. Here there are already nine registries and it is the
established idiom for *declare what you provide* — with `Search\Registry`'s fail-closed behaviour
as the model.

---

## 8. How other frameworks handle it

| | |
|---|---|
| **Django** | `contrib.sitemaps` in core: a `Sitemap` class per model with `items()` / `location()` / `lastmod()`, registered in a dict. `i18n = True` generates per-language URLs from one item set — parallel URLs only. |
| **Symfony** | `presta/sitemap-bundle`: an event each bundle subscribes to, writing URLs into named sections. Alternates come from the routes, because its routes carry locale variants. |
| **Laravel** | Nothing in core. `spatie/laravel-sitemap` — a fluent builder or a crawler. |
| **Next.js** | `sitemap.ts` returning `{url, lastModified, alternates.languages}` — the two **unified in one entry**, which is the conclusion this page reaches independently. |
| **Rails** | Nothing in core; a gem with a config DSL. |

---

## 9. Open decisions

1. **Where hreflang is delivered** — head only, sitemap only, or both with the all-or-nothing rule
   for groups (§6).
2. **How a provider expresses selection** — entirely the application's criterion, or does the
   framework offer a shape (`latest N`, `changed since`)?
3. **Sharding and the sitemap index** — how the generator names shards, and whether the index is
   regenerated wholesale or incrementally.
4. **`Document::$robots`** — worth introducing alongside this, since the four existing sites are
   the reason indexing and listing keep being confused. In scope or separate?

## 10. Next steps

1. Decide §9.1 first: it determines whether the registry's entries need alternates at all.
2. **Localized route groups**, which are self-contained and give correct hreflang immediately.
3. `Document` emission on top of them.
4. The sitemap registry, with the route groups as its first provider — and the group verification
   from §6.
