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
 * A redirect is a second address, chosen by the server being fetched, so it is a second question:
 * `fetch()` follows one only when asked to and re-runs the check on every hop. {@see nextHop()} and
 * {@see resolveLocation()} are that decision on its own, for a caller driving its own loop.
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
     * Where a response says to go next, checked — or `null` if it does not, or `false` if it may not.
     *
     * The whole difficulty of following a redirect safely, in one pure function, because that is what
     * makes it testable and what stops every application writing it again slightly differently.
     *
     * **A `302` is a second address, chosen by the server being fetched.** So each hop is a fresh
     * question for {@see isPublic()}: a host that passed the check can answer
     * `Location: http://169.254.169.254/…` and the stream wrapper would dial it without asking
     * anybody. That is not a hypothetical — an importer reading logo addresses out of a catalogue was
     * measured taking exactly that hop, with the second address receiving the connection.
     *
     * @param string        $from            The address that produced this response — a relative
     *                                       `Location` is resolved against it, which is the part that
     *                                       is easy to get subtly wrong.
     * @param list<string>  $responseHeaders The raw header lines, status line first, as
     *                                       `stream_get_meta_data()['wrapper_data']` gives them.
     * @param string|null   $reason          Filled when a hop is refused.
     * @return string|false|null  The next absolute URL; `null` when the response is not a redirect;
     *                            `false` when it is one that must not be followed.
     */
    public static function nextHop(
        string $from,
        array $responseHeaders,
        ?string &$reason = null
    ): string|false|null {
        $reason = null;
        $status = self::statusOf($responseHeaders);

        if ($status < 300 || $status > 399) {
            return null;
        }

        /*
         * The *last* Location, not the first.
         *
         * A misconfigured server can send two, and the header a client acts on is the last one it
         * received — so a check that read the first would approve an address the fetch does not dial.
         */
        $location = '';
        foreach ($responseHeaders as $line) {
            if (stripos($line, 'location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }

        if ($location === '') {
            $reason = 'A redirect with no Location to follow.';
            return false;
        }

        $target = self::resolveLocation($from, $location);

        if ($target === '') {
            $reason = 'That redirect does not resolve to a URL.';
            return false;
        }

        if (!self::isPublic($target, $hopReason)) {
            $reason = 'A redirect was refused: ' . (string) $hopReason;
            return false;
        }

        return $target;
    }

    /**
     * A `Location` value resolved against the address that sent it.
     *
     * Four shapes arrive in the wild and only one of them is a URL:
     *
     *  - `https://elsewhere.example/logo.png` — absolute, used as-is.
     *  - `//elsewhere.example/logo.png` — protocol-relative; inherits the scheme, **not** the host.
     *    Read as a path by a naive resolver, which turns somebody else's host into a directory on
     *    this one and quietly fetches the wrong thing.
     *  - `/assets/logo.png` — absolute path on the same host.
     *  - `assets/logo.png` — relative to the *directory* of the current path, which is the one people
     *    get wrong: relative to the path itself would resolve `/a/b` + `c` to `/a/b/c` instead of
     *    `/a/c`.
     *
     * `..` and `.` segments are collapsed, because a server is free to send them and the address that
     * gets checked has to be the address that gets dialled.
     *
     * @param string $from     An absolute URL.
     * @param string $location The `Location` header's value.
     * @return string An absolute URL, or `''` when it cannot be resolved into one.
     */
    public static function resolveLocation(string $from, string $location): string
    {
        $location = trim($location);

        if ($location === '') {
            return '';
        }

        $base = parse_url($from);

        if (!isset($base['scheme'], $base['host'])) {
            return '';
        }

        // Absolute already — including a scheme this class will refuse, which `isPublic()` says so
        // about rather than this method guessing.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $location) === 1) {
            return $location;
        }

        $authority = $base['scheme'] . '://';

        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }

        $authority .= $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');

        if (str_starts_with($location, '/')) {
            return $authority . self::normalisePath($location);
        }

        if (str_starts_with($location, '?') || str_starts_with($location, '#')) {
            return $authority . ($base['path'] ?? '/') . $location;
        }

        // Relative to the directory, not to the path.
        $directory = isset($base['path']) ? (string) preg_replace('#/[^/]*$#', '/', $base['path']) : '/';

        if ($directory === '' || $directory[0] !== '/') {
            $directory = '/' . $directory;
        }

        return $authority . self::normalisePath($directory . $location);
    }

    /**
     * The status code from a header block, or `0` when there is not one.
     *
     * Public for the same reason {@see nextHop()} is: a caller driving its own hop loop needs to know
     * what it is looking at, and the alternative is every one of them writing this three-line regex
     * again — differently, and at least one of them reading the *first* status line.
     *
     * That is the subtlety. A chain the stream wrapper followed itself leaves several status lines in
     * one header block, and the one describing the response in hand is the **last**. Reading the first
     * classifies the final 200 of a redirect chain as a redirect.
     *
     * @param list<string> $responseHeaders Raw header lines, as `stream_get_meta_data()`'s
     *                                      `wrapper_data` gives them.
     * @return int
     */
    public static function statusOf(array $responseHeaders): int
    {
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $matches) === 1) {
                // Not `return` on the first: a chain the wrapper followed itself would leave several
                // status lines, and the one that describes the response in hand is the last.
                $status = (int) $matches[1];
            }
        }

        return $status ?? 0;
    }

    /** Collapse `.` and `..` so the checked address and the dialled address are the same string. */
    private static function normalisePath(string $path): string
    {
        $query = '';
        $hash = strpos($path, '?');

        if ($hash !== false) {
            $query = substr($path, $hash);
            $path = substr($path, 0, $hash);
        }

        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }

            if ($segment === '..') {
                array_pop($out);
                continue;
            }

            $out[] = $segment;
        }

        return '/' . implode('/', $out) . (str_ends_with($path, '/') && $out !== [] ? '/' : '') . $query;
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
     * @param string       $url          The URL to fetch.
     * @param int          $maxBytes     Hard ceiling on the response body. Reached mid-stream, so a
     *                                   server that answers with a hundred gigabytes costs this one
     *                                   `$maxBytes`, not its memory.
     * @param string|null  $reason       Filled with why it failed.
     * @param int          $timeout      Seconds, for connect and for read.
     * @param int          $maxRedirects How many hops to follow, each one checked. `0` follows none.
     * @param int|null     $status       Filled with the status of the response the body came from —
     *                                   the last one, when hops were followed. `0` when nothing was
     *                                   fetched at all.
     * @return string|false The body, or false.
     */
    public static function fetch(
        string $url,
        int $maxBytes = 10485760,
        ?string &$reason = null,
        int $timeout = 10,
        int $maxRedirects = 0,
        ?int &$status = null
    ): string|false {
        $current = $url;
        $followed = 0;
        $status = 0;

        while (true) {
            $body = self::fetchOnce($current, $maxBytes, $reason, $timeout, $headers);

            if ($body === false) {
                return false;
            }

            /*
             * The status, and why it is an out-parameter rather than a refusal.
             *
             * `ignore_errors => true` stays: a caller that wants to read a 404's body should keep
             * being able to, and turning a non-2xx into `false` would take that away. What was missing
             * is that a caller could not *know* — a 404, a 403 and a 200 were all the same return
             * value, a string.
             *
             * «Check the content» answers that for most bodies and fails on the one that matters: a
             * CDN answering 404 with a placeholder image returns bytes that are a valid PNG, so every
             * content check passes and the placeholder is stored as the thing that was asked for.
             *
             * It is also the only place the difference between «this address is permanently wrong» and
             * «this server had a bad minute» lives, and that difference decides whether a caller
             * forgets an address or retries it tomorrow.
             */
            $status = self::statusOf($headers);

            $hop = self::nextHop($current, $headers, $hopReason);

            if ($hop === null) {
                return $body;               // not a redirect: this is the response
            }

            if ($hop === false) {
                $reason = (string) $hopReason;
                return false;
            }

            /*
             * A redirect with following switched off is a **failure**, not an empty success.
             *
             * `ignore_errors => true` is what lets a caller see a 404 body, and it also meant a `302`
             * came back as a successful fetch of an empty string — with no status and no `Location`
             * anywhere in the return, so a caller could neither act on it nor know it had happened.
             * Saying no is the only honest answer available at `maxRedirects = 0`.
             */
            if ($followed >= $maxRedirects) {
                $reason = $maxRedirects === 0
                    ? 'That address redirects, and redirects are not followed.'
                    : 'That address redirects more than ' . $maxRedirects . ' times.';

                return false;
            }

            $current = $hop;
            $followed++;
        }
    }

    /**
     * One request, to an address that has been checked, with the headers handed back.
     *
     * Split out so the hop loop in {@see fetch()} reads as a loop rather than as a request with a
     * loop wrapped round it — and so «check, then dial the address that was approved» stays a single
     * uninterrupted sequence, which is the property the whole class exists for.
     *
     * @param list<string>|null $headers Filled with the response's raw header lines.
     */
    private static function fetchOnce(
        string $url,
        int $maxBytes,
        ?string &$reason,
        int $timeout,
        ?array &$headers
    ): string|false {
        $headers = [];

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

        $address = $addresses[0];
        $dialled = self::dialledUrl($url, $address);

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

        $meta = stream_get_meta_data($handle);
        $headers = array_values(array_filter(
            (array) ($meta['wrapper_data'] ?? []),
            'is_string'
        ));

        $body = self::readCapped($handle, $maxBytes, $reason);
        fclose($handle);

        return $body;
    }

    /**
     * The same URL with the approved address in place of the name.
     *
     * This is what closes the DNS-rebinding window, and it is a separate method because it is the only
     * part of the fetch that can be checked without a network: `fopen($url)` would resolve the name a
     * second time, and a resolver that answers differently gets the private address it was refused a
     * moment ago. Substituting the address that passed makes «what did we approve» and «what did we
     * connect to» the same answer — and the name still goes out in `Host:`, so virtual hosting and
     * certificate verification are unaffected.
     *
     * The IPv6 brackets are the fiddly part: an address containing colons has to be bracketed or the
     * first colon reads as the port separator, and the URL points at a host that does not exist.
     *
     * @param string $url     The checked URL.
     * @param string $address One of the addresses its host resolved to.
     * @return string
     */
    public static function dialledUrl(string $url, string $address): string
    {
        $parts = parse_url($url);

        if (!isset($parts['scheme'])) {
            return $url;
        }

        return $parts['scheme'] . '://'
            . (str_contains($address, ':') ? '[' . $address . ']' : $address)
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Read a stream up to a ceiling, refusing rather than truncating when it is passed.
     *
     * Mid-stream, which is the whole point: a server answering with a hundred gigabytes costs this
     * process `$maxBytes` and not its memory. And a **refusal**, not a truncated body — half a JPEG or
     * half a JSON document is worse than nothing, because it is the shape the caller expects and the
     * failure surfaces somewhere else.
     *
     * Takes a handle rather than a URL so it can be checked against an in-memory stream, which is the
     * only part of the fetch that a suite making no network calls can reach.
     *
     * @param resource    $handle
     * @param int         $maxBytes
     * @param string|null $reason
     * @return string|false
     */
    private static function readCapped($handle, int $maxBytes, ?string &$reason): string|false
    {
        $body = '';

        while (!feof($handle)) {
            $chunk = fread($handle, 8192);

            if ($chunk === false) {
                break;
            }

            $body .= $chunk;

            if (strlen($body) > $maxBytes) {
                $reason = 'The response is larger than ' . $maxBytes . ' bytes.';

                return false;
            }
        }

        return $body;
    }
}
