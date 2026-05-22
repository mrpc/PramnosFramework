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
 * @package    PramnosFramework
 * @subpackage Http\Middleware
 */
class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $allowedOrigins = ['*'],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'],
        private bool  $allowCredentials = false,
        private int   $maxAge = 86400
    ) {}

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
            $rawOrigins = json_decode($rawOrigins, true) ?? [];
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
            $isPostgres = ($db->type === 'postgresql');

            if ($isPostgres) {
                $sql = $db->prepareQuery(
                    "SELECT s.cors_enabled, s.cors_origins
                     FROM applications.application_settings s
                     JOIN public.applications a ON a.appid = s.appid
                     WHERE a.name = %s
                     LIMIT 1",
                    $appName
                );
            } else {
                $sql = $db->prepareQuery(
                    "SELECT s.cors_enabled, s.cors_origins
                     FROM `applications_application_settings` s
                     JOIN `applications` a ON a.appid = s.appid
                     WHERE a.name = %s
                     LIMIT 1",
                    $appName
                );
            }

            $result = $db->query($sql);
            if ($result && $result->numRows > 0) {
                return static::fromCorsData(
                    (bool) $result->fields['cors_enabled'],
                    $result->fields['cors_origins'] ?? null
                );
            }
        } catch (\Throwable) {
            // DB unavailable, table missing, feature not enabled — fall back to wildcard.
        }
        return new self(['*']);
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
            header('HTTP/1.1 204 No Content');
            return '';
        }

        return $next($request);
    }
}
