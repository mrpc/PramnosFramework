---
date: 2026-07-17
categories:
  - Changelog
  - CLI
tags:
  - cli
  - scaffolding
  - init
readtime: 1
---

# `init` prompts for the admin password (with a safe fallback)

Project initialisation now lets you set the first admin account's password
interactively, and hardens that input against terminal editing quirks.

<!-- more -->

## Added

- **Admin password prompt** in `project:init` — press enter to accept a strong
  random default, or type your own.

## Fixed

- **UTF-8-aware line editing.** Before prompting, `init` enables `stty iutf8`
  on the terminal so backspace erases a whole multibyte character. On terminals
  left in byte-oriented mode (notably WSL) a backspace would otherwise delete a
  single byte of a multibyte character, silently corrupting the stored password.
- **Defensive `sanitizePassword()`.** As a second layer, the entered value is
  cleaned of stray backspace/DEL bytes, invalid UTF-8 and control characters (a
  warning is shown if anything was removed), so the saved password always
  matches what the user can reproduce at login.
- **`writeFile()` creates the parent directory** before writing, so
  `installUiFramework()` works standalone (e.g. `project:switch-ui`) against a
  project missing a sub-directory.
