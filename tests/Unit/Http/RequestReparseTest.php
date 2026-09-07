<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;

/**
 * Parsing the same request twice, which is a documented pattern rather than an abuse.
 *
 * `calcParams()` keeps what was in `$_GET` before it ran — right, and the reasoning on
 * the method stands: a front controller, a middleware, a rewrite rule or a test may have
 * put something there, and emptying the array discarded it silently.
 *
 * It was wrong about the keys **the method itself produces**. An application that
 * rewrites the request and calls it again inherited the previous parse:
 *
 * ```
 * el/parent/skipjobpost              1st: _option='skipjobpost'   2nd: 'skipjobpost'
 * el/parent/skipjobpost/anazitisi    1st: _option=null            2nd: 'anazitisi'
 * ```
 *
 * On the second parse `parent/skipjobpost` has two segments and produces no `_option` at
 * all, so the correct answer is *absent*. Instead it kept the **action's own name**, from
 * the parse where the language prefix had shifted every index by one — and an action whose
 * `_option` names itself routes to itself.
 *
 * Reported from an application that does this on **every** request: every URL carries a
 * language prefix, so the first segment is cut and the request re-parsed, and there are
 * nine such calls — one for the language, the rest for slug rewrites
 * (`oroi-xrhshs` → `page/oroi-chrisis`).
 */
#[CoversClass(Request::class)]
class RequestReparseTest extends TestCase
{
    protected function setUp(): void
    {
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        $_GET     = array();
        $_REQUEST = array();
        $_SERVER['REQUEST_URI'] = '/index.php';
        $_SERVER['PHP_SELF']    = '/index.php';
        Request::resetInstance();
    }

    /**
     * Parse a path, then re-parse each of the others, and report the final `$_GET`.
     *
     * The sequence the application runs, in one call: the URL as it arrived, then the same
     * URL with its language prefix removed. Driven through a single `Request` because that
     * is what the application has — the state that leaked is static, so a fresh object
     * would hide it either way.
     *
     * @param array<string, mixed> $before What somebody else had already put in `$_GET`
     * @return array<string, mixed>
     */
    private function parseThen(string $path, array $reparse, array $before = array()): array
    {
        $this->reset();
        $_SERVER['REQUEST_URI'] = '/' . $path;
        $_GET     = $before;
        $_REQUEST = $before;

        $request = new Request();
        $request->calcParams($path);

        foreach ($reparse as $again) {
            $request->calcParams($again);
        }

        return $_GET;
    }

    /**
     * The reported case: a two-segment path leaves no `_option` behind.
     *
     * The whole bug in one assertion. `parent/skipjobpost` is controller plus action and
     * nothing else, so `_option` must be absent — and it held the action's own name.
     */
    public function testATwoSegmentReparseLeavesNoOption(): void
    {
        // Act
        $get = $this->parseThen('el/parent/skipjobpost', array('parent/skipjobpost'));

        // Assert
        $this->assertArrayNotHasKey('_option', $get, 'the first parse\'s _option survived');
    }

    /**
     * And a three-segment path still produces one.
     *
     * The control. Clearing too much would satisfy the test above and break every URL that
     * carries a trailing parameter, which is most of them.
     */
    public function testAThreeSegmentReparseStillProducesOne(): void
    {
        // Act
        $get = $this->parseThen(
            'el/parent/skipjobpost/anazitisi^athina',
            array('parent/skipjobpost/anazitisi^athina')
        );

        // Assert
        $this->assertSame('anazitisi^athina', $get['_option'] ?? null);
    }

    /**
     * The path pairs go too, not only `_option`.
     *
     * `a/b/c/d` writes `$_GET['c'] = 'd'`, named by the URL — so "the keys this method
     * produces" is not a fixed list and cannot be a pattern. They are recorded as they are
     * written, which is the only way to take back exactly what was put there.
     */
    public function testThePathPairsAreClearedToo(): void
    {
        // Arrange — the first parse's own output, verified rather than assumed: with the
        // language prefix shifting every index, `el/jobs/list/filter/new` pairs
        // `list => filter`, sets `new => null` and `_option = 'new'`.
        $first = $this->parseThen('el/jobs/list/filter/new', array());

        $this->assertSame('filter', $first['list'] ?? null, 'the fixture does not pair anything');
        $this->assertSame('new', $first['_option'] ?? null);

        // Act — re-parsed without the prefix, which has two segments and produces nothing
        $get = $this->parseThen('el/jobs/list/filter/new', array('jobs/list'));

        // Assert
        $this->assertArrayNotHasKey('list', $get, 'a pair from the first parse survived');
        $this->assertArrayNotHasKey('new', $get);
        $this->assertArrayNotHasKey('_option', $get);
    }

    /**
     * A pair whose value changes takes the new value, not the old.
     *
     * The subtler half: the loop only writes a key when `!isset($_GET[$varname])`, so a
     * surviving pair does not merely linger — it **blocks** the new value from being
     * written. Without the clear this answers `new` on a request for `old`.
     */
    public function testAPairIsRewrittenRatherThanBlocked(): void
    {
        // Act — the same shape twice, which is what a slug rewrite produces: `page/view/k/v`
        // resolved to a different `v`
        $get = $this->parseThen('page/view/k/first', array('page/view/k/second'));

        // Assert
        $this->assertSame('second', $get['k'] ?? null, 'the stale pair blocked the new value');
        $this->assertSame('second', $_REQUEST['k'] ?? null, '$_REQUEST kept the stale value');
    }

    /**
     * What somebody else put in `$_GET` is still kept, across both parses.
     *
     * The behaviour this must not undo, and the reason the fix is a recorded list rather
     * than `$_GET = array()`: a front controller, a middleware, a rewrite rule and a test
     * all write keys here, and discarding them silently is the bug the keeping was for.
     */
    public function testForeignKeysSurviveBothParses(): void
    {
        // Act
        $get = $this->parseThen(
            'el/parent/skipjobpost',
            array('parent/skipjobpost'),
            array('mine' => 'keep-me', 'utm_source' => 'newsletter')
        );

        // Assert
        $this->assertSame('keep-me', $get['mine'] ?? null);
        $this->assertSame('newsletter', $get['utm_source'] ?? null);
    }

    /**
     * A `_option` in the query string is the caller's, and outlives the clear.
     *
     * It is cleared before the query string is merged, so `?_option=x` wins — as it did
     * before, and as it should: that one was written by whoever made the request.
     */
    public function testAnOptionFromTheQueryStringIsNotTakenAway(): void
    {
        // Arrange
        $this->reset();
        $_SERVER['REQUEST_URI'] = '/el/parent/skipjobpost?_option=mine';

        $request = new Request();
        $request->calcParams('el/parent/skipjobpost');

        // Act
        $_SERVER['REQUEST_URI'] = '/parent/skipjobpost?_option=mine';
        $request->calcParams('parent/skipjobpost');

        // Assert
        $this->assertSame('mine', $_GET['_option'] ?? null);
    }

    /**
     * `resetInstance()` forgets the record, so one test cannot clear another's keys.
     *
     * The list is static, like `$action` and `$_controller` beside it. A suite is one
     * process for thousands of requests, and a static that survives a reset is how a test
     * comes to depend on the one before it.
     */
    public function testResettingForgetsWhatWasDerived(): void
    {
        // Arrange — `list` is a key the first parse derives, so it is on the record
        $first = $this->parseThen('el/jobs/list/filter/new', array());
        $this->assertSame('filter', $first['list'] ?? null, 'the fixture derives nothing');

        // Act — a reset, then somebody else's key of the same name
        Request::resetInstance();
        $_GET     = array('list' => 'not-mine');
        $_REQUEST = array('list' => 'not-mine');

        $_SERVER['REQUEST_URI'] = '/jobs/list';
        (new Request())->calcParams('jobs/list');

        // Assert
        $this->assertSame('not-mine', $_GET['list'] ?? null, 'a reset instance cleared a foreign key');
    }
}
