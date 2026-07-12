<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * OAuth2 Access Token Entity
 *
 * Carries the in-memory representation of an access token during the
 * grant flow. Persisted to `usertokens` by AccessTokenRepository.
 * The AccessTokenTrait provides JWT generation (RS256) via lcobucci/jwt.
 *
 */
class AccessTokenEntity implements AccessTokenEntityInterface
{
    use AccessTokenTrait, TokenEntityTrait, EntityTrait;
}
