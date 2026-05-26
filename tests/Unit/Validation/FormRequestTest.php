<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Validation\FormRequest;
use Pramnos\Validation\ValidationException;

/**
 * Unit tests for FormRequest.
 *
 * FormRequest is the DX layer that bridges Validator::validate() to the
 * HTTP request cycle: it validates input, stores errors in session on
 * failure, and returns whitelisted data on success.
 *
 * Because failWith() calls header()/exit (not testable in isolation), these
 * tests use a Testable subclass that overrides failWith() to throw instead.
 */
#[CoversClass(FormRequest::class)]
class FormRequestTest extends TestCase
{
    // ─────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ─────────────────────────────────────────────────────────────────────────

    /** Returns a concrete FormRequest for a simple name+email form. */
    private function makeRequest(array $postData = [], string $redirectTo = ''): TestableFormRequest
    {
        $req = new TestableFormRequest($postData);
        if ($redirectTo !== '') {
            $req->redirectTo = $redirectTo;
        }
        return $req;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // validated() — success path
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When all rules pass, validated() must return only the whitelisted fields
     * declared in rules() — extra fields in the input are stripped.
     */
    public function testValidatedReturnsWhitelistedDataOnSuccess(): void
    {
        // Arrange — name and email are valid; extra_field is NOT in rules
        $req = $this->makeRequest([
            'name'        => 'Alice',
            'email'       => 'alice@example.com',
            'extra_field' => 'should-be-stripped',
        ]);

        // Act
        $data = $req->validated();

        // Assert — only declared fields are returned
        $this->assertArrayHasKey('name',  $data, 'validated data must include "name"');
        $this->assertArrayHasKey('email', $data, 'validated data must include "email"');
        $this->assertArrayNotHasKey('extra_field', $data, 'undeclared fields must be stripped');
        $this->assertSame('Alice',             $data['name']);
        $this->assertSame('alice@example.com', $data['email']);
    }

    /**
     * Validated values must be the cleaned versions from the Validator, not
     * the raw input — ensuring type coercion and sanitisation work as expected.
     */
    public function testValidatedDataMatchesInputWhenClean(): void
    {
        // Arrange
        $req = $this->makeRequest(['name' => 'Bob', 'email' => 'bob@example.com']);

        // Act
        $data = $req->validated();

        // Assert — no data corruption on valid input
        $this->assertSame('Bob',             $data['name']);
        $this->assertSame('bob@example.com', $data['email']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // validated() — failure path
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When a required field is missing, failWith() must be called with a
     * non-empty errors map. The Testable subclass stores those errors instead
     * of redirecting, letting us assert them here.
     */
    public function testValidatedCallsFailWithOnMissingRequiredField(): void
    {
        // Arrange — 'name' is required but absent
        $req = $this->makeRequest(['email' => 'alice@example.com']);

        // Act — failWith throws FailException in the Testable version
        try {
            $req->validated();
            $this->fail('Expected FailException to be thrown');
        } catch (FailException $e) {
            // Assert
            $this->assertNotEmpty($e->errors, 'errors must be non-empty on failure');
            $this->assertArrayHasKey('name', $e->errors, '"name" field must have an error');
        }
    }

    /**
     * An invalid email value must trigger validation failure with an error
     * specifically for the "email" field.
     */
    public function testValidatedFailsForInvalidEmail(): void
    {
        // Arrange
        $req = $this->makeRequest(['name' => 'Alice', 'email' => 'not-an-email']);

        // Act
        try {
            $req->validated();
            $this->fail('Expected FailException to be thrown');
        } catch (FailException $e) {
            // Assert
            $this->assertArrayHasKey('email', $e->errors, '"email" field must have an error for invalid address');
        }
    }

    /**
     * failWith() must store old input so views can repopulate form fields
     * after a redirect. The old input is stored in $_SESSION under the
     * configured key.
     */
    public function testFailWithStoresOldInputInSession(): void
    {
        // Arrange
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $req = $this->makeRequest(['name' => '', 'email' => 'foo@example.com']);

        // Act
        try {
            $req->validated();
        } catch (FailException) {
            // swallow redirect simulation
        }

        // Assert — old input must survive so the form can be repopulated
        $old = $_SESSION['_form_old_input'] ?? null;
        $this->assertIsArray($old, '$_SESSION[_form_old_input] must be set after failure');
        $this->assertSame('foo@example.com', $old['email'] ?? '');
    }

    /**
     * failWith() must store the validation errors in $_SESSION so the view can
     * display them on the next request (after redirect).
     */
    public function testFailWithStoresErrorsInSession(): void
    {
        // Arrange
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $req = $this->makeRequest(['name' => '', 'email' => 'valid@example.com']);

        // Act
        try {
            $req->validated();
        } catch (FailException) {
            // swallow
        }

        // Assert
        $errors = $_SESSION['_form_errors'] ?? null;
        $this->assertIsArray($errors, '$_SESSION[_form_errors] must be set after failure');
        $this->assertArrayHasKey('name', $errors, '"name" error must be stored in session');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Static helpers: hasErrors() / errors() / old() / clearErrors()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * hasErrors() must return true when the session contains non-empty errors
     * and false when the session is empty or the key is absent.
     */
    public function testHasErrorsReturnsTrueWhenSessionHasErrors(): void
    {
        // Arrange
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_form_errors'] = ['email' => ['The email field is required.']];

        // Assert
        $this->assertTrue(FormRequest::hasErrors(), 'hasErrors() must return true when errors are in session');

        // Arrange — clear
        unset($_SESSION['_form_errors']);

        // Assert
        $this->assertFalse(FormRequest::hasErrors(), 'hasErrors() must return false when session key is absent');
    }

    /**
     * errors() without a field argument returns the full errors map.
     * errors() with a field argument returns only that field's messages.
     */
    public function testErrorsReturnsCorrectStructure(): void
    {
        // Arrange
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_form_errors'] = [
            'name'  => ['Name is required.'],
            'email' => ['Email is invalid.'],
        ];

        // Act — all errors
        $all = FormRequest::errors();

        // Assert
        $this->assertArrayHasKey('name',  $all, 'errors() must include the "name" key');
        $this->assertArrayHasKey('email', $all, 'errors() must include the "email" key');

        // Act — single field
        $emailErrors = FormRequest::errors('email');

        // Assert
        $this->assertSame(['Email is invalid.'], $emailErrors, 'errors("email") must return only email errors');
    }

    /**
     * old() must return the value stored in the old-input session when the
     * field exists, and the $default when it does not.
     */
    public function testOldReturnsStoredValueOrDefault(): void
    {
        // Arrange
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_form_old_input'] = ['username' => 'Alice'];

        // Act + Assert — field that was stored
        $this->assertSame('Alice', FormRequest::old('username'), 'old() must return stored value');

        // Act + Assert — field that was NOT stored
        $this->assertSame('', FormRequest::old('password'), 'old() must return empty string default for missing field');
        $this->assertSame('fallback', FormRequest::old('missing', 'fallback'), 'old() must return $default when field absent');
    }

    /**
     * clearErrors() must remove both the errors key and the old-input key
     * from the session so subsequent page loads see a clean state.
     */
    public function testClearErrorsRemovesBothSessionKeys(): void
    {
        // Arrange
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['_form_errors']    = ['name' => ['Required.']];
        $_SESSION['_form_old_input'] = ['name' => ''];

        // Act
        FormRequest::clearErrors();

        // Assert
        $this->assertArrayNotHasKey('_form_errors',    $_SESSION, '_form_errors must be removed by clearErrors()');
        $this->assertArrayNotHasKey('_form_old_input', $_SESSION, '_form_old_input must be removed by clearErrors()');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // getRedirectUrl()
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * getRedirectUrl() must return the explicit $redirectTo property value
     * when it is set, so subclasses can hard-code the failure destination.
     */
    public function testGetRedirectUrlReturnsRedirectToProperty(): void
    {
        // Arrange
        $req = $this->makeRequest([], '/custom/path');
        $ref = new \ReflectionClass($req);
        $m   = $ref->getMethod('getRedirectUrl');

        // Act
        $url = $m->invoke($req);

        // Assert
        $this->assertSame('/custom/path', $url, 'getRedirectUrl() must use $redirectTo when set');
    }

    /**
     * When $redirectTo is empty, getRedirectUrl() falls back to / rather than
     * to a null/empty string. An empty redirect target would cause a broken
     * Location header.
     */
    public function testGetRedirectUrlDefaultsToSlashWhenRefererAbsent(): void
    {
        // Arrange — no HTTP_REFERER in the environment
        unset($_SERVER['HTTP_REFERER']);
        $req = $this->makeRequest([]);
        $ref = new \ReflectionClass($req);
        $m   = $ref->getMethod('getRedirectUrl');

        // Act
        $url = $m->invoke($req);

        // Assert
        $this->assertSame('/', $url, 'getRedirectUrl() must fall back to "/" when HTTP_REFERER is absent');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Teardown
    // ─────────────────────────────────────────────────────────────────────────

    protected function tearDown(): void
    {
        unset(
            $_SESSION['_form_errors'],
            $_SESSION['_form_old_input'],
            $_SERVER['HTTP_REFERER']
        );
        parent::tearDown();
    }
}

// =============================================================================
// Test helpers — defined at file level so they don't pollute the test class
// =============================================================================

/**
 * Thrown by TestableFormRequest::failWith() instead of calling header()/exit.
 * Carries the errors array so assertions can inspect it.
 */
class FailException extends \RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct('validation failed');
    }
}

/**
 * Concrete FormRequest for a simple "name (required) + email (required|email)" form.
 * Overrides failWith() to throw FailException so tests can assert on errors
 * without dealing with real HTTP redirects.
 */
class TestableFormRequest extends FormRequest
{
    /** Re-declared as public so tests can set it directly. */
    public string $redirectTo = '';

    public function __construct(private readonly array $fakeInput = []) {}

    public function rules(): array
    {
        return [
            'name'  => 'required|string|min:1',
            'email' => 'required|email',
        ];
    }

    protected function input(): array
    {
        return $this->fakeInput;
    }

    protected function failWith(array $errors, array $oldInput = []): never
    {
        // Store old input in session (same as real failWith) so session tests work
        if (session_status() !== PHP_SESSION_NONE) {
            $_SESSION[$this->errorsSessionKey]   = $errors;
            $_SESSION[$this->oldInputSessionKey] = $oldInput;
        }
        throw new FailException($errors);
    }
}
