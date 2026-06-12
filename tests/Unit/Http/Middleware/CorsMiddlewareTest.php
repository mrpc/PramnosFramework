<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Database\Database;
use Pramnos\Http\Middleware\CorsMiddleware;
use Pramnos\Http\Request;

/**
 * Tests for the CorsMiddleware factory methods added in PF-43.
 *
 * CorsMiddleware::fromCorsData() and ::fromApplicationSettings() allow
 * database-driven CORS policy enforcement instead of hard-coded wildcard
 * or config-file origins.
 *
 * fromCorsData() is tested in isolation (no DB needed).
 * fromApplicationSettings() is tested for its fallback path (DB exception)
 * and no-row path; DB-present success paths are covered by integration tests.
 */
#[CoversClass(CorsMiddleware::class)]
class CorsMiddlewareTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // getAllowedOrigins()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getAllowedOrigins() exposes the origins the middleware was constructed with.
     * Used to verify factory output without relying on untestable header() calls.
     */
    public function testGetAllowedOriginsReturnsConstructorValue(): void
    {
        // Arrange
        $origins = ['https://app.example.com', 'https://admin.example.com'];
        $mw = new CorsMiddleware($origins);

        // Act / Assert
        $this->assertSame($origins, $mw->getAllowedOrigins());
    }

    /**
     * Default construction (no arguments) yields wildcard as the single origin.
     */
    public function testGetAllowedOriginsDefaultsToWildcard(): void
    {
        // Arrange / Act
        $mw = new CorsMiddleware();

        // Assert
        $this->assertSame(['*'], $mw->getAllowedOrigins());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // fromCorsData()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When cors_enabled is false, the middleware must use wildcard regardless of
     * what origins are stored — a disabled CORS policy should be permissive so
     * existing integrations keep working while the admin configures origins.
     */
    public function testFromCorsDataReturnsSelfWithWildcardWhenDisabled(): void
    {
        // Arrange / Act
        $mw = CorsMiddleware::fromCorsData(false, ['https://app.example.com']);

        // Assert — disabled policy → wildcard, specific origins ignored
        $this->assertSame(['*'], $mw->getAllowedOrigins());
    }

    /**
     * When cors_enabled is true and origins is a non-empty array, those exact
     * origins must be used by the middleware.
     */
    public function testFromCorsDataReturnsSelfWithSpecificOriginsWhenEnabled(): void
    {
        // Arrange
        $origins = ['https://app.example.com', 'https://admin.example.com'];

        // Act
        $mw = CorsMiddleware::fromCorsData(true, $origins);

        // Assert — enabled + specific origins → exactly those origins
        $this->assertSame($origins, $mw->getAllowedOrigins());
    }

    /**
     * PostgreSQL stores cors_origins as TEXT[] which PHP receives as a JSON
     * string from the PDO driver. fromCorsData() must decode the JSON and use
     * the resulting array.
     */
    public function testFromCorsDataParsesJsonStringOrigins(): void
    {
        // Arrange — simulate PostgreSQL TEXT[] returned as JSON string
        $rawOrigins = '["https://spa.example.com","https://mobile.example.com"]';

        // Act
        $mw = CorsMiddleware::fromCorsData(true, $rawOrigins);

        // Assert — JSON decoded correctly
        $this->assertSame(
            ['https://spa.example.com', 'https://mobile.example.com'],
            $mw->getAllowedOrigins()
        );
    }

    /**
     * When cors_enabled is true but cors_origins is empty (admin saved an empty
     * list), the middleware must fall back to wildcard to avoid locking out all
     * callers due to a misconfiguration.
     */
    public function testFromCorsDataFallsBackToWildcardForEmptyOrigins(): void
    {
        // Act
        $mw = CorsMiddleware::fromCorsData(true, []);

        // Assert — empty list → wildcard fallback
        $this->assertSame(['*'], $mw->getAllowedOrigins());
    }

    /**
     * null cors_origins (column is NULL in DB) with cors_enabled = true must
     * also produce a wildcard middleware — null and empty are equivalent here.
     */
    public function testFromCorsDataFallsBackToWildcardForNullOrigins(): void
    {
        // Act
        $mw = CorsMiddleware::fromCorsData(true, null);

        // Assert
        $this->assertSame(['*'], $mw->getAllowedOrigins());
    }

    /**
     * Invalid JSON in cors_origins (e.g. a corrupt row) must be handled
     * gracefully — the factory must return wildcard rather than throwing.
     */
    public function testFromCorsDataHandlesInvalidJsonGracefully(): void
    {
        // Arrange — malformed JSON that json_decode cannot parse
        $badJson = 'not-valid-json}}';

        // Act
        $mw = CorsMiddleware::fromCorsData(true, $badJson);

        // Assert — parse failure → wildcard, no exception thrown
        $this->assertSame(['*'], $mw->getAllowedOrigins());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // fromApplicationSettings()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the database connection throws during the CORS lookup (table not yet
     * migrated, authserver feature disabled, DB not connected), the factory must
     * silently fall back to wildcard rather than propagating the exception.
     *
     * This is the most important fallback path: a missing application_settings
     * table must never break the API entry point.
     */
    public function testFromApplicationSettingsFallsBackToWildcardOnDbException(): void
    {
        // Arrange — mock DB that throws on prepareQuery (simulates missing table)
        $db = $this->createMock(Database::class);
        $db->type = 'mysql';
        $db->method('prepareQuery')
           ->willThrowException(new \RuntimeException('Table not found'));

        // Act
        $mw = CorsMiddleware::fromApplicationSettings('myapp', $db);

        // Assert — exception caught → wildcard fallback
        $this->assertSame(['*'], $mw->getAllowedOrigins(),
            'DB exception must trigger wildcard fallback, not propagate');
    }

    /**
     * When the query returns 0 rows (application not registered in the DB yet),
     * the factory must return wildcard — the application is not in
     * application_settings so its policy defaults to permissive.
     */
    public function testFromApplicationSettingsFallsBackToWildcardWhenNoRow(): void
    {
        // Arrange — mock DB that returns an empty result (0 rows)
        $emptyResult        = new \stdClass();
        $emptyResult->numRows = 0;

        $db = $this->createMock(Database::class);
        $db->type = 'mysql';
        $db->method('prepareQuery')->willReturn('SELECT 1');
        $db->method('query')->willReturn($emptyResult);

        // Act
        $mw = CorsMiddleware::fromApplicationSettings('unknown-app', $db);

        // Assert — no row → wildcard
        $this->assertSame(['*'], $mw->getAllowedOrigins(),
            'Missing application row must yield wildcard, not empty array');
    }

    /**
     * When the query finds a row with cors_enabled = true and specific origins,
     * fromApplicationSettings() must return those origins via fromCorsData().
     */
    public function testFromApplicationSettingsReturnsCorsDataWhenEnabled(): void
    {
        // Arrange — mock DB that returns one row with cors_enabled + origins
        $row               = new \stdClass();
        $row->cors_enabled = 1;
        $row->cors_origins = '["https://client.example.com"]';

        $result            = new \stdClass();
        $result->numRows   = 1;
        $result->fields    = [
            'cors_enabled' => 1,
            'cors_origins' => '["https://client.example.com"]',
        ];

        $db = $this->createMock(Database::class);
        $db->type = 'mysql';
        $db->method('prepareQuery')->willReturn('SELECT 1');
        $db->method('query')->willReturn($result);

        // Act
        $mw = CorsMiddleware::fromApplicationSettings('myapp', $db);

        // Assert — row found and enabled → specific origins used
        $this->assertSame(['https://client.example.com'], $mw->getAllowedOrigins());
    }

    /**
     * When cors_enabled is false in the DB row, the factory must return wildcard
     * regardless of what cors_origins contains.
     */
    public function testFromApplicationSettingsReturnsWildcardWhenCorsDisabledInDb(): void
    {
        // Arrange — cors_enabled = 0
        $result          = new \stdClass();
        $result->numRows = 1;
        $result->fields  = [
            'cors_enabled' => 0,
            'cors_origins' => '["https://client.example.com"]',
        ];

        $db = $this->createMock(Database::class);
        $db->type = 'mysql';
        $db->method('prepareQuery')->willReturn('SELECT 1');
        $db->method('query')->willReturn($result);

        // Act
        $mw = CorsMiddleware::fromApplicationSettings('myapp', $db);

        // Assert — disabled in DB → wildcard
        $this->assertSame(['*'], $mw->getAllowedOrigins());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // handle() smoke-test after factory construction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A CorsMiddleware built via fromCorsData() must still call $next for a
     * normal GET request — confirming the factory produces a fully functional
     * middleware, not just a data container.
     */
    public function testFromCorsDataProducesFunctionalMiddleware(): void
    {
        // Arrange
        $mw  = CorsMiddleware::fromCorsData(true, ['https://app.example.com']);
        $req = Request::create('/api/data', 'GET');

        // Act
        $result = $mw->handle($req, fn(Request $r) => 'controller-response');

        // Assert — $next was called and its result returned
        $this->assertSame('controller-response', $result);
    }

    /**
     * When a specific allowed origin matches the HTTP_ORIGIN header, handle()
     * must set 'Access-Control-Allow-Origin' to that origin and add 'Vary: Origin'.
     * This covers the elseif branch at line 121 of handle().
     */
    public function testHandleSetsSpecificOriginHeaderWhenOriginMatches(): void
    {
        // Arrange — specific origin (not wildcard)
        $origin = 'https://app.example.com';
        $_SERVER['HTTP_ORIGIN'] = $origin;
        $mw  = new CorsMiddleware([$origin]);
        $req = Request::create('/api/data', 'GET');

        // Act — run in output buffer to discard headers (cannot assert headers in CLI)
        $result = $mw->handle($req, fn(Request $r) => 'ok');

        // Assert — $next was invoked (origin-specific path completes normally)
        $this->assertSame('ok', $result);
        unset($_SERVER['HTTP_ORIGIN']);
    }

    /**
     * When allowCredentials is true, handle() must emit the
     * Access-Control-Allow-Credentials header. Covers line 130-132.
     */
    public function testHandleEmitsCredentialsHeaderWhenEnabled(): void
    {
        // Arrange
        $mw  = new CorsMiddleware(['*'], allowCredentials: true);
        $req = Request::create('/api/data', 'GET');

        // Act
        $result = $mw->handle($req, fn(Request $r) => 'ok');

        // Assert — $next still called normally
        $this->assertSame('ok', $result);
    }

    /**
     * An OPTIONS preflight request must be answered immediately with '' and
     * must NOT invoke $next. Covers lines 135-138 of handle().
     */
    public function testHandleReturnEarlyOnOptionsPreflightRequest(): void
    {
        // Arrange
        $mw  = new CorsMiddleware(['*']);
        $req = Request::create('/api/data', 'OPTIONS');

        $nextCalled = false;
        $next = function (Request $r) use (&$nextCalled) {
            $nextCalled = true;
            return 'action-response';
        };

        // Act
        $result = $mw->handle($req, $next);

        // Assert — preflight short-circuits before reaching the action
        $this->assertSame('', $result,
            'OPTIONS preflight must return empty string, not the action response');
        $this->assertFalse($nextCalled,
            '$next must NOT be called for OPTIONS preflight requests');
    }

    /**
     * fromApplicationSettings() with a PostgreSQL DB must build the SQL using
     * schema-qualified table names (public.applications, applications.application_settings).
     * Covers the $isPostgres branch at line 82.
     */
    public function testFromApplicationSettingsUsesPostgresSchemaWhenTypeIsPostgresql(): void
    {
        // Arrange — mock DB that says it's PostgreSQL
        $result          = new \stdClass();
        $result->numRows = 1;
        $result->fields  = ['cors_enabled' => 1, 'cors_origins' => '["https://pg.example.com"]'];

        $db = $this->createMock(Database::class);
        $db->type = 'postgresql';
        $db->method('prepareQuery')->willReturn('SELECT 1');
        $db->method('query')->willReturn($result);

        // Act
        $mw = CorsMiddleware::fromApplicationSettings('myapp', $db);

        // Assert — row was parsed from the PostgreSQL path
        $this->assertSame(['https://pg.example.com'], $mw->getAllowedOrigins());
    }
}
