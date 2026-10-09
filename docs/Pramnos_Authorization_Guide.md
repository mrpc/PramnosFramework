---
use_cases:
  - Deciding whether the current user may perform an action
  - Granting or denying a permission, and checking one
  - Restricting a controller action, an API endpoint, a route or a menu item
  - Working out why a permission check returned what it did
  - Choosing between a usertype capability, a gate and a permission row
  - Isolating one organisation's or tenant's data from another's
  - Giving a user a different role in each organisation they belong to
  - Working out why a member was refused in one organisation and allowed in another
  - Creating a role and giving it to somebody
  - Turning on user groups and putting accounts in them
  - Giving the same permissions to every member of a group
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

// Several privileges at once: each gets the same element, resource type and subject type
$permissions->allow('7', 'articles', ['read', 'edit'], '', 'module', 'group');

// Many resources at once, for one subject: one transaction, one cache flush, one webhook
$permissions->allowMany($roleId, [
    'articles' => ['read', 'create', 'edit'],
    'comments' => ['read'],
], 'module', 'group');

// Ask
if ($permissions->isAllowed(42, 'articles', 'edit')) {
    // ...
}
```

`allowMany()` grants on the whole of each resource. It writes either every grant or none,
because a role set up halfway works on some screens and not on others. It flushes the
permission cache once and queues one `permissions_changed` for the subject. Setting up a new
organisation's roles is the case it exists for: per-privilege `allow()` did all of that
for every privilege. The list form of `allow()` without an element goes through it.

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

### How an allow and a deny are decided

Rows for the same object and action — from the user and from every active role they hold —
are compared by `priority`: **the higher priority decides, and a deny wins a tie.** Two rows at
the default 100 are a deny; an allow at 500 overrides a deny at 100, which is how one exception
is carved out of a broad refusal. Nothing but an allow is an implicit deny.

The priority is compared as it is stored, and stored as it was written — the same on MySQL and
PostgreSQL. `PermissionResolver`, the `authserver.effective_permissions` view and the PL/pgSQL
helpers that read it all apply this one rule.

### The Permissions screen

`Admin → Access → Permissions` edits `authserver.permissions` rows directly:

| Field | Meaning |
| --- | --- |
| Subject | a **user** or a **role**, by id — the two the resolver reads; anything else is refused |
| Object type / Object ID | what is protected; a blank id means every object of the type |
| Action | `read`, `update`, `*`… |
| Grant | allow or deny |
| Priority | see above; 100 unless set |
| Application ID | the one application the grant applies within; blank for every application |
| Expires | when the grant stops counting; blank for permanent |
| Conditions | a JSON object passed with the grant to the application, which evaluates it; blank for unconditional |

**An application's grant uses that application's vocabulary.** Once an application has declared
its capabilities ([AuthServer Integration Guide §5](Pramnos_AuthServer_Integration_Guide.md#5-declaring-your-capabilities-manifest)),
entering its id in *Application ID* turns *Object type* into a list of its active resources,
*Action* into the chosen resource's active scopes plus `*`, and lists under *Conditions* the
condition keys it evaluates, each with its value type. Saving an object type or action it does
not declare is refused with the reason — a typo such as `invoice` for `invoices` would otherwise be
stored and never match anything. A grant with no application, or for an application that has
declared nothing, stays free text.

The permissions list and a role's page mark a grant whose resource or scope its application no
longer declares (or never did), with the reason under the object type. The grant is still
stored and still sent; the mark is why it has stopped having any effect in the application.

`CapabilitiesSyncService::catalog()` is the data behind both, and `problemWith($catalog, $appId,
$objectType, $action)` the check — an application's own writer of grants can call it too.

### Telling applications that something changed

Every write that changes what somebody may do queues a `permissions_changed` webhook: a row on
this screen, a grant or revocation on a user's page, a decision in the Administration screens
panel, `Permissions::allow()` / `deny()` / `removePermission()`, a role saved, deactivated or
deleted, a role given or taken, and an organisation membership added or removed. All of them go
through one call, which an application's own writer should use too:

```php
\Pramnos\Auth\WebhookService::permissionsChanged('user', $userId, ['operation' => 'update']);
\Pramnos\Auth\WebhookService::permissionsChanged('role', $roleId, ['operation' => 'deactivate']);
```

The body sent is `{"subject_type": …, "subject_id": …}` plus the context. A role's event names
no user: the subscriber drops the cache of everyone holding it. The call never throws — the
change it reports has already been written.

It goes to the applications the change concerns, not to every subscriber: with an `app_id` in
the context, that application alone; otherwise the applications the user — or each holder of the
role — has an unexpired token for or has consented to. `WebhookService::queueEventForApplications()`
does the same for any event.

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

## Roles: naming a set of permissions

A permission row's subject can be a `user` or a `role`. Granting to a role and then
giving people the role is the point of RBAC — you change what "operator" means once,
rather than editing every operator's grants.

### The screens

`Admin → Access → Roles`, or `Roles` from the console-scaffolded routes:

| Screen | What it does |
| --- | --- |
| `roles` | every role, with its organisation and whether it is active |
| `roles/view/{id}` | the role, the permissions granted to it, and who holds it |
| `roles/edit/{id}` | name, description, owning organisation, active |
| `roles/members/{id}` | add and remove holders |

A **user's** page lists the roles they hold — each with its organisation or *system-wide*,
when it was granted and until when — and marks one that **does not count** (the role was
deactivated, or the assignment expired), since that is the usual answer to a missing
permission. In code, `Role::heldBy($userId)`.

Deciding what a role *is* is the same order of privilege as deciding what it may do, so these
screens open at the same floor as Permissions.

### System-wide or an organisation's

The one field worth understanding on the form. A role with no organisation is
**system-wide** and valid everywhere. A role that names one may only be held by a
member of that organisation, and counts only within it — see
[Multi-tenancy](#multi-tenancy-scoping-to-an-organisation) below.

Assigning an organisation's role to somebody who is not a member is refused, with a
message saying to add them to the organisation first. The check is in
`Role::assignTo()`, not in the controller, so an API caller reaching the model
directly gets the same answer.

A role's organisation cannot be changed once anybody holds it. The holders who are
not members of the new organisation would silently stop having it — create a separate
role for that organisation instead.

**An organisation's role is only enforced per organisation if the application says which
organisation a request is about.** Without an
[organisation resolver](#checking-permissions-in-an-organisation), a user's roles from every
organisation are read together, so a Client in one organisation and an Editor in another
can edit in both.

### In code

```php
use Pramnos\Auth\Role;

$role = new Role($controller);
$role->role_name       = 'operator';
$role->description     = 'Handles day-to-day invoicing';
$role->organization_id = 5;        // null for a system-wide role
$role->save();

if (!$role->assignTo($userId, grantedBy: $adminId)) {
    echo $role->getLastError();    // says why, in words for an operator
}

$role->revokeFrom($userId);
$role->holders();                  // [userid => granted_at]
```

`assignTo()` is idempotent — "add" pressed twice is not an error — and re-activates a
previously revoked assignment rather than leaving it inactive.

### What deactivating and revoking do

Neither deletes. Revoking a role sets the assignment's `is_active = 0`; deactivating a
role sets the role's. Both are readable afterwards, and the resolver ignores both: a revoked
assignment grants nothing, and a deactivated role grants nothing to anybody assigned it — in
`PermissionResolver::resolve()`, so in `Permissions::isAllowed()` and in administration screens
granted by permission too. An assignment to a role id that has no row in `authserver.roles` is
not dropped by this; only a role marked inactive is.
Deleting the *role* does remove its assignments, because a row naming a role that no
longer exists is not history anybody can read.

Removing somebody from an **organisation** touches none of this: their assignments
stay, stop counting while they are out, and count again if they return. Their organisation's role can
still be taken from them after they have left: on PostgreSQL the membership trigger guards a
grant, and deactivating an assignment grants nothing.

## User groups: an optional feature

A group is a named set of accounts. It is the `usergroups` feature, and it is off unless a
project turns it on.

### Turning it on

- **A new project:** `init` asks *Enable User groups [usergroups]? [y/N]*, only when `auth` is
  enabled, because a membership row references a user. You can also pass it on the command
  line: `--features=auth,…,usergroups`.
- **An existing project:**

    ```bash
    php bin/pramnos project:reconfigure --enable-feature=usergroups
    php bin/pramnos migrate
    ```

The migration creates `usergroups` (`groupid`, `name`, `description`, `order`) and
`userstogroups` (`userid`, `groupid`). A membership is deleted with its user and with its
group. An installation that already has either table keeps it as it is. In that case
`userstogroups` gets no foreign key to a `groupid` whose type the migration cannot know, and
the screen deletes a group's memberships itself.

### The screen

*Administration → People → Groups* (`/admin/Groups`) appears only while the feature is on.
There you:

- create, edit and delete groups;
- open a group to see its members;
- add a member by username, email or user id;
- remove a member.

Nothing is scaffolded into the project. The controller is found through the framework's
fallback, so a project that enables the feature later has the screen at once. An
application class named `Groups` replaces it, and `project:publish-views` copies its views
(`groups/`).

### Roles held by a group

A group can hold roles. Every member then holds them, **by the same rules as a role given
directly**:

- the assignment must be active and not expired, and the role itself active;
- in a check scoped to an organisation (`resolveForOrganization()`), a system-wide role always
  counts, and an organisation's own role counts only while the member belongs to that
  organisation.

Give and withdraw them on the group's page, in the *Roles* section. A withdrawn role is
switched off, not deleted, so the record stays, exactly as `Role::revokeFrom()` does for a
user. Every change that alters somebody's permissions tells the applications they use, the
same `permissions_changed` event a direct role assignment sends:

- a role given to or withdrawn from a group;
- a member joining or leaving a group that holds roles;
- deleting such a group.

The assignments are in `authserver.group_roles` (`groupid`, `roleid`, `granted_by`,
`granted_at`, `expires_at`, `is_active`), created by the same feature. Deleting a role
removes them, along with its user assignments and its own grants in
`authserver.permissions` (`subject_type = 'role'`). There is no need to delete the grants
first. A role given to a group is found by `WebhookService` among the role's holders.

A permission check about a group is answered about the group:
`$permissions->isAllowed($groupId, 'articles', 'edit', '', 'module', 'group')` is true when a
role the group holds allows it.

### What else reads membership

- **Mail audiences.** The mass-message screen offers each group as an audience.
- **The legacy permission store.** On an installation still using `<prefix>permissions`,
  `isAllowed($userId, …)` falls back to the user's groups when the user has no rule of their
  own, and a group's deny wins over another group's allow.
- **Your own code.** `$user->getGroups()` returns the groups an account is in, keyed by
  group id. A membership change is seen by the next call: the screen flushes the
  `usergroups` SQL-cache category that `getGroups()` reads under.

Erasing an account under GDPR removes its memberships. `userstogroups` is also listed as
personal data, so the MCP database-inspection tool will not read it.

## An organisation's own administrator

Somebody can be given one organisation to run — its members, its invitations, who holds its
roles — without any of the administration area. They are whoever is allowed `manage` on the
object `organization` with that organisation's id:

```php
Permissions::getInstance()->allow($userId, 'organization', 'manage', (string) $orgId);
// or to a role of the organisation, which its members then hold
```

A permission rather than a flag, so a deny, an expiry and the audit trail work as for any other
grant, and it can come through a role. A superuser manages every organisation.
`OrganizationAdmin::manages($user, $orgId)` is the check; `managedBy($user)` lists them.

**What they can do, in that organisation only** — at `/organization`, with a link in the account
sidebar for anybody who manages one:

| | |
| --- | --- |
| Add by address | an account becomes a member at once; an address with no account gets an invitation into the organisation — either with one of its roles |
| Remove a member | the membership ends; their assignments stay and stop counting |
| Give / take a role | only the organisation's own roles, and only one that allows nothing the manager is not allowed themselves |
| Invitations | see them, and withdraw one still waiting |

Roles are created and edited by an administrator, not here — a manager assigns what exists. Every
change is written to the affected account's activity log and sent as `permissions_changed`.

The same addresses answer JSON for a script (`Accept: application/json` or `X-Requested-With`):

| | |
| --- | --- |
| `GET organization` | `{organizations: [{organization_id, name}]}` |
| `GET organization/view/{id}` | `{organization_id, name, members, roles, invitations}` |
| `POST organization/add/{id}` | `email`, optional `roleid` |
| `POST organization/remove/{id}` | `userid` |
| `POST organization/giverole/{id}`, `takerole/{id}` | `userid`, `roleid` |
| `POST organization/withdraw/{id}` | `invitation` |

A write answers `{ok, message}`, a refusal `{ok: false, error}` with `401`, `403` or `422`. Writes
are `POST` with the session's token — the form field or `X-CSRF-Token` — so they are for a
signed-in browser, including a single-page application on the same site. `OrganizationAdmin` is
the service underneath, for an application's own API.

## Multi-tenancy: scoping to an organisation

The schema has `organizations`, `user_organizations`, and an organisation column on
`authserver.roles` where NULL means "system-wide". Whether any of it is *enforced*
depends on which method you call, and the difference matters more than it looks.

### `resolve()` is not scoped, and never was

```php
$resolver->resolve($userId, $appId);
```

Returns the permissions of **every** active role the user holds, whatever
organisation that role belongs to. It scopes by application (`permissions.app_id`),
not by organisation. In a multi-tenant application that is the wrong question: a
role defined for organisation 5 will answer for organisation 3.

It stays that way because that is what it has always done and applications depend on
it. Use it when there is one tenant, or when you are asking "what can this person do
anywhere".

### `resolveForOrganization()` is

```php
$resolver->resolveForOrganization($userId, $appId, $organizationId);
```

A role counts only if it is **system-wide** (`organization_id` NULL), or belongs to
that organisation **and the user is an active, unexpired member of it**.

| Role's organisation | User a member? | Counts |
|---|---|---|
| NULL (system-wide) | — | yes |
| the one being asked about | yes | yes |
| the one being asked about | no, or membership inactive/expired | **no** |
| a different one | — | **no** |

Membership is read from `authserver.user_organizations` (or whatever
`authserver_organization_table` points at). `is_active = 0` counts as not a member —
that is how the admin screen removes somebody, keeping the audit trail — and so does
a passed `expires_at`.

**Leaving an organisation deletes nothing.** The role assignment stays exactly where
it is and stops counting; rejoining restores the same set. Revoking access and
forgetting what somebody was are different operations.

Direct grants to the user are unaffected by any of this: they are on the person, not
on a role, so they have no organisation to scope by.

It is one joined query — measurably cheaper than reading memberships separately, even
when they are already in memory.

### Telling the framework which organisation a request is about

The framework cannot guess the organisation: one application takes it from a subdomain,
another from a header, another from the signed-in member's current workspace. So it asks
the application, once:

```php
// where the application boots, e.g. Application::init() or a service provider
\Pramnos\Auth\OrganizationScope::resolveWith(fn () => Tenant::currentOrganizationId());
```

From then on every permission check the framework makes in a request uses
`resolveForOrganization()` for the organisation the resolver returns:
`ApiCrudController::authorize()`, `permissionFor()` and `permissionForObject()`,
`Permissions::isAllowed()`, and `Gate`'s permission fallback. A resolver returning `null`
means "this request is about no organisation" and is checked as before.

**This changes what an unanswered action means for the generated CRUD endpoints.** Read
[Checking permissions in an organisation](#checking-permissions-in-an-organisation) before
you turn it on. `/api/internal/permissions` calls `resolveForOrganization()` when the
resource server passes `organization_id`; see the
[AuthServer Integration guide](Pramnos_AuthServer_Integration_Guide.md).

For **reads**, isolating tenants is usually not an authorization question at all but a query
one, and a global scope on the models is the answer. It is one line and covers every list
path, including the REST endpoints:

```php
Invoice::addGlobalScope('tenant', fn(string $f): string =>
    ($f === '' ? '' : "({$f}) AND ") . 'organization_id = ' . $currentOrgId
);
```

See [the ORM guide](Pramnos_ORM_Guide.md#scopes). The two work together: the scope decides
which rows a member sees, and the organisation's roles decide what they may do to them.

### And a warning about the default

`ApiCrudController::authorize()` treats "no rule at all" as **allowed** when no organisation
is in scope, deliberately, so that a project which has granted nothing keeps working. A
scaffolded multi-tenant API **without an organisation resolver** is therefore open by default:
no grants written, no scope registered, every row served to everyone signed in.

That is the correct default for a single-tenant application and the wrong one for a
multi-tenant one. If you are building the second:

1. set `OrganizationScope::resolveWith()`, so an action no role grants is refused;
2. register the models' tenant scope, so a member only sees their organisation's rows;
3. do both before you generate the endpoints, not after.

## Checking permissions in an organisation

This section is for an application whose users belong to several organisations and hold a
**different role in each**: a project agency whose clients log in, a SaaS with workspaces,
a municipality's departments. Read it before setting `OrganizationScope::resolveWith()`,
because it changes how the generated API answers.

### The problem, in one example

Maria is a **Client** in organisation A and an **Editor** in organisation B. The roles grant
only what each may do. Nothing is written as "not allowed":

| Role | Organisation | Grants on `posts` |
|---|---|---|
| Client | A | `read` |
| Editor | B | `read`, `update` |

Maria opens a post of organisation A and presses **Save** (`PUT /posts/42`).

| | What the check reads | Result |
|---|---|---|
| **No organisation resolver** | every role Maria holds anywhere: Client **and** Editor | `update` is granted by Editor: **saved**. Wrong. |
| **Resolver, organisation A** | system-wide roles, and A's roles because she is a member: Client | no role grants `update`: **refused**. Right. |
| **Resolver, organisation B** | system-wide roles, and B's: Editor | `update` granted: **saved**. Right. |

The first row is two problems, not one. Read without an organisation, Maria's roles are
merged across organisations. And even with the merge removed, the Client role says nothing
about `update`, and **outside an organisation "nothing" means "allowed"** for the generated
endpoints (see [API endpoints](#api-endpoints)). The resolver fixes both: the roles are read
for one organisation, and an action no role grants is refused.

### What the resolver changes, and where

| Check | Without a resolver | With one, and an organisation in scope |
|---|---|---|
| Which roles count | every role the user holds | system-wide roles, plus that organisation's if the user is an active member |
| `ApiCrudController::authorize()` / `permissionFor()` with no matching grant | allowed | **refused** |
| `ApiCrudController::permissionForObject()` with no matching grant | no opinion (`null`) | **refused** (`false`) |
| `Permissions::isAllowed()` with no matching grant | `false` (or `null` with the last argument `false`) | the same |
| `Gate` with `fallbackToPermissions()` | refuses an ability nobody grants | the same, in that organisation |
| The permission store can't be read | no opinion | **refused** |

`Permissions::isAllowed()` and `Gate` already refused what nobody granted. For them the
resolver only removes the merge across organisations. The generated CRUD endpoints are the
ones whose answer to "nothing granted" changes.

A request the resolver says is about **no** organisation (it returns `null`) is checked
exactly as before.

### Setting it up

```php
use Pramnos\Auth\OrganizationScope;

// Once, where the application boots.
OrganizationScope::resolveWith(function (): ?int {
    return Tenant::currentOrganizationId();   // int, or null for "no organisation"
});
```

The resolver may return an `int`, a numeric string, or `null`. It is called per check, so
keep it cheap: read a value the request already established, don't query for it.

**One controller whose organisation comes from somewhere else**, for example its URL:

```php
class ProjectsController extends \Pramnos\Application\ApiCrudController
{
    protected function permissionOrganizationId(): ?int
    {
        return (int) $this->request->get('organization') ?: null;
    }
}
```

**A check outside the request**, such as a worker acting for one tenant, or a screen listing
what a member may do in each of their organisations:

```php
$permissions = \Pramnos\Auth\Permissions::getInstance();
$permissions->isAllowedInOrganization($userId, $organizationId, 'posts', 'update');
```

It takes the same arguments as `isAllowed()`, with the organisation second, and does not
change the request's organisation for anything else. Answers are cached per organisation, so
asking about A and then B on one instance gives each its own answer.

### Before you turn it on

Once an organisation is in scope, an endpoint action that no role grants is refused **for
everyone**, the organisation's owner included. That is the point: a role model is closed
until a grant opens it. It also means:

- **Every role needs every action it should have.** An Owner role that was relying on
  "nothing said, so allowed" for `delete` loses `delete`. List each role's actions per
  resource before switching.
- **A new endpoint is closed until granted.** Add the grant to the roles in the same change
  that adds the endpoint, or nobody can use it.
- **System-wide roles keep working everywhere.** A support or administrator role with
  `organization_id` NULL counts in every organisation, which is usually what staff need.
- **Direct grants to a user count in every organisation.** They belong to the person, not to
  a role, so there is no organisation to scope them by.
- **Your own `User::hasPermission()` scheme is asked first, unchanged.** If the application
  declares its own permission names, the generated endpoints use those answers before the
  store's, with no organisation. See [API endpoints](#api-endpoints).

### When something goes wrong, the answer is no

Every failure refuses. None falls back to the unscoped check, because the unscoped check
merges every organisation's roles, which is the thing this exists to prevent.

| What happened | Result | Where it is recorded |
|---|---|---|
| The resolver throws | refused | error log: `Refused <resource>.<action>: the organisation could not be resolved: …` |
| The resolver returns something that is not an id (`'abc'`, `0`, an array) | refused | the same |
| The application supplies its own resolver (`permissionResolver()`) that doesn't implement `OrganizationPermissionResolverInterface` | refused in an organisation; unchanged outside one | error log names the class |
| The permission tables can't be read | refused in an organisation; no opinion outside one | — |

**Diagnosing a refusal nobody expected:**
1. Check the error log for `Refused`. It names the resource and action.
2. Check what the resolver returned for the request (it returns `null` → the old rules apply).
3. Check the member's roles in that organisation with
   `PermissionResolver::resolveForOrganization($userId, null, $orgId)`: is there a grant
   for that `object_type` and `action`?
4. Check the membership: an inactive or expired row in `authserver.user_organizations`
   means the organisation's roles don't count.

### A permission resolver of your own

`ApiCrudController::permissionResolver()` can return a resolver of the application's own, as
`PermissionResolverInterface`. To answer in an organisation it must also implement
`OrganizationPermissionResolverInterface::resolveForOrganization()`. Without it, every check
in an organisation is refused and the error log says which class is missing it. It is a
separate interface because adding a method to `PermissionResolverInterface` would stop every
existing implementation from loading.

## Usertypes: what a kind of account may reach

`users.usertype` is an integer read as a **threshold**, and a *capability* is what a
threshold grants: `admin.area`, `admin.users`, `devpanel`. It is the layer that answers
before there is any record to reason about.

```php
\Pramnos\User\UserTypes::can(90, 'admin.settings');   // false — the screen opens at 98
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
row. With [an organisation resolver](#checking-permissions-in-an-organisation)
set, the store answers for the request's organisation only.

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

| The store says | No organisation in scope | An organisation in scope |
| --- | --- | --- |
| explicit **allow** | allowed | allowed |
| explicit **deny** | refused | refused |
| **no rule at all** | allowed | **refused** |
| **can't be read** | no opinion → allowed | **refused** |

The left column's last two rows are a compatibility decision, stated plainly so nobody
discovers it in production: **a project that has granted nothing keeps working exactly as it
did before this class existed.** The right column applies once the application has said which
organisation a request is about, because an application with roles per organisation means
"not granted" when it grants nothing; see
[Checking permissions in an organisation](#checking-permissions-in-an-organisation).

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

In an organisation, `permissionForObject()` returns `false` where it would return `null`, so
the same `=== false` comparison refuses a record no role grants. Nothing in the controller
changes.

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

## Administration screens: by usertype or by permission

Every screen in the administration area has an **ability named after its menu item** —
`admin.users`, `admin.roles`, `admin.logs` — declared on its controller:

```php
class Reports extends \Pramnos\Application\Controller
{
    protected string $adminAbility = 'admin.reports';   // the NavItem's id
    protected int $requiredUserType = 98;               // the floor, for usertype mode
}
```

The framework's own screens are at `AdminAccess::defaultUsertype()` — 98, or the
`admin_default_usertype` setting. An application's screen states its own floor, as above.

`Controller::exec()` checks it before **any** action runs, so an action that forgets its own
check is still behind it — inside the administration area, where the application has one. A
controller that extends a screen to serve a public page outside the area (a status page built on
Health) is not an administration screen there; an action that calls `requireMinUserType()`
itself is checked wherever it is reached. The menu item and the screen ask the same question by the same name,
so a link cannot show a screen that refuses, or hide one that opens.

Which question that is depends on one setting, **`admin_access`**:

| `admin_access` | A screen opens for | Two administrators at 90 |
| --- | --- | --- |
| `usertype` (default) | anybody at or above the screen's floor — 98 for every framework screen, `admin_default_usertype` to move it | see the same area |
| `permissions` | whoever holds the screen's ability — granted to them or to a role they hold | see what each was given |
| `mixed` | the floor, with decisions on top: an **allow** opens a screen below its floor, a **deny** closes it above | see the same area, minus what was denied, plus what was allowed |

`mixed` with nothing decided is exactly `usertype`, which makes it the way to start using grants on
a running installation: switch, then allow or deny screen by screen.

Under `permissions` and `mixed`:

- **Under `permissions`, nothing granted means closed.** Unlike an ordinary menu permission,
  silence is a no there: the grant *is* the access. Under `mixed`, silence is the floor.
- **A deny wins** — from the user or from any role they hold — over any allow.
- **The superuser opens everything** — usertype at or above `admin_superuser_usertype`, **98**
  unless set — so switching modes before granting anything cannot lock the last administrator out.
- **A refused screen sends you to the first one you may open**, with a message, rather than out
  of the area.
- **Somebody below the area's floor**, `admin.min_usertype`, reaches the area when a grant opens
  at least one screen to them; otherwise the floor refuses them as it does under `usertype`.

This is the intended way to give an account part of the area: framework screens open at 98, a
superuser's, and everybody else holds a role that allows what they need —

```php
// app settings: admin_access = mixed
AdminAccess::setDecisions('role', $supportRoleId, [
    'admin.users' => 'allow', 'admin.logs' => 'allow',
], $whatTheEditorMayGrant);
```

— rather than a usertype in between, which opens every screen at once.

### Granting screens

The **Administration screens** panel on a **user's** page decides for that person; the same
panel on a **role's** page decides for everyone holding the role. Each screen is **Default**,
**Allow** or **Deny** — Default meaning closed under `permissions` and the floor under `mixed`. On a
user's page a second column shows what they can open in the end — through their roles and
usertype as well.

The panel can be changed by the superuser or by somebody holding `admin.permissions`, and only
for the screens that editor can open themselves: a grant is the access, so handing out one you do
not hold would go round every other check.

In code, the same rows:

```php
use Pramnos\Auth\AdminAccess;

AdminAccess::setDecisions('role', $supportRoleId, [
    'admin.users' => 'allow',
    'admin.logs'  => 'allow',
    'admin.roles' => 'deny',
    'admin.queue' => 'default',                      // neither: back to the mode's default
], $whatTheEditorMayGrant);
AdminAccess::decisionsFor('user', 42);               // ['admin.users' => 'allow']
AdminAccess::allows($user, 'admin.roles', 90);       // what the screen will answer
AdminAccess::mode();                                 // usertype | permissions | mixed
```

A decision is an `allow` or `deny` row in `authserver.permissions` — object `admin.users`, action
`view`, no record id; a deny is stored above an allow in priority. `setDecisions()` touches only
those rows. `setGrants()` / `grantsFor()` are the allow-only form of the same.

### An application's own screens

Register the menu item in the Admin section and declare the same id on the controller. It then
appears in the panel, is granted like the framework's own, and is guarded in `exec()`. A screen
with public actions on the same controller — a monitor endpoint — lists them in
`$adminPublicActions`. `AdminScreensDeclareAnAbilityTest` fails when a framework screen and its
menu item disagree.

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

An Admin item under `admin_access = permissions` is the exception: it is shown only when its
ability is granted — see [Administration screens](#administration-screens-by-usertype-or-by-permission).

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

## Reference

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
- `Pramnos\Application\ApiCrudController` — `authorize()`, `permissionFor()`,
  `permissionForObject()`, `permissionOrganizationId()`
- `Pramnos\Auth\OrganizationScope` — `resolveWith()`, `current()`, `isConfigured()`, `reset()`
- `Pramnos\Auth\OrganizationPermissionResolverInterface` — a resolver that can answer in one
  organisation
- `Permissions::isAllowedInOrganization()` — `isAllowed()` about a named organisation
- `Pramnos\Routing\Router` — `hasPermissions()`, `addRoute()`;
  `Pramnos\Routing\Route` — `requirePermissions()`, `addPermissions()`
- `Pramnos\Application\NavRegistry` — permission-gated menu items

**Related guides:**

- [Authentication](Pramnos_Authentication_Guide.md) — establishing *who* the user is
- [Legacy permissions migration](Pramnos_Legacy_Permissions_Migration.md) — moving a
  hand-built `<prefix>permissions` table into `authserver.permissions`
- [AuthServer integration](Pramnos_AuthServer_Integration_Guide.md) — where permissions live
