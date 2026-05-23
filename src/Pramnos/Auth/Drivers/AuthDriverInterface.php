<?php

declare(strict_types=1);

namespace Pramnos\Auth\Drivers;

/**
 * Contract for pluggable authentication drivers.
 *
 * Implement this interface to supply a custom authentication backend (LDAP,
 * OAuth2 introspection, API token, etc.).  Register drivers with Auth via
 * Auth::setDriver() or Auth::addDriver().
 *
 * The framework ships DatabaseAuthDriver as the default implementation.
 *
 * @package PramnosFramework
 */
interface AuthDriverInterface
{
    /**
     * Verify credentials and return an AuthResult.
     *
     * Implementations must NOT throw on bad credentials — return
     * AuthResult::failure() instead.  Exceptions are reserved for
     * infrastructure failures (DB down, network error).
     *
     * @param string $username         Username or email address to look up
     * @param string $password         Plain-text password (or encrypted hash when $encryptedPassword=true)
     * @param bool   $encryptedPassword When true, $password is already a bcrypt hash and is compared directly
     * @return AuthResult
     */
    public function verify(
        string $username,
        string $password,
        bool   $encryptedPassword = false
    ): AuthResult;
}
