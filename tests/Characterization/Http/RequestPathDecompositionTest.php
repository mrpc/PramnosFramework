<?php

declare(strict_types=1);

namespace Pramnos\Tests\Characterization\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;

/**
 * What `calcParams()` actually does to a path — measured, not claimed.
 *
 * A **characterization** test: it records behaviour rather than arguing for it, and it exists
 * because an application migrating off its own legacy request class asked a fair question — *if
 * the decomposition differs, document it so we know what we are migrating to* — and the only
 * honest answer to that is a table somebody can diff against, produced by running the code.
 *
 * Writing it down found two anomalies, and both have since been fixed. The record of what they
 * were is kept in the methods below, because the value of a characterization test is not only
 * what the code does now.
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
 * ## The two anomalies it found, now fixed
 *
 * **1. Leftover path segments became `$_GET` keys.** `module/action/x` set `_option = x` *and*
 * `$_GET['module'] = 'action'`, because only `$parts[2]` was removed before the key/value
 * pairing loop walked what was left. A controller reading `$_GET['jobposts']` on
 * `/jobposts/view/479` got `'view'`, from a key nobody put there. The other two branches
 * already removed the controller and the action; that one was the outlier.
 *
 * **2. Slashes inside the query string changed the path decomposition.** `$slashes` was
 * recounted *after* the query string had been appended to `$request`, and `$slashes` is what
 * chooses the branch. So `?return=/account/settings` moved the same path: the leftover key
 * changed from `jobposts => view` to `479 => null`. The append had no other effect —
 * `explode('?', …)` threw the query string away immediately, `$mainString[1]` is never read,
 * and `$request` is not used past that line.
 *
 * Both were routing changes, so they were made deliberately rather than in passing. The blast
 * radius, measured: **the four tests in this file and nothing else in 13,294** depended on
 * either behaviour.
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
     * Three segments put the third in `_option`, and nothing else.
     *
     * The first anomaly, fixed. It used to also set `$_GET['jobposts'] = 'view'` — only
     * `$parts[2]` was removed before the key/value pairing loop walked the rest, so the
     * controller and the action were paired into a `$_GET` entry. Code reading
     * `$_GET['jobposts']` on `/jobposts/view/479` got `'view'`, from a key nobody put there.
     */
    public function testThreeSegmentsSetOptionAndNothingElse(): void
    {
        // Act
        $result = $this->decompose('jobposts/view/479');

        // Assert
        $this->assertSame('jobposts', $result['controller']);
        $this->assertSame('view', $result['action']);
        $this->assertSame('479', $result['get']['_option'] ?? null);
        $this->assertSame(
            ['_option' => '479'],
            $result['get'],
            'a path segment leaked into $_GET as a key of its own again'
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
        $this->assertArrayNotHasKey('jobposts', $withQuery['get']);
    }

    /**
     * A slash inside the query string changes nothing about the path.
     *
     * The second anomaly, fixed, and it was the one with teeth. `$slashes` — which chooses the
     * branch — was recounted *after* the query string had been appended to `$request`, so an
     * ordinary `?return=/account/settings` decomposed the same path differently: the leftover
     * key changed from `jobposts => view` to `479 => null`.
     *
     * `_option` survived either way, which is exactly why it went unnoticed. It was the leftover
     * keys that moved, and code reading one of them by name read something else — a plausible
     * mechanism for a page that serves differently once a return-url parameter is added to it.
     */
    public function testASlashInTheQueryStringDoesNotChangeTheDecomposition(): void
    {
        // Act
        $plain  = $this->decompose('jobposts/view/479');
        $sliced = $this->decompose('jobposts/view/479', '/index.php?return=/account/settings');

        // Assert — the path decomposes identically
        $this->assertSame($plain['controller'], $sliced['controller']);
        $this->assertSame($plain['action'], $sliced['action']);
        $this->assertSame($plain['get']['_option'], $sliced['get']['_option']);

        // Assert — and the only extra key is the parameter itself
        $this->assertSame('/account/settings', $sliced['get']['return'] ?? null);
        $this->assertArrayNotHasKey('jobposts', $sliced['get']);
        $this->assertArrayNotHasKey('479', $sliced['get'], 'a segment became a key of its own');
    }

    /**
     * One slash used to be enough, so this shape is not rare.
     *
     * `?u=/a` — a single slash in a single parameter. Pinned separately from the case above
     * because the count was cumulative: the failure did not need a long return path, only one.
     */
    public function testOneSlashInTheQueryStringIsAlsoHarmless(): void
    {
        // Act
        $result = $this->decompose('jobposts/view/479', '/index.php?u=/a');

        // Assert
        $this->assertSame('479', $result['get']['_option'] ?? null);
        $this->assertSame('/a', $result['get']['u'] ?? null);
        $this->assertSame(['u', '_option'], array_keys($result['get']));
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
