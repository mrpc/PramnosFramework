<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * A permission resolver that can answer about one organisation.
 *
 * Its own interface rather than a method added to {@see PermissionResolverInterface}, because a
 * method added to an interface stops every class implementing it from loading. A resolver an
 * application supplies without it still works wherever no organisation is in scope; where one
 * is, the framework refuses rather than fall back to the unscoped answer, which is the union of
 * the user's roles in every organisation.
 */
interface OrganizationPermissionResolverInterface extends PermissionResolverInterface
{
    /**
     * What a user may do in one organisation: system-wide roles, plus that organisation's
     * roles if they are a member of it.
     *
     * @return array{user_id:int,app_id:int|null,organization_id:int,permissions:list<array<string,mixed>>}
     */
    public function resolveForOrganization(int $userId, ?int $appId, int $organizationId): array;
}
