# AI Context & Instructions for Pramnos Framework

## Project Overview
Pramnos Framework is a modern, independent PHP framework. **Production code must strictly target PHP 7.4**, while the **testing environment runs on PHP 8.4+**. It is built natively without relying on heavy external framework vendors. The framework supports its own MVC architecture, custom HTTP request/response routing, database abstractions (simultaneous MySQL and PostgreSQL/TimescaleDB support), caching layers (Redis/Memcached), and robust translation layers.

## Core Directives for LLMs
1. **Never Assume Laravel/Symfony Paradigms**: This is an independent framework. Always inspect `src/Pramnos/*` logic before assuming dependency injection rules, ORM paradigms, or router behaviors.
2. **Strict PHP 7.4 Compatibility for Core Code**: Any code modifying `src/Pramnos/*` must be 100% compatible with PHP 7.4. You may deploy PHP 7.4 typed properties, but **do not** use PHP 8+ features like match expressions, named arguments, or native attributes in the core framework logic.
3. **PHP 8.4 for Testing Only**: The `tests/` directory executes via Docker on PHP 8.4 (using PHPUnit 11). Fast-forwarded PHP 8 features and native attributes are securely allowed exclusively within test classes.
3. **Multi-Database Dialects**: The framework explicitly connects to both **MySQL** and **PostgreSQL/TimescaleDB**. Ensure modifications to `src/Pramnos/Database` abstract queries across both SQL dialects safely.

## Architecture Guidelines
- `src/Pramnos/` - The core framework logic.
  - `Database/` - Database connection and querying logic.
  - `Routing/` - Request dispatcher and route registration.
  - `Http/` - Request/Response state manipulation.
  - `Application/` - Application bootstrapping and config management.
- `tests/` - The strictly isolated PHPUnit 11 testing environment.
  - `tests/fixtures/app/` - A dummy/mock Application setup used strictly for Integration tests.
  - `tests/Unit/` - Fast, isolated tests mocking infrastructure.
  - `tests/Integration/` - Tests that explicitly boot the framework via the dummy app and interact with MySQL, Postgres, or Redis.

## Docker & Testing Infrastructure
The testing environment is 100% containerized. **Do NOT run `php` or `phpunit` natively on the host system.** The host system does NOT have a full standard development environment installed. Any necessary PHP scripts, tests, or composer commands must be executed strictly through the Docker containers.

- **Docker Compose Topology (`docker-compose.yml`)**:
  - `db` (`mysql:8.0`) - Primary SQL datastore.
  - `timescaledb` (`timescale/timescaledb:latest-pg14`) - PostgreSQL / Time-series datastore.
  - `redis` (`redis:alpine`) - In-memory cache layer.
  - `php-apache-environment` - The main test runner equipped with `libpq-dev`, Xdebug (HTML code coverage), and `ext-intl`.

- **Executing Tests:**
  Tests execute dynamically via host wrappers that proxy into the PHP container:
  - Windows: `dockertest.bat`
  - WSL/Linux: `./dockertest`
  
- **Testing Rules:**
  - The project operates on strict **PHPUnit 11** constraints.
  - Legacy metadata annotations (`@covers`, `@author`, `@package`, `@expectedException`) are banned.
  - Always use native PHP 8 Attributes (e.g., `#[\PHPUnit\Framework\Attributes\CoversClass(Route::class)]`) mapped exclusively at the top of test classes.
