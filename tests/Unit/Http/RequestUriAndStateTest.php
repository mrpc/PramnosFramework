<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;

/**
 * Three things `Request` got wrong about state it did not own.
 *
 * All three were found the same way: a consuming application patched them in its
 * own `vendor/mrpc/pramnosframework/` directory rather than filing them, and the
 * patch was noticed while checking two *other* findings that turned out to be
 * that same local patch read back as though it were upstream.
 *
 * **1. The subdirectory strip assumed PHP_SELF is a web path.** The constructor
 * cut `strlen(dirname($_SERVER['PHP_SELF']))` characters off the front of the
 * URI unconditionally. Under the CLI — a console command, a daemon, a test
 * runner — PHP_SELF is a filesystem path: under PHPUnit it is
 * `…/vendor/bin/phpunit`, whose dirname is 23 characters, so every URI lost its
 * first 23. **This repository's own routing tests work around it**, pinning
 * `PHP_SELF` to `/index.php` with a comment explaining why — a workaround
 * written twice, in two repositories, before anyone called it a bug.
 *
 * **2. `parse_str($query, $_GET)` replaced the array** rather than merging into
 * it, discarding anything a front controller, a middleware, a rewrite or a test
 * had put there first.
 *
 * **3. `getInstance()` held its instance in a function-static**, which nothing
 * outside the method can clear — so a process could only ever have one request,
 * and a suite could not start a second.
 */
#[CoversClass(Request::class)]
class RequestUriAndStateTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $server = [];

    /** @var array<string,mixed> */
    private array $get = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get    = $_GET;
        Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET    = $this->get;
        Request::resetInstance();
    }

    /**
     * Build a request for a URI, with PHP_SELF as the environment would set it.
     */
    private function requestFor(string $uri, string $phpSelf): Request
    {
        $_SERVER['REQUEST_URI']    = $uri;
        $_SERVER['PHP_SELF']       = $phpSelf;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Request::resetInstance();

        return new Request();
    }

    // ── 1. The subdirectory strip ───────────────────────────────────────────

    /**
     * The URI survives a PHP_SELF that is not a web path.
     *
     * These are the shapes the CLI and a test runner actually produce, and each
     * one used to eat as many characters off the front of the path as its
     * dirname is long.
     *
     * The reversal that reddens this: strip
     * `strlen(dirname($_SERVER['PHP_SELF']))` unconditionally again.
     *
     * @return array<string,array{string}>
     */
    public static function nonWebPhpSelfProvider(): array
    {
        return [
            'phpunit'          => ['/var/www/html/vendor/bin/phpunit'],
            'a console command' => ['/var/www/html/bin/pramnos'],
            'a relative path'  => ['worker.php'],
            'a bare script'    => ['phpunit'],
        ];
    }

    /**
     * @param string $phpSelf What the environment reports as PHP_SELF.
     */
    #[DataProvider('nonWebPhpSelfProvider')]
    public function testTheUriIsIntactWhenPhpSelfIsNotAWebPath(string $phpSelf): void
    {
        // Act
        $request = $this->requestFor('/api/stations/signup', $phpSelf);

        // Assert
        $this->assertSame('api/stations/signup', $request->getRequestUri());
    }

    /**
     * The case the strip exists for still works: an application served from a
     * subdirectory has that directory removed.
     *
     * Asserted so the fix reads as "strip when it really is a prefix" rather
     * than "stop stripping" — the feature has a job, it was just doing it
     * unconditionally.
     */
    public function testASubdirectoryIsStillStripped(): void
    {
        // Act — the app lives at /myapp, so /myapp/stations is /stations.
        $request = $this->requestFor('/myapp/stations/7', '/myapp/index.php');

        // Assert
        $this->assertSame('stations/7', $request->getRequestUri());
    }

    /**
     * An application at the web root is unaffected.
     *
     * `dirname('/index.php')` is `/`, which is not a prefix of `/stations` in
     * the `directory . '/'` sense — and the leading slash is trimmed anyway.
     */
    public function testAnApplicationAtTheWebRootIsUnaffected(): void
    {
        // Act
        $request = $this->requestFor('/stations/7', '/index.php');

        // Assert
        $this->assertSame('stations/7', $request->getRequestUri());
    }

    /**
     * A directory that merely *looks* like a prefix is not one.
     *
     * `/myapplication/x` starts with the characters of `/myapp`, and a
     * `strlen()`-based strip would cut them. The rule compares against
     * `directory . '/'`, so it does not.
     */
    public function testAPartialNameMatchIsNotASubdirectory(): void
    {
        // Act
        $request = $this->requestFor('/myapplication/stations', '/myapp/index.php');

        // Assert
        $this->assertSame('myapplication/stations', $request->getRequestUri());
    }

    /**
     * A request that is exactly the subdirectory resolves to the root, not to a
     * negative offset.
     */
    public function testTheSubdirectoryItselfResolvesToTheRoot(): void
    {
        // Act
        $request = $this->requestFor('/myapp/', '/myapp/index.php');

        // Assert
        $this->assertSame('', $request->getRequestUri());
    }

    // ── 2. $_GET ────────────────────────────────────────────────────────────

    /**
     * The query string is merged into `$_GET`, not written over it.
     *
     * `parse_str($query, $_GET)` replaces the array. Usually a no-op, because
     * `$_GET` already holds the parsed query — but anything a front controller,
     * a middleware or a rewrite put there first was discarded, silently.
     *
     * The reversal: pass `$_GET` to `parse_str()` again as the output array.
     */
    public function testValuesAlreadyInGetSurviveTheQueryStringParse(): void
    {
        // Arrange — something upstream injected a value.
        $_SERVER['REQUEST_URI']    = '/stations?page=2';
        $_SERVER['PHP_SELF']       = '/index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['injected_by_rewrite' => 'kept', 'page' => '1'];
        Request::resetInstance();

        // Act — calcParams() runs when `r` is present, which is the front
        // controller's own routing parameter.
        $_GET['r'] = 'stations';
        new Request();

        // Assert — the injected value survived …
        $this->assertArrayHasKey('injected_by_rewrite', $_GET);
        $this->assertSame('kept', $_GET['injected_by_rewrite']);
        // … and the query string still wins on a key it defines.
        $this->assertSame('2', $_GET['page']);
    }

    // ── 3. The instance ─────────────────────────────────────────────────────

    /**
     * `getInstance()` can be reset, so a process can serve a second request.
     *
     * It used to hold the instance in a function-static, which nothing outside
     * the method can reach — so a suite that exercises routing or input got the
     * first request it ever built, for the whole run.
     */
    public function testTheSharedInstanceCanBeReset(): void
    {
        // Arrange
        $_SERVER['REQUEST_URI'] = '/first';
        $_SERVER['PHP_SELF']    = '/index.php';
        Request::resetInstance();
        $first = Request::getInstance();

        // Assert the premise: it is shared.
        $this->assertSame($first, Request::getInstance());

        // Act
        Request::resetInstance();
        $_SERVER['REQUEST_URI'] = '/second';
        $second = Request::getInstance();

        // Assert
        $this->assertNotSame($first, $second);
        $this->assertSame('second', $second->getRequestUri());
    }

    /**
     * The reset clears the derived state too.
     *
     * Leaving `$requestUri` behind would hand the next request the previous
     * one's address — the failure the reset exists to prevent, arriving one step
     * later. Asserted on the static directly, before anything rebuilds it.
     */
    public function testTheResetClearsTheDerivedState(): void
    {
        // Arrange
        $this->requestFor('/stations/7', '/index.php');
        $this->assertSame('stations/7', Request::$requestUri);

        // Act
        Request::resetInstance();

        // Assert
        $this->assertSame('', Request::$requestUri);
        $this->assertSame('GET', Request::$requestMethod);
        $this->assertSame([], Request::$putData);
    }

    /**
     * The reset lets the next request read its own flash.
     *
     * The flash bag is captured once per request and the session keys are unset
     * as they are read, so the captured copy left behind answered for the next
     * request too — with the previous request's already-consumed contents.
     *
     * One process, one request hides it entirely. Anything serving more than one
     * — a worker, a daemon, a test making two requests — got a flash mechanism
     * that worked once and was silently dead afterwards: `addMessage()` wrote to
     * the session, the redirect landed, and the page rendered without the
     * message. Which is the shape of "the save worked and said nothing".
     */
    public function testTheNextRequestReadsItsOwnFlash(): void
    {
        // Arrange — one request reads an empty bag, which is what caches it
        $_SESSION = [];
        $this->requestFor('/applications/edit/7', '/index.php');
        $this->assertSame([], Request::getInstance()->messages());

        // Act — the next request arrives with a flash waiting for it
        Request::resetInstance();
        $_SESSION['_messages'] = ['A new secret has been generated.'];
        $_SESSION['_errors'] = ['A name is required.'];
        $this->requestFor('/applications/edit/7', '/index.php');

        // Assert
        $this->assertSame(['A new secret has been generated.'],
            Request::getInstance()->messages());
        $this->assertSame(['A name is required.'],
            Request::getInstance()->flashErrors());

        // Cleanup
        Request::resetInstance();
        $_SESSION = [];
    }

    /**
     * A path with no second segment leaves no action behind from the last one.
     *
     * `self::$action` is written only when there **is** a second segment, so a re-route to a
     * bare module used to inherit the previous call's action — and `getAction()` answered with
     * it. One request per process hides that in production; a suite is one process for thousands
     * of requests, which is where it was found.
     *
     * The consequence is not cosmetic. A controller that reads the action as an identifying
     * value — a hash in `/updatemailsettings/<hash>` — terminates with "Invalid User" when it
     * inherits an unrelated one, so the page fails for a reason that has nothing to do with the
     * request being made.
     */
    public function testARerouteToABareModuleDoesNotInheritTheOldAction(): void
    {
        // Arrange — a first route with an action.
        Request::resetInstance();
        $_SERVER['REQUEST_URI'] = '/index.php?r=users/edit';
        $request = new Request();
        $request->calcParams('users/edit');
        $this->assertSame('edit', $request->getAction(), 'precondition: the action was read');

        // Act — a second route to a module with no action at all.
        $request->calcParams('dashboard');

        // Assert
        $this->assertSame('dashboard', $request->getController());
        $this->assertSame(
            '',
            $request->getAction(),
            'the previous route\'s action survived, so this module answers as that one'
        );
    }

    /**
     * The controller and the action are cleared together, which is the invariant.
     *
     * Stated as one assertion because the bug was the asymmetry: the controller was cleared and
     * the action was not, inside the same six lines. Anything that clears one and not the other
     * is the same defect again under a different name.
     */
    public function testTheControllerAndTheActionAreClearedTogether(): void
    {
        // Arrange
        Request::resetInstance();
        $_SERVER['REQUEST_URI'] = '/index.php?r=alpha/beta';
        $request = new Request();
        $request->calcParams('alpha/beta');

        // Act — a route that names neither.
        $request->calcParams('');

        // Assert
        $this->assertSame('', $request->getController());
        $this->assertSame('', $request->getAction());
    }
}
