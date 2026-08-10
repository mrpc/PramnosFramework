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
     * Is there a real, signed-in user on this request?
     *
     * User 1 is the anonymous/system account, so it does not count — the same
     * rule the generated controllers applied inline.
     */
    protected function isAuthenticated(): bool
    {
        $user = $_SESSION['user'] ?? null;

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
        $user = $_SESSION['user'] ?? null;
        if (!is_object($user)) {
            return null;
        }

        try {
            $permissions = \Pramnos\Auth\Permissions::getInstance();
            $decision    = $permissions->isAllowed(
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

        return $decision === null ? null : (bool) $decision;
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
