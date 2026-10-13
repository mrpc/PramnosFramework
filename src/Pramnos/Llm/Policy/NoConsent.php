<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

/** No consent step: data may go to any configured provider. */
final class NoConsent implements Consent
{
    /** @param array<string, mixed> $context */
    public function refusal(array $context, string $provider): ?string
    {
        return null;
    }
}
