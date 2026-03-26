# Environment Variables & Dotenv Support

## Overview

This feature introduces support for environment variables in the Pramnos Framework using a `.env` file, similar to modern frameworks like Laravel and Symfony.

It allows developers to:

* Store sensitive configuration outside the codebase
* Manage environment-specific settings (dev, staging, production)
* Access environment variables in a consistent and safe way

---

## Why This Was Added

Previously, Pramnos relied on constants or manual configuration handling.

This approach had limitations:

* Hard to manage across environments
* Not secure for sensitive data (e.g. API keys, DB credentials)
* No standardized way to load configuration

With this feature:

* Configuration becomes centralized
* Sensitive data can be excluded from version control
* The framework becomes more flexible and modern

---

## Installation (Framework Side)

The feature uses Symfony’s Dotenv component.

Dependency added:

```
symfony/dotenv
```

No additional setup is required by the framework user beyond creating a `.env` file.

---

## Usage

### 1. Create a `.env` file

At the root of your project:

```
.env
```

Example:

```
APP_ENV=dev
APP_DEBUG=true
DEBUG_LEVEL=2
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=secret
TEST_FLOAT=3.14
TEST_EMPTY=
```

---

### 2. Load the `.env` file

After loading the Composer autoloader, call:

```php
require __DIR__ . '/../vendor/autoload.php';

loadDotenv(__DIR__);
```

> ⚠️ This should be done early in the application lifecycle (e.g. entry point like `index.php`).

---

### 3. Access environment variables

Use the helper:

```php
envvar('KEY');
```

Examples:

```php
var_dump(envvar('APP_ENV'));      // "dev"
var_dump(envvar('APP_DEBUG'));    // true
var_dump(envvar('DEBUG_LEVEL'));  // 2
var_dump(envvar('TEST_FLOAT'));   // "3.14"
var_dump(envvar('TEST_EMPTY'));   // ""
```

You can also provide a default value:

```php
envvar('NOT_DEFINED', 'default_value');
```

---

## Behavior & Parsing Rules

The `envvar()` helper automatically converts common string values:

| Value    | Result              |
| -------- | ------------------- |
| `true`   | `true` (bool)       |
| `false`  | `false` (bool)      |
| `null`   | `null`              |
| `empty`  | `""` (empty string) |
| `"text"` | `text`              |
| `'text'` | `text`              |

### Numeric Values

Numeric values are **NOT automatically cast to boolean**.

Example:

```
DEBUG_LEVEL=1
```

```php
envvar('DEBUG_LEVEL'); // "1"
```

This allows using values like:

* `0`, `1`, `2`, `3` for debug levels
* without breaking logic by converting them to `true/false`

---

## Important Notes

### `.env` should NOT be committed

Add to `.gitignore`:

```
.env
```

For shared configuration, you can create:

```
.env.example
```

---

### `loadDotenv()` is safe to call multiple times

The function internally prevents reloading the same file.

---

### Fallback Order

The `envvar()` helper checks values in this order:

1. `getenv()`
2. `$_ENV`
3. `$_SERVER`

---

## Backward Compatibility

This feature does **not break existing functionality**.

* Existing `env()` helper remains unchanged
* Old projects continue to work without modification
* New projects can adopt `.env` gradually

---

## Best Practices

* Always call `loadDotenv()` early
* Use `envvar()` instead of `env()` for new code
* Keep secrets in `.env`, not in source code
* Use `.env.example` for documentation

---

## Example Integration

```php
require __DIR__ . '/../vendor/autoload.php';

loadDotenv(__DIR__);

$env = envvar('APP_ENV', 'prod');
$debug = envvar('APP_DEBUG', false);
$debugLevel = envvar('DEBUG_LEVEL', 0);
```

---

## Summary

This feature brings modern configuration handling to Pramnos:

* Clean separation of config and code
* Safer handling of sensitive data
* Flexible multi-environment support
* Developer-friendly API

It is a foundational step toward more advanced features such as:

* configuration management
* validation systems
* environment-based behavior
