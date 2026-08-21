<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Encryption;

/**
 * End-to-end encryption for `private-encrypted-` channels.
 *
 * A private channel is private because the *server* checks who may subscribe. An
 * encrypted channel adds the thing that check cannot give you: the payload is
 * unreadable to anything between the publisher and the subscriber — including the
 * WebSocket daemon, which relays the ciphertext without being able to open it, and
 * including a managed Pusher or Reverb server you do not operate.
 *
 * That is the whole reason to reach for it: it moves the trust boundary off the
 * relay. If you run the daemon yourself and trust it, a `private-` channel is
 * already enough.
 *
 * ## The scheme is Pusher's, deliberately
 *
 * `pusher-js` decrypts `private-encrypted-` channels natively, so matching the wire
 * format means no client-side code at all:
 *
 *   - a per-channel key: `sha256(channel_name || master_key)`, 32 bytes;
 *   - the payload encrypted with NaCl secretbox (XSalsa20-Poly1305);
 *   - sent as `{"nonce": base64, "ciphertext": base64}`.
 *
 * The auth endpoint hands the subscriber the same per-channel key as
 * `shared_secret`, which is why the key derivation must be a pure function of the
 * channel name — the two sides derive it independently and never exchange it over
 * the socket.
 *
 * ## What this does not protect
 *
 * The channel *name* travels in the clear, and so does the event name. Only the
 * payload is encrypted. A channel called `private-encrypted-patient.4417` still
 * tells a relay operator that patient 4417 exists and that something happened to
 * them — put nothing in a channel name that the payload is being encrypted to hide.
 */
final class ChannelEncrypter
{
    /** Channels with this prefix are encrypted. */
    public const PREFIX = 'private-encrypted-';

    /**
     * @param string $masterKey Raw 32-byte key. Generate with
     *                          `base64_encode(random_bytes(32))` and store it as
     *                          configuration; changing it makes every channel's key
     *                          change, so in-flight messages become undecryptable.
     * @throws \RuntimeException When libsodium is unavailable, or the key is the
     *         wrong length. Both are refused at construction rather than at the
     *         first publish: a realtime feature that fails on its first real event
     *         fails in front of users.
     */
    public function __construct(private readonly string $masterKey)
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            // Not exercised by the suite: sodium is bundled with PHP 8 and loaded in
            // every environment this runs in, so there is no way to take this branch
            // without unloading an extension mid-process.
            // @codeCoverageIgnoreStart
            throw new \RuntimeException(
                'Encrypted channels need the sodium extension, which is not loaded.'
            );
            // @codeCoverageIgnoreEnd
        }

        if (strlen($this->masterKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(
                'The broadcasting encryption master key must be exactly '
                . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' raw bytes; got '
                . strlen($this->masterKey) . '. Decode it from base64 before passing it.'
            );
        }
    }

    /**
     * Build one from a base64-encoded key, which is how it will be configured.
     *
     * @throws \RuntimeException When the value is not valid base64.
     */
    public static function fromBase64(string $encoded): self
    {
        $raw = base64_decode($encoded, true);

        if ($raw === false) {
            throw new \RuntimeException(
                'The broadcasting encryption key is not valid base64.'
            );
        }

        return new self($raw);
    }

    /** True when $channel is an encrypted one. */
    public static function isEncrypted(string $channel): bool
    {
        return str_starts_with($channel, self::PREFIX);
    }

    /**
     * The per-channel key, raw.
     *
     * A pure function of the channel name and the master key, because the publisher
     * and the subscriber derive it independently — it is never sent over the socket.
     */
    public function sharedSecret(string $channel): string
    {
        return hash('sha256', $channel . $this->masterKey, true);
    }

    /**
     * The per-channel key as the auth endpoint sends it.
     */
    public function sharedSecretForClient(string $channel): string
    {
        return base64_encode($this->sharedSecret($channel));
    }

    /**
     * Encrypt a payload for $channel.
     *
     * @param array<string,mixed> $payload
     * @return array{nonce:string, ciphertext:string} The envelope to publish.
     */
    public function encrypt(string $channel, array $payload): array
    {
        // A fresh nonce per message. Reusing one under the same key breaks the
        // construction outright — it is not a weakening, it is a break — and the
        // key here is fixed per channel, so the nonce is the only thing that varies.
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $ciphertext = sodium_crypto_secretbox(
            (string) json_encode($payload),
            $nonce,
            $this->sharedSecret($channel)
        );

        return [
            'nonce'      => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /**
     * Decrypt an envelope from $channel.
     *
     * @param array<string,mixed> $envelope
     * @return array<string,mixed>|null Null when the envelope is malformed or does
     *         not authenticate. One answer for both, because a caller can do nothing
     *         different with the difference and the distinction is exactly what a
     *         padding-oracle style probe wants.
     */
    public function decrypt(string $channel, array $envelope): ?array
    {
        $nonce      = base64_decode((string) ($envelope['nonce'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['ciphertext'] ?? ''), true);

        if (
            $nonce === false
            || $ciphertext === false
            || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
        ) {
            return null;
        }

        try {
            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->sharedSecret($channel));
        } catch (\Throwable) {
            // sodium_crypto_secretbox_open() throws rather than returning false for
            // a structurally impossible input. The guards above make that
            // unreachable from here — the key is always a 32-byte hash and the nonce
            // length is checked — but a throw escaping a decrypt would surface as a
            // 500 on a message that should simply have been dropped.
            // @codeCoverageIgnoreStart
            return null;
            // @codeCoverageIgnoreEnd
        }

        if ($plaintext === false) {
            return null;
        }

        $decoded = json_decode($plaintext, true);

        return is_array($decoded) ? $decoded : null;
    }
}
