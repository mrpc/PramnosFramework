---
use_cases:
  - Counting a site's own page views, visits and visitors without Google Analytics
  - Finding where a site's visitors came from — search, social, an AI assistant, a campaign
  - Recording the market or area of a page view beside it
  - Writing an existing tracker's hits into the framework's table during a move
  - Keeping the GeoIP database and the referrer spam list up to date
  - Charting visits, visitors and sources as metrics, or asking the assistant about the traffic
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

## As metrics

Wherever a site is declared and the table is there, the traffic is a set of
[metrics](Pramnos_Metrics_Guide.md) with nothing declared, so it can be charted, put in reports,
given goals and alerts:

| Metric | |
| --- | --- |
| `visits.views` | page views by people: robots and pages without JavaScript left out |
| `visits.visits` | visits, counted on their landing page (the cookie model: a cookieless site has none) |
| `visits.visitors` | distinct visitors (a distinct metric: it does not add up across days) |
| `visits.signed_in` | visits in which the person was signed in at some page |
| `visits.single_page` | visits of one page |
| `visits.duration` | the mean visit, in seconds, from its first page to its last |
| `visits.bots`, `visits.no_js` | robots' hits, and pages without JavaScript, counted apart |

They break down by `visit_site`, `visit_source`, `visit_country`, `visit_device`,
`visit_browser`, `visit_os` and `signed_in`. The application's own `dimension` goes under the name
`visits.dimension_name` gives it, `visit_area` by default, with the label
`visits.dimension_label`. Naming it after a dimension the application already declares, `market`
say, lets one filter cover both, and the application's values win. A visit's breakdown is its
landing page's. `'metrics' => ['visits' => false]` leaves them out.

## The daily rollup

The counts by source, country, device and the rest are kept by the metrics' store. Pages,
referrers, clicks out and campaigns are not, and reading them over a long period from the hits
is a scan. So `stats.visit_pages_daily` keeps people's page views and clicks out per UTC day, by
site, page, link out, referrer, source and campaign. For each it keeps the `views` (a click's
clicks) and the `visits` that began there.

- **On TimescaleDB**, once `timescale:ensure` has made `page_hits` a hypertable, it is a
  continuous aggregate. It is refreshed by its own policy, and reads include the hours not
  materialised yet.
- **Elsewhere** it is a table that `visits:rollup` fills, every 30 minutes, for yesterday and
  today, each day replaced whole. Older days are filled with
  `php bin/pramnos visits:rollup --from=2026-01-01 --to=2026-10-31`.

The assistant's tool reads it for a period longer than a month, by whole UTC days.

## For the assistant

The analyst's `visits` tool (`visits.tool` renames it) reads the hits directly, for any days.
- **The figures:** the period's figures, as the metrics count them; `compare` adds the period
  before.
- **A breakdown:** sources, campaigns, referrers, countries, devices, browsers, os, the
  application's dimension, pages, clicks out, or the kinds of account signed in. Each row has its
  views and visits.
- **`page`:** keeps only the pages whose address holds a given part.

Robots and pages without JavaScript are never in the figures. They are counted apart, and the
tool tells the model to say so.

## The urls they refer to

`page_hits.urlid`, `target` and `referrer` are declared references of the
[urls registry](Pramnos_Logging_Guide.md#the-urls-registry), so `urls:prune` keeps every
address a hit refers to.
