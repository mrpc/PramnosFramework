# Claude Code — Project Rules for PramnosFramework

## Project context

- **Branch:** `v1.2-dev` → target `main`
- **Stack:** PHP 8.4, MySQL 8.0, PostgreSQL 14, TimescaleDB (Docker)
- **Test suites:** `vendor/bin/phpunit` (framework, 171 tests) and the Urbanwater integration suite (`/home/mrpc/projects/urbanwater/src`, 5 176 tests)
- **Roadmap:** `ROADMAP_1.2.md` — always check before deciding what to work on next
- **Progress log:** `PROGRESS.md` — update after completing any non-trivial task
- **Feature docs:** `docs/1.2-new-features.md` — must be updated in parallel with every implementation

## Behaviour rules

### 1. Docs travel with code

Every new public class or method that ships must have its entry in `docs/1.2-new-features.md` in the **same commit**. Do not defer documentation to a later step.

### 2. Tests before refactoring internal framework classes

Before modifying any of `Auth`, `User`, `Logs`, `Adjacencylist`, `Migration`, ensure formal characterization tests exist in `tests/Characterization/` covering × 3 databases. The Urbanwater integration suite counts as partial PostgreSQL coverage only — it does not satisfy this requirement.

### 3. Phase order is mandatory

Implementation order: **Phase 1 (Grammar → DDL) → Phase 4 (Infra) → Phase 2 (Backports)**. Do not start Phase 2 backport work without Feature Registry, Service Providers, and Migration System in place.

### 4. Always run tests via `./dockertest`

**Never** run `vendor/bin/phpunit` directly. Always use:

```bash
./dockertest                         # full suite
./dockertest --filter TestName       # single test / class
./dockertest --coverage              # with HTML coverage report
./dockertest --testdox               # human-readable output
```

The script ensures the Docker containers are up, dependencies are installed, and the PHP environment inside the container is used (PHP 8.4 + correct extensions). Running phpunit outside Docker may use a different PHP version, miss extensions, or skip database integration tests entirely.

### 5. Commit discipline

- Every logical unit of work (bug fix, feature, doc update) is a separate commit.
- Commit message format: `type(scope): short description` — e.g. `feat(querybuilder): add whereNull/whereNotNull`, `fix(database): prepare() skips string literals for %X`.
- Never commit debug `error_log()` calls.
- `PROGRESS.md` is updated in the same commit that closes a task.

### 6. BC is a hard constraint

No existing public method signature may change. New capabilities are additive. See `ROADMAP_1.2.md` → "Αρχή Σχεδιασμού: Backward Compatibility" for the full rule set.

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
