# Dashboard

The administration overview: how many people are using the site, how the API is performing, how the database is doing, and whether the health checks pass, all on one page.

## What you can do here

- See **active users** now and over recent periods.
- See **API requests**, error rate and average latency for the last 24 hours.
- See the **database size**, connections and cache hit ratio.
- See the **health check** results at a glance.
- Open the **database** detail page: server version, size, connections, running processes, table sizes, and on TimescaleDB its hypertables, continuous aggregates and scheduled jobs.
- Open the **cache** detail page: the cache adapter, its categories and a browser of stored items.
- On the cache page, view a single item's content, or **clear the cache**.

## Good to know

- Clearing the cache removes every cached entry. The site keeps working, but pages may be slower for a short while as the cache refills.
- The cache browser shows expired entries too, marked as expired, so you can still see what was stored.
- The "Active users (now)" figure comes from the current sessions; the Users screen's session list shows who they are.
- The database figures depend on what the database server lets the site read. Some numbers may show as empty on restricted hosting.

## Common questions

- **The API figures are all zero.** No API calls were recorded in the period, or the API is not in use on this site.
- **Is clearing the cache safe?** Yes. It only removes copies; nothing is lost.
