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
        $request = [
            'time'   => round((microtime(true) - self::startTime()) * 1000, 2),
            'memory' => round(memory_get_peak_usage(true) / 1048576, 2),
        ];

        // The name every log line written during this request also carries, so
        // the toolbar can ask the server for what the response could not bring
        // back. Absent when ids are not being issued, which is production.
        $requestId = RequestId::activeId();
        if ($requestId !== null) {
            $request['id'] = $requestId;
        }

        // `request` is the reliable copy. The two keys below are written first
        // and then overwritten by any collector that shares their name — and one
        // does: MemoryCollector is registered as `memory`, so `$payload['memory']`
        // ends up as its array of byte counts rather than a number of megabytes.
        // Anything that read it as a scalar printed "[object Object]MB".
        //
        // Both are kept where they were, because a front end may already read
        // them, and moved somewhere a collector cannot reach.
        $payload = $request + ['request' => $request];

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

        // The phases worth seeing in a browser's own network panel, which is
        // where somebody looks when the toolbar is not involved at all. Only
        // these: a request that ran twenty migrations would otherwise write a
        // header with twenty entries in it, and headers land in every access log
        // between here and the client.
        foreach ($payload['timers']['named_timers'] ?? [] as $timer) {
            $name = (string) ($timer['name'] ?? '');
            if (in_array($name, self::TIMED_PHASES, true)) {
                $parts[] = sprintf('%s;dur=%s', $name, $timer['ms'] ?? 0);
            }
        }

        $queryCount = $payload['queries']['count'] ?? null;
        if ($queryCount !== null) {
            $sqlMs = $payload['queries']['total_ms'] ?? null;
            $parts[] = $sqlMs !== null
                ? sprintf('db;dur=%s;desc="%d queries"', $sqlMs, $queryCount)
                : sprintf('db;desc="%d queries"', $queryCount);
        }

        return implode(', ', $parts);
    }

    /**
     * The phases that travel in `Server-Timing`.
     *
     * A fixed list rather than every named timer: the header is written to
     * access logs and proxy logs, and an application is free to name a timer
     * anything at all — including something it would rather not have in a log
     * file. These are the framework's own, and they are the ones that mean the
     * same thing in every application.
     *
     * @var list<string>
     */
    private const TIMED_PHASES = [
        'bootstrap',
        'db-connect',
        'providers',
        'session',
        'routing',
        'middleware',
        'action',
        'controller',
    ];

    /** The response header that carries the summary. */
    public const HEADER = 'X-Pramnos-Debug';

    /**
     * A compact summary, small enough to travel as a response header.
     *
     * The full payload rides in the body, but only a JSON object has somewhere
     * to put it. A `204 No Content`, a redirect, an HTML fragment, a JSON array
     * — none of them do, and those are ordinary responses for the very calls a
     * page makes after it has rendered. This is what the toolbar's AJAX panel
     * shows for them: enough to see that a save took 900ms and ran 40 queries,
     * which is the observation that starts an investigation.
     *
     * Counts and timings only. No statements, no session, no request bodies —
     * a header is written to access logs and proxy logs, and anything put here
     * is put there too.
     *
     * @return string A JSON object, safe to use as a header value
     */
    public static function summary(): string
    {
        $payload = self::build();

        $summary = [
            'time'   => $payload['time'],
            'memory' => $payload['memory'],
        ];

        // The id matters most exactly here: a response reduced to a header is one
        // that could not carry its own detail, and the id is how the detail is
        // asked for afterwards.
        if (isset($payload['request']['id'])) {
            $summary['id'] = $payload['request']['id'];
        }

        // summarise() puts `count` on every query collector it understands, so
        // its absence means there was no usable query data — not that it has to
        // be counted here.
        $queryCount = $payload['queries']['count'] ?? null;

        if (is_int($queryCount)) {
            $summary['queries'] = $queryCount;
        }

        $route = $payload['route'] ?? [];
        if (is_array($route) && isset($route['controller'])) {
            $summary['route'] = (string) $route['controller']
                . (isset($route['action']) ? '/' . $route['action'] : '');
        }

        // ExceptionsCollector reports `count` and `items`. This used to look for
        // `exceptions` or `errors` — keys it has never emitted — so the summary
        // said nothing about a request that threw, which is the one thing a
        // bodiless error response could still have told anybody.
        $exceptions = $payload['exceptions'] ?? [];
        if (is_array($exceptions)) {
            $count = $exceptions['count'] ?? count($exceptions['items'] ?? []);
            if (is_int($count) && $count > 0) {
                $summary['errors'] = $count;
            }
        }

        // A header value cannot contain newlines, and a control character in one
        // is a header-injection bug rather than a formatting problem.
        return (string) json_encode(
            $summary,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    /**
     * Send the debug headers for this response.
     *
     * Both headers, together: `Server-Timing` because browsers render it in
     * their own network panel with no front-end code at all, and
     * {@see HEADER} because the toolbar's AJAX panel needs more than a duration.
     *
     * @return void
     */
    public static function sendHeaders(): void
    {
        // Once per response. The API layer sends its own Server-Timing as it
        // builds a reply, and the output-buffer callback offers again for every
        // response including that one — `header(..., false)` appends rather than
        // replaces, so without this a JSON API reply would carry the same timing
        // twice.
        if (self::$headersSent) {
            return;
        }

        if (!self::isEnabled() || PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        // @codeCoverageIgnoreStart
        // Emitting headers is HTTP-only; unreachable under CLI, which is where
        // the tests run. What is emitted is decided by headerLines(), which is
        // tested.
        self::$headersSent = true;

        foreach (self::headerLines() as [$line, $replace]) {
            header($line, $replace);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * Every header an annotated response carries, and whether it replaces.
     *
     * The two cache headers are the ones with consequences. A response carrying
     * debug data must never be served to somebody else: on a live server the
     * toolbar is open for one browser, by token, while every other visitor gets
     * the same URLs — and a shared cache in front of the application does not
     * know the difference. A cached JSON body with a `_debug` key would hand
     * that browser's query log to whoever asked for the same URL next.
     *
     * `Vary: Cookie` is the correct statement in general, since the grant lives
     * in a cookie. `no-store` is the one that is actually obeyed by every
     * intermediary, and it is added when the grant came from a token — which is
     * to say, whenever this is happening on a server that is not a development
     * one.
     *
     * @return list<array{0: string, 1: bool}> Header line, and whether to replace
     */
    public static function headerLines(): array
    {
        $lines = [
            ['Server-Timing: ' . self::serverTiming(), false],
            [self::HEADER . ': ' . self::summary(), true],
            ['Vary: Cookie', false],
        ];

        // Announced on its own header as well as inside the summary: this one is
        // readable in the browser's network panel and in a proxy log, without
        // parsing anything, and it is what somebody quotes when they ask for
        // help with a request that failed.
        $requestId = RequestId::activeId();
        if ($requestId !== null) {
            $lines[] = ['X-Request-Id: ' . $requestId, true];
        }

        if (DebugAccess::isGranted()) {
            $lines[] = ['Cache-Control: no-store, private, max-age=0', true];
        }

        return $lines;
    }

    /**
     * Whether this response has already been given its debug headers.
     *
     * @var bool
     */
    private static bool $headersSent = false;

    /**
     * Forget that the headers were sent.
     *
     * For tests, and for a worker that serves more than one request in a single
     * PHP lifetime.
     */
    public static function resetHeaderState(): void
    {
        self::$headersSent = false;
    }

    /**
     * Attach the payload to an already-encoded JSON body.
     *
     * Anything that is not a JSON object is returned untouched: a plain string,
     * a top-level array, or a body that failed to decode has nowhere to put a
     * `_debug` key, and mangling it would be worse than having no debug data. A
     * body that already carries one — because it came through the API layer,
     * which attaches its own — is left alone rather than rebuilt.
     *
     * @param  string $body
     * @return string
     */
    public static function attachTo(string $body): string
    {
        if (!self::isEnabled() || trim($body) === '') {
            return $body;
        }

        // Cheap rejection before the expensive decode: the overwhelming majority
        // of responses this is offered are HTML.
        if (!str_starts_with(ltrim($body), '{')) {
            return $body;
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded) || array_is_list($decoded) || isset($decoded['_debug'])) {
            return $body;
        }

        $decoded['_debug'] = self::build();

        $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        // Re-encoding can fail where decoding did not — malformed UTF-8 deep in
        // the data. The response matters; its annotation does not.
        return $encoded === false ? $body : $encoded;
    }
}
