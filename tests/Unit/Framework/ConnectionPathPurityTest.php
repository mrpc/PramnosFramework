<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework;

use PHPUnit\Framework\TestCase;

/**
 * Nothing on the connection-establishment path resolves application config.
 *
 * WHAT: `Database::connect()` and everything it calls must not ask the
 *       application for a setting.
 * WHY:  it did, and the failure was almost unreadable. `setApplicationContext()`
 *       — which runs while the PostgreSQL connection is being set up — called
 *       `Request::clientIp()`, which reads the trusted-proxy list from the
 *       application, which can pull in `Settings`, which queries the database.
 *       Querying the database from inside the code that is still opening the
 *       database surfaced as a MySQL-quoted statement arriving at PostgreSQL:
 *
 *           ERROR: syntax error at or near ","
 *           LINE 1: select `setting`, `value` from `settings`
 *
 *       Backticks untranslated and `#PREFIX#` empty — the signature of a query
 *       issued on a connection that did not yet know its own driver.
 *
 * This is checked by reading the source rather than by executing, because the
 * bug is structural: the call is wrong even on the runs where it happens to
 * work. It is the same technique as {@see SilentFailureTest} — make the shape
 * expensive rather than chase the symptom.
 *
 * The same mistake was made twice in one change, the other time in
 * `Session::getFingerprint()`, so the class of error is worth a guard of its
 * own rather than a note on one method.
 */
class ConnectionPathPurityTest extends TestCase
{
    /**
     * Methods that run while a connection is being established.
     *
     * Each is a place where the database is not yet usable, so anything that
     * might reach `Settings` — which queries the database — is a cycle.
     *
     * @var list<string>
     */
    private const CONNECTION_PATH_METHODS = [
        'setTrackingInfo',
    ];

    /**
     * Calls that reach application configuration, directly or eventually.
     *
     * `Request::clientIp()` is here because it consults the trusted-proxy list;
     * `Settings::getSetting()` because it is the thing that issues the query.
     *
     * @var list<string>
     */
    private const CONFIG_LOOKUPS = [
        'Request::clientIp(',
        'Settings::getSetting(',
        'Application::getInstance(',
    ];

    /**
     * The connection path stays free of configuration lookups.
     *
     * The assertion names the offending method and call so the next person to
     * add one is told exactly what they did, rather than discovering it as a
     * syntax error from the wrong dialect three layers away.
     */
    public function testConnectionEstablishmentDoesNotResolveConfiguration(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Database/Database.php'
        );
        $offenders = [];

        // Act
        foreach (self::CONNECTION_PATH_METHODS as $method) {
            $body = $this->methodBody($source, $method);
            $this->assertNotNull($body, "precondition: {$method}() still exists");

            foreach (self::CONFIG_LOOKUPS as $lookup) {
                // A mention inside a comment is the explanation of why the call
                // is absent, which is exactly what we want to keep.
                $code = (string) preg_replace('#//[^\n]*#', '', $body);
                $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

                if (str_contains($code, $lookup)) {
                    $offenders[] = $method . '() calls ' . $lookup . ')';
                }
            }
        }

        // Assert
        $this->assertSame(
            [],
            $offenders,
            "These run while the connection is being opened, and resolving "
            . "configuration there can query the database through the very "
            . "connection being established:\n  " . implode("\n  ", $offenders)
        );
    }

    /**
     * The body of a method, by brace matching from its declaration.
     *
     * Crude but sufficient: the question is only which calls appear inside one
     * known method, and a real parser would be a dependency for no extra
     * accuracy here.
     */
    private function methodBody(string $source, string $method): ?string
    {
        if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $open = strpos($source, '{', $m[0][1]);
        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return null;
    }
}
