<?php

declare(strict_types=1);

namespace Pramnos\Push;

/**
 * The VAPID key pair, and the one fact a browser needs before it can subscribe.
 *
 * VAPID (RFC 8292) is how a push service knows which application a notification came from: the
 * server signs a JWT with a P-256 private key, and the browser was given the matching public key
 * when it subscribed. There is no registration, no account and no shared secret with the
 * provider — the key pair *is* the identity.
 *
 * Which makes one property worth stating before anybody generates a second pair: **rotating the
 * key invalidates every existing subscription**. A browser subscribed with the old public key
 * cannot be pushed to with the new private one, and it will not find out until somebody notices
 * that notifications stopped. Generate once, back the pair up, and treat losing it as losing
 * every subscriber.
 *
 * @author  Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license MIT
 */
class Vapid
{
    /** Where the pair lives, beside the application's other keys. */
    public const DIRECTORY = 'app/keys';

    public const PRIVATE_FILE = 'vapid_private.key';

    public const PUBLIC_FILE  = 'vapid_public.key';

    /**
     * Generate a P-256 key pair, base64url-encoded the way the Push API expects.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generate(): array
    {
        $key = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new \RuntimeException('Could not generate a P-256 key pair: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($key);

        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'], $details['ec']['d'])) {
            throw new \RuntimeException('The generated key is not an EC key with usable parameters.');
        }

        /*
         * The uncompressed point format: `0x04 || X || Y`, each coordinate padded to 32 bytes.
         *
         * The padding is not decoration. `openssl_pkey_get_details()` returns the coordinates as
         * big-endian integers with leading zeroes stripped, so roughly one key in 256 comes back
         * 31 bytes long — and a 64-byte public key that is occasionally 63 bytes is a key that
         * works until one day it does not, on a machine nobody can reproduce.
         */
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $d = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);

        return [
            'publicKey'  => self::encode("\x04" . $x . $y),
            'privateKey' => self::encode($d),
        ];
    }

    /**
     * The stored pair, or null when this installation has none.
     *
     * @return ?array{publicKey: string, privateKey: string, subject: string}
     */
    public static function load(?string $root = null): ?array
    {
        $root    = rtrim($root ?? (defined('ROOT') ? (string) ROOT : (string) getcwd()), '/');
        $private = $root . '/' . self::DIRECTORY . '/' . self::PRIVATE_FILE;
        $public  = $root . '/' . self::DIRECTORY . '/' . self::PUBLIC_FILE;

        if (!is_readable($private) || !is_readable($public)) {
            return null;
        }

        $privateKey = trim((string) file_get_contents($private));
        $publicKey  = trim((string) file_get_contents($public));

        if ($privateKey === '' || $publicKey === '') {
            return null;
        }

        return [
            'publicKey'  => $publicKey,
            'privateKey' => $privateKey,
            'subject'    => self::subject(),
        ];
    }

    /**
     * The `sub` claim: who to contact about this application's notifications.
     *
     * Required by RFC 8292, and not decoration — it is the address a push service uses when
     * something is wrong with what you are sending, before it starts refusing. A `mailto:` or an
     * `https:` URL; anything else is rejected by some services and silently accepted by others,
     * which is the worse outcome.
     */
    public static function subject(): string
    {
        $configured = \Pramnos\Application\Application::currentInstance()
            ?->applicationInfo['push']['subject'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $admin = (string) \Pramnos\Application\Settings::getSetting('admin_mail');

        if ($admin !== '' && filter_var($admin, FILTER_VALIDATE_EMAIL) !== false) {
            return 'mailto:' . $admin;
        }

        $site = (string) \Pramnos\Application\Settings::getSetting('site_url');

        return $site !== '' ? rtrim($site, '/') : '';
    }

    /**
     * Is this installation able to send a push at all?
     */
    public static function configured(?string $root = null): bool
    {
        return self::load($root) !== null;
    }

    /** base64url, which is what the Push API speaks — `+/=` are not valid there. */
    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /** The other direction, tolerant of the padding a client may or may not have stripped. */
    public static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}
