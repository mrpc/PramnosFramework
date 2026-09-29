<?php

declare(strict_types=1);

namespace Pramnos\Auth\OAuth2\Entities;

use DateTimeImmutable;
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

    /** The protected resource this token is for (RFC 8707), or null for none in particular. */
    private ?string $resource = null;

    public function setResource(?string $resource): void
    {
        $this->resource = $resource;
    }

    /**
     * The trait's JWT, with the resource added to `aud`.
     *
     * RFC 8707 §2: a token requested for a resource names that resource as its audience, so
     * the resource can refuse a token that was issued for somebody else. The client id stays
     * in `aud` as the trait puts it, so nothing that reads it today reads anything different.
     * Overrides the trait's private method — a class member wins over a trait's — and is
     * otherwise its body unchanged.
     *
     * @return \Lcobucci\JWT\Token
     */
    private function convertToJWT()
    {
        $this->initJwtConfiguration();

        $audience = [(string) $this->getClient()->getIdentifier()];
        if ($this->resource !== null) {
            $audience[] = $this->resource;
        }

        return $this->jwtConfiguration->builder()
            ->permittedFor(...$audience)
            ->identifiedBy($this->getIdentifier())
            ->issuedAt(new DateTimeImmutable())
            ->canOnlyBeUsedAfter(new DateTimeImmutable())
            ->expiresAt($this->getExpiryDateTime())
            ->relatedTo((string) $this->getUserIdentifier())
            ->withClaim('scopes', $this->getScopes())
            ->getToken($this->jwtConfiguration->signer(), $this->jwtConfiguration->signingKey());
    }
}
