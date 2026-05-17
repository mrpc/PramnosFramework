<?php

declare(strict_types=1);

namespace PramnosTest\Routing\Fixtures;

use Pramnos\Routing\Attributes\Route;

/**
 * Fixture controller that exercises edge cases in RouteDiscovery::registerRoute():
 * - OPTIONS HTTP method (line 146)
 * - Unknown / unsupported HTTP method → null → skip (lines 147, 151)
 * - middleware attribute on a route (line 159)
 *
 * Used by RoutingCharacterizationTest exclusively.
 */
class DiscoveryEdgeCasesController
{
    #[Route('/api/preflight', methods: 'OPTIONS', name: 'edge.options')]
    public function preflight(): string
    {
        return 'options_ok';
    }

    /** Unknown method 'PURGE' must be silently skipped; the GET fallback is still registered. */
    #[Route('/api/edge/purge', methods: 'PURGE')]
    #[Route('/api/edge/purge', methods: 'GET', name: 'edge.purge.get')]
    public function purge(): string
    {
        return 'purge_ok';
    }

    #[Route('/api/secured', methods: 'GET', name: 'edge.secured', middleware: ['App\Middleware\AuthMiddleware', 'App\Middleware\Throttle'])]
    public function secured(): string
    {
        return 'secured_ok';
    }
}
