<?php

declare(strict_types=1);

namespace Pramnos\Conversations\Beacon;

/**
 * A request between the primary, the beacon and the outside watcher, signed with a shared secret:
 * `X-Beacon-Time` (Unix seconds) and `X-Beacon-Signature: sha256=` the HMAC-SHA256 of the time, a
 * line break and the body. Five minutes either way, so a request seen is not one that can be sent
 * again an hour later.
 */
final class Signature
{
    /** How far a request's time may be from ours, in seconds. */
    public const WINDOW = 300;

    /**
     * The headers for a body.
     *
     * @return array{X-Beacon-Time: string, X-Beacon-Signature: string}
     */
    public static function headers(string $secret, string $body, ?int $now = null): array
    {
        $time = (string) ($now ?? time());

        return ['X-Beacon-Time' => $time, 'X-Beacon-Signature' => 'sha256=' . hash_hmac('sha256', $time . "\n" . $body, $secret)];
    }

    /** Whether a request was signed with this secret, recently. An empty secret signs nothing. */
    public static function verify(string $secret, ?string $time, ?string $signature, string $body, ?int $now = null): bool
    {
        if ($secret === '' || $time === null || $signature === null || !ctype_digit($time) || abs(($now ?? time()) - (int) $time) > self::WINDOW) {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $time . "\n" . $body, $secret), $signature);
    }
}
