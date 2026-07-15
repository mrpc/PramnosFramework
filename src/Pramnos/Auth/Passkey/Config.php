<?php

declare(strict_types=1);

namespace Pramnos\Auth\Passkey;

use Pramnos\Application\Settings;

/**
 * WebAuthn Relying Party configuration (value object).
 *
 * A passkey ceremony is bound to a Relying Party (RP) — the identity of the
 * server the credential belongs to. This VO carries the RP id/name, the list
 * of allowed origins the browser response may come from, and the ceremony
 * defaults (attestation conveyance, user verification, timeout).
 *
 * The values are read from application settings via {@see self::fromSettings()}
 * so a deployment can point passkeys at its own domain without touching code.
 * Everything here is our own type — no webauthn-lib class leaks across this
 * boundary (anti-corruption layer).
 *
 * Setting keys (all optional, sensible defaults applied):
 *   - passkey_rp_id          RP id, an effective domain (e.g. "example.com").
 *                            Defaults to the host of the configured site URL.
 *   - passkey_rp_name        Human-readable RP name shown by authenticators.
 *   - passkey_origins        Comma-separated allowed origins
 *                            (e.g. "https://example.com,https://www.example.com").
 *                            Defaults to the configured site URL origin.
 *   - passkey_timeout        Ceremony timeout in milliseconds (default 60000).
 *   - passkey_user_verification  "required" | "preferred" | "discouraged"
 *                                (default "preferred").
 */
final class Config
{
    /** Attestation conveyance is fixed to "none" (consumer passkeys, no attestation check). */
    public const ATTESTATION = 'none';

    /**
     * @param string   $rpId             RP id (effective domain).
     * @param string   $rpName           Human-readable RP name.
     * @param string[] $allowedOrigins   Origins the browser response may come from.
     * @param int      $timeout          Ceremony timeout in milliseconds.
     * @param string   $userVerification "required" | "preferred" | "discouraged".
     */
    public function __construct(
        public readonly string $rpId,
        public readonly string $rpName,
        public readonly array $allowedOrigins,
        public readonly int $timeout = 60000,
        public readonly string $userVerification = 'preferred'
    ) {
    }

    /**
     * Build a Config from application settings, falling back to the site URL.
     *
     * @param string|null $siteUrl Base site URL used to derive RP id / origin
     *                             when the dedicated settings are absent. When
     *                             null the "siteurl" setting is used.
     */
    public static function fromSettings(?string $siteUrl = null): self
    {
        $siteUrl = $siteUrl ?? (string) Settings::getSetting('siteurl');
        $host    = self::hostFromUrl($siteUrl);
        $origin  = self::originFromUrl($siteUrl);

        $rpId = (string) (Settings::getSetting('passkey_rp_id') ?: $host);
        $rpName = (string) (Settings::getSetting('passkey_rp_name')
            ?: (Settings::getSetting('sitename') ?: $rpId));

        $originsSetting = (string) Settings::getSetting('passkey_origins');
        if ($originsSetting !== '') {
            $origins = array_values(array_filter(array_map('trim', explode(',', $originsSetting))));
        } else {
            $origins = $origin !== '' ? [$origin] : [];
        }

        $timeout = (int) (Settings::getSetting('passkey_timeout') ?: 60000);

        $uv = (string) (Settings::getSetting('passkey_user_verification') ?: 'preferred');
        if (!in_array($uv, ['required', 'preferred', 'discouraged'], true)) {
            $uv = 'preferred';
        }

        return new self($rpId, $rpName, $origins, $timeout, $uv);
    }

    /** Extract the host portion of a URL, or '' when it has none. */
    private static function hostFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    /** Extract "scheme://host[:port]" from a URL, or '' when it cannot be parsed. */
    private static function originFromUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }
}
