---
use_cases:
  - Loading a conventionally-named addon class and calling lifecycle methods on it
  - Adding an application's own action/filter hook for its own internal use
  - Registering something to run on every request boot (AppInit) or after settings are saved
  - Checking whether a bundled addon is deprecated and what replaces it
---

# Pramnos Framework - Addon Guide

`Pramnos\Addon\Addon` is two related but distinct things: a simple tag-based action/filter pair
(`addAction()`/`doAction()`, `addFilter()`/`applyFilters()`), and a separate addon-*instance*
system (`load()`/`unload()`/`triger()`/`filter()`) for conventionally-named, loaded-once, called-by-name
plugin classes. See `Pramnos_Extensibility_Guide.md` for how this compares to `Event` and to
middleware, and when to reach for each.

## Table of Contents

1. [Action and Filter Tags](#action-and-filter-tags)
2. [The Addon-Instance System](#the-addon-instance-system)
3. [Deprecated Bundled Addons](#deprecated-bundled-addons)
4. [API Reference](#api-reference)
5. [Related Documentation](#related-documentation)

## Action and Filter Tags

A side-effect hook (`addAction`/`doAction`) and a value-transform pipeline (`addFilter`/
`applyFilters`), both priority-ordered, both keyed by an arbitrary string tag — any code, not only
framework internals, can both register and call these.

```php
// Register, once, during boot
Addon::addAction('after_setup_theme', function () {
    // runs once the theme is set up
});

Addon::addFilter('mediauploader_beforeimageupload', [$this, 'changeUploadMaxSize']);

// Call, wherever the moment actually happens
Addon::doAction('after_setup_theme');
$media = Addon::applyFilters('mediauploader_beforeimageupload', $mediaObject);
```

`doAction()`/`applyFilters()` both accept extra arguments either as an array (`doAction($tag,
[$a, $b])`) or as varargs (`doAction($tag, $a, $b)`); `$acceptedArgs` on `addAction()`/`addFilter()`
caps how many of them actually reach a given callback.

This is a generic utility — the two framework-internal tags that currently exist (`send_headers`,
`after_setup_theme`) are listed in `Pramnos_Extensibility_Guide.md`'s catalog, but an application is
equally free to use `addAction()`/`addFilter()` entirely for its own code's internal pluggability,
with no framework involvement at all.

## The Addon-Instance System

A conventionally-named class, loaded from a configurable directory, registered once, and called
by `(type, name)` for the rest of the request — the one capability the tag-based pair above and
`Event` both lack.

```php
Addon::load('online');              // ADDONS_PATH/system/online.php -> class addon_system_online
Addon::load('custom', 'auth');      // ADDONS_PATH/auth/custom.php -> class addon_auth_custom
Addon::load($someFqcn);             // Or an already-autoloadable class name directly
```

`$addon` resolves either as an existing, autoloadable class name, or — when that doesn't exist —
as a file: `ADDONS_PATH/<type>/<name>.php` (or `ROOT/addons/<type>/<name>.php` if `ADDONS_PATH`
isn't defined), expected to declare a class named `addon_<type>_<name>`. Either way the instance is
registered under `self::$_addons[$type][$name]` for the life of the request, discoverable
afterward via `getAddon()`/`getaddons()`/`isActive()`.

### Calling a lifecycle method on every loaded addon of a type

```php
Addon::triger('AppInit', 'system');   // calls onAppInit() on every loaded 'system' addon
Addon::triger('SaveSettings');        // type defaults to every addon, action name is free-form
```

`triger($action, $type = '')` calls `on{$action}()` on each addon of `$type` (or every loaded
addon, if `$type` is omitted) that implements it — silently skipping ones that don't.
`filter($action, $type, $content)` is the equivalent for a value-transform: it calls
`filter{$action}($content)` on each addon of `$type` and threads the (possibly modified) value
through, the same shape as `applyFilters()` but dispatched to addon *instances* rather than
registered callbacks.

**`AppInit` fires automatically** from `Application::init()`, after every configured addon has
loaded — this is how a `system`-type addon gets to run on every request without the application
calling it explicitly.

**`SaveSettings` is a de facto convention, not a framework-enforced contract**: several
independent applications' settings controllers call `Addon::triger('SaveSettings')` right after
persisting settings, so an addon that defines `onSaveSettings()` reacts to that moment. Nothing in
the framework requires this name — it is simply what every settings-controller scaffold so far has
used, which is worth following for consistency rather than inventing a different name.

### Configuring which addons load

An application's `app.php` lists what `Application::init()` loads automatically:

```php
'addons' => [
    ['addon' => \Pramnos\Addon\User\User::class, 'type' => 'user'],
    ['addon' => 'online', 'type' => 'system'],
],
```

## Deprecated Bundled Addons

Three addons shipped with the framework have native, built-in replacements and are deprecated —
docblock-only for now, nothing changes at runtime:

| Class | Deprecated since | Replacement |
|---|---|---|
| `Pramnos\Addon\System\Session` | v1.2 | `Pramnos\Http\Middleware\SessionTrackingMiddleware` (register under `app.php`'s `middleware` key) |
| `Pramnos\Addon\User\User` | v1.2 | Built into `Auth::auth()`/`Auth::logout()` automatically, when no `Addon\User` addon is registered |
| `Pramnos\Addon\Auth\UserDatabase` | v1.2 | `Pramnos\Auth\Drivers\DatabaseAuthDriver`, used automatically when no addon is registered |

### What "deprecated" means here, concretely

- **Stage 1 (now)**: the `@deprecated` docblock on each class, and this page. Nothing changes at
  runtime — an application that registers one of these three in `app.php`'s `addons` list
  continues to work exactly as before.
- **Stage 2 (a future minor version, not yet scheduled)**: `Addon::load()` will emit a PHP
  deprecation notice (`E_USER_DEPRECATED`) when loading one of these three, so it surfaces in logs
  and in any existing deprecation-warning capture, without breaking anything.
- **Stage 3 (a future major version)**: removal, with an Upgrade Guide row at that point naming
  the exact replacement — the same process every other breaking change in this framework follows.

### Migrating is not the only option

**The addon-instance mechanism itself (`load()`/`triger()`) is not deprecated and has no planned
removal** — only these three specific, generic, bundled implementations are. An application
whose needs the native replacement doesn't cover can keep using the addon-instance mechanism
indefinitely by writing its own addon instead of the bundled one: one real application with
beacon/prefetch-aware session tracking and its own auth/user customizations did exactly this,
loaded through the same `Addon::load()` calls the bundled versions use. Migrating *away from
`Addon::load()` entirely* is never required by this deprecation — only away from these three
specific classes, for an application that has no reason to keep them.

## API Reference

| Method | Does |
|---|---|
| `Addon::addAction($tag, $callback, $priority = 10, $acceptedArgs = 1)` | Register a side-effect callback for `$tag` |
| `Addon::doAction($tag, $arg = [])` | Call every registered action callback for `$tag` |
| `Addon::addFilter($tag, $callback, $priority = 10, $acceptedArgs = 1)` | Register a value-transform callback for `$tag` |
| `Addon::applyFilters($tag, $value, $arg = [])` | Thread `$value` through every registered filter callback for `$tag` |
| `Addon::removeAction($tag, $callback, $priority = 10)` / `removeFilter(...)` | Unregister a previously-added callback |
| `Addon::load($addon, $type = 'system')` | Load and register an addon instance, by class name or by `(type, name)` convention |
| `Addon::unload($addon, $type = 'system')` | Remove a registered addon instance |
| `Addon::isActive($type, $addon)` / `Addon::getAddon($type, $addon)` / `Addon::getaddons($type = '')` | Check/read the addon-instance registry |
| `Addon::triger($action, $type = '')` | Call `on{$action}()` on every loaded addon (of `$type`, if given) |
| `Addon::trigerAddon($action, $type, $addon)` | Call `on{$action}()` on one specific loaded addon |
| `Addon::filter($action, $type, $content)` | Thread `$content` through `filter{$action}()` on every loaded addon of `$type` |

## Related Documentation

- [Extensibility Guide](Pramnos_Extensibility_Guide.md) - How this compares to `Event` and middleware, and when to use each
- [Theme Guide](Pramnos_Theme_Guide.md) - `after_setup_theme`, a worked `addAction()` example
- [Authentication Guide](Pramnos_Authentication_Guide.md) - `Addon::load()` in the auth bootstrap sequence
