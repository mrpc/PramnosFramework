<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Grants;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A token for a user, on the strength of an assertion the client signed (RFC 7523 §2.1).
 *
 * For a trusted service acting for one of its users without that user signing in. The assertion
 * passes every check {@see \Pramnos\Auth\OAuth2\JwtAssertion} makes, signed with the client's own
 * key, and its `sub` names the user by email or username. Its holder can obtain a token for any
 * user, so an application uses this grant only once it is enabled for it —
 * `GrantPolicy::enable($appId, 'jwt_bearer')`. No refresh token: the service asserts again.
 */
class JwtBearerGrant extends AbstractGrant
{
    use AuthenticatesClient;

    /**
     * {@inheritdoc}
     */
    public function getIdentifier()
    {
        return 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    }

    /**
     * {@inheritdoc}
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        \DateInterval $accessTokenTTL
    ) {
        $client    = $this->validateClient($request);
        $assertion = $this->getRequestParameter('assertion', $request);
        if (!is_string($assertion) || $assertion === '') {
            throw OAuthServerException::invalidRequest('assertion');
        }

        try {
            $claims = (new \Pramnos\Auth\OAuth2\JwtAssertion(\Pramnos\Framework\Factory::getDatabase()))
                ->verify($assertion, $this->application((string) $client->getIdentifier()));
        } catch (\UnexpectedValueException $e) {
            throw OAuthServerException::invalidGrant($e->getMessage());
        }

        $userId = $this->userNamed(is_string($claims->sub ?? null) ? $claims->sub : '');
        if ($userId === null) {
            throw OAuthServerException::invalidGrant('The assertion\'s sub is not an active user.');
        }

        $scopes      = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));
        $scopes      = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, (string) $userId);
        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, (string) $userId, $scopes);
        $responseType->setAccessToken($accessToken);

        return $responseType;
    }

    /**
     * The active user a `sub` names: by email, then by username.
     */
    protected function userNamed(string $sub): ?int
    {
        if ($sub === '') {
            return null;
        }
        $users = fn () => \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#users')
            ->where('active', 1)->where('userid', '>', 1);
        $id = $users()->where('email', $sub)->value('userid') ?? $users()->where('username', $sub)->value('userid');

        return $id !== null ? (int) $id : null;
    }
}
