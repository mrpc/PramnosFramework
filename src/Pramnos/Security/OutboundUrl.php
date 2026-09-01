<?php

declare(strict_types=1);

namespace Pramnos\Security;

/**
 * Is this URL safe for the *server* to fetch?
 *
 * A URL a visitor typed is not a URL the server may request. The server sits inside a network the
 * visitor cannot reach: a cloud provider's metadata endpoint on 169.254.169.254, an unauthenticated
 * admin panel on loopback, a database on a private address, another service on the same subnet that
 * trusts anything arriving from inside. Handing that URL to `file_get_contents()` turns the
 * application into a proxy into its own network, and the response is usually handed back to whoever
 * supplied the address.
 *
 * This is the check that has to happen before the request, and it exists as its own class because
 * every feature of this shape needs the same one — fetch a logo from a URL, import a feed, call a
 * webhook the user configured, follow a redirect somebody else chose.
 *
 * ```php
 * $reason = '';
 * if (!\Pramnos\Security\OutboundUrl::isPublic($url, $reason)) {
 *     throw new \InvalidArgumentException($reason);
 * }
 * ```
 *
 * ## What it does not do
 *
 * It does not close the DNS-rebinding window: the name is resolved here and resolved again by
 * whatever makes the request, and a hostile resolver can answer differently the second time. Closing
 * that means connecting to the address this check approved rather than to the name — which is a
 * property of the fetch, not of the URL. {@see fetch()} dials the approved address and puts the name
 * in `Host:`, which is why it is the thing to reach for rather than this check plus a
 * `file_get_contents()` of your own.
 *
 * @copyright   (c) 2005 - 2026 Yannis - Pastis Glaros
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @license     MIT
 */
final class OutboundUrl
{
    /**
     * Schemes the server may fetch.
     *
     * `file://` and `php://` are the reason this list is an allowlist rather than a denylist:
     * `file:///etc/passwd` is a perfectly well-formed URL, and a fetch helper that accepts any scheme
     * PHP knows reads local files for whoever supplies the address.
     */
    public const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * May the server fetch this URL?
     *
     * @param string       $url    The URL to check.
     * @param string|null  $reason Filled with why it was refused, for a log or an error message. It
     *                             names the class of problem and never the resolved address, which
     *                             would hand an attacker the network map this check exists to hide.
     * @return bool
     */
    public static function isPublic(string $url, ?string &$reason = null): bool
    {
        $reason = null;
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            $reason = 'Not a URL with a host in it.';
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            $reason = 'Only ' . implode(' and ', self::ALLOWED_SCHEMES) . ' URLs may be fetched.';
            return false;
        }

        /*
         * `user:pass@host` is refused rather than ignored.
         *
         * `http://expected.example@10.0.0.1/` reads as a URL for expected.example to a person and to
         * a naive check that looks at the string; the host is 10.0.0.1. Nothing legitimate puts
         * credentials in a URL any more, so refusing the whole shape costs nothing and removes a
         * class of misreading — including the reviewer's.
         */
        if (isset($parts['user']) || isset($parts['pass'])) {
            $reason = 'A URL with credentials in it is not fetched.';
            return false;
        }

        foreach (self::addressesOf($parts['host'], $reason) as $address) {
            if (!self::isPublicAddress($address)) {
                $reason = 'That host resolves to an address inside this network.';
                return false;
            }
        }

        return $reason === null;
    }

    /**
     * Every address a host resolves to — or the literal, if it is one.
     *
     * **All** of them, not the first. A name with one public and one private A record passes a check
     * that looks at one answer and fails whichever one the fetch happens to connect to, which is the
     * kind of bug that works in testing.
     *
     * @param string      $host
     * @param string|null $reason Filled when the name does not resolve at all.
     * @return list<string>
     */
    public static function addressesOf(string $host, ?string &$reason = null): array
    {
        $host = trim($host, '[]');           // an IPv6 literal arrives bracketed

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];

        // Both families: a host with only a private AAAA record passes an A-only check.
        foreach (['A', 'AAAA'] as $type) {
            $records = @dns_get_record($host, $type === 'A' ? DNS_A : DNS_AAAA);

            foreach (is_array($records) ? $records : [] as $record) {
                $address = $record['ip'] ?? ($record['ipv6'] ?? '');
                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        if ($addresses === []) {
            /*
             * No records is a refusal, not an empty pass.
             *
             * A name that does not resolve cannot be fetched anyway, so refusing costs nothing — and
             * returning an empty list from a loop that checks each address is how «no addresses» ends
             * up meaning «no address failed the check».
             */
            $reason = 'That host does not resolve.';
        }

        return $addresses;
    }

    /**
     * Is this a routable public address?
     *
     * `FILTER_FLAG_NO_PRIV_RANGE` and `NO_RES_RANGE` between them cover loopback, the three private
     * IPv4 blocks, link-local (which is where cloud metadata lives), the reserved blocks, and their
     * IPv6 equivalents including unique-local `fc00::/7`.
     */
    public static function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Fetch a URL the caller does not control, with every limit that makes that safe.
     *
     * The check and the fetch belong together. Between `isPublic()` and a separate
     * `file_get_contents()` sits a second DNS lookup, and a resolver that answers differently the
     * second time gets the private address it was refused the first — the rebinding window
     * `isPublic()` cannot close on its own. This connects to the address that was approved and sends
     * the name in `Host:`, so the answer to «what did we check» and «what did we connect to» is the
     * same.
     *
     * @param string       $url       The URL to fetch.
     * @param int          $maxBytes  Hard ceiling on the response body. Reached mid-stream, so a
     *                                server that answers with a hundred gigabytes costs this one
     *                                `$maxBytes`, not its memory.
     * @param string|null  $reason    Filled with why it failed.
     * @param int          $timeout   Seconds, for connect and for read.
     * @return string|false The body, or false.
     */
    public static function fetch(
        string $url,
        int $maxBytes = 10485760,
        ?string &$reason = null,
        int $timeout = 10
    ): string|false {
        if (!self::isPublic($url, $reason)) {
            return false;
        }

        $parts = parse_url($url);
        $host = trim((string) $parts['host'], '[]');
        $addresses = self::addressesOf($host);

        if ($addresses === []) {
            $reason = 'That host does not resolve.';
            return false;
        }

        /*
         * Dialled by address, with the name in `Host:`.
         *
         * This is what closes the rebinding window. `fopen($url)` would resolve the name a second
         * time, and a resolver that answers differently gets the private address it was refused a
         * moment ago. Substituting the address that passed the check makes «what did we approve» and
         * «what did we connect to» the same answer.
         */
        $address = $addresses[0];
        $dialled = $parts['scheme'] . '://'
            . (str_contains($address, ':') ? '[' . $address . ']' : $address)
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');

        $context = stream_context_create([
            'http' => [
                'method'           => 'GET',
                'timeout'          => $timeout,
                /*
                 * Redirects off, and this is not a limitation to work around.
                 *
                 * A 302 is a second URL, chosen by the server being fetched, and the wrapper follows
                 * it without asking anybody — so an address that passed the check redirects to
                 * 169.254.169.254 and the whole check was theatre. Following redirects safely means
                 * re-running `isPublic()` on each hop, which is the caller's decision to make with
                 * the `Location` header in hand.
                 */
                'follow_location'  => 0,
                'max_redirects'    => 0,
                'ignore_errors'    => true,
                'header'           => 'Host: ' . $host
                                      . (isset($parts['port']) ? ':' . $parts['port'] : '')
                                      . "\r\nConnection: close\r\n",
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                // The certificate is checked against the name, not the address dialled.
                'peer_name'        => $host,
                'SNI_enabled'      => true,
            ],
        ]);

        $handle = @fopen($dialled, 'rb', false, $context);

        if ($handle === false) {
            $reason = 'The request could not be made.';
            return false;
        }

        $body = '';
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);

            if ($chunk === false) {
                break;
            }

            $body .= $chunk;

            if (strlen($body) > $maxBytes) {
                fclose($handle);
                $reason = 'The response is larger than ' . $maxBytes . ' bytes.';
                return false;
            }
        }

        fclose($handle);

        return $body;
    }
}
