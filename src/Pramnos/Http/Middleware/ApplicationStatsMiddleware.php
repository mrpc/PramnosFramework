<?php

declare(strict_types=1);

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;
use Pramnos\Http\Response;
use Pramnos\Http\TooManyRequestsException;

/**
 * Records each API request against the calling application, in `applications.application_stats`.
 *
 * The table, its hypertable, its compression policy and its hourly and daily aggregates all
 * ship with the framework; nothing wrote a row, so every aggregate aggregated nothing and
 * «usage per key» was each application's to build. This is the writer.
 *
 * ```php
 * $router->group(['prefix' => '/api/v1', 'middleware' => [
 *     new ApplicationStatsMiddleware(),      // first: it sees the refusals of what follows
 *     new RateLimitMiddleware(600, 3600),
 *     new ApiAuthMiddleware(...),
 * ]], $routes);
 * ```
 *
 * **One row per application per minute, not per request.** The table is already shaped as
 * pre-aggregated buckets — unique on `(time, appid)`, counters rather than events — so a request
 * is one upsert that adds itself to its minute: counts, the status bucket, bytes, and the
 * running average, minimum and maximum response time. The aggregates sum those rows.
 *
 * **Opt-in, and best-effort.** It is a write per API request, which an installation chooses
 * rather than inherits. A failed write — no table, a database hiccup — is swallowed: the
 * request it measures has already been served.
 *
 * Only a key with an application row is recorded. A request with no key or with the site's own
 * key is the application calling itself, and has no `appid` to count against.
 */
class ApplicationStatsMiddleware implements MiddlewareInterface
{
    private const TABLE = 'applications.application_stats';

    /**
     * @param \Closure|null $applicationOf `fn (string $apiKey): int` — the calling application's
     *                                     id, 0 for none. Defaults to the `applications` row.
     * @param mixed         $database      The connection to write to. Defaults to the current one.
     */
    public function __construct(
        private ?\Closure $applicationOf = null,
        private mixed $database = null
    ) {
    }

    /**
     * Serve the request, then add it to its application's minute.
     *
     * A rate-limit refusal is counted as such and re-thrown; any other exception is counted by
     * its HTTP code, or as a 500, and re-thrown. What reaches the client is never changed.
     */
    public function handle(Request $request, callable $next): mixed
    {
        $key     = (string) ($_SERVER['HTTP_APIKEY'] ?? '');
        $started = hrtime(true);

        try {
            $result = $next($request);
        } catch (TooManyRequestsException $exception) {
            $this->record($key, 429, $started, 0, true);
            throw $exception;
        } catch (\Throwable $exception) {
            $code = (int) $exception->getCode();
            $this->record($key, $code >= 400 && $code <= 599 ? $code : 500, $started, 0, false);
            throw $exception;
        }

        $this->record($key, $this->statusOf($result), $started, $this->bytesOf($result), false);

        return $result;
    }

    /**
     * The calling application's id from its key, or 0 when there is none.
     */
    public static function applicationIdOf(string $apiKey): int
    {
        if ($apiKey === '') {
            return 0;
        }

        return (int) (new \Pramnos\Application\Api\Apikey($apiKey))->appid;
    }

    /**
     * Add one request to its application's minute. Never throws.
     */
    private function record(string $key, int $status, int $started, int $bytesSent, bool $rateLimited): void
    {
        try {
            $appId = ($this->applicationOf ?? self::applicationIdOf(...))($key);
            if ($appId <= 0) {
                return;
            }

            $db = $this->database ?? \Pramnos\Framework\Factory::getDatabase();
            $ms = (hrtime(true) - $started) / 1e6;
            $this->upsert($db, $appId, $status, $ms, $bytesSent, $rateLimited);
        } catch (\Throwable) {
            // Best-effort: the request this measures has already been served.
        }
    }

    /**
     * One statement: insert the minute, or add this request to it.
     *
     * The update's columns are qualified with the table's name, because on PostgreSQL an
     * unqualified name in `DO UPDATE SET` could be the existing row or `EXCLUDED`. The average
     * is listed first: MySQL evaluates `ON DUPLICATE KEY UPDATE` left to right, so it must read
     * `total_requests` before that column is incremented — PostgreSQL reads the old row either way.
     */
    private function upsert(mixed $db, int $appId, int $status, float $ms, int $bytesSent, bool $rateLimited): void
    {
        $qb      = $db->queryBuilder()->table(self::TABLE);
        $table   = $db->schema()->resolveTableName(self::TABLE);
        $t       = str_contains($table, '.') ? substr((string) strrchr($table, '.'), 1) : $table;
        $col     = static fn (string $name): string => $t . '.' . $name;
        $elapsed = sprintf('%.3F', $ms);
        $bucket  = (int) floor($status / 100);
        $ok      = $status < 400 ? 1 : 0;

        $counts = [
            'successful_requests'   => $ok,
            'failed_requests'       => 1 - $ok,
            'status_2xx'            => $bucket === 2 ? 1 : 0,
            'status_3xx'            => $bucket === 3 ? 1 : 0,
            'status_4xx'            => $bucket === 4 ? 1 : 0,
            'status_5xx'            => $bucket === 5 ? 1 : 0,
            'rate_limited_requests' => $rateLimited ? 1 : 0,
            'bytes_sent'            => $bytesSent,
            'bytes_received'        => max(0, (int) ($_SERVER['CONTENT_LENGTH'] ?? 0)),
        ];

        $update = [
            'avg_response_time' => $qb->raw(
                "(COALESCE({$col('avg_response_time')}, 0) * {$col('total_requests')} + {$elapsed})"
                . " / ({$col('total_requests')} + 1)"
            ),
            'min_response_time' => $qb->raw("LEAST(COALESCE({$col('min_response_time')}, {$elapsed}), {$elapsed})"),
            'max_response_time' => $qb->raw("GREATEST(COALESCE({$col('max_response_time')}, {$elapsed}), {$elapsed})"),
            'total_requests'    => $qb->raw("{$col('total_requests')} + 1"),
        ];
        foreach ($counts as $name => $value) {
            $update[$name] = $qb->raw("{$col($name)} + " . (int) $value);
        }

        $qb->upsert(
            [
                'time'              => date('Y-m-d H:i:00'),
                'appid'             => $appId,
                'total_requests'    => 1,
                'avg_response_time' => $ms,
                'min_response_time' => $ms,
                'max_response_time' => $ms,
            ] + $counts,
            ['time', 'appid'],
            $update
        );
    }

    /** The status the client received. */
    private function statusOf(mixed $result): int
    {
        if ($result instanceof Response) {
            return $result->getStatusCode();
        }
        $code = http_response_code();

        return is_int($code) && $code > 0 ? $code : 200;
    }

    /** The body's length in bytes. */
    private function bytesOf(mixed $result): int
    {
        return match (true) {
            $result instanceof Response => strlen($result->getBody()),
            is_string($result)          => strlen($result),
            is_array($result)           => strlen((string) json_encode($result)),
            default                     => 0,
        };
    }
}
