<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;

/**
 * OAuth2 Client Entity
 *
 * Represents a registered OAuth2 client application within the league/oauth2-server
 * grant flow. Hydrated by ClientRepository from the `applications` table.
 *
 * @package PramnosFramework
 */
class ClientEntity implements ClientEntityInterface
{
    use EntityTrait, ClientTrait;

    private string $name = '';
    /** @var string|string[] */
    private string|array $redirectUri = '';
    private bool $isConfidential = true;

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @param string|string[] $uri */
    public function setRedirectUri(string|array $uri): void
    {
        $this->redirectUri = $uri;
    }

    /** @return string|string[] */
    public function getRedirectUri(): string|array
    {
        return $this->redirectUri;
    }

    public function setConfidential(bool $isConfidential): void
    {
        $this->isConfidential = $isConfidential;
    }

    public function isConfidential(): bool
    {
        return $this->isConfidential;
    }
}
