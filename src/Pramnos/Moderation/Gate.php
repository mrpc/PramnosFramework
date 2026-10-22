<?php

declare(strict_types=1);

namespace Pramnos\Moderation;

/**
 * Whether what somebody writes goes through, before it is written: not banned or muted where they
 * write, not faster than the scope's slow mode, not more than the rate allows, and what the filter
 * lets through — masked where it masks.
 *
 * ```php
 * $verdict = Gate::check([Subject::visitor($key), Subject::ip($ip), Subject::nickname($nick)], 'conversation:12', $text);
 * if (!$verdict['allowed']) {
 *     return ['error' => $verdict['message'], 'retryAfter' => $verdict['retryAfter']];
 * }
 * $text = $verdict['text'];
 * ```
 *
 * The first subject is the writer — their account or visitor key — and is who the slow mode and the
 * rate count; the others (their address, their nickname) are looked for among the restrictions
 * too. A refusal by the rate or the filter is written in the log.
 *
 * The slow mode and the rate are counted in the application's cache.
 * ponytail: a cache that is off counts nothing, and lets everybody through; the restrictions and the
 * filter hold without it.
 */
final class Gate
{
    /**
     * Check a text by somebody, in a scope.
     *
     * @param list<Subject> $who The writer first, then what else identifies them
     * @return array{allowed: bool, text: string, reason: string, message: string, retryAfter: int}
     *         `reason`: '' when allowed, else banned, muted, slow, rate or filter
     */
    public static function check(array $who, string $scope, string $text, ?int $now = null): array
    {
        $now    = $now ?? time();
        $writer = $who[0] ?? throw new \InvalidArgumentException(t('Somebody has to be writing.'));
        $held   = Moderation::restriction($who, $scope, $now);
        if ($held !== null) {
            $wait = $held['until'] === null ? 0 : $held['until'] - $now;

            return self::refused($held['effect'] === 'ban' ? 'banned' : 'muted', $text, $wait, $held['effect'] === 'ban'
                ? t('You may not write here.') : ($held['until'] === null ? t('You may not write here for now.') : t('You may write here again in %s.', self::span($wait))));
        }
        $slow = Moderation::slowModeOf($scope);
        $last = (int) (self::recall('slow:' . $scope . ':' . $writer->key()) ?? 0);
        if ($slow > 0 && $last > 0 && $now - $last < $slow) {
            return self::refused('slow', $text, $slow - ($now - $last), t('Slow mode: you may write again in %s.', self::span($slow - ($now - $last))));
        }
        $rate   = Moderation::rule('rate');
        $bucket = (new \Pramnos\Http\TokenBucket())->take('moderation:' . $writer->key(), max(1, (int) $rate['messages']), max(1, (int) $rate['messages']), max(1, (int) $rate['per']));
        if (!$bucket['allowed']) {
            Moderation::log('refuse', $writer, $scope, null, 'rate');

            return self::refused('rate', $text, $bucket['retry_after'], t('Too many messages: wait %s.', self::span($bucket['retry_after'])));
        }
        $filtered = Filter::apply($text);
        if (!$filtered['allowed']) {
            Moderation::log('refuse', $writer, $scope, null, 'filter', ['filter' => $filtered['reasons']]);

            return self::refused('filter', $text, 0, t('That message cannot be sent.'));
        }
        if ($slow > 0) {
            self::keep('slow:' . $scope . ':' . $writer->key(), $now, $slow);
        }

        return ['allowed' => true, 'text' => $filtered['text'], 'reason' => '', 'message' => '', 'retryAfter' => 0];
    }

    /**
     * A refusal.
     *
     * @return array{allowed: bool, text: string, reason: string, message: string, retryAfter: int}
     */
    private static function refused(string $reason, string $text, int $wait, string $message): array
    {
        return ['allowed' => false, 'text' => $text, 'reason' => $reason, 'message' => $message, 'retryAfter' => max(0, $wait)];
    }

    /** A wait, said: `45″`, `3′`, `2 h`, `5 d`. */
    private static function span(int $seconds): string
    {
        return match (true) {
            $seconds < 60    => max(1, $seconds) . '″',
            $seconds < 3600  => (int) ceil($seconds / 60) . '′',
            $seconds < 86400 => (int) ceil($seconds / 3600) . ' h',
            default          => (int) ceil($seconds / 86400) . ' d',
        };
    }

    /** What was kept under a name, or null. */
    private static function recall(string $name): mixed
    {
        $kept = \Pramnos\Framework\Factory::getCache()->load('moderation-' . md5($name), 'moderation');

        return $kept === false ? null : json_decode((string) $kept, true);
    }

    /** Keep a value under a name for `$seconds`. */
    private static function keep(string $name, mixed $value, int $seconds): void
    {
        $cache = \Pramnos\Framework\Factory::getCache();
        // The cache takes its time to live from the load before a save.
        $cache->load('moderation-' . md5($name), 'moderation', max(1, $seconds));
        $cache->save((string) json_encode($value), 'moderation-' . md5($name));
    }
}
