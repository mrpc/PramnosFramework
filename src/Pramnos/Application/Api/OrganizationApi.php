<?php

declare(strict_types=1);

namespace Pramnos\Application\Api;

use Pramnos\Application\Controllers\Organization;
use Pramnos\Routing\Router;

/**
 * The organisations a person manages, under the application's API, with the API's credentials —
 * what `/organization` offers its managers, for an app or a SPA: the list, one with its members,
 * roles and invitations, and adding, removing, giving and taking roles, withdrawing invitations.
 *
 * ```php
 * \Pramnos\Application\Api\OrganizationApi::routes($r);      // under /organizations
 * ```
 *
 * The screen's own actions answer, as JSON: the same rights (`OrganizationAdmin::manages()`) and
 * the same answers — `{organizations}`, `{organization_id, name, members, roles, invitations}`,
 * `{ok, message}` — and their refusals with their status.
 */
class OrganizationApi
{
    /** Mount the routes, under `$path`, each described by the action that answers it. */
    public static function routes(Router $r, string $path = '/organizations'): void
    {
        $path = '/' . trim($path, '/');
        $r->get($path, static fn () => static::screen(0)->display())->describeFrom([Organization::class, 'display']);
        $r->get($path . '/{id}', static fn ($id) => static::screen((int) $id)->view())->describeFrom([Organization::class, 'view']);
        foreach (['add', 'remove', 'giverole', 'takerole', 'withdraw'] as $action) {
            $r->post($path . '/{id}/' . $action, static fn ($id) => static::screen((int) $id)->{$action}())->describeFrom([Organization::class, $action]);
        }
    }

    /** The screen, answering in JSON for this organisation. */
    protected static function screen(int $id): Organization
    {
        $screen = new class (\Pramnos\Application\Application::getInstance()) extends Organization {
            /** The organisation from the route. */
            public int $routeId = 0;

            /** The route's organisation, not the page's address. */
            protected function organizationId(): int
            {
                return $this->routeId;
            }

            /** Always JSON: this is the API. */
            protected function wantsJson(): bool
            {
                return true;
            }
        };
        $screen->routeId = $id;

        return $screen;
    }
}
