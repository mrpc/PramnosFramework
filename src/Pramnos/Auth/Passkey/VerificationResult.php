<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

/**
 * Outcome of a successful passkey authentication ceremony (value object).
 *
 * {@see PasskeyServiceInterface::finishAuthentication()} throws on any failure,
 * so obtaining a VerificationResult means the assertion was valid, the signature
 * check passed and the signature counter moved forward (no replay/clone).
 *
 * It resolves the authenticated {@see $userId} — which for a usernameless
 * ceremony is only known here, from the credential's user handle — and returns
 * the {@see $credential} with its {@see PasskeyCredential::$signCount} already
 * advanced to the value that was persisted.
 */
final class VerificationResult
{
    /**
     * @param int              $userId     Authenticated user id.
     * @param PasskeyCredential $credential The credential used, with the updated
     *                                      sign count.
     * @param int              $signCount  New signature counter (persisted).
     */
    public function __construct(
        public readonly int $userId,
        public readonly PasskeyCredential $credential,
        public readonly int $signCount
    ) {
    }
}
