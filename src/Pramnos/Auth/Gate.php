<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Authorization rules that live in code.
 *
 * The framework already had a permission **store** — {@see Permissions}, which records what
 * a given installation has granted, and can be edited at runtime by an admin. What it cannot
 * express is a *rule*: "the author, or a moderator" is not a row, and writing it as rows
 * means one row per article per user.
 *
 * A gate is that missing half. It answers the same question from the other direction:
 *
 * | | Answers | Lives in | Changes by |
 * | --- | --- | --- | --- |
 * | {@see Permissions} | what has this installation granted | a table | an admin, at runtime |
 * | `Gate` | what does this rule mean | code | a deploy |
 *
 * Neither replaces the other, and most applications end up wanting both — which is why
 * {@see fallbackToPermissions()} exists, and why it is off until you ask for it.
 *
 * ### Defining
 *
 * ```php
 * Gate::define('update-post', function ($user, $post) {
 *     return $user->userid === $post->userid;
 * });
 *
 * Gate::policy(\App\Models\Post::class, \App\Policies\PostPolicy::class);
 *
 * // "an administrator may do anything", once, instead of in every rule
 * Gate::before(fn ($user) => $user->isAdmin() ? true : null);
 * ```
 *
 * ### Asking
 *
 * ```php
 * Gate::allows('update-post', $post);           // bool, for the current user
 * Gate::denies('update-post', $post);           // the same question, inverted
 * Gate::authorize('update-post', $post);        // or throws AuthorizationException
 *
 * Gate::forUser($someoneElse)->allows('update-post', $post);
 * ```
 *
 * ### The order a decision is made in
 *
 * 1. **`before` callbacks**, in registration order. A non-`null` return decides immediately
 *    and nothing else runs — this is how "an admin may do anything" is expressed once.
 * 2. **A named ability**, if one was defined for this name.
 * 3. **A policy**, if the first argument is an object (or class name) with a registered
 *    policy carrying a method of this name.
 * 4. **The permission store**, if {@see fallbackToPermissions()} is on and the ability reads
 *    as `resource.privilege`.
 * 5. Otherwise **deny**. An ability nobody defined is not an ability.
 * 6. **`after` callbacks**, which may override the result. A `null` return leaves it alone.
 *
 * A `null` from a rule at step 2 or 3 means "no opinion" and falls through to the next step,
 * the same three-valued idea the permission store uses.
 *
 * ### Registration is process-wide
 *
 * Abilities are static, which is right for a request and wrong for a test run or a worker
 * serving more than one request — see {@see reset()}.
 *
 * @see Permissions for the store
 * @see AuthorizationException for what `authorize()` throws
 */
class Gate
{
    /**
     * Named abilities.
     *
     * @var array<string, callable>
     */
    private static array $abilities = [];

    /**
     * Model class name => policy class name.
     *
     * @var array<string, string>
     */
    private static array $policies = [];

    /**
     * Callbacks consulted before every check.
     *
     * @var callable[]
     */
    private static array $beforeCallbacks = [];

    /**
     * Callbacks given the chance to override every result.
     *
     * @var callable[]
     */
    private static array $afterCallbacks = [];

    /**
     * How to find the current user, when nothing else says.
     *
     * @var callable|null
     */
    private static $userResolver = null;

    /**
     * Resource type used when falling back to the permission store, or null when off.
     *
     * @var string|null
     */
    private static ?string $permissionFallbackType = null;

    /**
     * The user this gate answers for.
     *
     * @var object|null
     */
    private ?object $user;

    /**
     * @param object|null $user The user to answer for; null means nobody is signed in
     */
    public function __construct(?object $user = null)
    {
        $this->user = $user;
    }

    // ── Registration ────────────────────────────────────────────────────────────

    /**
     * Defines a named ability.
     *
     * The callback receives the user first and then whatever the check passed:
     * `Gate::allows('update-post', $post)` calls `$callback($user, $post)`.
     *
     * Return `true` to allow, `false` to deny, and `null` to express **no opinion** — which
     * falls through to a policy, then to the store, rather than denying. That distinction is
     * what lets several rules cover one ability without fighting.
     *
     * @param string   $ability  The name checks will use
     * @param callable $callback Receives `($user, ...$arguments)`
     * @return void
     */
    public static function define(string $ability, callable $callback): void
    {
        self::$abilities[$ability] = $callback;
    }

    /**
     * Registers a policy class for a model class.
     *
     * A policy is an ordinary class whose methods are ability names:
     *
     * ```php
     * class PostPolicy
     * {
     *     public function update($user, $post) { return $user->userid === $post->userid; }
     * }
     * ```
     *
     * `Gate::allows('update', $post)` then calls `PostPolicy::update($user, $post)`. Hyphens
     * and underscores in an ability are folded to camelCase, so `update-post` finds
     * `updatePost()`.
     *
     * A policy may also carry its own `before()` and `after()`, which run around **its own**
     * methods only — narrower than the global hooks, and useful for "the owner may do
     * anything to their own record".
     *
     * @param string $class       The model class the policy governs
     * @param string $policyClass The policy class name
     * @return void
     */
    public static function policy(string $class, string $policyClass): void
    {
        self::$policies[ltrim($class, '\\')] = $policyClass;
    }

    /**
     * Adds a callback consulted before every check.
     *
     * Return `true`/`false` to decide immediately, or `null` to let the check continue. This
     * is where "an administrator may do anything" belongs — written once rather than at the
     * top of every rule.
     *
     * @param callable $callback Receives `($user, $ability, ...$arguments)`
     * @return void
     */
    public static function before(callable $callback): void
    {
        self::$beforeCallbacks[] = $callback;
    }

    /**
     * Adds a callback that may override a result.
     *
     * Runs after a decision has been reached. Return `null` to leave it alone.
     *
     * @param callable $callback Receives `($user, $ability, $result, ...$arguments)`
     * @return void
     */
    public static function after(callable $callback): void
    {
        self::$afterCallbacks[] = $callback;
    }

    /**
     * Tells the gate how to find the current user.
     *
     * Without this, `Gate::allows()` asks {@see \Pramnos\Http\RequestIdentity} — which is
     * where the framework's own authentication puts the answer. Override when an application
     * identifies its user some other way.
     *
     * @param callable $resolver Returns the current user object, or null
     * @return void
     */
    public static function resolveUserUsing(callable $resolver): void
    {
        self::$userResolver = $resolver;
    }

    /**
     * Lets undefined abilities fall through to the permission store.
     *
     * With this on, an ability written as `resource.privilege` that no gate or policy claims
     * is answered by `Permissions::isAllowed($userId, $resource, $privilege, …)`. That is the
     * bridge between rules in code and grants in a table: define the rules that need
     * reasoning, and let everything else be data an administrator can edit.
     *
     * **Off by default**, and deliberately explicit: a gate that silently consults a database
     * for names nobody registered is a gate whose answers cannot be read off the code.
     *
     * @param string|null $resourceType The store's `$resourceType`, or null to switch it off
     * @return void
     */
    public static function fallbackToPermissions(?string $resourceType = 'module'): void
    {
        self::$permissionFallbackType = $resourceType;
    }

    /**
     * Forgets every ability, policy, hook and resolver.
     *
     * Registration is process-wide, which is correct for a request and wrong for anything
     * that handles more than one: a test run, or a worker. Both need this between requests,
     * for exactly the reason the framework's other process-wide singletons do.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$abilities              = [];
        self::$policies               = [];
        self::$beforeCallbacks        = [];
        self::$afterCallbacks         = [];
        self::$userResolver           = null;
        self::$permissionFallbackType = null;
    }

    /**
     * Whether an ability has been defined by name.
     *
     * @param string $ability The ability name
     * @return bool True when `define()` has been called for it
     */
    public static function has(string $ability): bool
    {
        return isset(self::$abilities[$ability]);
    }

    /**
     * The policy class registered for a model class, if any.
     *
     * @param object|string $class A model instance or class name
     * @return string|null The policy class name, or null when none is registered
     */
    public static function getPolicyFor(object|string $class): ?string
    {
        $name = ltrim(is_object($class) ? $class::class : $class, '\\');

        return self::$policies[$name] ?? null;
    }

    // ── Asking ──────────────────────────────────────────────────────────────────

    /**
     * A gate bound to a specific user.
     *
     * @param object|null $user The user to answer for
     * @return static A gate answering for that user
     */
    public static function forUser(?object $user): static
    {
        return new static($user);
    }

    /**
     * A gate bound to whoever is signed in now.
     *
     * @return static A gate answering for the current user
     */
    public static function current(): static
    {
        return new static(self::currentUser());
    }

    /**
     * Whether the current user may do this.
     *
     * @param string $ability      The ability name
     * @param mixed  ...$arguments Passed to the rule after the user
     * @return bool True when allowed
     */
    public static function allows(string $ability, mixed ...$arguments): bool
    {
        return self::current()->check($ability, ...$arguments);
    }

    /**
     * Whether the current user may **not** do this.
     *
     * @param string $ability      The ability name
     * @param mixed  ...$arguments Passed to the rule after the user
     * @return bool True when refused
     */
    public static function denies(string $ability, mixed ...$arguments): bool
    {
        return !self::allows($ability, ...$arguments);
    }

    /**
     * Refuses loudly if the current user may not do this.
     *
     * @param string $ability      The ability name
     * @param mixed  ...$arguments Passed to the rule after the user
     * @return void
     * @throws AuthorizationException When the check refuses
     */
    public static function authorize(string $ability, mixed ...$arguments): void
    {
        self::current()->enforce($ability, ...$arguments);
    }

    /**
     * Whether this gate's user may do this.
     *
     * @param string $ability      The ability name
     * @param mixed  ...$arguments Passed to the rule after the user
     * @return bool True when allowed
     */
    public function check(string $ability, mixed ...$arguments): bool
    {
        return $this->decide($ability, $arguments) === true;
    }

    /**
     * Whether this gate's user may do **all** of these.
     *
     * @param string[] $abilities     The ability names
     * @param mixed    ...$arguments  Passed to each rule after the user
     * @return bool True only when every one allows
     */
    public function all(array $abilities, mixed ...$arguments): bool
    {
        foreach ($abilities as $ability) {
            if (!$this->check($ability, ...$arguments)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this gate's user may do **any** of these.
     *
     * @param string[] $abilities     The ability names
     * @param mixed    ...$arguments  Passed to each rule after the user
     * @return bool True when at least one allows
     */
    public function any(array $abilities, mixed ...$arguments): bool
    {
        foreach ($abilities as $ability) {
            if ($this->check($ability, ...$arguments)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Refuses loudly if this gate's user may not do this.
     *
     * Named `enforce()` rather than `authorize()` because
     * {@see \Pramnos\Application\ApiCrudController::authorize()} already exists with a
     * different meaning and signature, and two things called `authorize()` in one hierarchy
     * would be a trap regardless of whether PHP allowed it.
     *
     * @param string $ability      The ability name
     * @param mixed  ...$arguments Passed to the rule after the user
     * @return void
     * @throws AuthorizationException When the check refuses
     */
    public function enforce(string $ability, mixed ...$arguments): void
    {
        if (!$this->check($ability, ...$arguments)) {
            throw new AuthorizationException($ability);
        }
    }

    /**
     * The user this gate answers for.
     *
     * @return object|null The user, or null when nobody is signed in
     */
    public function user(): ?object
    {
        return $this->user;
    }

    // ── Deciding ────────────────────────────────────────────────────────────────

    /**
     * Runs the decision, in the documented order.
     *
     * Returns `null` rather than `false` when nothing had an opinion, so the caller can tell
     * "refused" from "nobody said" — `check()` collapses both to `false`, but `after`
     * callbacks see the difference.
     *
     * @param string  $ability   The ability name
     * @param mixed[] $arguments Whatever the check passed
     * @return bool|null True, false, or null for no opinion
     */
    private function decide(string $ability, array $arguments): ?bool
    {
        $result = $this->runBefore($ability, $arguments);

        if ($result === null) {
            $result = $this->runAbility($ability, $arguments);
        }

        if ($result === null) {
            $result = $this->runPolicy($ability, $arguments);
        }

        if ($result === null) {
            $result = $this->runPermissionFallback($ability);
        }

        return $this->runAfter($ability, $result, $arguments);
    }

    /**
     * Consults the `before` callbacks.
     *
     * @param string  $ability   The ability name
     * @param mixed[] $arguments Whatever the check passed
     * @return bool|null The first non-null answer, or null
     */
    private function runBefore(string $ability, array $arguments): ?bool
    {
        foreach (self::$beforeCallbacks as $callback) {
            $result = $callback($this->user, $ability, ...$arguments);
            if ($result !== null) {
                return (bool) $result;
            }
        }

        return null;
    }

    /**
     * Lets the `after` callbacks override a result.
     *
     * @param string    $ability   The ability name
     * @param bool|null $result    What was decided
     * @param mixed[]   $arguments Whatever the check passed
     * @return bool|null The final answer
     */
    private function runAfter(string $ability, ?bool $result, array $arguments): ?bool
    {
        foreach (self::$afterCallbacks as $callback) {
            $override = $callback($this->user, $ability, $result, ...$arguments);
            if ($override !== null) {
                $result = (bool) $override;
            }
        }

        return $result;
    }

    /**
     * Calls a named ability, if one is defined.
     *
     * @param string  $ability   The ability name
     * @param mixed[] $arguments Whatever the check passed
     * @return bool|null The rule's answer, or null when there is no such ability
     */
    private function runAbility(string $ability, array $arguments): ?bool
    {
        if (!isset(self::$abilities[$ability])) {
            return null;
        }

        $result = (self::$abilities[$ability])($this->user, ...$arguments);

        return $result === null ? null : (bool) $result;
    }

    /**
     * Calls a policy method, if the first argument has a policy carrying one.
     *
     * The first argument may be an instance or a class name, so a check can be made for a
     * model that does not exist yet — `Gate::allows('create', Post::class)`.
     *
     * @param string  $ability   The ability name
     * @param mixed[] $arguments Whatever the check passed
     * @return bool|null The policy's answer, or null when no policy applies
     */
    private function runPolicy(string $ability, array $arguments): ?bool
    {
        $subject = $arguments[0] ?? null;

        if (!is_object($subject) && !(is_string($subject) && class_exists($subject))) {
            return null;
        }

        $policyClass = self::getPolicyFor($subject);
        if ($policyClass === null || !class_exists($policyClass)) {
            return null;
        }

        $policy = new $policyClass();
        $method = self::methodName($ability);

        if (!method_exists($policy, $method)) {
            return null;
        }

        // A policy's own before() narrows the global hook to this policy's methods.
        if (method_exists($policy, 'before')) {
            $result = $policy->before($this->user, $ability, ...$arguments);
            if ($result !== null) {
                return (bool) $result;
            }
        }

        $result = $policy->$method($this->user, ...$arguments);
        $result = $result === null ? null : (bool) $result;

        if (method_exists($policy, 'after')) {
            $override = $policy->after($this->user, $ability, $result, ...$arguments);
            if ($override !== null) {
                return (bool) $override;
            }
        }

        return $result;
    }

    /**
     * Asks the permission store, when the fallback is switched on.
     *
     * Only abilities shaped `resource.privilege` are eligible: without a separator there is
     * nothing to tell the store, and guessing would be worse than declining.
     *
     * The store is asked with `$nonExistEqualsFalse = false`, so "no rule" arrives here as
     * `null` — no opinion — rather than as a denial. Whether that becomes a refusal is
     * decided by the caller and the `after` hooks, not silently here.
     *
     * @param string $ability The ability name
     * @return bool|null The store's answer, or null
     */
    private function runPermissionFallback(string $ability): ?bool
    {
        if (self::$permissionFallbackType === null || !str_contains($ability, '.')) {
            return null;
        }

        $subjectId = $this->subjectId();
        if ($subjectId === null) {
            return null;
        }

        [$resource, $privilege] = explode('.', $ability, 2);

        try {
            $permissions = Permissions::getInstance();

            $result = $permissions->isAllowed(
                $subjectId,
                $resource,
                $privilege,
                '',
                self::$permissionFallbackType,
                'user',
                false
            );
        } catch (\Throwable) {
            // @codeCoverageIgnoreStart
            // A store that cannot be read has no opinion. Reporting it as a denial would
            // lock people out over a broken query; as an approval, worse.
            //
            // Not covered by a test: reaching it needs a database that fails mid-request,
            // and the unit suite has a working one. Said here rather than faked with a test
            // whose description would not match what it does.
            return null;
            // @codeCoverageIgnoreEnd
        }

        return $result === null ? null : (bool) $result;
    }

    /**
     * This gate's user, as the permission store identifies subjects.
     *
     * @return int|string|null The user id, or null when there is nobody to ask about
     */
    private function subjectId(): int|string|null
    {
        if ($this->user === null) {
            return null;
        }

        foreach (['userid', 'id', 'userId'] as $property) {
            if (isset($this->user->$property)) {
                return $this->user->$property;
            }
        }

        return null;
    }

    /**
     * Whoever is signed in now.
     *
     * Uses the registered resolver if there is one, and otherwise the identity the
     * framework's authentication sealed for this request.
     *
     * @return object|null The current user, or null
     */
    private static function currentUser(): ?object
    {
        if (self::$userResolver !== null) {
            return (self::$userResolver)();
        }

        if (class_exists(\Pramnos\Http\RequestIdentity::class)) {
            return \Pramnos\Http\RequestIdentity::user();
        }

        // @codeCoverageIgnoreStart
        // Unreachable while RequestIdentity ships with the framework. Kept because the
        // gate must still answer if it ever does not, rather than fatal on a class that
        // is not there.
        return null;
        // @codeCoverageIgnoreEnd
    }

    /**
     * The policy method name for an ability.
     *
     * `update-post` and `update_post` both find `updatePost()`, so an ability can read
     * naturally in a route file and still be a valid method name.
     *
     * @param string $ability The ability name
     * @return string The method to look for
     */
    private static function methodName(string $ability): string
    {
        if (!str_contains($ability, '-') && !str_contains($ability, '_')) {
            return $ability;
        }

        $parts  = preg_split('/[-_]+/', $ability) ?: [$ability];
        $method = array_shift($parts);

        foreach ($parts as $part) {
            $method .= ucfirst($part);
        }

        return $method;
    }
}
