# Contributing to Pramnos Framework

Thanks for considering a contribution. The short version:

1. **Docker is the only requirement.** `git clone`, then `./dockertest --nocoverage` — it
   builds the environment, brings up the databases, and runs the full test suite in one step.
2. **Always run tests through `./dockertest`**, never `vendor/bin/phpunit` directly.
3. **One logical change per commit**, with a message in `type(scope): short description`
   form — e.g. `fix(database): prepare() skips string literals for %X`.
4. **A user-visible change ships its documentation in the same pull request**: the guide page
   that owns the topic, and an entry in today's changelog post
   (`docs/version-history/posts/YYYY-MM-DD.md`).
5. **Public method signatures never change.** Add a new method instead.

The full guide — environment setup, test conventions, documentation requirements, the
backward-compatibility rules a change must follow, and how a pull request is reviewed — is
the [Contributing Guide](https://mrpc.github.io/PramnosFramework/Pramnos_Contributing_Guide/)
on the documentation site.

Building an application *with* the framework, rather than working on its own source? See the
[Getting Started guide](https://mrpc.github.io/PramnosFramework/Getting_Started/) instead.

Found a bug or have a feature request? [Open an issue](https://github.com/mrpc/PramnosFramework/issues).
