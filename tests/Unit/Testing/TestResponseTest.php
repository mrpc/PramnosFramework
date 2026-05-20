<?php

declare(strict_types=1);

namespace Tests\Unit\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\AssertionFailedError;
use Pramnos\Http\Response;
use Pramnos\Testing\TestResponse;

/**
 * Unit tests for TestResponse — the fluent assertion wrapper for HTTP responses.
 *
 * TestResponse wraps a Pramnos\Http\Response and exposes PHPUnit assertions as
 * chainable methods. Tests cover:
 *  - Status assertions: assertSuccessful(), assertStatus()
 *  - Content assertions: assertSee(), assertDontSee(), assertSeeText()
 *  - JSON assertions: assertJson(), assertJsonPath() — including nested paths
 *  - DOM assertions: assertSelectorExists(), assertSelectorContains(), assertSelectorAttribute()
 *  - getResponse() accessor
 *  - Failure cases: each assertion must throw AssertionFailedError when the
 *    condition is not met so callers get actionable failure messages.
 */
#[CoversClass(TestResponse::class)]
class TestResponseTest extends TestCase
{
    // ── getResponse() ─────────────────────────────────────────────────────────

    /**
     * getResponse() must return the exact Response object that was passed
     * to the constructor — allows callers to inspect raw headers/body.
     */
    public function testGetResponseReturnsWrappedInstance(): void
    {
        // Arrange
        $response    = Response::make('body', 200);
        $testResponse = new TestResponse($response);

        // Act
        $result = $testResponse->getResponse();

        // Assert
        $this->assertSame($response, $result,
            'getResponse() must return the exact Response passed to the constructor');
    }

    // ── assertSuccessful() ────────────────────────────────────────────────────

    /**
     * assertSuccessful() must not throw for any 2xx status code and must
     * return $this for chaining.
     */
    public function testAssertSuccessfulPassesFor200(): void
    {
        // Arrange
        $response     = Response::make('OK', 200);
        $testResponse = new TestResponse($response);

        // Act + Assert — must not throw, must return $this
        $result = $testResponse->assertSuccessful();
        $this->assertSame($testResponse, $result,
            'assertSuccessful() must return $this for method chaining');
    }

    /**
     * assertSuccessful() must not throw for 201 Created — a common POST response.
     */
    public function testAssertSuccessfulPassesFor201(): void
    {
        // Arrange
        $response     = Response::make('Created', 201);
        $testResponse = new TestResponse($response);

        // Act + Assert
        $this->assertSame($testResponse, $testResponse->assertSuccessful());
    }

    /**
     * assertSuccessful() must throw AssertionFailedError for a 404 response.
     *
     * This is the critical failure-case: a consumer of a 404 page must not
     * accidentally pass a "successful" assertion.
     */
    public function testAssertSuccessfulFailsFor404(): void
    {
        // Arrange
        $response     = Response::make('Not Found', 404);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertSuccessful();
    }

    /**
     * assertSuccessful() must throw AssertionFailedError for a 500 response.
     */
    public function testAssertSuccessfulFailsFor500(): void
    {
        // Arrange
        $response     = Response::make('Error', 500);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertSuccessful();
    }

    // ── assertStatus() ───────────────────────────────────────────────────────

    /**
     * assertStatus() must not throw when the actual status matches the expected
     * value and must return $this for chaining.
     */
    public function testAssertStatusPassesOnMatch(): void
    {
        // Arrange
        $response     = Response::make('Not Found', 404);
        $testResponse = new TestResponse($response);

        // Act + Assert
        $result = $testResponse->assertStatus(404);
        $this->assertSame($testResponse, $result);
    }

    /**
     * assertStatus() must throw AssertionFailedError when the actual status
     * differs from the expected status.
     */
    public function testAssertStatusFailsOnMismatch(): void
    {
        // Arrange
        $response     = Response::make('OK', 200);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertStatus(404);
    }

    // ── assertSee() / assertDontSee() ────────────────────────────────────────

    /**
     * assertSee() must pass when the substring is present in the body and
     * return $this for chaining.
     */
    public function testAssertSeePassesWhenSubstringPresent(): void
    {
        // Arrange
        $response     = Response::make('Hello World', 200);
        $testResponse = new TestResponse($response);

        // Act + Assert
        $result = $testResponse->assertSee('World');
        $this->assertSame($testResponse, $result);
    }

    /**
     * assertSee() must throw AssertionFailedError when the substring is absent.
     */
    public function testAssertSeeFailsWhenSubstringAbsent(): void
    {
        // Arrange
        $response     = Response::make('Hello World', 200);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertSee('Goodbye');
    }

    /**
     * assertDontSee() must pass when the string is NOT in the body.
     */
    public function testAssertDontSeePassesWhenAbsent(): void
    {
        // Arrange
        $response     = Response::make('Hello World', 200);
        $testResponse = new TestResponse($response);

        // Act + Assert
        $result = $testResponse->assertDontSee('Goodbye');
        $this->assertSame($testResponse, $result);
    }

    /**
     * assertDontSee() must throw AssertionFailedError when the string IS present
     * — guards against responses that should not expose certain content.
     */
    public function testAssertDontSeeFailsWhenPresent(): void
    {
        // Arrange
        $response     = Response::make('Hello World', 200);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertDontSee('World');
    }

    // ── assertSeeText() ──────────────────────────────────────────────────────

    /**
     * assertSeeText() must strip HTML tags before checking, so that callers
     * can assert on visible text content regardless of markup structure.
     */
    public function testAssertSeeTextStripsHtmlTags(): void
    {
        // Arrange — text surrounded by HTML tags
        $response     = Response::make('<h1>Hello</h1> <p>World</p>', 200);
        $testResponse = new TestResponse($response);

        // Act + Assert — plain text must be found without tags
        $result = $testResponse->assertSeeText('Hello');
        $this->assertSame($testResponse, $result);
    }

    /**
     * assertSeeText() must throw AssertionFailedError when the text is absent
     * even after stripping HTML.
     */
    public function testAssertSeeTextFailsWhenTextAbsent(): void
    {
        // Arrange
        $response     = Response::make('<h1>Hello</h1>', 200);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertSeeText('Goodbye');
    }

    // ── assertJson() ─────────────────────────────────────────────────────────

    /**
     * assertJson() must pass when the response body is valid JSON containing
     * all the expected key-value pairs at the top level.
     */
    public function testAssertJsonPassesForMatchingSubset(): void
    {
        // Arrange
        $response     = Response::json(['name' => 'Alice', 'role' => 'admin']);
        $testResponse = new TestResponse($response);

        // Act + Assert — partial match (role is not checked)
        $result = $testResponse->assertJson(['name' => 'Alice']);
        $this->assertSame($testResponse, $result);
    }

    /**
     * assertJson() must throw AssertionFailedError when the expected key is
     * missing from the JSON response.
     */
    public function testAssertJsonFailsForMissingKey(): void
    {
        // Arrange
        $response     = Response::json(['name' => 'Alice']);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertJson(['missing_key' => 'value']);
    }

    /**
     * assertJson() must throw AssertionFailedError when the response body is
     * not valid JSON.
     */
    public function testAssertJsonFailsForNonJsonBody(): void
    {
        // Arrange
        $response     = Response::make('plain text, not JSON', 200);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertJson(['key' => 'value']);
    }

    // ── assertJsonPath() ─────────────────────────────────────────────────────

    /**
     * assertJsonPath() must resolve dot-notated paths into nested JSON
     * structures and pass when the value matches.
     */
    public function testAssertJsonPathPassesForNestedPath(): void
    {
        // Arrange — nested JSON with user.age
        $response     = Response::json(['user' => ['name' => 'Bob', 'age' => 30]]);
        $testResponse = new TestResponse($response);

        // Act + Assert
        $result = $testResponse->assertJsonPath('user.age', 30);
        $this->assertSame($testResponse, $result);
    }

    /**
     * assertJsonPath() must throw AssertionFailedError when the path exists
     * but the value does not match the expected value.
     */
    public function testAssertJsonPathFailsOnValueMismatch(): void
    {
        // Arrange
        $response     = Response::json(['user' => ['age' => 30]]);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertJsonPath('user.age', 99);
    }

    /**
     * assertJsonPath() must throw AssertionFailedError when the path does not
     * exist in the JSON response.
     */
    public function testAssertJsonPathFailsForMissingPath(): void
    {
        // Arrange
        $response     = Response::json(['user' => ['name' => 'Bob']]);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertJsonPath('user.nonexistent', 'value');
    }

    // ── DOM assertions ────────────────────────────────────────────────────────

    /**
     * assertSelectorExists() must pass when the CSS selector matches at least
     * one element, returning $this for chaining.
     *
     * Requires symfony/dom-crawler (installed as require-dev).
     */
    public function testAssertSelectorExistsPassesForPresentSelector(): void
    {
        // Arrange
        $html         = '<div class="container"><h1 id="title">Welcome</h1></div>';
        $response     = Response::make($html, 200);
        $testResponse = new TestResponse($response);

        // Act + Assert
        $result = $testResponse->assertSelectorExists('.container h1');
        $this->assertSame($testResponse, $result);
    }

    /**
     * assertSelectorExists() must throw AssertionFailedError when no element
     * matches the given CSS selector.
     */
    public function testAssertSelectorExistsFailsForAbsentSelector(): void
    {
        // Arrange
        $html         = '<div><p>No heading here</p></div>';
        $response     = Response::make($html, 200);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertSelectorExists('h1');
    }

    /**
     * assertSelectorContains() must pass when the matched element's text
     * includes the expected string.
     */
    public function testAssertSelectorContainsPassesForMatchingText(): void
    {
        // Arrange
        $html         = '<div class="container"><h1 id="title">Welcome</h1></div>';
        $response     = Response::make($html, 200);
        $testResponse = new TestResponse($response);

        // Act + Assert
        $result = $testResponse->assertSelectorContains('#title', 'Welcome');
        $this->assertSame($testResponse, $result);
    }

    /**
     * assertSelectorContains() must throw AssertionFailedError when the element
     * exists but its text does not contain the expected string.
     */
    public function testAssertSelectorContainsFailsWhenTextAbsent(): void
    {
        // Arrange
        $html         = '<h1 id="title">Hello</h1>';
        $response     = Response::make($html, 200);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertSelectorContains('#title', 'Goodbye');
    }

    /**
     * assertSelectorAttribute() must pass when the matched element's attribute
     * equals the expected value.
     */
    public function testAssertSelectorAttributePassesForMatchingAttribute(): void
    {
        // Arrange
        $html         = '<a href="/home" id="nav-link">Home</a>';
        $response     = Response::make($html, 200);
        $testResponse = new TestResponse($response);

        // Act + Assert
        $result = $testResponse->assertSelectorAttribute('#nav-link', 'href', '/home');
        $this->assertSame($testResponse, $result);
    }

    /**
     * assertSelectorAttribute() must throw AssertionFailedError when the
     * attribute value does not match the expected value.
     */
    public function testAssertSelectorAttributeFailsOnValueMismatch(): void
    {
        // Arrange
        $html         = '<a href="/home" id="link">Home</a>';
        $response     = Response::make($html, 200);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertSelectorAttribute('#link', 'href', '/about');
    }

    /**
     * assertSelectorAttribute() must throw AssertionFailedError when the
     * CSS selector matches no elements.
     */
    public function testAssertSelectorAttributeFailsForAbsentSelector(): void
    {
        // Arrange
        $html         = '<p>No links here</p>';
        $response     = Response::make($html, 200);
        $testResponse = new TestResponse($response);

        // Assert + Act
        $this->expectException(AssertionFailedError::class);
        $testResponse->assertSelectorAttribute('a', 'href', '/anything');
    }

    // ── Method chaining ───────────────────────────────────────────────────────

    /**
     * All assertions must be chainable in a single fluent expression.
     *
     * This documents the intended use-pattern: $response->assertStatus(200)->assertSee('text').
     */
    public function testAssertionsAreChainable(): void
    {
        // Arrange
        $html         = '<h1>Welcome</h1>';
        $response     = Response::make($html, 200);
        $testResponse = new TestResponse($response);

        // Act + Assert — chain multiple assertions without intermediate variables
        $testResponse
            ->assertStatus(200)
            ->assertSuccessful()
            ->assertSee('Welcome')
            ->assertDontSee('Error')
            ->assertSeeText('Welcome');
    }
}
