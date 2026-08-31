<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;

/**
 * What `calcParams()` actually does to a path — measured, not claimed.
 *
 * A **characterization** test: it records present behaviour rather than asserting desired
 * behaviour, and two of the things it records are anomalies. It exists because an application
 * migrating off its own legacy request class asked a fair question — *if the decomposition
 * differs, document it so we know what we are migrating to* — and the only honest answer to that
 * is a table somebody can diff against, produced by running the code.
 *
 * ## The rules, as they are
 *
 * `$parts = explode('/', $path)` and `$slashes = substr_count($request, '/')`, then:
 *
 * | shape | controller | action | `_option` |
 * | --- | --- | --- | --- |
 * | `module` | `module` | *empty* | — |
 * | `module/action` | `module` | `action` | — |
 * | `module/action/x` | `module` | `action` | `x` |
 * | `a/b/c/d` | `a` | `b` | — (`c => d` becomes a `$_GET` pair) |
 *
 * ## Two anomalies, recorded rather than fixed
 *
 * **1. Leftover path segments become `$_GET` keys.** `module/action/x` sets `_option = x` *and*
 * `$_GET['module'] = 'action'`, because parts 0 and 1 stay in the array that the key/value
 * pairing loop then walks. A controller reading `$_GET['jobposts']` on `/jobposts/view/479`
 * gets `'view'`.
 *
 * **2. Slashes inside the query string change the path decomposition.** `$slashes` is recounted
 * *after* the query string is appended to the path, so `?return=/account/settings` makes the
 * same path take a different branch — and the leftover key changes from `jobposts => view` to
 * `479 => null`.
 *
 * Neither is fixed here. Both decide **which page is served** for URLs already in use, so
 * changing them is a routing change for every installation and belongs in a deliberate release,
 * not in a test. What this file does is make them visible and make a change to them fail loudly.
 */
#[CoversClass(Request::class)]
class RequestPathDecompositionTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/index.php';
        Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_SERVER['REQUEST_URI'] = '/index.php';
        Request::resetInstance();
    }

    /**
     * Decompose one path and report everything it produced.
     *
     * @return array{controller: string, action: string, get: array<string, mixed>}
     */
    private function decompose(string $path, string $uri = '/index.php'): array
    {
        $_GET = [];
        $_SERVER['REQUEST_URI'] = $uri;
        Request::resetInstance();

        $request = new Request();
        $request->calcParams($path);

        return [
            'controller' => (string) $request->getController(),
            'action'     => (string) $request->getAction(),
            'get'        => $_GET,
        ];
    }

    /** A bare module names a controller and no action. */
    public function testABareModule(): void
    {
        // Act
        $result = $this->decompose('dashboard');

        // Assert
        $this->assertSame('dashboard', $result['controller']);
        $this->assertSame('', $result['action']);
        $this->assertArrayNotHasKey('_option', $result['get']);
    }

    /** Two segments are controller and action, and nothing else. */
    public function testModuleAndAction(): void
    {
        // Act
        $result = $this->decompose('jobposts/view');

        // Assert
        $this->assertSame('jobposts', $result['controller']);
        $this->assertSame('view', $result['action']);
        $this->assertSame([], $result['get'], 'two segments produce no parameters');
    }

    /**
     * Three segments put the third in `_option` — **and leave a junk key behind**.
     *
     * The first anomaly, recorded. `_option` is right and expected; `jobposts => view` is
     * neither, and a controller reading `$_GET['jobposts']` on `/jobposts/view/479` gets
     * `'view'`. Parts 0 and 1 are not removed before the key/value pairing loop walks what is
     * left.
     */
    public function testThreeSegmentsSetOptionAndAlsoLeaveAJunkKey(): void
    {
        // Act
        $result = $this->decompose('jobposts/view/479');

        // Assert — what a caller relies on
        $this->assertSame('jobposts', $result['controller']);
        $this->assertSame('view', $result['action']);
        $this->assertSame('479', $result['get']['_option'] ?? null);

        // Assert — and what it also gets, which nobody asked for
        $this->assertSame(
            'view',
            $result['get']['jobposts'] ?? null,
            'the junk key is gone — which is an improvement, and a routing change: update this '
            . 'test deliberately rather than to make it pass'
        );
    }

    /** Four segments pair the tail as key and value, and set no `_option`. */
    public function testFourSegmentsPairTheTail(): void
    {
        // Act
        $result = $this->decompose('a/b/c/d');

        // Assert
        $this->assertSame('a', $result['controller']);
        $this->assertSame('b', $result['action']);
        $this->assertSame('d', $result['get']['c'] ?? null);
        $this->assertArrayNotHasKey('_option', $result['get']);
    }

    /** An odd tail segment becomes a null-valued key, and also the `_option`. */
    public function testAnOddTailSegmentBecomesBothAKeyAndTheOption(): void
    {
        // Act
        $result = $this->decompose('a/b/c/d/e');

        // Assert
        $this->assertSame('d', $result['get']['c'] ?? null);
        // `array_key_exists`, not `??`: the value *is* null, and `??` cannot tell a null value
        // from an absent key — which is the same trap the framework's own `__isset()` docs warn
        // about, met here while writing the test.
        $this->assertArrayHasKey('e', $result['get'], 'the odd one out is a key with no value');
        $this->assertNull($result['get']['e']);
        $this->assertSame('e', $result['get']['_option'] ?? null);
    }

    /**
     * A query string with no slash changes nothing about the path.
     *
     * The control for the test below: the query string's own keys arrive, and the path
     * decomposition is identical to having no query string at all.
     */
    public function testAQueryStringWithoutSlashesDoesNotChangeThePath(): void
    {
        // Act
        $plain = $this->decompose('jobposts/view/479');
        $withQuery = $this->decompose('jobposts/view/479', '/index.php?lang=en');

        // Assert
        $this->assertSame($plain['controller'], $withQuery['controller']);
        $this->assertSame($plain['action'], $withQuery['action']);
        $this->assertSame($plain['get']['_option'], $withQuery['get']['_option']);
        $this->assertSame('en', $withQuery['get']['lang'] ?? null);
        $this->assertSame('view', $withQuery['get']['jobposts'] ?? null, 'the same junk key');
    }

    /**
     * A **slash inside the query string** changes which branch the path takes.
     *
     * The second anomaly, and the one with teeth. `$slashes` is recounted after the query string
     * is appended to the path, so a `?return=/account/settings` — an ordinary return-url
     * parameter — makes the same path decompose differently. Here the leftover key changes from
     * `jobposts => view` to `479 => null`.
     *
     * `_option` survives in this shape, which is why it is not visible in most applications. It
     * is the leftover keys that move, and code reading one of them by name reads something else.
     */
    public function testASlashInTheQueryStringChangesTheDecomposition(): void
    {
        // Act
        $plain  = $this->decompose('jobposts/view/479');
        $sliced = $this->decompose('jobposts/view/479', '/index.php?return=/account/settings');

        // Assert — the useful part is stable
        $this->assertSame('jobposts', $sliced['controller']);
        $this->assertSame('view', $sliced['action']);
        $this->assertSame('479', $sliced['get']['_option'] ?? null);

        // Assert — and the leftovers are not the same leftovers
        $this->assertArrayHasKey('jobposts', $plain['get']);
        $this->assertArrayNotHasKey(
            'jobposts',
            $sliced['get'],
            'the decomposition no longer depends on slashes in the query string — an improvement, '
            . 'and a routing change: update this test deliberately'
        );
        $this->assertArrayHasKey('479', $sliced['get']);
        $this->assertSame('/account/settings', $sliced['get']['return'] ?? null);
    }

    /** One slash is enough to move it, so this is not a rare shape. */
    public function testEvenOneSlashInTheQueryStringIsEnough(): void
    {
        // Act
        $result = $this->decompose('jobposts/view/479', '/index.php?u=/a');

        // Assert
        $this->assertArrayNotHasKey('jobposts', $result['get']);
        $this->assertArrayHasKey('479', $result['get']);
    }

    /**
     * A trailing slash is not a segment.
     *
     * `rtrim($requestParam, '/')`, so `/module/action/` and `/module/action` are the same
     * request — which is what a visitor typing a URL expects, and worth pinning because the
     * `$slashes` count is what it would otherwise change.
     */
    public function testATrailingSlashIsNotASegment(): void
    {
        // Act
        $bare    = $this->decompose('jobposts/view');
        $trailing = $this->decompose('jobposts/view/');

        // Assert
        $this->assertSame($bare['controller'], $trailing['controller']);
        $this->assertSame($bare['action'], $trailing['action']);
        $this->assertSame($bare['get'], $trailing['get']);
    }

    /**
     * Keys already in `$_GET` survive the decomposition.
     *
     * A rewrite rule, a front controller or a middleware may have put something there, and this
     * method used to empty the array outright.
     */
    public function testExistingGetKeysAreKept(): void
    {
        // Arrange
        $_GET = ['injected' => 'kept'];
        $_SERVER['REQUEST_URI'] = '/index.php';
        Request::resetInstance();

        // Act
        (new Request())->calcParams('jobposts/view');

        // Assert
        $this->assertSame('kept', $_GET['injected'] ?? null);
    }
}
