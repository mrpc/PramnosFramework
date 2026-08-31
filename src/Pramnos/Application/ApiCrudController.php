<?php

declare(strict_types=1);

namespace Pramnos\Application;

/**
 * Base class for generated API CRUD controllers.
 *
 * Generated controllers used to repeat the same two lines at the top of every
 * action — "is there a session user, and is their id at least 2" — which is
 * authentication, not authorisation. Every signed-in user could list, edit and
 * delete every record of every entity, and the only way to change that was to
 * edit five generated methods by hand.
 *
 * The check now lives here, per action, and consults the framework's permission
 * store. The default is deliberately unchanged in effect for a project that has
 * granted nothing: authentication is still enough. A permission row makes the
 * decision instead, and denies it where it says deny — so authorisation becomes
 * data rather than a code change.
 *
 * Everything is overridable: an application with its own rules replaces
 * `authorize()` (or one action's privilege) in the generated subclass, which is
 * the file it owns.
 */
class ApiCrudController extends Controller
{
    /**
     * Resource name this controller guards, as used in the permission store.
     *
     * Empty means "derive it from the class name", which is what the generated
     * controllers rely on: a `Thing` controller guards the `thing` resource.
     */
    protected string $resource = '';

    /**
     * Refuse an action, with the status that says why.
     *
     * 401 and 403 are different answers and a client acts on them differently:
     * 401 means "sign in", 403 means "signing in again will not help". Returning
     * 401 for both — as the generated controllers did — sends a permission
     * problem to the login screen forever.
     *
     * @param  string $action The CRUD action being refused
     * @return array|null     The error envelope, or null when the action is allowed
     */
    protected function guard(string $action): ?array
    {
        if (!$this->isAuthenticated()) {
            return ['status' => 401, 'error' => 'not_authenticated'];
        }

        if (!$this->authorize($action)) {
            return ['status' => 403, 'error' => 'forbidden'];
        }

        return null;
    }

    /**
     * Who this request is, as established by whatever authenticated it.
     *
     * Not `$_SESSION['user']`. An API request is identified by its token, and in
     * an application that also serves a website from the same origin the two
     * share a cookie — reading the session here meant a browser's login could
     * answer for an API call that presented nothing.
     *
     * @return object|null The user, or null when the request is anonymous
     */
    protected function requestUser(): ?object
    {
        $user = \Pramnos\User\User::getCurrentUser();

        return is_object($user) ? $user : null;
    }

    /**
     * Is there a real, signed-in user on this request?
     *
     * User 1 is the anonymous/system account, so it does not count — the same
     * rule the generated controllers applied inline.
     */
    protected function isAuthenticated(): bool
    {
        $user = $this->requestUser();

        return is_object($user) && (int) ($user->userid ?? 0) >= 2;
    }

    /**
     * May the current user perform this action on this resource?
     *
     * Three outcomes come out of the permission store, and the distinction
     * matters:
     *
     *   - an explicit **allow** → allowed;
     *   - an explicit **deny**  → refused;
     *   - **no rule at all**    → allowed, because a project that has granted
     *     nothing must keep working exactly as it did before this class existed.
     *
     * Override in the generated controller to tighten it — returning
     * `parent::authorize($action) && $user->isAdmin()`, for example.
     *
     * @param string $action list|read|create|update|delete
     */
    protected function authorize(string $action): bool
    {
        $decision = $this->permissionFor($action);

        return $decision !== false;
    }

    /**
     * The permission store's opinion, or null when it has none.
     *
     * `$nonExistEqualsFalse = false` is what makes "no rule" distinguishable
     * from "denied": with it, a missing row returns null instead of collapsing
     * to false and locking out every project that never granted anything.
     *
     * A failure to read permissions is not an authorisation decision, so it is
     * reported as "no opinion" rather than silently allowing or denying based on
     * a broken query.
     */
    protected function permissionFor(string $action): ?bool
    {
        $user = $this->requestUser();
        if (!is_object($user)) {
            return null;
        }

        $decision = $this->askPermissionStore($action);

        return $decision === null ? null : (bool) $decision;
    }

    /**
     * Ask the permission system about one action for the current user.
     *
     * The framework has one store: **authserver.permissions**, created by the
     * `auth` feature's migrations and read through PermissionResolver. Grants
     * are (object_type, object_id, action) with deny-over-allow already
     * resolved.
     *
     * The legacy `<prefix>permissions` table is consulted afterwards, and only
     * where a project actually has one — no migration creates it, so that means
     * an installation that hand-built it before the store existed.
     *
     * Neither present means no opinion, not denial.
     *
     * @return bool|null true allow, false deny, null no opinion
     */
    protected function askPermissionStore(string $action): ?bool
    {
        $user = $this->requestUser();
        if (!is_object($user)) {
            return null;
        }

        // An application's own scheme comes first: it is the one that actually
        // governs the rest of that application, so a generated endpoint must not
        // be looser than the hand-written ones beside it.
        $own = $this->askApplicationUser($user, $action);
        if ($own !== null) {
            return $own;
        }

        $modern = $this->askPermissionResolver((int) $user->userid, $action);
        if ($modern !== null) {
            return $modern;
        }

        if (!$this->legacyAclExists()) {
            return null;
        }

        try {
            return \Pramnos\Auth\Permissions::getInstance()->isAllowed(
                (int) $user->userid,
                $this->resourceName(),
                $action,
                '',
                'module',
                'user',
                false
            );
        } catch (\Throwable) {
            return null;
        }
    }


    /**
     * Ask the application's own User class, when it has an opinion.
     *
     * Real applications carry their own permission scheme long before they
     * adopt the framework's. The reference production application, for one,
     * declares named flags on the user — `viewCustomer`, `editDevice`,
     * `createUser` — lists them in `User::getAllPermissions()`, and asks
     * `$user->hasPermission('viewCustomer')`. A generated endpoint that ignored
     * that would be a hole in an otherwise guarded application.
     *
     * Two rules keep this from guessing:
     *
     *  - it is asked only when the User class actually implements
     *    `hasPermission()`;
     *  - and only for a permission the application **declares**. Such
     *    implementations typically read an undefined flag as "no", so asking
     *    about an entity the application never heard of would deny every
     *    generated CRUD until someone added a column. Undeclared means no
     *    opinion, and the caller falls through to the framework's systems.
     *
     * CRUD actions are mapped to the verbs those schemes use: list and read are
     * `view`, update is `edit`.
     *
     * @param  object $user   The session user
     * @param  string $action list|read|create|update|delete
     * @return bool|null      true allow, false deny, null nothing to say
     */
    protected function askApplicationUser(object $user, string $action): ?bool
    {
        if (!method_exists($user, 'hasPermission')) {
            return null;
        }

        $verb = match ($action) {
            'list', 'read' => 'view',
            'update'       => 'edit',
            default        => $action,
        };
        $permission = $verb . ucfirst($this->resourceName());

        if (!$this->applicationDeclaresPermission($user, $permission)) {
            return null;
        }

        try {
            return (bool) $user->hasPermission($permission);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Does the application declare this permission name?
     *
     * Looked up in `User::getAllPermissions()` when the class provides it — the
     * shape the reference application uses: sections of name => label. Without
     * such a registry there is no way to tell a real "no" from an unknown name,
     * so nothing is asked.
     */
    protected function applicationDeclaresPermission(object $user, string $permission): bool
    {
        if (!method_exists($user, 'getAllPermissions')) {
            return false;
        }

        try {
            $declared = $user::getAllPermissions();
        } catch (\Throwable) {
            return false;
        }

        if (!is_array($declared)) {
            return false;
        }

        foreach ($declared as $section) {
            if (is_array($section) && array_key_exists($permission, $section)) {
                return true;
            }
        }

        return array_key_exists($permission, $declared);
    }

    /**
     * The RBAC/ABAC system's verdict for this resource and action.
     *
     * A grant matches when its object_type is this resource and its action is
     * this action (or `*`, the usual "everything on this object" row). Grants
     * carrying ABAC conditions are ignored here: the resolver passes conditions
     * through for the application to evaluate against its own request context,
     * and a generated controller has no such context — treating a conditional
     * grant as unconditional would hand out access the rule did not give.
     *
     * @return bool|null true allow, false deny, null nothing to say
     */
    protected function askPermissionResolver(int $userId, string $action): ?bool
    {
        return $this->resolverVerdict($userId, $action, null);
    }

    /**
     * The permission store's opinion about one action on one specific record.
     *
     * `authorize()` asks about the endpoint — "may this user update invoices" —
     * because that is all it is given. It is not the same question as "may this user
     * update invoice 42", and only the caller holding the id can ask the second one.
     *
     * Use it in a generated controller that has row-level grants:
     *
     * ```php
     * // In a generated controller, where the action already has the id.
     * public function read($id): mixed
     * {
     *     if (($denied = $this->guard('read')) !== null) {
     *         return Response::json($denied, $denied['status']);
     *     }
     *     if ($this->permissionForObject('read', (string) $id) === false) {
     *         return Response::json(['error' => 'forbidden'], 403);
     *     }
     *     // …
     * }
     * ```
     *
     * Same three outcomes as everywhere else in this class: true allow, false deny,
     * null no rule — and null must not be read as a refusal, or a project that has
     * granted nothing stops working.
     *
     * @param string $action   list|read|create|update|delete
     * @param string $objectId The record's identifier.
     * @return bool|null
     */
    protected function permissionForObject(string $action, string $objectId): ?bool
    {
        $user = $this->requestUser();
        if (!is_object($user)) {
            return null;
        }

        return $this->resolverVerdict((int) $user->userid, $action, $objectId);
    }

    /**
     * Read a verdict out of the permission store for one action, optionally against
     * one specific record.
     *
     * ## Why `$objectId` exists
     *
     * `object_id` is the store's only per-record mechanism, and this loop used to
     * ignore it: every grant matching (object_type, action) counted, whatever record
     * it named. So a grant written as "read invoice 42" — the careful, narrow thing
     * an administrator reaches for — was read by the generated endpoints as "read
     * invoices", and the list action returned all of them.
     *
     * Now a grant applies only where it was meant to:
     *
     *   - `$objectId === null` — the endpoint-level question. Only grants that name
     *     no object (`object_id` NULL or `*`) answer it. A grant for one record has
     *     nothing to say about the whole collection, in either direction: it cannot
     *     open it, and a deny on one row cannot close it.
     *   - `$objectId` given — the record-level question. Grants naming that record
     *     answer it, and so do the blanket ones, since a grant over every invoice
     *     covers invoice 42.
     *
     * A grant carrying ABAC `conditions` is still skipped: the resolver passes those
     * through for the application to evaluate against its own request context, and
     * this class has none of that context.
     *
     * @param int         $userId   Whose permissions to read.
     * @param string      $action   list|read|create|update|delete
     * @param string|null $objectId The record, or null for the endpoint-level question.
     * @return bool|null true allow, false deny, null nothing to say
     */
    private function resolverVerdict(int $userId, string $action, ?string $objectId): ?bool
    {
        try {
            $result = $this->permissionResolver()->resolve($userId, null);
        } catch (\Throwable) {
            // No authserver schema, or an unreadable one: not a decision.
            return null;
        }

        $resource = $this->resourceName();
        $verdict  = null;

        foreach ($result['permissions'] ?? [] as $grant) {
            if (($grant['object_type'] ?? '') !== $resource) {
                continue;
            }
            $granted = (string) ($grant['action'] ?? '');
            if ($granted !== $action && $granted !== '*') {
                continue;
            }
            if (($grant['conditions'] ?? null) !== null) {
                continue;
            }
            if (!$this->grantCoversObject($grant, $objectId)) {
                continue;
            }

            // A deny anywhere in the matching set settles it; the resolver has
            // already applied deny-over-allow within each (object, action).
            if (($grant['grant'] ?? '') === 'deny') {
                return false;
            }
            $verdict = true;
        }

        return $verdict;
    }

    /**
     * The resolver to read grants from.
     *
     * Its own method so a test can substitute a resolver over canned grants: the
     * object-matching rules below are the whole point of this class's permission
     * handling, and reaching them through a live authserver schema would test the
     * schema instead. Same seam, and the same reason, as
     * {@see \Pramnos\Auth\OAuth2\Repositories\ClientRepository::makeApplication()}.
     */
    protected function permissionResolver(): \Pramnos\Auth\PermissionResolverInterface
    {
        return new \Pramnos\Auth\PermissionResolver(
            \Pramnos\Database\Database::getInstance()
        );
    }

    /**
     * Does this grant speak to the object being asked about?
     *
     * @param array<string, mixed> $grant    One resolved grant.
     * @param string|null          $objectId The record, or null for the whole type.
     */
    private function grantCoversObject(array $grant, ?string $objectId): bool
    {
        $grantObject = $grant['object_id'] ?? null;

        // NULL and '*' both mean "every object of this type" — the column is
        // nullable and also documented as accepting the wildcard.
        $isBlanket = $grantObject === null
            || $grantObject === ''
            || (string) $grantObject === '*';

        if ($isBlanket) {
            return true;
        }

        return $objectId !== null && (string) $grantObject === $objectId;
    }

    /**
     * Does the legacy ACL table exist in this installation?
     *
     * It has no migration and nothing provisions it, so in a scaffolded project
     * the answer is no — and `Permissions::isAllowed()` reports its failed
     * lookup as `false`, which is indistinguishable from a deny. Asking first is
     * what keeps "cannot answer" apart from "no": without it, every signed-in
     * user was refused every action, and a fresh project's admin screen told its
     * own administrator they had no permission.
     *
     * Cached per request: the answer cannot change mid-request.
     */
    protected function legacyAclExists(): bool
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        try {
            $database = $this->db();
            $table    = $this->legacyAclTable();
            // Asked through the schema builder, not with a `SELECT ... FROM` against the
            // table. A select against a table that is not there is an *error* on the way
            // to being an answer: PostgreSQL raises `relation "permissions" does not
            // exist`, the connection logs it before this catch ever sees it, and every
            // API request on an installation with no legacy ACL wrote a line that looked
            // like a fault. On this server it was the most frequent error in the log, and
            // it was the framework asking a question. `Permissions::tableExists()` reached
            // the same conclusion; this caller had not.
            //
            // `hasTable()` also resolves `#PREFIX#` and the schema/prefix difference
            // between drivers, which the raw name did not.
            $exists = $database->schema()->hasTable((string) $table);
        } catch (\Throwable) {
            $exists = false;
        }

        return $exists;
    }

    /**
     * The legacy ACL table's name.
     *
     * Its own method, like {@see db()} below, so a test can point the probe at a table that
     * certainly does not exist — which is the only state in which the bug above was
     * visible, and the state every new installation is in.
     */
    protected function legacyAclTable(): string
    {
        return defined('DB_PERMISSIONSTABLE') ? DB_PERMISSIONSTABLE : '#PREFIX#permissions';
    }

    /**
     * The connection this controller asks.
     *
     * A seam, matching `Permissions::db()`. The bug the probe above carried is a
     * *dialect* fact — PostgreSQL logs the failed select, MySQL does not — so the test
     * for it has to bring its own PostgreSQL connection.
     */
    protected function db(): \Pramnos\Database\Database
    {
        return \Pramnos\Database\Database::getInstance();
    }

    /**
     * The resource name used when asking about permissions.
     */
    protected function resourceName(): string
    {
        if ($this->resource !== '') {
            return $this->resource;
        }

        $class = static::class;
        $short = substr($class, (int) strrpos($class, '\\') + 1);

        return strtolower($short);
    }
}
