<?php

declare(strict_types=1);

namespace Pramnos\Debug;

/**
 * The debug toolbar's data, in a form a JSON API can carry.
 *
 * The HTML toolbar is injected before `</body>` by DebugBarMiddleware. A JSON
 * response has no `</body>`, and a SPA's page is a static shell that never goes
 * through the middleware pipeline — so a single-page application had no debug
 * information at all, for exactly the requests where it matters most.
 *
 * Rather than store request data server-side and expose an extra endpoint, the
 * data travels **with the response it describes**: each API response carries a
 * `_debug` key, and the front end shows it. Nothing to correlate, nothing to
 * clean up, and it works for every call including the ones that failed.
 *
 * Only ever attached when the toolbar is active (development), and deliberately
 * small: counts, timings, and the queries — not request bodies or session
 * contents, which would leak into a browser's network log.
 */
class ApiDebugPayload
{
    /**
     * Should responses carry debug data?
     *
     * The question is answered by the toolbar itself: collectors are registered
     * only when DebugBarServiceProvider booted, and it only boots in debug mode.
     * Asking that rather than re-reading APP_DEBUG / settings keeps one
     * definition of "development" instead of two that can disagree.
     */
    public static function isEnabled(): bool
    {
        return DebugBar::getInstance()->getCollectors() !== [];
    }

    /**
     * Build the payload from whatever the toolbar collected for this request.
     *
     * A collector that throws is reported as an error entry rather than being
     * allowed to break the response it is only annotating — instrumentation must
     * never be the reason an API call fails.
     *
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $bar     = DebugBar::getInstance();
        $payload = [
            'time'   => round((microtime(true) - self::startTime()) * 1000, 2),
            'memory' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];

        foreach ($bar->getCollectors() as $name => $collector) {
            try {
                $data = $collector->collect();
            } catch (\Throwable $e) {
                $payload[$name] = ['error' => $e->getMessage()];
                continue;
            }

            $payload[$name] = self::summarise($name, $data);
        }

        return $payload;
    }

    /**
     * Reduce a collector's data to what is useful in a browser panel.
     *
     * Queries are the reason anyone opens this, so they keep their statements
     * and timings; everything else is passed through as collected. The list is
     * capped because a page that ran 400 queries would otherwise weigh more than
     * the response it is attached to — and the count still tells the story.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function summarise(string $name, array $data): array
    {
        if ($name !== 'queries') {
            return $data;
        }

        $queries = $data['queries'] ?? $data['statements'] ?? [];
        if (!is_array($queries)) {
            return $data;
        }

        $data['count'] = count($queries);
        if (count($queries) > self::MAX_QUERIES) {
            $data['truncated'] = count($queries) - self::MAX_QUERIES;
            $queries = array_slice($queries, 0, self::MAX_QUERIES);
        }
        $data['queries'] = $queries;
        unset($data['statements']);

        return $data;
    }

    /** How many queries are carried before the list is cut short. */
    private const MAX_QUERIES = 100;

    /**
     * When this request started, as accurately as the SAPI allows.
     */
    private static function startTime(): float
    {
        return (float) ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
    }

    /**
     * A `Server-Timing` header value for this request.
     *
     * Browsers render this in the network panel without any front-end code, so
     * it is useful even in a project that never adds the debug panel — and it is
     * the one piece that also works for responses with no body (204, redirects).
     *
     * @return string Value for the Server-Timing header
     */
    public static function serverTiming(): string
    {
        $payload = self::build();
        $parts   = [sprintf('app;dur=%s', $payload['time'])];

        $queryCount = $payload['queries']['count'] ?? null;
        if ($queryCount !== null) {
            $parts[] = sprintf('db;desc="%d queries"', $queryCount);
        }

        return implode(', ', $parts);
    }
}
