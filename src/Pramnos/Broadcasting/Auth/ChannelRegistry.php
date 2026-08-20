<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Auth;

/**
 * Who may subscribe to which channel.
 *
 * The framework could verify a channel signature but never produce one, and it had
 * nowhere to say *which user may join which channel* — so every application wrote
 * its own `/broadcasting/auth` endpoint and its own HMAC. That is production
 * security code, rewritten per project, and it is the same code every time.
 *
 * Patterns are written **without** the `private-` / `presence-` prefix, and
 * placeholders in braces are passed to the callback as arguments:
 *
 * ```php
 * $registry->channel('order.{id}', function (?object $user, string $id): bool {
 *     return $user !== null && Order::load((int) $id)?->userid === $user->userid;
 * });
 *
 * // A presence channel returns the member's identity instead of true:
 * $registry->channel('room.{room}', function (?object $user, string $room): array|bool {
 *     if ($user === null || !$user->canJoin($room)) {
 *         return false;
 *     }
 *     return ['user_id' => (string) $user->userid, 'user_info' => ['name' => $user->name]];
 * });
 * ```
 *
 * ## The decision is made here, never in the daemon
 *
 * This runs in a normal request, where a database and a session are cheap. The
 * WebSocket daemon is a single-threaded select loop, and a permission lookup per
 * subscribe — `Gate` reaching an effective-permissions view — would block every
 * other connection on the process. So the endpoint decides and signs; the daemon
 * only verifies an HMAC. That split is not a compromise, it is the reason the
 * Pusher protocol is shaped this way.
 *
 * A channel with no registered pattern is **denied**. An unroutable channel name
 * is a missing rule, and defaulting to open would make every typo a hole.
 */
final class ChannelRegistry
{
    /** @var array<string, callable> pattern → authorization callback */
    private array $patterns = [];

    /**
     * Register an authorization rule.
     *
     * @param string   $pattern  Channel pattern without prefix, e.g. `order.{id}`.
     * @param callable $callback `fn(?object $user, string ...$params): bool|array`
     *                           Returning an array admits the user to a presence
     *                           channel and becomes their member data; true admits
     *                           them to a private one; false denies.
     */
    public function channel(string $pattern, callable $callback): self
    {
        $this->patterns[$pattern] = $callback;

        return $this;
    }

    /** True when some registered pattern matches $channel. */
    public function has(string $channel): bool
    {
        return $this->match($channel) !== null;
    }

    /**
     * Decide whether $user may join $channel.
     *
     * @param string      $channel Full channel name, prefix included.
     * @param object|null $user    The authenticated user, or null.
     * @return array<string,mixed>|bool An array is presence member data; a bool is
     *         a plain allow/deny. False for an unmatched channel.
     */
    public function authorize(string $channel, ?object $user): array|bool
    {
        // A public channel needs no authorization and must not be signed: handing
        // out a token for one would imply a guard that is not there.
        if (!self::needsAuthorization($channel)) {
            return true;
        }

        $matched = $this->match($channel);
        if ($matched === null) {
            return false;
        }

        [$callback, $params] = $matched;

        $result = $callback($user, ...$params);

        if (is_array($result)) {
            return $result;
        }

        return $result === true;
    }

    /**
     * True when $channel is one the protocol requires a signature for.
     */
    public static function needsAuthorization(string $channel): bool
    {
        return str_starts_with($channel, 'private-')
            || str_starts_with($channel, 'presence-');
    }

    /**
     * True when $channel is a presence channel, which must carry member data.
     */
    public static function isPresence(string $channel): bool
    {
        return str_starts_with($channel, 'presence-');
    }

    /**
     * Strip the protocol prefix, so patterns are written the way an application
     * thinks about its channels rather than the way the wire names them.
     */
    public static function stripPrefix(string $channel): string
    {
        foreach (['private-encrypted-', 'private-', 'presence-'] as $prefix) {
            if (str_starts_with($channel, $prefix)) {
                return substr($channel, strlen($prefix));
            }
        }

        return $channel;
    }

    /**
     * Find the pattern matching $channel and extract its placeholder values.
     *
     * @return array{0:callable, 1:list<string>}|null
     */
    private function match(string $channel): ?array
    {
        $name = self::stripPrefix($channel);

        foreach ($this->patterns as $pattern => $callback) {
            $params = self::extract($pattern, $name);
            if ($params !== null) {
                return [$callback, $params];
            }
        }

        return null;
    }

    /**
     * Match $name against $pattern, returning the placeholder values or null.
     *
     * A placeholder matches one segment and never a dot, so `order.{id}` does not
     * swallow `order.42.items` — a pattern that matched more than it names would
     * hand one rule's decision to a channel it was never written for.
     *
     * @return list<string>|null
     */
    private static function extract(string $pattern, string $name): ?array
    {
        $regex = '#^' . preg_replace(
            '/\\\\\{[A-Za-z_][A-Za-z0-9_]*\\\\\}/',
            '([^.]+)',
            preg_quote($pattern, '#')
        ) . '$#';

        if (preg_match($regex, $name, $matches) !== 1) {
            return null;
        }

        return array_values(array_slice($matches, 1));
    }
}
