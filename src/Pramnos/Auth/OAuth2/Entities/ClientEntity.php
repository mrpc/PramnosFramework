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

    // PHP 8.4: properties declared by ClientTrait ($name, $redirectUri,
    // $isConfidential) must not be redeclared with a different visibility or
    // type — the trait uses untyped protected properties.  Defaults are set
    // via the constructor instead so the trait's property declarations win.
    public function __construct()
    {
        $this->name           = '';
        $this->redirectUri    = '';
        $this->isConfidential = true;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return (string) $this->name;
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
        return (bool) $this->isConfidential;
    }
}
