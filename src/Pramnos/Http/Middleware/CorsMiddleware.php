<?php

namespace Pramnos\Http\Middleware;

use Pramnos\Http\MiddlewareInterface;
use Pramnos\Http\Request;

/**
 * Sets CORS response headers and handles OPTIONS preflight requests.
 *
 * Usage — globally for all API routes (in ServiceProvider::boot()):
 *   $router->addGlobalMiddleware(new CorsMiddleware(
 *       allowedOrigins: ['https://app.example.com', 'https://admin.example.com']
 *   ));
 *
 * Usage — wildcard (allow any origin, e.g. public API):
 *   $router->addGlobalMiddleware(new CorsMiddleware());  // defaults to ['*']
 *
 * Usage — per route:
 *   $router->get('/api/status', fn() => ...)->middleware(new CorsMiddleware());
 *
 * Preflight (OPTIONS) requests are answered with 204 and do not reach the action.
 *
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $allowedOrigins = ['*'],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'],
        private bool  $allowCredentials = false,
        private int   $maxAge = 86400
    ) {
        // `Access-Control-Allow-Origin: *` together with
        // `Allow-Credentials: true` is a combination browsers reject outright,
        // so the effect is that credentialed cross-origin requests simply stop
        // working — with nothing anywhere saying why. Refusing the combination
        // at construction turns a silent misconfiguration into a sentence.
        if ($allowCredentials && in_array('*', $allowedOrigins, true)) {
            throw new \InvalidArgumentException(
                'CorsMiddleware: credentials cannot be allowed for every origin. '
                . 'Browsers reject "Access-Control-Allow-Origin: *" with '
                . '"Access-Control-Allow-Credentials: true". List the origins '
                . 'that may send credentials instead of using "*".'
            );
        }
    }

    /** @return list<string> */
    public function getAllowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    /**
     * Construct from pre-fetched application_settings data.
     *
     * Separates data-parsing from DB access so the logic can be unit-tested
     * without a database connection.
     *
     * @param bool              $enabled    Value of application_settings.cors_enabled
     * @param list<string>|string|null $rawOrigins  JSON string or array from cors_origins column
     */
    public static function fromCorsData(bool $enabled, array|string|null $rawOrigins): self
    {
        if (!$enabled) {
            return new self(['*']);
        }
        if (is_string($rawOrigins)) {
            $rawOrigins = self::parseOrigins($rawOrigins);
        }
        $origins = array_values(array_filter((array) $rawOrigins));
        return new self(!empty($origins) ? $origins : ['*']);
    }

    /**
     * Build a CorsMiddleware by reading cors_enabled and cors_origins from the
     * application_settings table (PF-43).
     *
     * Falls back to wildcard ('*') when:
     *  - The DB is unavailable (table not yet migrated, connection error)
     *  - No row exists for $appName
     *  - cors_enabled is false
     *
     * @param string                           $appName Human-readable name from applications.name
     * @param \Pramnos\Database\Database|null  $db      Injected connection (defaults to Factory)
     */
    public static function fromApplicationSettings(
        string $appName,
        ?\Pramnos\Database\Database $db = null
    ): self {
        try {
            $db       ??= \Pramnos\Framework\Factory::getDatabase();
            // Through the builder, which names both tables as the installation has them: the
            // prefix, and on MySQL the `applications` schema folded into the name.
            $result = $db->queryBuilder()
                ->table('applications.application_settings AS s')
                ->select(['s.cors_enabled', 's.cors_origins'])
                ->join('#PREFIX#applications AS a', 'a.appid', '=', 's.appid')
                ->where('a.name', $appName)
                ->first();
            if ($result && $result->numRows > 0) {
                // PostgreSQL hands a boolean back as 't'/'f', and (bool) 'f' is true.
                return static::fromCorsData(
                    in_array($result->fields['cors_enabled'], [true, 1, '1', 't', 'true'], true),
                    $result->fields['cors_origins'] ?? null
                );
            }
        } catch (\Throwable) {
            // DB unavailable, table missing, feature not enabled — fall back to wildcard.
        }
        return new self(['*']);
    }

    /**
     * The origins as stored: JSON on MySQL (`["https://a"]`), a `TEXT[]` on PostgreSQL, which
     * comes back as `{https://a,"https://b"}`.
     *
     * @return list<string>
     */
    private static function parseOrigins(string $raw): array
    {
        $raw = trim($raw);

        if (str_starts_with($raw, '{') && str_ends_with($raw, '}')) {
            $inner = substr($raw, 1, -1);

            return $inner === '' ? [] : array_map(
                static fn (string $one): string => stripcslashes(trim($one, '"')),
                str_getcsv($inner, ',', '"', '\\')
            );
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function handle(Request $request, callable $next): mixed
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array('*', $this->allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($origin !== '' && in_array($origin, $this->allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        header('Access-Control-Max-Age: ' . $this->maxAge);

        if ($this->allowCredentials) {
            header('Access-Control-Allow-Credentials: true');
        }

        // Preflight — answer here, do not run the action.
        if ($request->getRequestMethod() === 'OPTIONS') {
            // http_response_code() rather than a hand-written status line: the
            // literal 'HTTP/1.1' is wrong on an HTTP/2 connection, and sending a
            // status line by hand makes PHP ignore every later change to the code.
            http_response_code(204);
            return '';
        }

        return $next($request);
    }
}
