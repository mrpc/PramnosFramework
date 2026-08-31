---
use_cases:
  - Deciding whether the current user may perform an action
  - Granting or denying a permission, and checking one
  - Restricting a controller action, an API endpoint, a route or a menu item
  - Working out why a permission check returned what it did
  - Choosing between a usertype capability, a gate and a permission row
---

# Authorization

Authorization in Pramnos is **three layers that answer different questions**, and most
applications use all three.

| | Answers | Lives in | Changes by |
| --- | --- | --- | --- |
| **`Pramnos\User\UserTypes`** | what may this *kind of account* reach | configuration | a deploy |
| **`Pramnos\Auth\Gate`** | what does this *rule* mean | code | a deploy |
| **`Pramnos\Auth\Permissions`** | what has this *installation* granted | a table | an admin, at runtime |

A rule like "the author, or a moderator" is not a row — written as rows it becomes one row
per article per user. A grant like "this customer's support team may export reports" is not
a rule — written in code it is the same for every installation. And "administrators can open
the administration area" is neither: it is what the account *is*, decided before any record
is in sight. Nothing here replaces anything else, and [the bridge between the last
two](#bridging-the-two) is one line.

Four places ask:

| Layer | Class | Decides |
| --- | --- | --- |
| Controller actions | `Pramnos\Application\Controller` — `can()`, `auth()` | whether an action runs |
| API endpoints | `Pramnos\Application\ApiCrudController::authorize()` | whether a CRUD verb is allowed |
| Routes | `Pramnos\Routing\Router::hasPermissions()` | whether a route is refused |
| Navigation | `Pramnos\Application\NavRegistry` | whether a menu item is shown |

Refusals throw `Pramnos\Auth\AuthorizationException` — code **403**, and it names what was
refused.

---

## Gates: rules in code

### Defining

```php
use Pramnos\Auth\Gate;

Gate::define('update-post', function ($user, $post) {
    return $user->userid === $post->userid;
});

// "an administrator may do anything", once, instead of at the top of every rule
Gate::before(fn ($user) => $user->isAdmin() ? true : null);
```

A rule returns `true` to allow, `false` to deny, and **`null` for no opinion** — which falls
through to the next step rather than refusing. That is what lets several rules cover one
ability without fighting.

### Policies

A policy is an ordinary class whose methods are ability names:

```php
namespace App\Policies;

class PostPolicy
{
    public function update($user, $post)
    {
        return $user->userid === $post->userid;
    }

    /** Runs before this policy's own methods — narrower than the global hook. */
    public function before($user, $ability, ...$args)
    {
        return $user->isModerator() ? true : null;
    }
}
```

```php
Gate::policy(\App\Models\Post::class, \App\Policies\PostPolicy::class);

Gate::allows('update', $post);        // → PostPolicy::update($user, $post)
Gate::allows('update-post', $post);   // → PostPolicy::updatePost(), if it has one
```

Hyphens and underscores fold to camelCase, so an ability can read naturally in a route file
and still be a method name. A policy can be found from a class name as well as an instance,
which is what `create` needs — there is no object yet.

### Asking

```php
Gate::allows('update-post', $post);                    // bool, current user
Gate::denies('update-post', $post);                    // the same, inverted
Gate::authorize('update-post', $post);                 // or throws

Gate::forUser($other)->check('update-post', $post);    // somebody else
Gate::current()->any(['edit', 'publish'], $post);      // any of these
Gate::current()->all(['edit', 'publish'], $post);      // all of these
```

Inside a controller, `can()` and `cannot()` are the short form:

```php
if ($this->cannot('update-post', $post)) {
    return $this->redirect('/posts');
}
```

### The order a decision is made in

1. **`before` callbacks**, in registration order. A non-`null` return decides immediately.
2. **A named ability**, if one was defined for this name.
3. **A policy**, if the first argument has one carrying a method of this name.
4. **The permission store**, if [the bridge](#bridging-the-two) is on.
5. Otherwise **deny** — an ability nobody defined is not an ability.
6. **`after` callbacks**, which may override the result.

Knowing this order is how every "why was this allowed" question gets answered.

### Who the user is

By default the gate asks `Pramnos\Http\RequestIdentity` — where the framework's own
authentication puts the answer. An application that identifies users some other way says so
once:

```php
Gate::resolveUserUsing(fn () => MyApp::currentUser());
```

Rules receive `null` when nobody is signed in, rather than the check throwing — so a
public-facing rule can simply say what an anonymous visitor may do.

### Seeing which step decided

The order above is the contract, and until the toolbar carried it there was **no way to observe
it**. A rule is a closure in a bootstrap file, so it appears in no stack trace. A `before` hook
that returns `true` skips everything after it and leaves no mark. The SQL panel cannot help,
because a decision may touch no database at all. And a 403 says something refused, not which of
six steps did.

The [debug toolbar](Pramnos_Debug_Toolbar_Usage.md)'s **Gate** tab lists every check the request
made, with the step that answered:

| Result | Ability | Decided by | What | Subject |
| --- | --- | --- | --- | --- |
| allowed | `update-post` | policy | `PostPolicy::update` | `App\Models\Post` |
| allowed | `see-menu` ×40 | before | a global `before()` hook decided | — |
| refused | `updatePost` | **default** | nothing claimed this ability | `App\Models\Post` |

**That last row is why the tab exists.** With `fallbackToPermissions()` off — the default — an
ability nobody defined is refused, so a **typo in an ability name is indistinguishable from a
deliberate deny**: both produce `false`. `default` tells them apart, and the tab counts those
separately so a badge says so before it is opened.

Identical checks collapse into a `×N`, because rendering a permission-gated menu can ask the same
question forty times and that should be one row.

**Arguments are never in the payload.** A policy check receives whole models, and the debug
payload travels to the browser — so a subject appears as a class name and a user as an id, and
nothing that came out of a database goes with it. It is also request-scoped by design: it shows
what *this request* decided, not what a user may do in general, which is a question for the
store.

Recording is opt-in — `Gate::enableDecisionLog()`, which the debug provider calls — so an
application that never opens the toolbar pays one boolean check per decision. Same shape as
`Database::enableQueryLog()`.

### Registration is process-wide

Abilities live in statics. That is right for a request and wrong for anything handling more
than one, so `Gate::reset()` exists, and the test suite calls it between tests via
`Pramnos\Framework\Testing\GateIsolation` — see
[the Testing Guide](Pramnos_Testing_Guide.md#isolating-process-wide-state).

---

---

## Permissions: grants in a table

`Pramnos\Auth\Permissions` reads and writes `authserver.permissions`, the framework's one
permission store. A permission is a **subject** doing a **privilege** to a **resource**:

```php
$permissions = \Pramnos\Auth\Permissions::getInstance();

// Grant: user 42 may edit the "articles" module
$permissions->allow(42, 'articles', 'edit');

// Deny explicitly — not the same as never granting
$permissions->deny(42, 'articles', 'delete');

// Ask
if ($permissions->isAllowed(42, 'articles', 'edit')) {
    // ...
}
```

Both `allow()` and `deny()` take the same shape, and so does `isAllowed()`:

```php
isAllowed(
    $subject,                      // usually a user id
    $resource,                     // 'articles', 'customers', a module name
    $privilege,                    // 'edit', 'delete', 'view'
    $resourceElement = '',         // a specific row, when the grant is per-record
    $resourceType    = 'module',
    $subjectType     = 'user',     // 'group' for a group grant
    $nonExistEqualsFalse = true
);
```

### The parameter that decides your security model

`$nonExistEqualsFalse` is the one to understand before writing anything:

| Value | "No rule exists" means |
| --- | --- |
| `true` (default) | **denied** — deny by default |
| `false` | **`null`** — no opinion, and the caller decides |

The `null` is not a nuisance to be cast away. It is what lets a caller tell **"explicitly
denied"** from **"nobody has said anything"**, and those two need different handling in any
system that gained permissions after it had users. Casting `null` to `false` locks out every
installation that never granted anything.

`removePermission()` deletes a rule, which returns that subject to "no opinion" — it is not
the same as `deny()`.

### Resolving everything a user has

`Pramnos\Auth\PermissionResolver` answers the other direction — not "may they do X" but
"what do they have":

```php
$resolver    = new \Pramnos\Auth\PermissionResolver($database);
$permissions = $resolver->resolve($userId, $appId);   // string[]
```

Used by the router and by anything that needs the whole set at once rather than one check
at a time. `PermissionResolverInterface` exists so an application can substitute its own.

### `hasPermission()` on your user

Several framework call sites ask the user object directly:

```php
if (method_exists($user, 'hasPermission') && $user->hasPermission('viewCustomer')) {
```

`hasPermission()` is **not** defined by the framework's `User` — the `method_exists()` guard
is deliberate. An application that wants named permission checks implements it, usually over
`Permissions::isAllowed()`. An application that does not is not broken; those call sites fall
back to their own defaults.

---

## Usertypes: what a kind of account may reach

`users.usertype` is an integer read as a **threshold**, and a *capability* is what a
threshold grants: `admin.area`, `admin.users`, `devpanel`. It is the layer that answers
before there is any record to reason about.

```php
\Pramnos\User\UserTypes::can(90, 'admin.settings');   // false — that is 98 and above
\Pramnos\User\UserTypes::capabilities(98);            // the resolved list
\Pramnos\User\UserTypes::label(95);                   // 'Administrator' — 95 is above the floor
```

The types, their capabilities, how an application declares its own, and why
`usertype_capabilities` **replaces** the framework's map instead of merging with it are all
in the [Authentication guide](Pramnos_Authentication_Guide.md#what-a-usertype-is-what-each-one-may-do-and-how-to-change-them),
next to `users.usertype` itself. `/admin/Users/types` renders the running answer.

### Which layer a question belongs to

| The question | The layer |
| --- | --- |
| May this kind of account reach this kind of screen? | a usertype capability |
| May *this* account touch *this* record? | a gate, or a permission row |
| Is the administration area browsable at all? | `admin.min_usertype` |

The third one is a separate thing again, and it is the one most often confused with the
first: `admin.min_usertype` is a floor on the whole area, applied by `Pramnos\Http\AdminArea`
before any screen's own check runs. It is not a substitute for a screen's check — **do not
remove a screen's own guard because the area has a floor.** The area's floor stops browsing;
the screen's guard is what decides whether that screen may act. A screen that is also
reachable outside the area (a public monitor endpoint, say) has no floor at all.

### Why capabilities are not permissions

A capability is about a *class* of account and a *class* of screen. It has no idea what a
record is, and giving it one would mean a capability per record — which is a permissions
table, badly. Conversely, a permission row cannot express "administrators can open the
administration area" without a row per administrator, rewritten every time somebody is
promoted.

The practical test: if the answer changes when a **row** in the database changes, it is a
permission. If it changes when somebody's **usertype** changes, it is a capability. If it
needs to look at the record — its author, its state, its owner — it is a gate.

---

## Bridging the two

The gate answers rules; the store answers grants. One line connects them:

```php
Gate::fallbackToPermissions();   // off by default
```

With it on, an ability written as `resource.privilege` that **no gate or policy claims** is
answered by `Permissions::isAllowed($userId, $resource, $privilege, …)`. So:

- rules that need reasoning are `define()`d or written as policies;
- everything else is data an administrator can edit, without a deploy;
- and one `Gate::allows('reports.export')` asks whichever layer owns the answer.

The store is asked with `$nonExistEqualsFalse = false`, so "no rule" arrives as **no
opinion** rather than as a denial — the gate decides what that means, not the absence of a
row.

It is **off by default and deliberately explicit**: a gate that silently consulted a
database for names nobody registered would be a gate whose answers cannot be read off the
code. Turning it on is a decision, and it should look like one.

Abilities with no `.` are never sent to the store — there would be nothing to tell it, and
guessing a resource is worse than declining.

---

## Controller actions

`Controller::auth($action)` runs before an action and can refuse it. It reads three
properties:

```php
class Articles extends \Pramnos\Application\Controller
{
    /** Actions that require a logged-in user. */
    public $actions_auth = ['edit', 'delete'];

    /** Permissions each action requires. */
    protected $action_permissions = [
        'edit'   => ['articles.edit'],
        'delete' => ['articles.delete'],
    ];

    /** The current user's permissions, as resolved at boot. */
    protected $user_permissions = [];
}
```

`actions_auth` is the login check; `action_permissions` is the permission check, and it only
applies when `user_permissions` has been populated. An empty `user_permissions` means the
permission check is skipped — so a controller that never receives them is protected by
`actions_auth` alone. That is worth knowing before assuming an action is guarded.

---

## API endpoints

`ApiCrudController::authorize(string $action)` covers `list|read|create|update|delete` for
generated CRUD endpoints. Its rule is three-valued, for the reason described above:

```php
protected function authorize(string $action): bool
{
    return $this->permissionFor($action) !== false;
}
```

| The store says | Result |
| --- | --- |
| explicit **allow** | allowed |
| explicit **deny** | refused |
| **no rule at all** | allowed |

The last row is a compatibility decision, stated plainly so nobody discovers it in
production: **a project that has granted nothing keeps working exactly as it did before this
class existed.** A failure to *read* permissions is also treated as "no opinion" rather than
as a decision, so a broken query cannot silently deny — or silently allow — on its own.

To tighten it, override in the generated controller:

```php
protected function authorize(string $action): bool
{
    return parent::authorize($action) && $this->user()->isAdmin();
}
```

### The endpoint question and the record question are different

`authorize()` asks whether a user may `update` **invoices**. It is not given an id, so
it cannot ask whether they may update **invoice 42** — and those are two questions
with two answers.

A grant answers one of them. Which one depends on its `object_id`:

| Grant | `authorize('read')` | invoice 42 | invoice 43 |
| --- | --- | --- | --- |
| `object_id` NULL or `*` | allow | allow | allow |
| `object_id` = `42` | *no rule* | allow | *no rule* |
| `object_id` = `42`, deny | *no rule* | deny | *no rule* |

The middle rows are the important ones, in both directions: a grant on one record does
not open the collection, and a deny on one record does not close it. A row-scoped
grant read as a resource-wide one would hand back every invoice in the table to
somebody who had been given exactly one.

To honour record-level grants, ask the second question where the id is — in the action
itself, which is the only place the base class never sees:

```php
// In a generated controller, where the action already has the id.
public function read($id): mixed
{
    if (($denied = $this->guard('read')) !== null) {
        return \Pramnos\Http\Response::json($denied, $denied['status']);
    }

    if ($this->permissionForObject('read', (string) $id) === false) {
        return \Pramnos\Http\Response::json(
            ['error' => 'forbidden'], 403
        );
    }

    // …
}
```

`permissionForObject()` is three-valued like everything else here, so compare against
`false` rather than treating `null` as a refusal. Reading `null` as "no" would refuse
every request in a project that has written no grants at all.

---

## Routes

A route can carry required permissions:

```php
$router->get('/admin/reports', 'Reports@index')
    ->requirePermissions(['reports.view']);

// or, adding to what a group already applied
$route->addPermissions('reports.export');
```

Both accept a string or an array. `Router::addRoute()` also takes them as its fourth
argument, and a route group applies them to everything inside it.

`Router::hasPermissions($route, $userPermissions)` is what decides, and it runs **after the
route has matched**: the route is found, then refused. The failure is an exception carrying
**403**, and when the refusal is about an OAuth scope the message names the missing scope:

```
Insufficient permissions to access this route. Missing scope: reports.export
```

Routes with no permissions declared are never refused here.

---

## Navigation

`NavRegistry` hides menu items the user may not use. Its rule is the same three-valued one,
and its docblock is worth quoting because the edge case is the whole design:

| Item | Logged in | Permission | Result |
| --- | --- | --- | --- |
| no permission set | — | — | kept |
| permission set | no | — | kept |
| permission set | yes | explicitly denied | removed |
| permission set | yes | no rule for it | **kept — silence is not a deny** |

A menu that vanished because nobody had granted anything yet would look like a broken
install, which is exactly what happened before the framework had a permission system.

### A count beside a label

A `NavItem` may carry a **badge** — the notification count beside a menu entry:

```php
NavRegistry::register(new NavItem(
    'user.messages', 'Messages', $base . 'messages',
    NavSection::User, 5, requireAuth: true, feature: 'messaging',
    icon: 'mail',
    badge: static fn (int $userId): int => MessagesController::unreadCount($userId),
));
```

A **closure**, not an `int`, and that is the whole of the design: navigation is registered once
at boot, so a number resolved there is the count as it was when the process started — for an
unread badge, always wrong and usually zero.

Read it with `badgeCount($userId)`, and render it with `badgeLabel($userId)`, which writes
anything over ninety-nine as `99+`. The difference between a hundred unread and four hundred is
not one anybody acts on, and a four-digit badge is wider than the label it sits beside.

Four things it will not do, because a badge is decoration on a screen that is about something
else and a navigation item that throws takes every page on the site with it:

- **It is not asked for a signed-out visitor.** The navigation renders for everybody, and a
  count for user 0 is a query against an account that does not exist — on every page, for every
  crawler.
- **It is resolved once per account per request.** A theme renders the navigation more than once
  — a header and a mobile menu are two renders of the same list.
- **A closure that throws counts zero.** The database is unreachable, or the table has not been
  migrated.
- **A negative answer counts zero.** «-1 unread» reads as a broken page rather than a broken
  count.

The closure must be cheap: an indexed `COUNT`, not a join. It runs on every page a signed-in
visitor loads.

---

## Diagnosing a decision

When a check returns something surprising, in this order:

1. **Ask the store directly** with `$nonExistEqualsFalse = false`. If it returns `null`,
   there is no rule and you are looking at a default, not a denial:
   ```php
   var_dump($permissions->isAllowed($uid, 'articles', 'edit', '', 'module', 'user', false));
   ```
2. **Check the subject type.** A grant made to a group is not found by a check that asks
   about a user, and vice versa — `$subjectType` has to match how it was written.
3. **Check `$resourceElement`.** A grant for a specific record does not answer a check for
   the resource as a whole.
4. **For a route**, remember it is matching, not refusing: if the URL 404s, the permission
   is a candidate before the route file is.
5. **For a controller action**, check `user_permissions` is actually populated. If it is
   empty, `action_permissions` was never consulted.

The [debug toolbar](Pramnos_Debug_Toolbar_Usage.md)'s **Auth** tab shows who the server
identified and by which credential, which settles the half of the question that is about
identity rather than permission.

---

## `Pramnos\Policy\PolicyEngine` is not this

There is a class called `PolicyEngine` and a table called `framework_policies`, and they
have **nothing to do with authorization**. They execute **data-retention policies** —
retention windows, aggregate refresh, compression, cache rebuilds. Same word, unrelated
concept.

It is called out here because grepping for "policy" finds it, and finding it is enough to
conclude you have found the authorization system. The one you want is
[`Pramnos\Auth\Gate`](#gates-rules-in-code).

---

## What this page used to say

Until 2026-08-14 this guide documented `Gate::define()`, policy classes with `before`/`after`
hooks, `auth()->can()`, `$this->authorize('update', $post)` and
`\Pramnos\Auth\AuthorizationException` — **none of which existed**. The page came out of the
v1.2 documentation reorganisation describing an API that was planned rather than shipped, and
it was found by a consumer who tried to build on it.

It is recorded rather than quietly overwritten because of *how* it failed. Eight other guides
had namespace slips — the class exists, the guide spells its path wrong — and a reader greps
the class name, finds it one namespace over and moves on. This page was the only one where
there was nothing to find under any namespace, and a reader who greps `Gate` and gets nothing
back cannot tell *"I searched wrong"* from *"this does not exist"*. That is the state in which
somebody keeps looking for another hour, and it lands on whoever is doing authorization work —
which is precisely where people reach for a framework instead of inventing something.

**The gate now exists**, because the design was sound and the gap was real: the permission
store cannot express a rule. Three things differ from what that page described, and they are
deliberate:

| That page | Today | Why |
| --- | --- | --- |
| `$this->authorize('update', $post)` on a controller | `$this->can()` / `$this->cannot()`, or `Gate::authorize()` | `ApiCrudController::authorize(string $action): bool` already exists with a different meaning; two `authorize()`s in one hierarchy is a trap even where PHP allows it |
| a global `auth()` helper | `Gate::` statics | the framework has three unrelated `auth()` methods already; a fourth spelling would have been the worst of them |
| policies only | policies **and** a permission-store bridge | the store was already there and already used; a gate that ignored it would have split authorization in two |

## Reference## Reference

**Classes:**

- `Pramnos\Auth\Gate` — rules: `define()`, `policy()`, `before()`, `after()`,
  `allows()`, `denies()`, `authorize()`, `forUser()`, `current()`, `check()`, `any()`,
  `all()`, `enforce()`, `fallbackToPermissions()`, `resolveUserUsing()`, `reset()`
- `Pramnos\Auth\AuthorizationException` — a refusal, code 403, carrying `getAbility()`
- `Pramnos\Auth\Permissions` — the store: `allow()`, `deny()`, `removePermission()`,
  `isAllowed()`, `setDefaultPermission()`
- `Pramnos\Auth\PermissionResolver` — every permission a user has, as a list
- `Pramnos\Auth\PermissionResolverInterface` — substitute your own
- `Pramnos\Application\Controller` — `can()`, `cannot()`, `auth()`, `$actions_auth`,
  `$action_permissions`
- `Pramnos\Application\ApiCrudController` — `authorize()`, `permissionFor()`
- `Pramnos\Routing\Router` — `hasPermissions()`, `addRoute()`;
  `Pramnos\Routing\Route` — `requirePermissions()`, `addPermissions()`
- `Pramnos\Application\NavRegistry` — permission-gated menu items

**Related guides:**

- [Authentication](Pramnos_Authentication_Guide.md) — establishing *who* the user is
- [Legacy permissions migration](Pramnos_Legacy_Permissions_Migration.md) — moving a
  hand-built `<prefix>permissions` table into `authserver.permissions`
- [AuthServer integration](Pramnos_AuthServer_Integration_Guide.md) — where permissions live
