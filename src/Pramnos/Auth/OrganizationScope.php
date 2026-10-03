<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Which organisation the current request is about, for every permission check that needs it.
 *
 * ```php
 * // once, where the application boots
 * OrganizationScope::resolveWith(fn () => Tenant::currentOrganizationId());
 * ```
 *
 * A user may hold different roles in different organisations: a Client in one, an Editor in
 * another. A permission check that asks the store without an organisation gets the union of
 * every role the user holds anywhere, so the Client could write wherever the Editor can. With
 * a resolver set, the checks the framework makes — `ApiCrudController::authorize()`,
 * `Permissions::isAllowed()`, and through it `Gate`'s permission fallback — ask
 * {@see PermissionResolver::resolveForOrganization()} instead: system-wide roles, plus the
 * roles of that organisation for a user who is a member of it.
 *
 * **Without a resolver, or when it returns null, nothing changes.** An application with one
 * organisation, or a request that is about none, is checked as it always was.
 *
 * **A resolver that fails refuses rather than widens.** Falling back to the unscoped check
 * would hand out the union of every role — the exact thing this exists to stop — so the
 * callers treat an exception here as a denial.
 */
final class OrganizationScope
{
    /** @var (callable(): (int|string|null))|null */
    private static $resolver = null;

    /**
     * Set how the current organisation is found; null removes it.
     *
     * @param (callable(): (int|string|null))|null $resolver Returns the organisation id, or null
     */
    public static function resolveWith(?callable $resolver): void
    {
        self::$resolver = $resolver;
    }

    /** Whether an application has said how to find the organisation. */
    public static function isConfigured(): bool
    {
        return self::$resolver !== null;
    }

    /**
     * The organisation the current request is about, or null when it is about none.
     *
     * @throws \UnexpectedValueException When the resolver answers with something that is not
     *         an organisation id. Callers treat that, like any exception from the resolver, as
     *         a refusal.
     */
    public static function current(): ?int
    {
        if (self::$resolver === null) {
            return null;
        }

        $id = (self::$resolver)();
        if ($id === null) {
            return null;
        }
        if (is_int($id) && $id > 0) {
            return $id;
        }
        if (is_string($id) && ctype_digit($id) && (int) $id > 0) {
            return (int) $id;
        }

        throw new \UnexpectedValueException(
            'The organisation resolver returned ' . get_debug_type($id) . ', not an organisation id or null.'
        );
    }

    /** Forget the resolver — between requests in a worker, and between tests. */
    public static function reset(): void
    {
        self::$resolver = null;
    }
}
