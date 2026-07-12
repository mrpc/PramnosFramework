<?php

declare(strict_types=1);

namespace Pramnos\Auth\Drivers;

/**
 * Immutable result object returned by AuthDriverInterface::verify().
 *
 * Named constructors (success / failure) ensure that callers always build a
 * fully-populated result without relying on positional array indices.
 *
 */
readonly class AuthResult
{
    private function __construct(
        public bool   $success,
        public int    $statusCode,
        public string $message,
        public string $username,
        public int    $uid,
        public string $email,
        public string $auth,
    ) {}

    /**
     * Build a successful authentication result.
     *
     * @param string $username Authenticated username
     * @param int    $uid      User identifier
     * @param string $email    User email address
     * @param string $auth     Stored password hash (used for cookie-based re-auth)
     * @param int    $statusCode Active-status code from the users table (default 1)
     */
    public static function success(
        string $username,
        int    $uid,
        string $email,
        string $auth,
        int    $statusCode = 1
    ): self {
        return new self(
            success:    true,
            statusCode: $statusCode,
            message:    '',
            username:   $username,
            uid:        $uid,
            email:      $email,
            auth:       $auth,
        );
    }

    /**
     * Build a failed authentication result.
     *
     * @param string $message   Human-readable failure reason
     * @param int    $statusCode Numeric status code (0 = inactive, 400 = wrong password, etc.)
     */
    public static function failure(string $message, int $statusCode = 0): self
    {
        return new self(
            success:    false,
            statusCode: $statusCode,
            message:    $message,
            username:   '',
            uid:        0,
            email:      '',
            auth:       '',
        );
    }

    /**
     * Convert to the legacy array format expected by Addon\User\User::onLogin()
     * and stored in Auth::$lastResponse.
     *
     * The shape must match the array returned by Addon\Auth\UserDatabase::onAuth()
     * so that existing code reading $auth->lastResponse continues to work.
     *
     * @param bool $remember Whether the user requested a persistent login cookie
     * @return array{status:bool, statusCode:int, message:string, username:string, uid:int|string, auth:string, email:string, remember:bool}
     */
    public function toArray(bool $remember = true): array
    {
        return [
            'status'     => $this->success,
            'statusCode' => $this->statusCode,
            'message'    => $this->message,
            'username'   => $this->username,
            'uid'        => $this->uid > 0 ? $this->uid : '',
            'auth'       => $this->auth,
            'email'      => $this->email,
            'remember'   => $remember,
        ];
    }
}
