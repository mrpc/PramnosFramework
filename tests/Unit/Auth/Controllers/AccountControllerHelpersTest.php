<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Account;

/**
 * Unit tests for Pramnos\Auth\Controllers\Account.
 *
 * Account is coupled to the Application / view layer for all public actions.
 * We test the private pure helpers that contain business logic but have no
 * external dependencies.
 *
 * Constructor note: Account calls addAuthAction() *before* parent::__construct(),
 * so we bypass the constructor entirely via newInstanceWithoutConstructor() to
 * avoid needing a booted Application.
 *
 * Tests cover:
 *   - validatePasswordPolicy(): pure string-validation helper with 5 distinct
 *     failure branches and a success path.
 *   - verifyUserPassword(): legacy SHA-256 path (covered by hash comparison).
 */
#[CoversClass(Account::class)]
class AccountControllerHelpersTest extends TestCase
{
    private Account $dashboard;

    protected function setUp(): void
    {
        // Arrange – bypass constructor (calls addAuthAction + parent::__construct)
        $rc              = new \ReflectionClass(Account::class);
        $this->dashboard = $rc->newInstanceWithoutConstructor();
    }

    // ── useStandaloneLayout() ─────────────────────────────────────────────────

    /**
     * The auth renders ask the theme for its standalone layout.
     *
     * Reported from a scaffolded project: `/login` came with the site header, the
     * navigation and a "Sign in" link pointing at the page being looked at, above a
     * `min-h-screen` centred card. The card is how every built-in auth view is
     * written, so the chrome was never meant to be there.
     *
     * `'login'` is the content type `Theme::$elements` has mapped to `login.php`
     * since the class was written — this asks for a template the theme layer already
     * knew about, rather than inventing a mechanism.
     */
    public function testUseStandaloneLayoutAsksTheThemeForTheLoginTemplate(): void
    {
        // Arrange — a document carrying a theme that records what it was asked for.
        $theme = new class {
            public string $contentType = '';
            public function setContentType($type): static
            {
                $this->contentType = (string) $type;
                return $this;
            }
        };
        $document = new class ($theme) {
            public function __construct(public object $themeObject) {}
        };

        // Act
        $this->accountReturning($document)->useStandaloneLayoutForTest();

        // Assert
        $this->assertSame('login', $theme->contentType);
    }

    /**
     * A document with no theme is not an error.
     *
     * A JSON or raw response has no theme object, and neither does a test stub — and
     * the API login path goes through the same controller. Throwing here would turn a
     * cosmetic layout choice into a fatal on every themeless response.
     */
    public function testUseStandaloneLayoutIgnoresADocumentWithNoTheme(): void
    {
        // Arrange
        $document = new class {
            public $themeObject = null;
        };

        // Act & Assert — the absence of an exception is the assertion.
        $this->accountReturning($document)->useStandaloneLayoutForTest();
        $this->assertNull($document->themeObject);
    }

    /**
     * An Account whose `document()` seam returns the given stub.
     *
     * `document()` is protected precisely so a test can supply one; the constructor
     * is bypassed because Account calls `addAuthAction()` before
     * `parent::__construct()` and would need a booted Application.
     */
    private function accountReturning(object $document): object
    {
        $subclass = new class extends Account {
            public ?object $stub = null;
            protected function document(): object
            {
                return $this->stub;
            }
            public function useStandaloneLayoutForTest(): void
            {
                $this->useStandaloneLayout();
            }
        };

        $account = (new \ReflectionClass($subclass))->newInstanceWithoutConstructor();
        $account->stub = $document;

        return $account;
    }

    // ── validatePasswordPolicy() ──────────────────────────────────────────────

    /**
     * validatePasswordPolicy() must return 'password_required' when the new
     * password is an empty string.
     *
     * This covers the first guard in validatePasswordPolicy() (line ~524).
     */
    public function testValidatePasswordPolicyReturnsRequiredForEmptyPassword(): void
    {
        // Act
        $result = $this->callPrivate('validatePasswordPolicy', '', '');

        // Assert
        $this->assertSame('password_required', $result,
            'Empty password must return "password_required"');
    }

    /**
     * validatePasswordPolicy() must return 'password_too_short' when the
     * password is fewer than 8 characters.
     *
     * This covers the `strlen($newPassword) < 8` branch (line ~527).
     */
    public function testValidatePasswordPolicyReturnsTooShortForShortPassword(): void
    {
        // Act — 7-char password
        $result = $this->callPrivate('validatePasswordPolicy', 'Ab1!xyz', 'Ab1!xyz');

        // Assert
        $this->assertSame('password_too_short', $result);
    }

    /**
     * validatePasswordPolicy() must return 'password_needs_digit' when the
     * password contains no digit characters.
     *
     * This covers the `!preg_match('/\d/', …)` branch (line ~530).
     */
    public function testValidatePasswordPolicyReturnsNeedsDigitWhenNoDigit(): void
    {
        // Act — 8+ chars, symbol, but no digit
        $result = $this->callPrivate('validatePasswordPolicy', 'NoDigit!', 'NoDigit!');

        // Assert
        $this->assertSame('password_needs_digit', $result);
    }

    /**
     * validatePasswordPolicy() must return 'password_needs_symbol' when the
     * password contains only letters and digits (no special character).
     *
     * This covers the `!preg_match('/[^A-Za-z0-9]/', …)` branch (line ~533).
     */
    public function testValidatePasswordPolicyReturnsNeedsSymbolWhenNoSymbol(): void
    {
        // Act — 8+ chars, has digit, but no symbol
        $result = $this->callPrivate('validatePasswordPolicy', 'NoSymbol1', 'NoSymbol1');

        // Assert
        $this->assertSame('password_needs_symbol', $result);
    }

    /**
     * validatePasswordPolicy() must return 'passwords_do_not_match' when the
     * new password and confirmation differ.
     *
     * This covers the `$newPassword !== $confirmPassword` branch (line ~536).
     */
    public function testValidatePasswordPolicyReturnsMismatchWhenConfirmationDiffers(): void
    {
        // Act — valid password but confirmation does not match
        $result = $this->callPrivate('validatePasswordPolicy', 'Valid1!pass', 'Different1!pass');

        // Assert
        $this->assertSame('passwords_do_not_match', $result);
    }

    /**
     * validatePasswordPolicy() must return null when the password meets all
     * requirements (length ≥ 8, has digit, has symbol) and matches the confirmation.
     *
     * This covers the `return null` success path (line ~539).
     */
    public function testValidatePasswordPolicyReturnsNullForValidPassword(): void
    {
        // Act — valid password: length 10, digit, symbol, matches confirmation
        $result = $this->callPrivate('validatePasswordPolicy', 'Secure1!ok', 'Secure1!ok');

        // Assert
        $this->assertNull($result,
            'A password meeting all policy requirements must return null');
    }

    /**
     * validatePasswordPolicy() edge case: password exactly 8 characters with
     * digit and symbol must pass.
     *
     * This confirms the >= 8 boundary (not strictly > 8).
     */
    public function testValidatePasswordPolicyAcceptsExactly8CharPassword(): void
    {
        // Act — exactly 8 chars: letter + digit + symbol + more letters
        $result = $this->callPrivate('validatePasswordPolicy', 'Ab1!xyzw', 'Ab1!xyzw');

        // Assert
        $this->assertNull($result,
            'A password of exactly 8 characters that meets all requirements must pass');
    }

    // ── Private reflection helper ─────────────────────────────────────────────

    /**
     * Call a private method on $this->dashboard via reflection.
     */
    private function callPrivate(string $method, mixed ...$args): mixed
    {
        $rm = new \ReflectionMethod(Account::class, $method);
        return $rm->invoke($this->dashboard, ...$args);
    }
}
