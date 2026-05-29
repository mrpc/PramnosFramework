<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\UserEntityInterface;

/**
 * OAuth2 User Entity
 *
 * Lightweight carrier for the authenticated user's identifier during
 * the Password grant flow. Hydrated by UserRepository after credential
 * validation against the `users` table.
 *
 */
class UserEntity implements UserEntityInterface
{
    private mixed $identifier = null;

    public function getIdentifier(): mixed
    {
        return $this->identifier;
    }

    public function setIdentifier(mixed $identifier): void
    {
        $this->identifier = $identifier;
    }
}
