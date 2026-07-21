# Claude Code — Project Rules for PramnosFramework

## Project context

- **Branch:** active development happens on **`main`**; all new fixes/features land there.
- **Stack:** PHP 8.5, MySQL 8.0, PostgreSQL 14, TimescaleDB (Docker)
- **Test suites:** `vendor/bin/phpunit` (framework unit/characterization/integration) and the reference application integration suite (`the application integration test suite`)
- **Docs:** ship a dated changelog post under `docs/version-history/posts/` with each change (see rule 10). `docs/1.2-new-features.md` is a frozen v1.2 reference — never edit it.

## Behaviour rules

### 1. Docs travel with code

Every user-visible change or new public class/method ships with its documentation in the **same commit** — a dated changelog post under `docs/version-history/posts/` (see rule 10). Do not defer documentation to a later step.

### 2. Tests before refactoring internal framework classes

Before modifying any of `Auth`, `User`, `Logs`, `Adjacencylist`, `Migration`, ensure formal characterization tests exist in `tests/Characterization/` covering × 3 databases. The reference application integration suite counts as partial PostgreSQL coverage only — it does not satisfy this requirement.

### 4. Always run tests via `./dockertest`

**Never** run `vendor/bin/phpunit` directly. Always use:

```bash
./dockertest                         # full suite
./dockertest --filter TestName       # single test / class
./dockertest --coverage              # with HTML coverage report
./dockertest --testdox               # human-readable output
```

The script ensures the Docker containers are up, dependencies are installed, and the PHP environment inside the container is used (PHP 8.5 + correct extensions). Running phpunit outside Docker may use a different PHP version, miss extensions, or skip database integration tests entirely.

`./dockertest` is portable across Linux, macOS and WSL: it uses a mkdir-based lock (not `flock`) and falls back to a bash `timeout` when GNU coreutils is absent, so no extra tooling is required on macOS.

### 5. Commit discipline

- Every logical unit of work (bug fix, feature, doc update) is a separate commit.
- Commit message format: `type(scope): short description` — e.g. `feat(querybuilder): add whereNull/whereNotNull`, `fix(database): prepare() skips string literals for %X`.
- Never commit debug `error_log()` calls.

### 6. BC is a hard constraint

No existing public method signature may change. New capabilities are additive.

### 7. Tests have detailed explanatory comments

Every test method must carry:
- A **doc-block** explaining *what* is being tested and *why* it matters (the invariant or edge case).
- **Inline section comments** (`// Arrange`, `// Act`, `// Assert`) to mark the three phases.
- For non-obvious assertions, a one-line comment explaining what it proves.

This rule overrides the general "no comments" default. The goal is that a developer reading the test understands the contract being verified without having to trace the production code.

### 9. Framework migration timestamps — always use the current date

New framework migration files under `database/migrations/framework/` **must** use the
current date as the timestamp prefix (e.g. `2026_05_28_000001_add_something.php`).

**Never reuse `2020_01_01_*`** for new migrations.

**Why:** The `2020_01_01_*` prefix is the "baseline" epoch for all migrations that were
written before the framework's migration system existed. Existing installations (e.g.
a legacy production install) set `migration_cutoff = 2020_01_02_000000` in their settings to
skip this entire baseline — they already have all those structures via their own
app-level migrations. Any new framework migration with a `2020_01_01_*` timestamp would
be silently skipped on those installations.

**Cutoff convention for legacy installations:**
```
migration_cutoff = 2020_01_02_000000   # skips all 2020_01_01_* baseline migrations
```
Set this in the application settings of any project whose database predates the
framework migration system.

### 8. Integration tests are mandatory for every DDL/DML feature

A feature is not considered **done** until it has integration tests that run against the real database (MySQL, PostgreSQL, TimescaleDB via Docker). Unit tests that only verify SQL string output are necessary but not sufficient. Integration tests must verify that the operation actually took effect in the database (schema exists, rows were written, indexes were created, etc.).

### 11. Code coverage — >95% on all new/changed code

Every new or modified unit of production code must reach **>95% line coverage**. No feature or
phase is considered **done** below this threshold. Verify with `./dockertest --coverage`.

Coverage must be **meaningful**: it has to exercise error and edge paths (invalid input, adapter/
dependency failures, replay/conflict cases, condition mismatches), not only the happy path.
Do not pad coverage with assertion-free tests. This supersedes the earlier >=90% baseline.

### 10. Keep the documentation site (MkDocs / GitHub Pages) current

The published docs site lives under `docs/` and is built with **MkDocs Material**
(`mkdocs.yml`, deployed by `.github/workflows/docs.yml`). Whenever you change documentation
or ship a user-visible change that warrants a changelog entry:

- **Changelog (date-based, version-independent):** add a dated post under
  `docs/version-history/posts/YYYY-MM-DD-<slug>.md` with `categories: [Changelog]`. This is
  the running, per-change log — **not** `docs/1.2-new-features.md` (which is the frozen v1.2
  technical reference) and **not** tied to a version. Group entries under `Added` / `Fixed` /
  `Documentation` etc. The MkDocs blog plugin paginates and archives posts by date, so no
  single page grows unbounded.
- **Releases:** version releases also get a curated row in `docs/releases.md`
  and a `categories: [Releases]` post.
- **Guides:** when you edit or add a guide page under `docs/`, wire it into the `nav:` in
  `mkdocs.yml` and keep cross-links valid.
- **Verify the build** with `./dockerdocs build` before committing site changes; treat broken
  links / nav warnings as failures.

> ⚠️ **Blog plugin gotcha (Material 9.7.6):** do **not** set `post_dir` in the `blog:`
> plugin config — explicitly setting it (even to its default `posts`) silently breaks the
> blog index post-stream *and* archive-page generation (posts still build individually, but
> the index shows nothing and archive links 404). Leave `post_dir` unset; posts live in
> `version-history/posts/` by default. Verified by isolated repro.

Do this **in the same commit** as the change it documents, mirroring rule 1.

---

## Session handoff rule

> **This rule exists because context windows are finite.** When you notice that the conversation is approaching its context limit (many large tool results have accumulated, responses are being compressed, or you are explicitly warned), act on this rule **before** you run out of context.

When context usage is high and you are about to stop mid-task, output a **Session Handoff block** as your last response:

```
## ⏸ Session Handoff

### What was done this session
- <bullet per completed task, with commit hash if applicable>

### Last task — where we stopped
<one paragraph: what the task was, what was the specific last action taken,
what file/line/function was being edited, and what remains to do>

### Exact next step
<the single next concrete action to take, specific enough that another
developer (or a new LLM session) can execute it without reading the full
conversation history — include file paths, method names, test commands>

### Pending / at-risk items
- <anything started but not committed>
- <anything that was about to break or needed a follow-up>

### Useful context for the next session
- Key files touched: <list>
- Run to verify state: `<command>`
```

Output this block even if the work is partially done. Do not wait until the absolute last token — leave enough context to write it cleanly.
