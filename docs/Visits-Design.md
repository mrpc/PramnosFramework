---
use_cases:
  - Designing the framework's site-traffic tracker shared by every application
  - Deciding whether a site counts visits with a cookie or without one
  - Fixing the urls registry's collisions, duplicates and orphans
  - Moving an application's own visit tables onto the framework without downtime
  - Checking whether an application's own Web Vitals duplicate the framework's
---

# Site traffic (design, FW-365)

**Status:** proposal. The content platform approved it with the changes folded in below (§9). The marketplace's answer is awaited.

Two applications count their sites' traffic, each its own way. The marketplace has a small
Matomo: a visit with a cookie, its pages, its source, the person once they sign in. The content
platform has a cookieless counter for the many sites it is connected to, written through the
framework's `WriteSpool`, with continuous aggregates kept for ever. Both become one feature of the
framework, `visits`, opt-in. Neither loses anything. The `urls` registry they both lean on is
fixed on the way.

## 1. What there is

| | Marketplace | Content platform | Framework today |
|---|---|---|---|
| Beacon | `tracker.php`, every page, even from the page cache | `sendBeacon` POST and a `<noscript>` GET, 204 | `pf-vitals.js` → `ClientVitals` (Web Vitals only) |
| Visitor | `visitid` cookie; a visit ends after 30′ idle, or on another site or campaign | 8 bytes of `sha256(daily salt · IP · UA · site)`; no visit, nothing kept of the person | — |
| Person | the account, once signed in | never | `SessionTrackingMiddleware`: who is online |
| Rows | `visits` (2.9M, 1.4 GB) + `visitactions` (3.2M, 435 MB) for three months, **updated** on every page | `site_hits` hypertable, append-only, 90 days; CAggs for ever | — |
| Writing | synchronous INSERT/UPDATE in the request | `WriteSpool::append()`, drained every minute | `WriteSpool`, `spool:drain` |
| Bots | `BOT`, `NO_JS` left out of the counts | `BotDetector`, crawler list, referrer spam list, 60/min, 20 events | `BotDetector` |
| Place | GeoIP (city) | DB-IP City Lite, `geoip:update` | — |
| Pages | `urls` via `Token::urlId()` (crc32) | post id or path | `urls`, `tokenactions.urlid` |
| Sites | one, three markets by path | many (`property_id`), a public key and its origins | — |
| Assistant | `VisitsTool` | `SiteTrafficTool` | — |

## 2. The rows

Rule 15: the engine's state goes in `pramnos.*`, the figures in `stats.*`.

### 2.1 Append-only, never updated

The marketplace's `visits` row is updated on every page: its last action, its count, its person.
An updated row cannot be compressed on TimescaleDB, and an UPDATE of a hot row is the most
expensive write a tracker can do. So nothing is updated. A visit's later facts are read from its
hits.

- **`pramnos.page_hits`**, a hypertable on `at`, primary key `(hitid, at)`:

  | Column | |
  |---|---|
  | `hitid`, `at` | Time to the second, in the key |
  | `site` | `pramnos.sites.siteid` |
  | `visitor` | `bigint`: the cookie's id, or the day's hash |
  | `visit` | `bigint`, the visit's id (cookie model); null cookieless |
  | `seq` | Its number within the visit: 1 is the landing page |
  | `kind` | `view`, `click` (a link out), `event` |
  | `urlid` | The page, through `urls` (§5) |
  | `post` | The application's own id of what the page shows (an article), 0 for none: a renamed post keeps its history |
  | `target` | `urlid` of the link out, for a click, its query kept (`watch?v=` is the video) |
  | `referrer_host`, `referrer` | The host as text, the rest as a `urlid`; on the first hit of a visit |
  | `source`, `medium`, `campaign` | The classification (§3.3) and the utm, on the first hit |
  | `userid` | The person, from the hit on which they are known |
  | `country`, `region`, `city` | GeoIP, the address then dropped |
  | `device`, `browser`, `os`, `screen` | |
  | `flags` | Bit field: bot, no JavaScript, prerender, from the page cache |

- **`pramnos.sites`**: `siteid`, `tenant`, `name`, `key` (public; an existing key is taken as it
  is), `origins` (each host with its `www.` and subdomains), `visitor` (`cookie` or `cookieless`),
  `idle` (30′), `keep` (the raw rows' retention: 90 days for the content platform), `count_nojs`
  (a page without JavaScript counts as a view in the figures: the content platform's do).
- **Continuous aggregates** (real-time, refreshed every 30′, kept for ever), in `stats.*`:
  - `traffic_hourly` and `traffic_daily` (site × source × device × flags: hits, visits, landings,
    exits);
  - `visitors_daily` (site × day, flags left out: distinct visitors — a distinct does not add up
    across groups, so it has its own; a range adds up its days, right for the daily hash);
  - `pages_hourly` (site × post × hour) and `pages_daily` (by post, and by path for post 0);
  - `referrers_daily` (referrer host and path → landing post or path: "from that page to this
    one");
  - `campaigns_daily` (source, medium and campaign together);
  - `places_daily`, `tech_daily`;
  - `clicks_daily` (target host and path, the query kept).

  All of them are in UTC. A visit's facts come from here, not from a visits table: its
  length is `max(at) − min(at)`, and its pages are `count(*)`.
- **Without TimescaleDB** — MySQL, plain PostgreSQL — the same `stats.*` tables are plain tables,
  filled by a `visits:rollup` job every 30′ from the raw rows since its last mark. The readers do
  not know which they read.

### 2.2 The visitor models

Per site, `sites.visitor`:
- **`cookie`** (the marketplace's): a first-party `pf_vid` cookie, a visit's id in `pf_visit`,
  30′ idle, a new visit on another site or campaign. The account joins on the first hit after
  signing in, and the hits before it are the same visit's, so the visit is theirs.
- **`cookieless`** (the content platform's): the daily-salted hash, no cookie, no visit, never
  a person. The salt is replaced at midnight UTC, in `pramnos.settings`.

## 3. Taking a hit

### 3.1 The beacon

`pf-hits.js`, beside `pf-vitals.js`: one `sendBeacon` per page view and per click out, with the
clicks queued before the page leaves; a `<noscript>` image for no JavaScript.
`POST /pf/hit` (and `GET` for the image) answers 204 always. Mounted by the feature, and
served from the page cache too: it asks nothing of the session.

Beacons already embedded elsewhere keep working. A plugin's or a customer's snippet posts to an
application's own address with its own fields, and the application's route hands them on:
`Visits::record($payload, $ip, $ua, $origin, $map)`. `$map` names the payload's fields (`t`
v/c, `p` post, `u`, `r`, `h`, `s`/`m`/`c` utm, for the content platform).

### 3.2 Refused before it costs anything

A key that names no site, an origin not the site's, a referrer on the spam list (Matomo's), more
than 60 a minute from one visitor (an atomic cache), and more than 20 events in a request are
dropped. **A bot or a page without JavaScript is kept, flagged** (`flags`), and counted apart:
the marketplace's reports say how many there were, and a wrong flag can be corrected later.
The content platform drops them today; it would keep them, flagged, and its figures exclude them
as now.

### 3.3 Where it came from

`Visits\Source::classify($referrer, $utm, $landing)` returns the marketplace's `VisitSource`
classes: direct, search, social, AI assistant, email, campaign, another website, internal. The
host lists are the framework's and an application adds its own (`visits.sources`).

### 3.4 Written later

`WriteSpool::append('pramnos.page_hits', $row)`: a line in a file, or redis. `spool:drain` writes
them in batches of 500 every minute, as the content platform does now. The marketplace's hits go
the same way, so its pages no longer wait on an INSERT.

### 3.5 Place and device

- `Pramnos\Visits\GeoIp`: the content platform's reader of a MaxMind-format database, DB-IP City
  Lite by default, refreshed monthly by `geoip:update`. The marketplace's own GeoIP2 database
  works with it unchanged. `maxmind-db/reader` is a `suggest`; without it there is no place, and
  the hit is still counted. The attribution DB-IP asks for is shown wherever places are shown.
- Device, browser and OS from the user agent with the framework's `BotDetector` parser; the UA
  itself is not kept.

## 4. Reading it

- **Metrics** (FW-360's): `visits.views`, `visits.visitors`, `visits.visits`, `visits.landings`.
  Their dimensions are site, source, country, device and page group. They read the daily
  aggregate, and bots and no-JS are left out unless a filter asks for them.
- **The assistant's tool `visits`** joins the marketplace's `VisitsTool` and the content
  platform's `SiteTrafficTool`. It answers sources, campaigns, countries, pages, referrers, clicks,
  devices and a period against another. `visits.tool` renames it.
- **A dashboard card**, and "online now" from `SessionTrackingMiddleware`.
- **The JSON API** for the content platform's stats pages, with the attribution.
- **Raw reads within `keep`**: `Visits::hits($site, $from, $to)` (totals, distinct visitors, top
  posts), for reports the aggregates do not answer.
- **`Visits::erase($tenant)`**: an organisation's sites, hits and figures, for its account's
  erasure.

## 5. The `urls` registry

Measured on the marketplace: 13.8M rows, 3.8 GB, never cleaned. About 567K are referred to; about
96% are orphans of deleted `visitactions`. The lookup is by `crc32` on a non-unique index. So 235
URLs are there twice, a race of two inserts. Worse, at 13.8M URLs crc32 expects about 22,000
collisions: a new URL whose hash matches an old one silently takes the old one's `urlid`.

- **A hash that does not collide:** a new column `sha` (`binary(20)` on MySQL, `bytea` on
  PostgreSQL), the SHA-1 of the whole URL, with a **unique** index. `Token::urlId()` looks up by
  it and inserts with "on conflict, read the one there", which ends the duplicates. `hash`
  (crc32) stays, unread, so nothing that reads it breaks. It goes in a later version.
- **The text:** `url` widens to 2000 characters. A longer URL is kept cut and found by the hash
  of the whole of it, as now.
- **Moving an installation** (`urls:rehash`, in batches by `urlid`, resumable):
  1. fill `sha` for every row;
  2. URLs there twice: point every reference at the lower `urlid`, then delete the other;
  3. make the unique index;
  4. switch the lookup.

  A collision already made cannot be undone: the old hits stay on the URL they were filed
  under. From the switch on, none is made.
- **Orphans:** `urls:prune` deletes URLs nobody refers to, in batches with `NOT EXISTS`.
  Referrers are declared, not guessed: `tokenactions.urlid`, `pramnos.page_hits.urlid`,
  `.target` and `.referrer`, and any table an application adds
  (`UrlRegistry::referencedBy('table', 'column')`). A URL younger than a day is never pruned,
  because a hit may still be in the spool. The marketplace's first run frees about 13M rows. After
  that it runs weekly, after the raw rows' retention.
- The name stays `urls` (rule 15: a table applications had before the framework adopted it). The
  foreign key `tokenactions.urlid → urls` cascades, so only an unreferenced URL is deleted.

## 6. `timescale:ensure` and the time in the key

A hypertable needs its time column in every unique key. `HypertableRegistry` assumes it is there
already. A table created plain with a `PRIMARY KEY (id)` is then left plain for good. The spec
gains `'primary_key' => ['hitid', 'at']`. For an existing plain table, `timescale:ensure` then:
- replaces the key (`ALTER TABLE … DROP CONSTRAINT …, ADD PRIMARY KEY (…)`);
- makes it a hypertable with `migrate_data => true`.

Both hold a lock for as long as the table takes. **That suits a small table, not 2.9M rows in
production**, so `ensure` refuses above `--max-rows` (100,000 by default) and points to §7.

## 7. Moving over without downtime

The marketplace's own move is its own, one-off script (the rule for conversion code). The
framework gives it the steps, and the same steps serve the content platform's `site_hits`:

1. **Beside.** The framework's tables are made empty, beside the old ones; nothing reads them.
2. **Both.** The tracker writes its old row as now and also appends the framework's hit. Every
   hit from then on is in both.
3. **The past.** `Visits::import(iterable $hits)` copies history oldest first, in chunks of a
   day, each chunk idempotent (the old ids kept in `import_ref`). It can be stopped and resumed,
   and it is throttled like the deferred-write drain (WAL and replica lag). The marketplace's 3.2M
   actions and 2.9M visits become about 3.2M hits.
4. **Compared.** `visits:compare --from --to` sets the old figures against the new, day by day:
   visits, visitors, views and sources. It lists any day that differs by more than 0.5%.
5. **Switched.** The metric, the tool and the reports read the framework's tables. The old writes
   stop, and the old tables stay read-only for 30 days.
6. **Dropped**, by the application's own migration.

The content platform's `site_hits` maps nearly one to one. Its CAggs are rebuilt from the
imported rows.

## 8. Web Vitals: not doubled, but twice

The framework's `pf-vitals.js` → `pramnos.client_vitals` keeps one row per metric. The
marketplace's `WebVitals` (`vitals.js` → `clientvitals`) keeps one row per navigation. Neither
counts twice today: the marketplace leaves out the framework's `create_client_vitals_table` and
answers `/ClientVitals` itself. It is still the same measurement written twice, and its own
docblock says it waits for the framework's. What it has that the framework's lacks:
- the market;
- the admin theme (`ui_version`);
- its page groups (the framework takes the same as `vitals.groups`);
- a hypertable with 90 days.

Proposal: the framework's gains `vitals.dimensions` (`fn (string $path, array $report): array`,
for market and theme) and a `HypertableRegistry` entry. The marketplace then moves its RUM onto
it, once its before/after comparison (P0) is done.

## 9. Questions for the applications

1. **Marketplace:** keep the raw hits for good (`keep` null, about 1 GB a quarter compressed far
   smaller), or 13 months with the aggregates for ever?
2. **Marketplace:** the three markets — one site with a market dimension from the path (as
   vitals does), or three sites?
3. **Content platform — answered:** bots and no-JS are kept flagged, with `count_nojs` on so its
   figures stay as they are.
4. **Content platform — answered:** `post` is a column, and the pages are aggregated by it.
5. **Content platform — answered:** distinct visitors, referrer → landing, clicks with their
   query, and the utm triple are covered (§2.1). The raw reads and the erasure are in §4, and an
   existing key and beacon are covered by §3.1. **Marketplace:** anything of yours still
   missing.

## 10. Order

| Step | What |
|---|---|
| 1 | `urls`: `sha`, unique index, `urls:rehash`, `urls:prune`, references declared |
| 2 | `HypertableRegistry` primary key; `timescale:ensure` for an existing small table |
| 3 | `pramnos.sites`, `page_hits`, the beacon, refusals, spool, GeoIP, source classes |
| 4 | Aggregates (CAggs, or the rollup), the metrics, the tool, the card, the API |
| 5 | `Visits::import()`, `visits:compare`; the content platform's move, then the marketplace's |
| 6 | Web Vitals: `vitals.dimensions` and the hypertable entry |

Step 1 helps today, whatever is decided about the rest. It can go first on the marketplace's word.
