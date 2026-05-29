<?php

declare(strict_types=1);

namespace Pramnos\Http\Psr;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator as NyholmCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Creates PSR-7 `ServerRequestInterface` objects from PHP superglobals.
 *
 * Thin wrapper around `nyholm/psr7-server` (if available) or a manual
 * construction path using `nyholm/psr7`. This gives the framework a
 * canonical entry point for PSR-7 request creation without coupling
 * application code to a specific PSR-7 implementation.
 *
 * ## Usage
 *
 * ```php
 * $request = ServerRequestCreator::fromGlobals();
 * // $request is a Psr\Http\Message\ServerRequestInterface
 * ```
 *
 * @see         https://www.php-fig.org/psr/psr-7/
 */
class ServerRequestCreator
{
    /**
     * Build a PSR-7 ServerRequest from the current PHP superglobals
     * ($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES).
     *
     * @return ServerRequestInterface
     */
    public static function fromGlobals(): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        // Use nyholm/psr7-server if available (transitive dep via nyholm/psr7)
        if (class_exists(NyholmCreator::class)) {
            $creator = new NyholmCreator($factory, $factory, $factory, $factory, $factory);
            return $creator->fromGlobals();
        }

        // Manual fallback: build a minimal ServerRequest from $_SERVER
        $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri     = self::uriFromGlobals();
        $request = $factory->createServerRequest($method, $uri, $_SERVER);

        foreach (getallheaders() ?: [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if (!empty($_COOKIE)) {
            $request = $request->withCookieParams($_COOKIE);
        }
        if (!empty($_GET)) {
            $request = $request->withQueryParams($_GET);
        }
        if (!empty($_POST)) {
            $request = $request->withParsedBody($_POST);
        }
        if (!empty($_FILES)) {
            $request = $request->withUploadedFiles($_FILES);
        }

        return $request;
    }

    /**
     * Build a PSR-7 ServerRequest from an array of server params.
     * Useful in tests where superglobals are not available.
     *
     * @param  array<string,mixed> $serverParams  Replaces $_SERVER.
     * @return ServerRequestInterface
     */
    public static function fromServerParams(array $serverParams): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $method  = $serverParams['REQUEST_METHOD'] ?? 'GET';
        $uri     = self::uriFromServerParams($serverParams);
        return $factory->createServerRequest($method, $uri, $serverParams);
    }

    // -------------------------------------------------------------------------

    private static function uriFromGlobals(): string
    {
        return self::uriFromServerParams($_SERVER);
    }

    private static function uriFromServerParams(array $s): string
    {
        $scheme = (!empty($s['HTTPS']) && $s['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $s['HTTP_HOST'] ?? ($s['SERVER_NAME'] ?? 'localhost');
        $path   = $s['REQUEST_URI'] ?? '/';
        return $scheme . '://' . $host . $path;
    }
}
