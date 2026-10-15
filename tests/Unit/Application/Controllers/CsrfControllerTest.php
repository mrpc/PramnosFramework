<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Csrf;

/**
 * `GET /Csrf` — the session's current CSRF token, for a page that has been open a while.
 *
 * It hands out the one secret a form proves itself with, so what matters is who may read it:
 * this site's pages, never another site's, and never a cache.
 */
#[CoversClass(Csrf::class)]
class CsrfControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = 'current-token';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['csrf_token'], $_SERVER['HTTP_SEC_FETCH_SITE']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        parent::tearDown();
    }

    /** Ask the controller, as the router would. */
    private function ask(): \Pramnos\Http\Response
    {
        $response = (new Csrf(\Pramnos\Application\Application::getInstance()))->display();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);

        return $response;
    }

    /**
     * A page of this site gets the session's token as JSON that nothing caches; a browser too
     * old to say where the request came from is answered the same.
     */
    public function testAPageOfThisSiteGetsTheToken(): void
    {
        // Arrange
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';

        // Act
        $response = $this->ask();

        // Assert
        $this->assertSame(200, $response->getStatusCode());
        $body    = json_decode($response->getBody(), true);
        $session = \Pramnos\Http\Session::getInstance();
        $this->assertSame('current-token', $body['token']);
        // The form token as Session::getTokenField() draws it: named by the session's token
        $this->assertSame($session->tokenParameters(), [$body['form']['name'] => $body['form']['value']]);
        $this->assertSame($session->getFingerprint(true), $body['form']['value_ip']);
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertStringStartsWith('application/json', (string) $response->getHeaderLine('Content-Type'));
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        $this->assertSame(200, $this->ask()->getStatusCode(), 'no header: answered');
    }

    /** A request the browser marks as another site's, and anything but a GET, get nothing. */
    public function testAnotherSiteAndOtherMethodsGetNothing(): void
    {
        // Arrange
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        // Act
        $crossSite = $this->ask();
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $posted = $this->ask();

        // Assert
        $this->assertSame([403, ''], [$crossSite->getStatusCode(), $crossSite->getBody()]);
        $this->assertSame([405, ''], [$posted->getStatusCode(), $posted->getBody()]);
    }
}
