<?php

namespace PramnosTest\ScopedRoutes;

use Pramnos\Routing\Attributes\Route;

/**
 * A discovered controller whose write route declares the API-key scope it needs.
 */
class ScopedController
{
    /** Declares a scope: discovery must attach the check. */
    #[Route('/scoped/stations', methods: 'POST', name: 'scoped.store', apiKeyScopes: ['stations:write'])]
    public function store(): string
    {
        return 'stored';
    }

    /** Declares none: discovery must attach nothing. */
    #[Route('/scoped/stations', methods: 'GET', name: 'scoped.index')]
    public function index(): string
    {
        return 'listed';
    }
}
