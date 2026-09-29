<?php

declare(strict_types=1);

namespace Pramnos\Auth;

use Pramnos\Application\Settings;

/**
 * Who may register themselves, and whether their address has to be confirmed first.
 *
 * - `auth_allow_registration` — open to anybody (unchanged).
 * - `auth_registration_domains` — a comma-separated list, e.g. `example.com, example.org`. When
 *   set, open registration accepts addresses at those domains only.
 * - `auth_registration_verify_email` — the new account cannot sign in until it follows a link
 *   mailed to its address. **Always on when a domain list is set**: without it anybody could type
 *   `someone@example.com` and hold an account that relying parties believe belongs to the domain.
 *
 * An invitation is a separate way in ({@see Invitations}): it opens registration for one address
 * while the rest is closed, and the invited address needs no confirmation — the link reaching it
 * is the confirmation.
 */
final class RegistrationPolicy
{
    public const DOMAINS_SETTING = 'auth_registration_domains';

    public const VERIFY_SETTING = 'auth_registration_verify_email';

    /** Is self-service registration open to anybody (within the domain list, if there is one)? */
    public static function isOpen(): bool
    {
        return self::flag('auth_allow_registration');
    }

    /**
     * The domains open registration is limited to, lowercased; empty for any.
     *
     * @return list<string>
     */
    public static function domains(): array
    {
        $raw = (string) Settings::getSetting(self::DOMAINS_SETTING, '');
        $domains = [];
        foreach (preg_split('/[\s,;]+/', strtolower($raw)) ?: [] as $domain) {
            $domain = ltrim(trim($domain), '@');
            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /** Does this address fall within the domain list? True when there is no list. */
    public static function allowsEmail(string $email): bool
    {
        $domains = self::domains();
        if ($domains === []) {
            return true;
        }

        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        // The whole domain, not a suffix: `evil-example.com` is not `example.com`.
        return in_array(strtolower(substr($email, $at + 1)), $domains, true);
    }

    /** Must a self-registered account confirm its address before it signs in? */
    public static function requiresEmailVerification(): bool
    {
        return self::domains() !== [] || self::flag(self::VERIFY_SETTING);
    }

    private static function flag(string $setting): bool
    {
        return in_array(strtolower((string) Settings::getSetting($setting, '')), ['1', 'true', 'yes', 'on'], true);
    }
}
