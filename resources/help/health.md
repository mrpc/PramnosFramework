# Health

A one-page check-up of the installation. Every registered health check runs when the page opens, and the results are shown with the database, cache, active sessions, PHP version and peak memory.

## What you can do here

- Read the **overall status** and the status of each check, with its message.
- See which **database** the site is connected to and its version, and which **cache adapter** is in use.
- See how many **active sessions** there are right now.
- Open **PHP Info**, the full PHP configuration, if you are a top-level administrator.

## Status colours

| Status | Meaning |
|---|---|
| ok (green) | Working as expected. |
| notice (blue) | Worth knowing about, but not a problem. |
| degraded (yellow) | Working, but something needs attention. |
| down (red) | A part of the system is not working. |

## Good to know

- The checks run live each time the page is loaded, so reloading gives a fresh reading.
- Uptime monitors can read the same results without signing in, from the `Health/check` address (full detail) and the `Health/status` address (only healthy or unhealthy, plus the names of failing checks). Both answer with an error status when the site is degraded or down, so a monitor can alert on it.
- The full-detail address reveals versions and internal details. If it is reachable from the internet, point public monitors at `Health/status` instead.

## Common questions

- **A check says degraded but the site works.** Degraded means something needs attention before it becomes an outage. Read the check's message for what to look at.
