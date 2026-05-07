<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Repositories;

use Pramnos\Auth\Application;
use Pramnos\Auth\OAuth2\Entities\ClientEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

/**
 * OAuth2 Client Repository
 *
 * Resolves OAuth2 client_id values to ClientEntity objects by querying the
 * `applications` table. Also validates client secrets during confidential
 * client authentication.
 *
 * @package PramnosFramework
 */
class ClientRepository implements ClientRepositoryInterface
{
    private \Pramnos\Application\Controller $controller;

    public function __construct(\Pramnos\Application\Controller $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Return a ClientEntity for the given client_id, or null if not found.
     *
     * The league/oauth2-server calls this to resolve the client before
     * validating the secret or grant type. The entity must be returned even
     * for unverified clients — secret validation is done in validateClient().
     */
    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $application = new Application($this->controller, 'Application', 0);
        if (!$application->loadByApiKey($clientIdentifier)) {
            return null;
        }

        $entity = new ClientEntity();
        $entity->setIdentifier($application->getClientIdentifier());
        $entity->setName($application->getClientName());
        $entity->setRedirectUri($application->getRedirectUris());
        $entity->setConfidential($application->isConfidential());

        return $entity;
    }

    /**
     * Validate client credentials.
     *
     * Returns true when the client_id + client_secret combination is valid
     * and the application is active. Public clients (secret=null) are
     * accepted only when the application is configured as non-confidential.
     */
    public function validateClient(
        string $clientIdentifier,
        ?string $clientSecret,
        ?string $grantType
    ): bool {
        $application = new Application($this->controller, 'Application', 0);
        return $application->validateCredentials($clientIdentifier, $clientSecret);
    }
}
