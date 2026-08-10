---
date: 2026-08-10
categories:
  - Changelog
  - Fixed
  - Security
tags:
  - console
  - api
  - auth
---

# Generated CRUD gets real authorisation — and `delete` gets any at all

Every generated API action opened with the same two lines: is there a session
user, and is their id at least 2. That is authentication. It meant any signed-in
user could list, edit and delete every record of every entity — and `delete`
carried **no check whatsoever**.

<!-- more -->

## What was there

```php
public function display()
{
    if (!isset($_SESSION['user']) || !is_object($_SESSION['user'])) {
        return array('status' => 401);
    }
    $user = $_SESSION['user'];
    if ($user->userid < 2) {
        return array('status' => 401);
    }
```

Repeated in four actions. The fifth — `delete<Entity>()` — had none of it:

```php
public function delete{{ modelClass }}(${{ primaryKey }})
{
    $model = new ...;
    $model->delete(...);
```

An API key alone was enough to destroy records. The key is not a secret in a
same-origin SPA: the shell hands it to the browser, because the API layer
demands it on every request.

## What replaces it

Generated controllers now extend `Pramnos\Application\ApiCrudController`, and
each action asks one question:

```php
if (($denied = $this->guard('delete')) !== null) {
    return $denied;
}
```

`guard()` separates the two answers that were previously conflated:

- **401 `not_authenticated`** — no signed-in user. Sign in.
- **403 `forbidden`** — signed in, but not permitted. Signing in again will not
  help, which is exactly what a 401 tells a client to do, forever.

`authorize()` consults the framework's permission store per action, with three
outcomes and a deliberate default:

| Permission store | Result |
|---|---|
| explicit allow | allowed |
| explicit deny | refused (403) |
| **no rule at all** | **allowed** |

The last row is the compatibility guarantee. A project that has granted nothing
behaves exactly as before; anything stricter would lock every existing project
out of its own API on upgrade. Authorisation becomes data: adding a permission
row takes effect without touching code, and it can grant `read` without granting
`delete` — which the old single check could not express.

`$nonExistEqualsFalse = false` is what makes that possible: it lets a missing row
answer `null` ("no opinion") instead of collapsing to `false`.

## Overriding it

The generated controller is the file the application owns, so the seam is there:

```php
protected function authorize(string $action): bool
{
    return parent::authorize($action) && $_SESSION['user']->isAdmin();
}
```

`protected string $resource` names the resource in the permission store; it
defaults to the controller's own name, so a `Thing` controller guards `thing`.

## Compatibility

Only newly generated controllers change — the base class is additive and nothing
rewrites files already in a project. An existing controller keeps its inline
checks until it is regenerated. Projects with no permission rows see no
behavioural change beyond `delete` finally requiring a signed-in user, which is
a fix, not a regression.

## Tests

`ApiCrudControllerTest` — anonymous requests are 401 without consulting
permissions at all, user 1 does not count as signed in, no rule means
authentication is enough, an explicit deny is 403 rather than 401, an explicit
allow passes, each action is asked about separately (so read can be granted
without delete), and the resource name defaults to the controller's name or is
declared.
