<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Repositories;

use Pramnos\Auth\OAuth2\Entities\UserEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Repositories\UserRepositoryInterface;

/**
 * OAuth2 User Repository — Password Grant
 *
 * Verifies username/password credentials during the Resource Owner Password
 * Credentials grant. Delegates to `Pramnos\User\User::validateUserCredentials()`
 * so the existing bcrypt/legacy-md5 logic is reused without duplication.
 *
 * The Password grant is considered legacy in OAuth 2.1; prefer Authorization
 * Code + PKCE for new integrations. This repository is provided for backward
 * compatibility with existing integrations.
 *
 */
class UserRepository implements UserRepositoryInterface
{
    /**
     * Validate user credentials and return a UserEntity on success.
     *
     * Returns null when the credentials are wrong or the user does not exist,
     * causing league/oauth2-server to return an invalid_grant error response.
     */
    public function getUserEntityByUserCredentials(
        $username,
        $password,
        $grantType,
        ClientEntityInterface $clientEntity
    ): ?UserEntityInterface {
        $credentials = \Pramnos\User\User::validateUserCredentials($username, $password);

        if (!$credentials || empty($credentials['userid']) || (int)$credentials['userid'] < 1) {
            return null;
        }

        $entity = new UserEntity();
        $entity->setIdentifier((int)$credentials['userid']);

        return $entity;
    }
}
