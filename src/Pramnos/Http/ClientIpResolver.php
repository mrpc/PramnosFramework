<?php

declare(strict_types=1);

namespace Pramnos\Http;

/**
 * Answers one question — what is the client's IP address — with a trusted-proxy
 * list as the only thing that makes a forwarding header believable.
 *
 * `$_SERVER['REMOTE_ADDR']` is the connecting peer. Behind a reverse proxy, a
 * CDN or a load balancer that peer is the proxy, so every visitor shares one
 * address: a per-IP rate limit becomes a global one and fires for everybody at
 * once, and anything binding a session to the address binds every session to
 * the same value.
 *
 * The obvious repair — read `X-Forwarded-For` — is worse than the disease. The
 * header is written by the client, so an attacker who sets a fresh random value
 * on every request gets a fresh rate-limit bucket every time while the logs show
 * a healthy spread of addresses. A control that looks like it works and does not
 * is the worst of the three states.
 *
 * So a forwarding header is read **only** when the peer that delivered it is
 * itself a trusted proxy, and the chain is walked from the right — the end the
 * infrastructure appended — taking the first address that is not a trusted hop.
 * With no proxies configured the answer is `REMOTE_ADDR`, unchanged. That is
 * both the safe default and the framework's previous behaviour, so nothing
 * changes for an application that does not opt in.
 *
 * Configuration lives in `app.php`:
 *
 *     'trusted_proxies' => ['private_ranges'],          // shorthand
 *     'trusted_proxies' => ['10.0.0.0/8', '2001:db8::/32', '192.0.2.7'],
 *
 * @package Pramnos\Http
 */
class ClientIpResolver
{
    /**
     * The `private_ranges` shorthand: loopback, link-local and RFC 1918 space,
     * plus their IPv6 equivalents. This is the common case — a proxy sitting on
     * the same private network as the application.
     *
     * @var list<string>
     */
    public const PRIVATE_RANGES = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    /**
     * Cloudflare's published edge ranges, for the `cloudflare` shorthand.
     *
     * This is a snapshot. Cloudflare publishes the authoritative list at
     * https://www.cloudflare.com/ips/ and does change it; an installation that
     * cares should pin its own copy in `trusted_proxies` rather than rely on a
     * constant in a framework release.
     *
     * @var list<string>
     */
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /**
     * @var list<string> CIDR blocks and bare addresses treated as proxies.
     */
    private array $trusted;

    /**
     * @param list<string> $trustedProxies CIDRs, bare IPs, or 'private_ranges'.
     */
    public function __construct(array $trustedProxies = [])
    {
        $this->trusted = $this->expand($trustedProxies);
    }

    /**
     * Build a resolver from the running application's configuration.
     *
     * Returns a resolver trusting nothing when there is no application, which
     * is the case in CLI and in most unit tests — and trusting nothing means
     * answering `REMOTE_ADDR`, so those contexts behave exactly as before.
     *
     * It asks for the *existing* application via
     * {@see \Pramnos\Application\Application::currentInstance()} and never
     * `getInstance()`, which is a factory: with no instance yet that would read
     * `app.php`, define constants and run the whole constructor — booting a
     * database and a session from inside a CSRF check or a rate-limit decision.
     * That is not a hypothetical: it broke a reference application's login
     * tests, which failed on "security token invalid" because a second
     * application was being constructed underneath them.
     */
    public static function fromApplication(): self
    {
        $app = \Pramnos\Application\Application::currentInstance();
        if ($app === null) {
            return new self();
        }

        $configured = $app->applicationInfo['trusted_proxies'] ?? [];

        return new self(is_array($configured) ? $configured : []);
    }

    /**
     * Resolve the client address from a `$_SERVER`-shaped array.
     *
     * @param array<string, mixed> $server
     * @return string The client address, or '' when there is no peer at all
     *                (CLI, where `REMOTE_ADDR` is simply absent).
     */
    public function resolve(array $server): string
    {
        $remote = $this->normalise((string) ($server['REMOTE_ADDR'] ?? ''));

        // No proxies trusted: the peer is the client, and any forwarding header
        // present is unverifiable noise. This is the default.
        if ($this->trusted === []) {
            return $remote;
        }

        // The peer is not one of our proxies, so it has no authority to tell us
        // who it is forwarding for — it is just a client that set a header.
        if ($remote === '' || !$this->isTrusted($remote)) {
            return $remote;
        }

        // Cloudflare states the client directly rather than appending to a
        // chain. It is single-valued, so there is nothing to walk — it is
        // believable only because the peer that sent it has already been
        // checked against the trusted set immediately above.
        $cloudflare = $this->normalise((string) ($server['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cloudflare !== '') {
            return $cloudflare;
        }

        $chain = $this->forwardedChain($server);
        if ($chain === []) {
            return $remote;
        }

        // Walk right-to-left. Each proxy appends the address it received the
        // request from, so the rightmost entries are the ones our own
        // infrastructure wrote and the leftmost is whatever the original client
        // claimed. The first entry that is not a trusted hop is the furthest
        // point we can still vouch for.
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            if (!$this->isTrusted($chain[$i])) {
                return $chain[$i];
            }
        }

        // Every hop is trusted, so the whole chain is our own infrastructure and
        // the leftmost entry is the closest thing to a client we were given.
        return $chain[0];
    }

    /**
     * The forwarded chain as a list of valid addresses, oldest first.
     *
     * `X-Forwarded-For` is checked first because it is what proxies actually
     * send. RFC 7239's `Forwarded` is honoured when `X-Forwarded-For` is absent.
     *
     * `X-Real-IP` is deliberately not consulted: it is single-valued, so there
     * is no chain to walk and no way to tell a proxy's own statement from a
     * client's, beyond the peer check that has already passed.
     *
     * @param array<string, mixed> $server
     * @return list<string>
     */
    private function forwardedChain(array $server): array
    {
        $raw = trim((string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
        if ($raw === '') {
            $raw = $this->forwardedHeaderAddresses((string) ($server['HTTP_FORWARDED'] ?? ''));
        }

        $chain = [];
        foreach (explode(',', $raw) as $entry) {
            $address = $this->normalise($entry);
            // Anything that is not an address is a client writing rubbish into
            // the header; dropping it keeps rubbish out of the cache key.
            if ($address !== '') {
                $chain[] = $address;
            }
        }

        return $chain;
    }

    /**
     * Extract the `for=` values from an RFC 7239 `Forwarded` header, in order,
     * as a comma-separated string so the caller can treat both headers alike.
     */
    private function forwardedHeaderAddresses(string $header): string
    {
        if (trim($header) === '') {
            return '';
        }

        $found = [];
        foreach (explode(',', $header) as $element) {
            foreach (explode(';', $element) as $pair) {
                $pair = trim($pair);
                if (stripos($pair, 'for=') !== 0) {
                    continue;
                }
                $found[] = trim(substr($pair, 4), " \t\"");
            }
        }

        return implode(',', $found);
    }

    /**
     * Reduce a header entry to a bare IP address, or '' when it is not one.
     *
     * Handles the forms that appear in the wild: a port suffix on IPv4
     * (`203.0.113.7:41234`), the bracketed IPv6 form (`[2001:db8::1]:443`), and
     * RFC 7239's obfuscated identifiers (`_hidden`, `unknown`) which are not
     * addresses and must not be treated as one.
     */
    private function normalise(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if ($value[0] === '[') {
            $close = strpos($value, ']');
            if ($close !== false) {
                $value = substr($value, 1, $close - 1);
            }
        } elseif (substr_count($value, ':') === 1) {
            // Exactly one colon means IPv4 with a port — a bare IPv6 address
            // always has more.
            $value = substr($value, 0, (int) strpos($value, ':'));
        }

        return filter_var($value, FILTER_VALIDATE_IP) === false ? '' : $value;
    }

    /**
     * Whether an address falls inside the trusted-proxy set.
     */
    public function isTrusted(string $address): bool
    {
        foreach ($this->trusted as $range) {
            if ($this->matches($address, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace shorthands with the ranges they stand for.
     *
     * @param list<string> $entries
     * @return list<string>
     */
    private function expand(array $entries): array
    {
        $expanded = [];
        foreach ($entries as $entry) {
            $entry = trim((string) $entry);
            if ($entry === '') {
                continue;
            }
            if (strtolower($entry) === 'private_ranges') {
                foreach (self::PRIVATE_RANGES as $range) {
                    $expanded[] = $range;
                }
                continue;
            }
            if (strtolower($entry) === 'cloudflare') {
                foreach (self::CLOUDFLARE_RANGES as $range) {
                    $expanded[] = $range;
                }
                continue;
            }
            $expanded[] = $entry;
        }

        return $expanded;
    }

    /**
     * Whether $address is inside $range, where $range is a CIDR block or a
     * single address.
     *
     * The comparison is done on the packed binary form, so IPv4 and IPv6 are
     * handled by the same code and a v4 address never matches a v6 range.
     */
    private function matches(string $address, string $range): bool
    {
        $packedAddress = @inet_pton($address);
        if ($packedAddress === false) {
            return false;
        }

        if (!str_contains($range, '/')) {
            $packedRange = @inet_pton($range);

            return $packedRange !== false && hash_equals($packedRange, $packedAddress);
        }

        [$subnet, $bits] = explode('/', $range, 2);
        $packedSubnet    = @inet_pton(trim($subnet));
        if ($packedSubnet === false || !is_numeric($bits)) {
            return false;
        }

        // A v4 address and a v6 subnet pack to different lengths, so they can
        // never match — which is the correct answer, not an error.
        if (strlen($packedSubnet) !== strlen($packedAddress)) {
            return false;
        }

        $bits = (int) $bits;
        if ($bits < 0 || $bits > strlen($packedAddress) * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        if ($wholeBytes > 0
            && !hash_equals(substr($packedSubnet, 0, $wholeBytes), substr($packedAddress, 0, $wholeBytes))
        ) {
            return false;
        }

        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }

        // Compare the leading bits of the first partial byte.
        $mask = ~((1 << (8 - $remaining)) - 1) & 0xFF;

        return (ord($packedSubnet[$wholeBytes]) & $mask)
            === (ord($packedAddress[$wholeBytes]) & $mask);
    }
}
