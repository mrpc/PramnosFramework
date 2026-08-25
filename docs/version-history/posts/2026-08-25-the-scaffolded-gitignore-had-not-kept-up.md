---
date: 2026-08-25
categories: [Changelog]
---

# The scaffolded .gitignore had not kept up with what init writes

Read off a real scaffolded project rather than off the scaffolder: four kinds of
generated file had no rule, and one docblock described a rule that had never existed.

<!-- more -->

## Fixed

- **`node_modules/` is ignored in every project.** It was written by
  `scaffoldSpaGitignore()`, which only runs for a SPA — but one does not need a build
  stack to acquire the directory: `npm install` runs at the project root for the
  OpenAPI/RapiDoc generator, and `./dockernpm` is scaffolded for every project. An MVC
  project with API docs on collected a few thousand untracked files and no rule saying
  they were expected.

- **The whole of `var/` is ignored**, replacing `/var/cache/` and `/var/logs/` by name.
  A real project also had `var/migrations/*.verified` — a per-database verification
  timestamp — and `var/migrations-schemaversion.lock`, a worker lock carrying a pid, a
  hostname and a heartbeat. Neither means anything on another machine, and naming
  directories one at a time is how the list fell behind in the first place. Nothing
  under `var/` is source and every writer mkdirs its own directory, so a clone with no
  `var/` is correct.

## Documentation

- [Getting Started](../../Getting_Started.md) gains a table of every `.gitignore` entry
  with the reason for it, including the two files that are committed **on purpose** —
  `.mcp.json` and `CLAUDE.md` are project configuration, and the point of them is that
  the next person to clone the repository gets them without being told.

- A docblock in `Init::scaffoldAiGuidelines()` claimed ".mcp.json is added to
  .gitignore because it contains DB credentials". Neither half was true: the file holds
  a command and its arguments, and no code ever added it to `.gitignore`. Corrected
  rather than deleted, because the next reader would otherwise wonder which of the two
  was the bug.
