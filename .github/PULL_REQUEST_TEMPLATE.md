## What this changes, and why

<!-- Not just what the diff shows — the problem this solves or the behaviour this fixes. -->

## Checklist

- [ ] `./dockertest --nocoverage` passes locally
- [ ] Each commit is one logical change, with a `type(scope): description` message
- [ ] If this is a user-visible change or a new public class/method: the guide page that owns
      the topic is updated, and there's an entry in today's changelog post
      (`docs/version-history/posts/YYYY-MM-DD.md`)
- [ ] No public method signature changed — if a behaviour change could still affect a
      consuming application, the commit takes `!` and there's a row in
      `docs/Pramnos_Upgrade_Guide.md`
- [ ] New DDL/DML has an integration test against a real database, not only a unit test of the
      generated SQL

## Anything the reviewer should know

<!-- Trade-offs, things you considered and ruled out, parts you're unsure about. Delete this section if there's nothing to add. -->
