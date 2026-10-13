<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

/**
 * Whether data may go to a provider for this call.
 *
 * Asked before anything is sent, and again before an answer moves to another provider for a
 * {@see DataRule}. A provider whose entry says `requires_consent` — one established where the
 * data has no adequacy decision behind it — is the usual case; the policy decides what counts as
 * agreement and who gave it.
 */
interface Consent
{
    /**
     * Why nothing may be sent to this provider for this call; or null to allow it.
     *
     * @param array<string, mixed> $context
     */
    public function refusal(array $context, string $provider): ?string;
}
