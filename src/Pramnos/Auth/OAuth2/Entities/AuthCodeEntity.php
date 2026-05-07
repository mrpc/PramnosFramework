<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Entities;

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * OAuth2 Authorization Code Entity
 *
 * Short-lived token (10 minutes) issued during the Authorization Code grant.
 * Carries the authorized user, client, scopes, and redirect URI.
 * Persisted to `usertokens` (tokentype='auth_code') by AuthCodeRepository.
 *
 * @package PramnosFramework
 */
class AuthCodeEntity implements AuthCodeEntityInterface
{
    use EntityTrait, TokenEntityTrait, AuthCodeTrait;
}
