---
date: 2026-08-26
categories: [Changelog]
---

# Every scaffolded project refused to test itself on macOS

`./dockertest` held its lock with `flock`. On macOS `flock` does not exist, so
`flock: command not found` made the acquire fail and every run answered "another
./dockertest run is already in progress" — with no other run anywhere. The suite could
not be started at all.

<!-- more -->

## Fixed

- **The scaffolded `dockertest` locks with a directory.** `mkdir` is atomic on Linux,
  macOS and WSL alike and succeeds only when the directory does not already exist; a PID
  file inside it lets a later run recognise a lock left behind by a hard-killed process.

  The framework's *own* `dockertest` was fixed for exactly this reason, and kept
  generating the broken version for every project it scaffolded. A developer following
  the framework's own instruction to "always run tests via `./dockertest`" was told, on
  a supported platform, that they could not.

  `flock` released itself when the process exited; a directory does not, so the release
  is now an explicit `trap '_release_lock' EXIT` and every early exit path goes through
  the same function. Without that the fix would have traded one platform's failure for
  every platform's: the first run would leave a lock and the second would refuse.

  There is a test that scaffolds a project, greps the generated script for `flock`, and
  runs `bash -n` over it — because a script assembled from a heredoc full of escaped
  dollars being *written* is a long way from it *running*.

## Documentation

- [Testing](../../Pramnos_Testing_Guide.md) gains "`./dockertest` says a run is already
  in progress": where the lock lives, what `--force` does, and how to fix a project
  scaffolded before today — version control does not update its copy for you.
