<?php

declare(strict_types=1);

namespace Pramnos\Tests\Fixtures\OpenApi;

use Pramnos\Routing\Attributes\Route;

/**
 * Fixture controller for the api:docs command test — a real PSR-4 file so the
 * command's directory scan resolves it to a class it can reflect.
 */
class DemoApiController
{
    /** Ping the service. */
    #[Route('/api/ping', methods: 'GET', name: 'demo.ping')]
    public function ping(): void
    {
    }

    /** Create a thing (admin only). */
    #[Route('/api/things', methods: 'POST', name: 'demo.create', middleware: ['App\\Middleware\\AdminAuthMiddleware'])]
    public function create(): void
    {
    }
}
