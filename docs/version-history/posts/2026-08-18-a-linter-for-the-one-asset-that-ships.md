---
date: 2026-08-18
categories: [Changelog]
---

# A linter for the one asset that ships

`debugbar.js` is around 3700 lines and is served on every page of every project that enables the
debug toolbar. It had `var hasMvcPage` declared twice.

A consuming project's linter found it, and the duplicate had stopped **1,195 panel tests** from
running there. Nothing in this repository could have caught it: there was no `package.json`, no
ESLint config, and no CI workflow for JavaScript at all — only the docs deploy.

<!-- more -->

## Added

```bash
./lintjs              # ESLint over src and tests/js
./lintjs --fix
./lintjs src          # a subtree
```

Inside the container, like `./dockertest`, and for the same reason: the container is the
environment. A linter that reports differently depending on whose Node ran it is worse than no
linter. `npm` joins `nodejs` in the `Dockerfile`, and `./lintjs` installs it into an image built
before that rather than failing on a detail nobody wants to think about while linting.

A `JavaScript` workflow runs the linter and `node --test` on **Node 20** — the version the
container ships, so a CI failure reproduces locally instead of being a CI-only surprise.

## Every rule is a defect, not a preference

No quote policy, no semicolons, no indentation. `debugbar.js` predates this config by years:
reformatting it would bury the next real change in noise, and a `--fix` sweep across 3700 lines
is exactly the diff nobody can review.

What is enabled is what a parser can decide and a test cannot — `no-redeclare`, `no-undef`,
`no-dupe-keys`, `no-unreachable`, `valid-typeof`, `use-isnan` and a dozen more of that kind.

!!! warning "A unit test for this was tried, and deleted"
    A test scanning for duplicate `var` declarations flagged `var rows` in **six unrelated
    functions**, because it matched an *identifier* rather than a redeclaration. The reporter of
    the original bug predicted exactly that. `no-redeclare` understands scope; a grep never will.

Verified by putting the bug back: two `var hasMvcPage` declarations produce
`'hasMvcPage' is already defined  no-redeclare`, and the suite stays green either way — which is
the point. The linter sees what the tests cannot.

## The first run found six things, and two were mine

`Blob` and `setImmediate` were missing from the globals I had configured. Cheap to fix and worth
recording: the first thing a new linter reports is often its own configuration.

The other four were real:

- **A dead `CLIENT_TABS` lookup** in `debugbar.js`. Deleting it correctly meant reading the code
  rather than the error: the three tabs it named — `errors`, `client`, `api` — *are* special,
  because they are drawn from what the script observed rather than from a response payload. But
  that is encoded as explicit `tab.key === …` checks in three separate places, and nothing read
  the table. It duplicated knowledge that lives elsewhere. Wiring it up would mean editing three
  behavioural branches in a 3700-line asset, which is a refactor and not the addition of a
  linter, so the observation is recorded in the file where the constant stood.
- **Three tests destructuring a `sandbox` they never used.** The same line appears **30 times** in
  that file and only three of them were unused, so a blanket replacement would have broken the
  other 27 — the three were edited by line number.

## One process note

The first attempt at all of this ran `npm install` on the **host**, which has Node 24 while the
container has Node 20. That is the wrong environment for the same reason `./dockertest` exists,
and the host artefacts were removed before anything was committed. Both new commands run in the
container only.
