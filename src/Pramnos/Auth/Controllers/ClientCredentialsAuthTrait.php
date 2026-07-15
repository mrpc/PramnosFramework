<?php

declare(strict_types=1);

namespace Pramnos\Auth\Controllers;

use Pramnos\Auth\Application;

/**
 * Shared client-credentials authentication for internal auth-server endpoints.
 *
 * Both the capabilities-push endpoint and the internal permissions endpoint
 * authenticate a calling resource server with its OAuth2 client credentials
 * (HTTP Basic or request body). The credential source and the validation step
 * are protected so tests can override them without a live request or database.
 */
trait ClientCredentialsAuthTrait
{
    /**
     * Extract client credentials from HTTP Basic auth or the request body.
     *
     * @return array{client_id:string,client_secret:string}|null
     */
    protected function extractClientCredentials(): ?array
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/^Basic\s+(.+)$/i', $authHeader, $m)) {
            $decoded = base64_decode($m[1], true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);
                return ['client_id' => $id, 'client_secret' => $secret];
            }
        }

        $id     = (string) ($_POST['client_id']     ?? '');
        $secret = (string) ($_POST['client_secret'] ?? '');
        if ($id !== '' && $secret !== '') {
            return ['client_id' => $id, 'client_secret' => $secret];
        }

        return null;
    }

    /**
     * Validate credentials and return the application id (appid), or null when
     * the credentials are invalid / the client is inactive.
     */
    protected function authenticateClient(string $clientId, string $clientSecret): ?int
    {
        $app    = new Application($this);
        $loaded = $app->loadByApiKey($clientId);
        if ($loaded === false) {
            return null;
        }
        if (!$app->validateCredentials($clientId, $clientSecret)) {
            return null;
        }
        return $app->appid > 0 ? $app->appid : null;
    }
}
