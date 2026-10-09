<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Grants;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The device authorization grant's last step (RFC 8628 §3.4): the device polls for its token.
 *
 * `/oauth/deviceauthorization` starts the flow and `/device` is where the user approves or denies
 * it; this is where the device, polling with its `device_code`, is told to wait, to slow down,
 * that it was refused, that it took too long — or is given its tokens, once.
 */
class DeviceCodeGrant extends AbstractGrant
{
    use AuthenticatesClient;

    /** RFC 8628 §3.2: seconds the device waits between polls. */
    public const INTERVAL = 5;

    private const TABLE = 'authserver.oauth2_device_codes';

    public function __construct(RefreshTokenRepositoryInterface $refreshTokenRepository)
    {
        $this->setRefreshTokenRepository($refreshTokenRepository);
    }

    /**
     * {@inheritdoc}
     */
    public function getIdentifier()
    {
        return 'urn:ietf:params:oauth:grant-type:device_code';
    }

    /** The bare `device_code` too, which older clients of this server send. */
    protected function grantTypeNames(): array
    {
        return [$this->getIdentifier(), 'device_code'];
    }

    /**
     * {@inheritdoc}
     */
    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        \DateInterval $accessTokenTTL
    ) {
        $client     = $this->validateClient($request);
        $deviceCode = $this->getRequestParameter('device_code', $request);
        if (!is_string($deviceCode) || $deviceCode === '') {
            throw OAuthServerException::invalidRequest('device_code');
        }

        $db  = \Pramnos\Framework\Factory::getDatabase();
        $row = $db->queryBuilder()->table(self::TABLE)
            ->where('device_code', $deviceCode)->where('client_id', $client->getIdentifier())->first();
        if (!$row || $row->numRows === 0) {
            throw OAuthServerException::invalidGrant('The device_code is not one this client was given.');
        }
        $device = $row->fields;
        $now    = time();

        if ((int) $device['expires_at'] <= $now) {
            throw new OAuthServerException('The device_code has expired; start again.', 11, 'expired_token', 400);
        }

        switch ((string) $device['status']) {
            case 'denied':
                throw new OAuthServerException('The user denied the request.', 9, 'access_denied', 400);
            case 'pending':
                $this->poll($db, (int) $device['id'], (int) ($device['last_polled_at'] ?? 0), $now);
                // poll() throws: authorization_pending or slow_down
            case 'authorized':
                break;
            default:
                throw OAuthServerException::invalidGrant('The device_code has already been used.');
        }

        // Claimed by one request: the update names the status it expects, and the claim is read
        // back, so two polls racing past `authorized` cannot both receive tokens.
        $claim = 'redeemed:' . bin2hex(random_bytes(5));
        $db->queryBuilder()->table(self::TABLE)
            ->where('id', (int) $device['id'])->where('status', 'authorized')
            ->update(['status' => $claim]);
        $mine = $db->queryBuilder()->table(self::TABLE)->where('id', (int) $device['id'])->value('status') === $claim;
        if (!$mine) {
            throw OAuthServerException::invalidGrant('The device_code has already been used.');
        }

        $scopes      = $this->scopesFrom((string) ($device['scope'] ?? ''), $client);
        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, (string) $device['user_id'], $scopes);
        $responseType->setAccessToken($accessToken);
        $refreshToken = $this->issueRefreshToken($accessToken);
        if ($refreshToken !== null) {
            $responseType->setRefreshToken($refreshToken);
        }

        return $responseType;
    }

    /**
     * A poll before the user has answered: wait, or slow down when it came too soon.
     *
     * @throws OAuthServerException always
     */
    private function poll(\Pramnos\Database\Database $db, int $id, int $lastPolled, int $now): void
    {
        $db->queryBuilder()->table(self::TABLE)->where('id', $id)->update(['last_polled_at' => $now]);
        if ($lastPolled > 0 && $now - $lastPolled < self::INTERVAL) {
            throw new OAuthServerException('Polling too often; wait ' . self::INTERVAL . ' seconds between requests.', 12, 'slow_down', 400);
        }

        throw new OAuthServerException('The user has not answered yet.', 13, 'authorization_pending', 400);
    }
}
