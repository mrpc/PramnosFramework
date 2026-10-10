<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Repositories;

use Pramnos\Auth\OAuth2\Entities\UserEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Pramnos\Auth\Loginlockout;
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
 * The account lockout is the login form's: a failure here counts toward it, a locked account is
 * refused before its password is checked (`invalid_grant`, with `Retry-After`), and a success
 * clears it. Without that, the token endpoint was a way round the lockout.
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
        // The login form's identifier, so both count toward the same lockout.
        $identifier = strtolower(trim((string) $username));
        $lockout    = $this->lockout();

        $status = $lockout->getLockoutStatus('identifier', $identifier);
        if (!empty($status['locked'])) {
            throw self::locked((int) $status['remaining']);
        }

        $credentials = \Pramnos\User\User::validateUserCredentials($username, $password);

        if (!$credentials || empty($credentials['userid']) || (int)$credentials['userid'] < 1) {
            $lockout->recordFailedAttempt('identifier', $identifier);

            return null;
        }
        $lockout->clearSuccessfulLoginState('identifier', $identifier);

        $entity = new UserEntity();
        $entity->setIdentifier((int)$credentials['userid']);

        return $entity;
    }

    /** The lockout store; a seam for tests. */
    protected function lockout(): Loginlockout
    {
        return new Loginlockout();
    }

    /**
     * `invalid_grant` for a locked account, telling the client when to try again.
     */
    private static function locked(int $seconds): OAuthServerException
    {
        return new class ($seconds) extends OAuthServerException {
            /** @param int $seconds Until the lockout ends */
            public function __construct(private int $seconds)
            {
                parent::__construct('The account is temporarily locked after too many failed sign-ins.', 10, 'invalid_grant', 400);
            }

            /** League's headers, and Retry-After. */
            public function getHttpHeaders()
            {
                return parent::getHttpHeaders() + ['Retry-After' => (string) max(1, $this->seconds)];
            }
        };
    }
}
