---
use_cases:
  - Reading configuration from .env or the environment
  - Understanding how dotenv parsing and precedence behave
  - Moving hard-coded settings out of app/app.php
---

# Environment Variables & Dotenv Support

## Overview

The framework reads configuration from environment variables and from a `.env` file at the
project root, through Symfony's Dotenv component (`symfony/dotenv`, a framework dependency).

This lets an application:

* keep credentials and other secrets out of version control;
* run one committed configuration in development, staging and production;
* read every value through one helper, `envvar()`, with consistent parsing.

---

## In a scaffolded project

A project created by `init` already does all of this. `app/config/settings.php` loads the
root `.env` with `loadDotenv(dirname(__DIR__, 2))` and reads every value through `envvar()`:

```php
'database' => [
    'type'     => envvar('APP_DB_TYPE', 'postgresql'),
    'hostname' => envvar('APP_DB_HOST', 'db'),
    'password' => (string) envvar('APP_DB_PASSWORD', ''),
    ...
],
'development' => envvar('APP_DEBUG', false),
```

`init` writes `.env` (this machine's values, ignored by git) and `.env.example` (the
committed list of every key, secrets blank). See
[Getting Started](Getting_Started.md#configuration-and-secrets) for why the keys are
`APP_DB_*` rather than `DB_*`.

---

## In a project you wire yourself

### 1. Create a `.env` file at the project root

```
APP_ENV=dev
APP_DEBUG=true
DEBUG_LEVEL=2
APP_DB_HOST=localhost
APP_DB_USER=root
APP_DB_PASSWORD=secret
TEST_FLOAT=3.14
TEST_EMPTY=
```

### 2. Load it

After the Composer autoloader, pass `loadDotenv()` the **directory that contains `.env`**.
From an entry point in `www/`, that is the parent directory:

```php
require __DIR__ . '/../vendor/autoload.php';

loadDotenv(dirname(__DIR__));
```

`loadDotenv($path = null, $fileName = '.env')` reads `$path/$fileName` (the current working
directory when `$path` is `null`). It returns `false` when the file is missing or unreadable,
or when `symfony/dotenv` is not installed. It uses Dotenv's `loadEnv()`, so `.env.local` and
`.env.<APP_ENV>` files are read as well when they exist.

Call it early, before anything reads configuration.

### 3. Read values

```php
envvar('KEY');
envvar('NOT_DEFINED', 'default_value');
```

```php
var_dump(envvar('APP_ENV'));      // string(3) "dev"
var_dump(envvar('APP_DEBUG'));    // bool(true)
var_dump(envvar('DEBUG_LEVEL'));  // int(2)
var_dump(envvar('TEST_FLOAT'));   // float(3.14)
var_dump(envvar('TEST_EMPTY'));   // string(0) ""
```

---

## Parsing rules

`envvar()` converts string values:

| Value                    | Result                    |
| ------------------------ | ------------------------- |
| `true`, `(true)`         | `true` (bool)             |
| `false`, `(false)`       | `false` (bool)            |
| `null`, `(null)`         | `null`                    |
| empty                    | `""` (empty string)       |
| `"text"` or `'text'`     | `text` (quotes removed)   |
| `2`                      | `2` (int)                 |
| `3.14`                   | `3.14` (float)            |
| anything else            | the trimmed string        |

The keyword match is case-insensitive. Numbers become `int` or `float`, never `bool`, so `0`,
`1`, `2` stay usable as levels.

---

## Precedence

`envvar()` returns the first value it finds in:

1. `getenv()`
2. `$_ENV`
3. `$_SERVER`

and the default otherwise. Dotenv does not overwrite a variable that is already set, so a
real environment variable beats `.env` — a host that injects `APP_DB_PASSWORD` needs no file.

---

## Notes

* **Do not commit `.env`.** Commit `.env.example` with every key and the secrets blank.
* **`loadDotenv()` is safe to call more than once.** A file already loaded in the request is
  not read again.
* **`env()` is a different helper.** It reads PHP *constants* (`env('FIELD')` returns
  `constant('FIELD')`), not the environment. Use `envvar()` for environment values.
