# Security Policy

## Reporting a Vulnerability

Please **do not** open a public issue for a security vulnerability.

Instead, use GitHub's [private vulnerability reporting](https://github.com/mrpc/PramnosFramework/security/advisories/new)
for this repository: it opens a draft security advisory that only you and the maintainer can
see, rather than a public issue. GitHub's own guide to the process is here:
[Privately reporting a security vulnerability](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing/privately-reporting-a-security-vulnerability).

Include, where you can:

- The affected version or commit.
- The database driver (MySQL, PostgreSQL, TimescaleDB) if relevant.
- A minimal reproduction.
- The impact as you understand it.

## Supported Versions

Security fixes are made against `main` (`dev-main`). There is no long-term support branch for
a previous major version at this time.

## Scope

This covers the framework's own source (`src/`, `database/migrations/framework/`). A
vulnerability in an application built on the framework, in a third-party dependency, or in
Composer/PHP itself belongs to that project instead.
