<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;

/**
 * Reading the body of a request, whatever method it used.
 *
 * This exists because of three bugs shipped in one consuming application, all the
 * same bug: PHP populates `$_POST` for **POST only**, so a handler reading `$_POST`
 * under DELETE finds nothing, and nothing anywhere says so. Banning worked and
 * unbanning was impossible; an endpoint worked over POST and failed over DELETE on
 * the same route; a third accepted JSON and refused the form-encoded body every
 * other endpoint used.
 *
 * All three passed their unit tests, because **a test that seeds `$_POST` for a
 * DELETE proves nothing** — it constructs a state no real request can produce. They
 * were found with curl. So these tests set a raw body and a method, the way a
 * request does, and never seed a superglobal the server would not have filled.
 *
 * Two more traps are covered because both were hit while *fixing* the above:
 *
 *   - `parse_str` over a JSON body yields a **garbled but non-empty** array, so a
 *     handler that falls back on `empty()` never reaches its fallback;
 *   - the captured request method is stale anywhere the method is set after the
 *     singleton was built, which is every test — a fix that passed over HTTP
 *     failed under PHPUnit for exactly that reason.
 *
 * Separate processes: Request keeps the method and both body stores in statics.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(Request::class)]
class RequestBodyTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['HTTP_HOST']   = 'localhost';
        $_SERVER['REQUEST_URI'] = '/api/1.0/things/42';
        $_GET    = [];
        $_POST   = [];
        Request::setRawInput(null);
    }

    protected function tearDown(): void
    {
        Request::setRawInput(null);
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    /**
     * Build a request the way the server would: a method and a raw body.
     *
     * @param  string $method
     * @param  string $body
     * @param  string $contentType
     * @return Request
     */
    private function incoming(string $method, string $body, string $contentType = ''): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        if ($contentType !== '') {
            $_SERVER['CONTENT_TYPE'] = $contentType;
        }
        Request::setRawInput($body);

        return new Request();
    }

    /**
     * A form-encoded DELETE body is readable.
     *
     * The unban case: the browser sends `id=7`, PHP fills nothing, and until now
     * the only way to reach it was to know that `Request::$deleteData` exists.
     */
    public function testAFormEncodedDeleteBodyIsReadable(): void
    {
        // Arrange
        $request = $this->incoming('DELETE', 'id=7&reason=mistake');

        // Act
        $body = $request->body();

        // Assert
        $this->assertSame(['id' => '7', 'reason' => 'mistake'], $body);
        $this->assertSame('7', $request->bodyValue('id'));
    }

    /**
     * A JSON DELETE body is decoded, not run through parse_str.
     *
     * This is the trap that broke every JSON caller of an endpoint inside the hour
     * its form-encoded case was fixed: `parse_str('{"id":7}', $out)` produces
     * `['{"id":7}' => '']` — non-empty, so an `empty()` fallback never fires, and
     * nonsense, so nothing reads correctly either.
     */
    public function testAJsonDeleteBodyIsDecoded(): void
    {
        // Arrange
        $request = $this->incoming('DELETE', '{"id":7,"reason":"mistake"}', 'application/json');

        // Act
        $body = $request->body();

        // Assert
        $this->assertSame(['id' => 7, 'reason' => 'mistake'], $body);
        // And nothing resembling the parse_str result survives
        $this->assertArrayNotHasKey('{"id":7,"reason":"mistake"}', $body);
    }

    /**
     * A JSON body with no content type declared is still decoded.
     *
     * A hand-written `curl` call and more than one HTTP client omit it. The body
     * itself is unambiguous, so the sniff is worth having.
     */
    public function testAJsonBodyIsDetectedWithoutAContentType(): void
    {
        // Arrange
        $request = $this->incoming('DELETE', '  {"id":9}  ');

        // Act & Assert
        $this->assertSame(['id' => 9], $request->body());
    }

    /**
     * The live request method is what decides, not the one captured at construction.
     *
     * `allCurrent()` answers from the method the singleton was built with, which is
     * correct in production and stale in every test — and a fix that relies on it
     * passes over HTTP and fails under PHPUnit. `body()` reads the method as it is
     * now, so both worlds agree.
     */
    public function testTheLiveMethodDecidesRatherThanTheCapturedOne(): void
    {
        // Arrange — built as a GET, as a test harness or a bootstrap would
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();

        // Act — and only then does the method become a DELETE with a body
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        Request::setRawInput('id=7');

        // Assert
        $this->assertSame(['id' => '7'], $request->body());
    }

    /**
     * A PATCH body is readable too, and it never was before.
     *
     * PHP fills nothing for PATCH either, and unlike DELETE there was not even a
     * store to look in — `all('PATCH')` falls through to `$_REQUEST`.
     */
    public function testAPatchBodyIsReadable(): void
    {
        // Arrange
        $request = $this->incoming('PATCH', '{"name":"new"}', 'application/json');

        // Act & Assert
        $this->assertSame(['name' => 'new'], $request->body());
    }

    /**
     * A PATCH body is found even when the method arrived after construction.
     *
     * The same stale-method trap as DELETE, and worth asserting separately because
     * PATCH has no store filled by anything else: if the lazy decode did not run,
     * the answer would be an empty array rather than a wrong one.
     */
    public function testAPatchBodyIsDecodedOnDemand(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $request = new Request();

        // Act
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        Request::setRawInput('name=new');

        // Assert
        $this->assertSame(['name' => 'new'], $request->body());
        $this->assertSame(['name' => 'new'], $request->all('PATCH'), 'and all() agrees');
    }

    /**
     * A HEAD request carries its query, like the GET it mirrors.
     *
     * HEAD has no body by definition, so answering with the query is the only
     * useful answer — and falling through to a body decode would try to read one
     * that cannot exist.
     */
    public function testAHeadRequestAnswersWithItsQuery(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $_GET = ['page' => '3'];
        $request = new Request();

        // Act & Assert
        $this->assertSame(['page' => '3'], $request->body());
    }

    /**
     * A method nobody planned for still gets its body read.
     *
     * WebDAV verbs, a proxied `REPORT`, anything an application chooses to route:
     * `$_REQUEST` is empty for all of them, so decoding the body is the only
     * answer that can be right.
     */
    public function testAnUnusualMethodStillHasItsBodyDecoded(): void
    {
        // Arrange
        $request = $this->incoming('REPORT', '{"depth":1}', 'application/json');

        // Act & Assert
        $this->assertSame(['depth' => 1], $request->body());
    }

    /**
     * A nested JSON body stays arrays all the way down.
     *
     * The regression this is here for: `(array) json_decode($raw)` casts only the
     * top level, so every nested value stayed an `stdClass`. A handler iterating a
     * nested list and checking `is_array($row)` rejected the whole payload, and
     * answered `200 {"success":true,"imported":0,"invalid":1}` — a success status,
     * nothing imported, and the only evidence a counter nobody reads. It had worked
     * as a standalone script calling `json_decode($raw, true)` itself; moving it
     * onto the framework's parsing is what broke it.
     */
    public function testANestedJsonPostBodyIsFullyDecoded(): void
    {
        // Arrange
        $request = $this->incoming(
            'POST',
            '{"fake_users":[{"nickname":"a"},{"nickname":"b"}]}',
            'application/json'
        );

        // Act
        $body = $request->body();

        // Assert — the list, and every row in it
        $this->assertIsArray($body['fake_users']);
        $this->assertIsArray($body['fake_users'][0], 'a nested row must not be an stdClass');
        $this->assertSame('a', $body['fake_users'][0]['nickname']);
        // And the same through the superglobal the constructor merges into, because
        // that is what existing handlers read
        $this->assertIsArray($_POST['fake_users'][1]);
    }

    /**
     * A nested JSON PUT body is fully decoded as well.
     *
     * The same cast was in the PUT branch, and `$putData` is what `all('PUT')`
     * returns — so a handler reading it saw objects inside arrays.
     */
    public function testANestedJsonPutBodyIsFullyDecoded(): void
    {
        // Arrange
        $request = $this->incoming('PUT', '{"tags":[{"id":1}]}', 'application/json');

        // Act
        $body = $request->body();

        // Assert
        $this->assertIsArray($body['tags'][0]);
        $this->assertSame(1, $body['tags'][0]['id']);
        $this->assertSame($body, $request->all('PUT'), 'the documented accessor agrees');
    }

    /**
     * A POST form body is read from `$_POST`, which is the one method PHP fills.
     */
    public function testAPostFormBodyComesFromThePostSuperglobal(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['username' => 'admin'];
        $request = new Request();

        // Act & Assert
        $this->assertSame(['username' => 'admin'], $request->body());
    }

    /**
     * On a GET the query string *is* the input.
     *
     * Returning an empty array instead would be technically right and useless: a
     * caller asking for "what did this request carry" means the query there.
     */
    public function testOnAGetTheQueryStringIsTheBody(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['page' => '2'];
        $request = new Request();

        // Act & Assert
        $this->assertSame(['page' => '2'], $request->body());
    }

    /**
     * An empty body is an empty array, and asking for a value gives the default.
     */
    public function testAnEmptyBodyIsAnEmptyArray(): void
    {
        // Arrange
        $request = $this->incoming('DELETE', '');

        // Act & Assert
        $this->assertSame([], $request->body());
        $this->assertSame('fallback', $request->bodyValue('id', 'fallback'));
        $this->assertNull($request->bodyValue('id'));
    }

    /**
     * A body that announces JSON and is not valid JSON yields nothing.
     *
     * Deliberately not parse_str: a caller that declared JSON and sent something
     * else has made a mistake, and inventing one nonsense key from it is how the
     * garbled-parse_str trap was created in the first place.
     */
    public function testAnInvalidJsonBodyYieldsNothingRatherThanNonsense(): void
    {
        // Arrange
        $request = $this->incoming('DELETE', '{not json at all', 'application/json');

        // Act
        $body = $request->body();

        // Assert
        $this->assertSame([], $body);
    }

    /**
     * A JSON body that decodes to a scalar is not a body of named values.
     *
     * `"7"` is valid JSON and carries no keys. An array is what every caller of
     * this method expects, so a scalar produces an empty one rather than a
     * surprise shape.
     */
    public function testAScalarJsonBodyProducesNoValues(): void
    {
        // Arrange
        $request = $this->incoming('PUT', '7', 'application/json');

        // Act & Assert
        $this->assertSame([], $request->body());
    }

    /**
     * `body()` does not depend on being called before anything else.
     *
     * The stores are filled by the constructor and reused, so a second call costs
     * nothing and cannot disagree with the first — a body read twice with two
     * different answers is the kind of thing that gets blamed on the caller.
     */
    public function testReadingTheBodyTwiceGivesTheSameAnswer(): void
    {
        // Arrange
        $request = $this->incoming('DELETE', 'id=7');

        // Act
        $first  = $request->body();
        $second = $request->body();

        // Assert
        $this->assertSame($first, $second);
    }
}
