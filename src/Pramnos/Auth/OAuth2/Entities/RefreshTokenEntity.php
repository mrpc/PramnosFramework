<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;

/**
 * OAuth2 Refresh Token Entity
 *
 * Long-lived token (1 month) that allows obtaining new access tokens without
 * re-authenticating the user. Linked to its parent access token via the
 * `usertokens.parentToken` column.
 *
 * @package PramnosFramework
 */
class RefreshTokenEntity implements RefreshTokenEntityInterface
{
    use RefreshTokenTrait, EntityTrait;
}
