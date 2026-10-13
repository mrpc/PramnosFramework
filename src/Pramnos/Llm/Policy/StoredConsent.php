<?php

declare(strict_types=1);

namespace Pramnos\Llm\Policy;

use Pramnos\Llm\CallLog;
use Pramnos\Llm\Providers;

/**
 * Agreement to send data to a provider, kept in `ai_consents`.
 *
 * Only a provider whose entry says `requires_consent` — one established where the data has no
 * adequacy decision behind it — is checked; any other is allowed. For the rest:
 *
 * - a person agrees for themselves: a row of (organisation, person, provider), with no
 *   organisation in an application that has none;
 * - a call with a person needs that person's agreement;
 * - a call with no person — a background job — needs anybody in the organisation to have agreed;
 * - a call with neither is refused.
 *
 * An application whose calls outside an organisation carry no organisation's data — an
 * administrator testing a key — says so, and those are allowed:
 *
 * ```php
 * 'llm' => ['consent' => App\Llm\Consent::class,          // or this class itself
 *           'consent_options' => ['allow_without_organization' => true]],
 * ```
 *
 * Withdrawing deletes the person's row, and their next call is refused.
 */
class StoredConsent implements Consent
{
    /** @param array<string, mixed> $context */
    public function refusal(array $context, string $provider): ?string
    {
        if (!self::required($provider)) {
            return null;
        }

        $org    = $context['organization_id'] ?? null;
        $org    = $org === null || (int) $org <= 0 ? null : (int) $org;
        $userId = (int) ($context['userid'] ?? 0);

        if ($org === null && !empty(self::options()['allow_without_organization'])) {
            return null;
        }

        $agreed = $userId > 0
            ? $this->has($org, $userId, $provider)
            : $org !== null && $this->organizationHas($org, $provider);

        if ($agreed) {
            return null;
        }

        return 'Nothing was sent: the AI features use ' . Providers::config($provider)['label'] . ', and '
            . ($userId > 0 ? 'you have not' : ($org !== null ? 'nobody in this organisation has' : 'nobody has')) . ' agreed to that yet.';
    }

    /** Whether this provider asks for agreement at all. */
    public static function required(string $provider): bool
    {
        return !empty(Providers::config($provider)['requires_consent']);
    }

    public function has(?int $organizationId, int $userId, string $provider): bool
    {
        return $this->rows($organizationId, $provider)->where('userid', $userId)->count() > 0;
    }

    /** Whether anybody in the organisation has agreed — what a background job needs. */
    public function organizationHas(int $organizationId, string $provider): bool
    {
        return $this->rows($organizationId, $provider)->count() > 0;
    }

    /** Agree. Agreeing twice is agreeing once. */
    public function give(?int $organizationId, int $userId, string $provider): void
    {
        if ($this->has($organizationId, $userId, $provider)) {
            return;
        }

        $stamp = CallLog::instant(time());
        \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('ai_consents')->insert([
            'organization_id' => $organizationId,
            'userid'          => $userId,
            'provider'        => $provider,
            'created_at'      => $stamp,
            'updated_at'      => $stamp,
        ]);
    }

    /** Withdraw this person's agreement. */
    public function withdraw(?int $organizationId, int $userId, string $provider): void
    {
        $this->rows($organizationId, $provider)->where('userid', $userId)->delete();
    }

    /** @return array<string, mixed> */
    private static function options(): array
    {
        $options = \Pramnos\Llm\Llm::config('consent_options', []);

        return is_array($options) ? $options : [];
    }

    private function rows(?int $organizationId, string $provider): \Pramnos\Database\QueryBuilder
    {
        $query = \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('ai_consents')->where('provider', $provider);

        return $organizationId === null ? $query->whereNull('organization_id') : $query->where('organization_id', $organizationId);
    }
}
