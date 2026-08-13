---
date: 2026-08-14
categories:
  - Changelog
  - Fixed
tags:
  - mcp
  - console
---

# The MCP server says which project it is

An MCP client lists its servers by the name each one reports. Every Pramnos project
reported **"Pramnos App"**, so a picker with two of them open could not tell them
apart.

<!-- more -->

The name came from a database-stored setting, falling back to the `TITLE` constant and
then to that generic default — while `app/app.php`'s `name` was right there, already
read by the console, with no database involved.

`mcp:serve` now prefers it, in this order:

1. `app/app.php`'s `name`
2. the `title` setting
3. the `TITLE` constant
4. `"Pramnos App"`

The ordering matters beyond tidiness. A database-stored title is reachable only
through `Settings`, so a project whose settings load fails for any reason fell all the
way through to the default — which is precisely how this was noticed, on a PostgreSQL
project whose settings query was failing at the time. A configuration file cannot fail
that way.

`McpServer::getName()` is public now as well, so what the server ended up calling
itself is a question that can be asked rather than inferred from a handshake.

## Documentation

- [Console Guide](../../Pramnos_Console_Guide.md) — the `.mcp.json` entry, and why the
  name comes from the configuration file.
