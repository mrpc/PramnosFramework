---
use_cases:
  - Counting a site's own page views, visits and visitors without Google Analytics
  - Finding where a site's visitors came from — search, social, an AI assistant, a campaign
  - Recording the market or area of a page view beside it
  - Writing an existing tracker's hits into the framework's table during a move
  - Keeping the GeoIP database and the referrer spam list up to date
---

# Site traffic

The application counts its own traffic: every page view and every click out of its sites goes to
`pramnos.page_hits` with where it came from, the place, the device and the person, when known.
Nothing is updated after it is written. A visit's length and pages are read from its hits.

```php
// app/app.php
'visits' => [
    'sites'     => ['main' => ['hosts' => ['example.com'], 'visitor' => 'cookie', 'idle' => 1800]],
    'dimension' => fn (string $path, array $hit): ?string => explode('/', trim($path, '/'))[0] ?: 'gr',
    'sources'   => ['search' => ['example-search.com']],   // added to the framework's lists
    'geoip'     => ['path' => '/var/lib/GeoIP/GeoIP2-City.mmdb'],   // else DB-IP's, fetched
    'spam'      => ['bad-referrer.example'],                // added to Matomo's list
],
```

```php
<?php echo \Pramnos\Visits\Visits::tag(); ?>   <!-- before </body>, on every page -->
```

The scaffolded themes' footers print it already: it prints nothing until a site is declared.
The administration's layout does not, so the operators' own pages are not counted.

Turn on the `visits` feature and run `migrate`. Its migration makes `pramnos.sites` and
`pramnos.page_hits`, and `timescale:ensure` makes `page_hits` a hypertable on TimescaleDB,
compressed by site after a week and kept for good.

## What a page sends

`tag()` prints a meta, `pf-hits.js` and a `<noscript>` image, and nothing without a site:

- **A page view** when the page is shown. A prerendered page is counted when it is shown, not when
  it is made, and is flagged.
- **A click out:** a link to another host. Clicks are queued, then sent as the page is left, or
  twenty at a time.
- **A page without JavaScript:** the image, `GET /Hits/pixel`, counts its referrer, flagged.

They go with `sendBeacon` to `POST /Hits`, the framework's controller, reached in every
application with no controller of its own. It asks nothing of the session except who is signed
in, so pages from the page cache are counted too. It always answers 204.

## The visitor and the visit

Per site, `visitor`:

| | |
| --- | --- |
| `cookie` (the default) | `pf_vid`, kept two years, is the visitor. `pf_visit` is the visit: a new one after `idle` seconds without a page (30 minutes by default), or when a page carries another campaign. Each hit has its place in the visit, `seq`: 1 is the landing page. Both cookies are HttpOnly and SameSite=Lax. |
| `cookieless` | no cookie, no visit, never a person. The visitor is eight bytes of `sha256(today's salt · address · agent · site)`, and the salt is replaced at midnight UTC, so yesterday's hash cannot be made again. |

The person (`userid`) is whoever is signed in when the hit comes.

## What a hit keeps

| Column | |
| --- | --- |
| `at`, `site`, `visitor`, `visit`, `seq` | when, which site, who, which visit, its place in it |
| `kind`, `event` | `view`, `click` or `event`, and an event's name |
| `urlid`, `target` | the page, and a click's link out with its query, through `urls` |
| `source`, `medium`, `campaign`, `referrer_host`, `referrer` | where the visit came from: on its first hit, and on every hit of a cookieless site |
| `userid` | the person, when signed in |
| `country`, `region`, `city` | from the address, which is not kept |
| `device`, `browser`, `os`, `screen` | from the user agent, which is not kept |
| `dimension` | the application's own: `visits.dimension`, given the path and the row — the market, say |
| `flags` | 1 a bot, 2 no JavaScript, 4 prerendered, 8 from the page cache |

**Where it came from** (`Source::classify()`). The utm parameters win: `email` or `social` by
their medium, `search` for `organic`, else by the source's name, else `campaign`. Without them it
goes by the referrer: `ai` (an assistant), `email` (a webmail), `search`, `social`, `internal` (one
of the application's own sites), `referral` (another website), or `direct` for none. The lists
are the framework's, and `visits.sources` adds to them.

## Refused, and counted apart

Not written at all:

- a page or an origin that is none of the sites' hosts;
- a referrer on the spam list;
- more than 60 hits a minute from one visitor (the cache's token bucket);
- more than 20 in one request.

**A bot, or a page without JavaScript, is written**, with its flag: reports count it apart and say
how many there were. A flag that turns out wrong can be corrected later.

## Written later

A hit is appended through the [write spool](Pramnos_Workers_And_Daemons_Guide.md#1d-the-write-spool-rows-written-out-of-the-request-path): a line in a file, or in
Redis. `spool:drain` writes the lines in batches every minute, so a page never waits on an INSERT.

## From an existing tracker

During a move, an application's own tracker writes the framework's row beside its own:

```php
\Pramnos\Visits\Hits::record([
    'site' => 'main', 'url' => $url, 'referrer' => $referrer, 'kind' => 'view',
    'visitor' => $visitor, 'visit' => $visitId, 'seq' => $seq, 'userid' => $userId,
    'ip' => $ip, 'ua' => $userAgent, 'at' => $time, 'utm' => $utm, 'flags' => 0,
]);
```

The same refusals of a referrer, the same source classes, place, device and dimension apply.

## Place

`GeoIp::lookup($ip)` reads a MaxMind-format database. That is DB-IP's City Lite by default, which
`visits:update` fetches on the 3rd of each month into `var/visits/`, or the application's own file
(`visits.geoip.path`), which is never fetched. It needs `maxmind-db/reader`, a suggest. Without it
or the file there is no place, and the hit is still counted.

DB-IP's licence asks for its credit wherever places are shown: `GeoIp::ATTRIBUTION`.

`visits:update` also fetches Matomo's referrer spam list (public domain) into
`var/visits/referrer-spam.txt`. A list it cannot fetch leaves the one kept in place.

## The urls they refer to

`page_hits.urlid`, `target` and `referrer` are declared references of the
[urls registry](Pramnos_Logging_Guide.md#the-urls-registry), so `urls:prune` keeps every
address a hit refers to.
