---
use_cases:
  - Setting up a local environment to work on the framework's own source
  - Running the test suite before opening a pull request
  - Writing a commit message or structuring a pull request for this repository
  - Understanding what documentation a change needs to ship with
  - Understanding the backward-compatibility rules a change must follow
---

# Contributing to Pramnos Framework

This page is for working on the framework's own source — `src/`, `tests/`, `docs/`,
`database/migrations/framework/`. If you are building an application *with* the framework
instead, see the [Getting Started guide](Getting_Started.md).

## Setting up

Docker is the only requirement; nothing else needs a local install.

```bash
git clone https://github.com/mrpc/PramnosFramework.git
cd PramnosFramework
./dockertest --nocoverage
```

The first run builds the PHP/Apache image, brings up MySQL, PostgreSQL/TimescaleDB and Redis,
installs Composer dependencies, and generates the OAuth2 signing key pair the OAuth2
integration tests need — all automatically. A later run reuses the same containers and is
much faster.

## Running tests

Always through `./dockertest`, never `vendor/bin/phpunit` directly — the script ensures the
containers are up, dependencies are installed, and the test run uses the PHP version and
extensions the project targets rather than whatever is on your machine.

```bash
./dockertest --nocoverage                     # the suite — the usual way to run it
./dockertest --nocoverage --filter TestName    # a single test or class
./dockertest --coverage                        # only when the coverage number itself matters
./dockertest --testdox                          # human-readable output
```

`--nocoverage` is the default to reach for: the image enables Xdebug coverage instrumentation,
which on the full suite is roughly 3× slower than a plain run — worth it when you need the
number, wasted when you are just checking whether a test is red or green.

Every push and pull request runs the same suite in GitHub Actions automatically.

## Making a change

- **Branch:** active development happens on `main`.
- **One logical change per commit.** A bug fix, a feature, a documentation update — each is
  its own commit, not bundled with unrelated changes.
- **Commit messages** follow `type(scope): short description` — for example
  `feat(querybuilder): add whereNull/whereNotNull` or
  `fix(database): prepare() skips string literals for %X`.
- **A change that could break a consuming application** takes a `!` after the type/scope —
  `fix(database)!: …` — and gets a row in the Upgrade Guide (below). This applies to behaviour
  changes as much as to signature changes: a default, a response shape, or a decoded value
  changing while the code still compiles is exactly the kind of change this is for.
- **Public method signatures do not change.** Add a new method rather than adding a parameter
  to an existing one — a subclass that overrides it fails to load entirely (a fatal error, not
  a warning) the moment its signature stops matching the parent's. Where there genuinely is no
  compatible way to make a change, it takes `!` and a short note in the day's changelog post
  saying what an override needs to do.

## Documentation travels with the change

A user-visible change or a new public class/method ships its documentation in the **same
pull request** — both of:

1. **The guide page that owns the topic** (`docs/Pramnos_*_Guide.md`), updated to describe
   current behaviour. If no page owns the topic yet, add a section to the closest one or
   create a new page, give it a `use_cases:` frontmatter list, and add it to `mkdocs.yml`'s
   navigation — a page outside the nav builds without error but is unreachable on the
   published site.
2. **An entry in today's changelog post**, `docs/version-history/posts/YYYY-MM-DD.md` —
   appended to if the file already exists for today, created following the existing posts'
   format if it does not.

A guide describes the feature as it is now; the changelog post is the dated record of what
changed and why. Keep dates and "this used to…" phrasing out of guide prose — that is what
the changelog is for.

## Backward compatibility

No existing public method signature changes, ever — see above. A behaviour change (not just a
signature) that could affect a consuming application gets a row in
`docs/Pramnos_Upgrade_Guide.md`, under `## Since v1.2 — unreleased`: three columns — what
area, what changed, and what an application should do about it, with the third column
specific enough to act on directly.

## Tests

- **Integration tests are required for DDL/DML changes** — a unit test verifying the SQL
  string a change produces is necessary but not sufficient; an integration test must show the
  effect against a real database (MySQL, PostgreSQL, TimescaleDB, run via Docker).
- **New or changed code is expected to reach high line coverage**, checked with
  `./dockertest --coverage`, exercising error and edge paths as well as the happy path — not
  padded with assertion-free tests.
- Raw SQL in code you are already editing is a good opportunity to convert it to the query
  builder, which is the layer that actually knows the dialect differences between MySQL and
  PostgreSQL.

## Opening a pull request

- Describe what changed and why, not just what the diff shows.
- The full test suite runs automatically; a pull request with a red check will need that
  resolved before it can be merged.
- Keep unrelated changes out of the same pull request — a formatting pass bundled with a bug
  fix makes the actual fix harder to review and to revert independently if needed.

## Reporting a bug or requesting a feature

Open an issue on GitHub. For a bug, the most useful report includes the smallest input that
reproduces it and what you expected instead of what happened.
