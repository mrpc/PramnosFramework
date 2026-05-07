<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Repositories;

use Pramnos\Auth\OAuth2\Entities\RefreshTokenEntity;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

/**
 * OAuth2 Refresh Token Repository
 *
 * Persists refresh tokens to `usertokens` (tokentype='refresh_token').
 * The refresh token is linked to its parent access token via the
 * `usertokens.parentToken` column so that revocation can cascade.
 *
 * @package PramnosFramework
 */
class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    private \Pramnos\Application\Controller $controller;

    public function __construct(\Pramnos\Application\Controller $controller)
    {
        $this->controller = $controller;
    }

    /**
     * Return a new empty RefreshTokenEntity (not yet persisted).
     */
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    /**
     * Persist a newly issued refresh token.
     *
     * Looks up the parent access token to copy the userid and applicationid,
     * then stores the refresh token in `usertokens`.
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $now = time();

        // Resolve parent access token row
        $parentAccessTokenId = $this->resolveAccessTokenId($refreshTokenEntity->getAccessToken()->getIdentifier());
        $parentRow           = $this->loadAccessTokenRow($parentAccessTokenId);

        $expires = $refreshTokenEntity->getExpiryDateTime()
            ? $refreshTokenEntity->getExpiryDateTime()->getTimestamp()
            : 0;

        $sql = $db->prepareQuery(
            'INSERT INTO `#PREFIX#usertokens`'
            . ' (userid, tokentype, token, created, status, applicationid, parentToken, expires, deviceinfo)'
            . ' VALUES (%d, %s, %s, %d, 1, %d, %d, %d, %s)',
            (int) ($parentRow['userid'] ?? 0),
            'refresh_token',
            $refreshTokenEntity->getIdentifier(),
            $now,
            (int) ($parentRow['applicationid'] ?? 0),
            $parentAccessTokenId,
            $expires,
            ''
        );
        $db->query($sql);
    }

    /**
     * Revoke a refresh token by setting status=0.
     */
    public function revokeRefreshToken(string $tokenId): void
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            "UPDATE `#PREFIX#usertokens` SET `status` = 0 WHERE `token` = %s AND `tokentype` = 'refresh_token'",
            $tokenId
        );
        $db->query($sql);
    }

    /**
     * Return true when the refresh token does not exist or has been revoked.
     */
    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            "SELECT status FROM `#PREFIX#usertokens` WHERE `token` = %s AND `tokentype` = 'refresh_token'",
            $tokenId
        );
        $result = $db->query($sql);

        if (!$result || $result->numRows == 0) {
            return true;
        }
        return (int)$result->fields['status'] !== 1;
    }

    private function resolveAccessTokenId(string $identifier): int
    {
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            "SELECT tokenid FROM `#PREFIX#usertokens` WHERE `token` = %s AND `tokentype` = 'access_token'",
            $identifier
        );
        $result = $db->query($sql);
        return ($result && $result->numRows > 0) ? (int)$result->fields['tokenid'] : 0;
    }

    private function loadAccessTokenRow(int $tokenId): array
    {
        if ($tokenId === 0) {
            return [];
        }
        $db  = \Pramnos\Framework\Factory::getDatabase();
        $sql = $db->prepareQuery(
            'SELECT userid, applicationid FROM `#PREFIX#usertokens` WHERE tokenid = %d',
            $tokenId
        );
        $result = $db->query($sql);
        return ($result && $result->numRows > 0) ? $result->fields : [];
    }
}
