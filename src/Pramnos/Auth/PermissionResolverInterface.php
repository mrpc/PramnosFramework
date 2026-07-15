<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * Resolves a user's effective RBAC+ABAC permissions for one application.
 *
 * The framework implementation (PermissionResolver) reads authserver.permissions
 * — the user's own grants plus those of the roles they hold — scoped to a single
 * application (audience), applies deny-over-allow, and returns each effective
 * grant together with its ABAC conditions (pass-through, evaluated at runtime by
 * the caller).
 *
 * This is an extension seam: an application layer (e.g. an application auth layer
 * licensing) can decorate or subclass a resolver to intersect the framework
 * result with an entitlement gate — without forking the framework —
 * because licensing composes as `Licensing ∩ (RBAC ∩ ABAC)`.
 */
interface PermissionResolverInterface
{
    /**
     * Resolve the effective permissions of a user within an application.
     *
     * @param int      $userId users.userid to resolve for.
     * @param int|null $appId  applications.appid (audience). Global permissions
     *                         (app_id IS NULL) always apply; app-scoped rows
     *                         apply only when their app_id matches $appId.
     * @return array{user_id:int,app_id:int|null,permissions:list<array{
     *             object_type:string,object_id:string|null,action:string,
     *             grant:string,conditions:mixed}>}
     *         A flat list of effective grants (grant = 'allow'|'deny').
     */
    public function resolve(int $userId, ?int $appId): array;
}
