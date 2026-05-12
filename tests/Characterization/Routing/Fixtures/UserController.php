<?php

declare(strict_types=1);

namespace PramnosTest\Routing\Fixtures;

use Pramnos\Routing\Attributes\Route;

/**
 * Fixture controller used by RoutingCharacterizationTest.
 * Methods return simple strings so tests can verify dispatch output.
 */
class UserController
{
    #[Route('/users', methods: 'GET', name: 'users.index')]
    public function index(): string
    {
        return 'user_list';
    }

    #[Route('/users', methods: 'POST', name: 'users.store', permissions: ['write:users'])]
    public function store(): string
    {
        return 'user_created';
    }

    #[Route('/users/{id}', methods: 'GET', name: 'users.show')]
    public function show(): string
    {
        return 'user_detail';
    }

    #[Route('/users/{id}', methods: 'PUT',    name: 'users.update')]
    #[Route('/users/{id}', methods: 'PATCH')]
    public function update(): string
    {
        return 'user_updated';
    }

    #[Route('/users/{id}', methods: 'DELETE', name: 'users.destroy')]
    public function destroy(): string
    {
        return 'user_deleted';
    }
}
