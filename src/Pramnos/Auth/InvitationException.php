<?php

declare(strict_types=1);

namespace Pramnos\Auth;

/**
 * An invitation that could not be made, with a stable reason a screen can word its own way.
 *
 * `reason` is one of `invalid_email`, `account_exists`, `unknown_role`,
 * `role_outside_organization`, `not_a_member`.
 */
class InvitationException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
