<?php

declare(strict_types=1);

namespace Pramnos\Email;

/**
 * A DKIM key pair, and the DNS record that publishes its public half.
 *
 * ```php
 * $key = DkimKey::generate('customer.example', 'pramnos1');
 * // Store $key->privateKeyPem where the application keeps secrets.
 * // Ask the domain's owner to publish $key->recordName as TXT, with $key->recordValue.
 * $email->signWith('customer.example', 'pramnos1', $key->privateKeyPem);
 * ```
 *
 * Only for mail the application signs itself, with {@see Email::signWith()}. A relay that
 * signs for the installation's own domain has its own key and record already.
 *
 * {@see DnsAuthentication::inspect()} with the same selector reports whether the record has
 * been published.
 */
final class DkimKey
{
    /**
     * @param string $domain        The signing domain
     * @param string $selector      The selector the record is published under
     * @param string $privateKeyPem The private key, PEM-encoded: a secret
     * @param string $publicKey     The public key, base64 DER, as the record's `p=` carries it
     */
    private function __construct(
        public readonly string $domain,
        public readonly string $selector,
        public readonly string $privateKeyPem,
        public readonly string $publicKey,
    ) {
    }

    /**
     * Make an RSA key pair for a domain and selector.
     *
     * @param string $domain   The domain of the From address the key will sign for
     * @param string $selector A name for this key, unique within the domain
     * @param int    $bits     2048 by default: what every major receiver accepts. 1024 is weak,
     *                         and 4096 does not fit some DNS providers' TXT fields
     * @return self
     * @throws \InvalidArgumentException for an empty domain or selector, or fewer than 1024 bits
     * @throws \RuntimeException when OpenSSL cannot make the key
     */
    public static function generate(string $domain, string $selector, int $bits = 2048): self
    {
        $domain   = strtolower(trim($domain));
        $selector = trim($selector);
        if ($domain === '' || $selector === '' || $bits < 1024) {
            throw new \InvalidArgumentException('A DKIM key needs a domain, a selector and at least 1024 bits.');
        }

        $key = openssl_pkey_new(['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || !openssl_pkey_export($key, $privateKeyPem)) {
            throw new \RuntimeException('OpenSSL could not make a DKIM key: ' . (string) openssl_error_string());
        }

        $publicPem = (string) (openssl_pkey_get_details($key)['key'] ?? '');
        $publicKey = preg_replace('/-----[^-]+-----|\s+/', '', $publicPem) ?? '';

        return new self($domain, $selector, (string) $privateKeyPem, $publicKey);
    }

    /** The host the TXT record goes on: `<selector>._domainkey.<domain>`. */
    public function recordName(): string
    {
        return $this->selector . '._domainkey.' . $this->domain;
    }

    /**
     * The TXT record's value.
     *
     * Longer than 255 characters at 2048 bits. Most DNS screens split it into strings on their
     * own; one that does not takes it as several quoted strings, which DNS joins back together.
     */
    public function recordValue(): string
    {
        return 'v=DKIM1; k=rsa; p=' . $this->publicKey;
    }
}
