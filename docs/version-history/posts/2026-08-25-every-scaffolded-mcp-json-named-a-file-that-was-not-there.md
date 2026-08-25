---
date: 2026-08-25
categories: [Changelog]
---

# Every scaffolded .mcp.json named a file that was not there

The MCP server shipped in every new project and could never start. Found by looking at
a scaffolded project, not at the scaffolder — the test that covered this file was
asserting the bug.

<!-- more -->

## Fixed

- **`.mcp.json` names the CLI the project actually has.** The stub hardcoded
  `php ./bin/pramnos mcp:serve`. That path exists in the framework's own repository and
  nowhere in a scaffolded project, where the CLI is `<cliName>.php` at the root and
  `bin/pramnos` lives under `vendor/`. Nothing failed: an MCP client given a command it
  cannot run simply has no server, so the symptom was a feature that was never there.

- **A Docker project gets the container form**, with `-T`. Two separate reasons, both
  load-bearing. The database is reachable only from inside the container and `mcp:serve`
  is a database tool above all, so a host-side server answers every query with a
  connection error. And MCP speaks stdio over the pipe, so `docker-compose exec` without
  `-T` allocates a TTY the protocol never gets a clean stream through — which is also
  why the scaffolded `./<cliName>` wrapper is not reused: it keeps its TTY on purpose,
  for interactive prompts.

  ```json
  { "mcpServers": { "myapp": { "command": "docker-compose", "args":
      ["exec", "-T", "-u", "www-data", "app", "php", "myapp.php", "mcp:serve"] } } }
  ```

- **The test now asserts the file exists.** The old one checked the rendered stub against
  the literal it had been written from — `assertContains('./bin/pramnos', ...)` — so it
  agreed with the defect for as long as the defect lasted. The replacement scaffolds a
  project and calls `assertFileExists()` on the script `.mcp.json` names, in both the
  Docker and non-Docker shapes. A configuration file no test executes cannot fail a
  suite; the only assertion worth making about one is that what it points at is there.

## Documentation

- [MCP Guide](../../Pramnos_MCP_Guide.md) shows both shapes and says why `-T` is not
  optional. `McpServe`'s own docblock did the same thing the stub did — it used
  `./bin/pramnos` as the example, correct in this repository and wrong everywhere it
  would be copied from.
