<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/**
 * OAuth2 Scope Entity
 *
 * Represents a single OAuth2 scope (e.g. "read", "write", "admin").
 * Hydrated by ScopeRepository::getScopeEntityByIdentifier().
 * JSON-serialized to the scope identifier string for JWT embedding.
 *
 */
class ScopeEntity implements ScopeEntityInterface
{
    use EntityTrait;

    public function getIdentifier(): mixed
    {
        return $this->identifier;
    }

    public function jsonSerialize(): mixed
    {
        return $this->getIdentifier();
    }
}
