<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

use RuntimeException;

/**
 * Raised when a passkey ceremony cannot be completed.
 *
 * This is our own exception type: the adapter catches whatever the underlying
 * WebAuthn library throws (verification failure, malformed response, unknown
 * credential, counter regression) and re-throws it as a PasskeyException, so
 * callers of {@see PasskeyServiceInterface} depend only on framework types.
 *
 * The message is intentionally coarse — a verification failure must not leak
 * which specific check failed to a remote caller.
 */
class PasskeyException extends RuntimeException
{
}
