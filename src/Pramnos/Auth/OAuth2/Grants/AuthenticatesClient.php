<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Grants;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Client authentication for this framework's own grants.
 *
 * League authenticates with a secret only. The token endpoint also accepts a client assertion
 * (`private_key_jwt`, RFC 7523 §2.2), so a client that authenticates that way for
 * `client_credentials` can do so for these grants as well.
 */
trait AuthenticatesClient
{
    /**
     * The authenticated client: by its secret, or by a client assertion.
     *
     * @throws OAuthServerException invalid_client
     */
    protected function validateClient(ServerRequestInterface $request)
    {
        $assertion = $this->getRequestParameter('client_assertion', $request);
        $type      = $this->getRequestParameter('client_assertion_type', $request);
        if (is_string($assertion) && $type === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer') {
            [$clientId] = $this->getClientCredentials($request);
            $client     = $this->getClientEntityOrFail($clientId, $request);
            $app        = $this->application((string) $clientId);
            try {
                $claims = (new \Pramnos\Auth\OAuth2\JwtAssertion(\Pramnos\Framework\Factory::getDatabase()))->verify($assertion, $app);
                if (($claims->sub ?? null) !== $clientId) {
                    throw new \UnexpectedValueException('The assertion\'s sub must be the client_id.');
                }
            } catch (\UnexpectedValueException $e) {
                // A 401 needs the request: League reads its Authorization header for the challenge.
                $refused = new OAuthServerException('Client authentication failed: ' . $e->getMessage(), 4, 'invalid_client', 401);
                $refused->setServerRequest($request);
                throw $refused;
            }
        } else {
            $client = parent::validateClient($request);
        }

        // The grant policy is checked once, for every grant, before this runs — see
        // Oauth::token() and ClientPolicy.
        return $client;
    }

    /** The application behind a client id, active. */
    protected function application(string $clientId): \Pramnos\Auth\Application
    {
        $app = new \Pramnos\Auth\Application(new \Pramnos\Application\Controller());
        if ($app->loadByApiKey($clientId) === false) {
            throw new OAuthServerException('Client authentication failed', 4, 'invalid_client', 400);
        }

        return $app;
    }

    /**
     * Requests this grant answers: its identifier, and any older names it keeps for clients
     * written against them.
     */
    public function canRespondToAccessTokenRequest(ServerRequestInterface $request)
    {
        $grantType = ((array) $request->getParsedBody())['grant_type'] ?? null;

        return in_array($grantType, $this->grantTypeNames(), true);
    }

    /** @return list<string> */
    protected function grantTypeNames(): array
    {
        return [$this->getIdentifier()];
    }

    /**
     * Scope entities for a stored scope string, as the client is allowed them.
     *
     * @return list<\League\OAuth2\Server\Entities\ScopeEntityInterface>
     */
    protected function scopesFrom(string $stored, ClientEntityInterface $client): array
    {
        $scopes = [];
        foreach (\Pramnos\User\Token::parseScopes($stored) as $name) {
            $scope = $this->scopeRepository->getScopeEntityByIdentifier($name);
            if ($scope !== null) {
                $scopes[] = $scope;
            }
        }

        return $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client);
    }
}
