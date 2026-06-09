<?php

declare(strict_types=1);

namespace Pramnos\Framework;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Extended coverage tests for Pramnos\Framework\Base.
 *
 * The original BaseTest.php covers __construct, the magic property quartet,
 * and isset() semantics for null values.  This file adds coverage for:
 *
 *  - addError() / addMessage() — storing and reading back via protected helpers
 *  - _getErrors() / _getMessages() — both the session=true and session=false paths
 *  - _printErrors() / _printMessages() — HTML span rendering
 *  - hasErrors() / hasMessages() — boolean state checks
 *  - _set() — fluent property setter
 *  - _setParentObject() / _getParentObject() — parent-node reference management
 *
 * Because addError() / addMessage() are protected we exercise them through a
 * concrete anonymous subclass that exposes them as public helpers.
 *
 * SESSION handling: several methods write to / read from $_SESSION.  We isolate
 * every test by unsetting the relevant keys in setUp() and restoring state in
 * tearDown(), so that tests are hermetic regardless of PHP's session state.
 */
#[CoversClass(Base::class)]
class BaseExtendedTest extends TestCase
{
    /** @var Base Concrete anonymous subclass that exposes protected helpers. */
    private Base $obj;

    protected function setUp(): void
    {
        // Arrange — create a test subclass that publicises all protected methods
        $this->obj = new class extends Base {
            // ---- addError / addMessage ----
            public function pubAddError(string $e): static   { return $this->addError($e); }
            public function pubAddMessage(string $m): static  { return $this->addMessage($m); }

            // ---- _getErrors / _getMessages ----
            public function pubGetErrors(bool $session = true): array|false
            {
                return $this->_getErrors($session);
            }
            public function pubGetMessages(bool $session = true): array|false
            {
                return $this->_getMessages($session);
            }

            // ---- _printErrors / _printMessages ----
            public function pubPrintErrors(string $class = 'pramnosError'): string
            {
                return $this->_printErrors($class);
            }
            public function pubPrintMessages(string $class = 'pramnosMessage'): string
            {
                return $this->_printMessages($class);
            }

            // ---- hasErrors / hasMessages ----
            public function pubHasErrors(): bool   { return $this->hasErrors(); }
            public function pubHasMessages(): bool { return $this->hasMessages(); }
        };

        // Clean session state so tests are hermetic
        unset($_SESSION['_errors'], $_SESSION['_messages']);
    }

    protected function tearDown(): void
    {
        // Restore session state after each test
        unset($_SESSION['_errors'], $_SESSION['_messages']);
    }

    // =========================================================================
    // addError() / _getErrors()
    // =========================================================================

    /**
     * addError() stores the error in $_errors and returns $this for chaining.
     * _getErrors(false) returns the stored array (no session path).
     *
     * The false path of _getErrors() is tested here because it reads directly
     * from $this->_errors without touching $_SESSION.
     */
    public function testAddErrorStoresErrorAndGetErrorsReturnsIt(): void
    {
        // Arrange / Act
        $result = $this->obj->pubAddError('Something went wrong');

        // Assert — method returns $this for fluent chaining
        $this->assertSame($this->obj, $result, 'addError() must return $this');

        // Assert — _getErrors(false) returns the error without session involvement
        $errors = $this->obj->pubGetErrors(false);
        $this->assertIsArray($errors);
        $this->assertContains('Something went wrong', $errors);
    }

    /**
     * _getErrors(false) returns false when no errors have been added.
     *
     * This covers the `count($this->_errors) == 0` branch.
     */
    public function testGetErrorsReturnsFalseWhenEmpty(): void
    {
        // Act
        $result = $this->obj->pubGetErrors(false);

        // Assert — no errors → false
        $this->assertFalse($result, '_getErrors() must return false when there are no errors');
    }

    /**
     * Multiple errors can be accumulated; _getErrors(false) returns all of them.
     */
    public function testMultipleErrorsAccumulate(): void
    {
        // Arrange
        $this->obj->pubAddError('Error 1');
        $this->obj->pubAddError('Error 2');

        // Act
        $errors = $this->obj->pubGetErrors(false);

        // Assert
        $this->assertCount(2, $errors, 'Both errors must be present');
        $this->assertContains('Error 1', $errors);
        $this->assertContains('Error 2', $errors);
    }

    // =========================================================================
    // addMessage() / _getMessages()
    // =========================================================================

    /**
     * addMessage() stores the message and returns $this for chaining.
     * _getMessages(false) returns the stored array.
     */
    public function testAddMessageStoresMessageAndGetMessagesReturnsIt(): void
    {
        // Arrange / Act
        $result = $this->obj->pubAddMessage('Welcome!');

        // Assert — fluent chain
        $this->assertSame($this->obj, $result, 'addMessage() must return $this');

        // Assert — message readable without session
        $messages = $this->obj->pubGetMessages(false);
        $this->assertIsArray($messages);
        $this->assertContains('Welcome!', $messages);
    }

    /**
     * _getMessages(false) returns false when no messages have been added.
     */
    public function testGetMessagesReturnsFalseWhenEmpty(): void
    {
        // Act
        $result = $this->obj->pubGetMessages(false);

        // Assert
        $this->assertFalse($result, '_getMessages() must return false when there are no messages');
    }

    // =========================================================================
    // _getErrors() — session=true path
    // =========================================================================

    /**
     * _getErrors(true) reads from $_SESSION['_errors'] when the session array
     * is set, removes the key, and returns the errors.
     *
     * This covers the `$session == true && isset($_SESSION)` true path of
     * _getErrors().
     */
    public function testGetErrorsSessionTrueReadsFromSession(): void
    {
        // Arrange — pre-populate the session
        $_SESSION['_errors'] = ['session error'];

        // Act
        $result = $this->obj->pubGetErrors(true);

        // Assert — returns the session errors
        $this->assertIsArray($result);
        $this->assertContains('session error', $result);

        // Assert — session key is consumed (removed)
        $this->assertArrayNotHasKey('_errors', $_SESSION,
            '_getErrors(true) must unset $_SESSION[_errors] after reading');
    }

    /**
     * _getErrors(true) returns false when $_SESSION does not have an '_errors'
     * key (the `else` branch of the session-isset check).
     */
    public function testGetErrorsSessionTrueReturnsFalseWhenSessionKeyAbsent(): void
    {
        // Arrange — session exists but no _errors key
        $_SESSION = [];

        // Act
        $result = $this->obj->pubGetErrors(true);

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // _getMessages() — session=true path
    // =========================================================================

    /**
     * _getMessages(true) reads from $_SESSION['_messages'], removes the key,
     * and returns the messages array.
     */
    public function testGetMessagesSessionTrueReadsFromSession(): void
    {
        // Arrange
        $_SESSION['_messages'] = ['session message'];

        // Act
        $result = $this->obj->pubGetMessages(true);

        // Assert
        $this->assertIsArray($result);
        $this->assertContains('session message', $result);
        $this->assertArrayNotHasKey('_messages', $_SESSION,
            '_getMessages(true) must unset $_SESSION[_messages] after reading');
    }

    /**
     * _getMessages(true) returns false when $_SESSION['_messages'] is absent.
     */
    public function testGetMessagesSessionTrueReturnsFalseWhenKeyAbsent(): void
    {
        // Arrange
        $_SESSION = [];

        // Act
        $result = $this->obj->pubGetMessages(true);

        // Assert
        $this->assertFalse($result);
    }

    // =========================================================================
    // _printErrors()
    // =========================================================================

    /**
     * _printErrors() wraps each error in a <span class="pramnosError">...</span>
     * HTML element.
     *
     * This is used in view templates to display inline validation error messages.
     */
    public function testPrintErrorsWrapsEachErrorInSpan(): void
    {
        // Arrange
        $this->obj->pubAddError('Field is required');
        $this->obj->pubAddError('Must be an email');

        // Act — consume errors via session=true so the session path is also hit
        $_SESSION['_errors'] = $this->obj->_errors; // sync to session
        $html = $this->obj->pubPrintErrors();

        // Assert — two spans rendered with the default class
        $this->assertStringContainsString('<span class="pramnosError">Field is required</span>', $html);
        $this->assertStringContainsString('<span class="pramnosError">Must be an email</span>', $html);
    }

    /**
     * _printErrors() uses a custom CSS class when one is provided.
     */
    public function testPrintErrorsUsesCustomClass(): void
    {
        // Arrange — populate session directly (printErrors reads via _getErrors which uses session)
        $_SESSION['_errors'] = ['err'];

        // Act
        $html = $this->obj->pubPrintErrors('my-error-class');

        // Assert
        $this->assertStringContainsString('class="my-error-class"', $html);
    }

    /**
     * _printErrors() returns an empty string when there are no errors.
     */
    public function testPrintErrorsReturnsEmptyStringWhenNoErrors(): void
    {
        // Act — no errors added, no session key
        $html = $this->obj->pubPrintErrors();

        // Assert
        $this->assertSame('', $html, '_printErrors() must return empty string when no errors');
    }

    // =========================================================================
    // _printMessages()
    // =========================================================================

    /**
     * _printMessages() wraps each message in a <span class="pramnosMessage">...</span>
     * HTML element.
     */
    public function testPrintMessagesWrapsEachMessageInSpan(): void
    {
        // Arrange — put a message in the session (the session=true path is used by default)
        $_SESSION['_messages'] = ['Operation successful'];

        // Act
        $html = $this->obj->pubPrintMessages();

        // Assert
        $this->assertStringContainsString('<span class="pramnosMessage">Operation successful</span>', $html);
    }

    /**
     * _printMessages() returns an empty string when no messages exist.
     */
    public function testPrintMessagesReturnsEmptyStringWhenNoMessages(): void
    {
        // Act
        $html = $this->obj->pubPrintMessages();

        // Assert
        $this->assertSame('', $html);
    }

    // =========================================================================
    // hasErrors()
    // =========================================================================

    /**
     * hasErrors() returns false when there are no errors in $_errors or in the
     * session.
     */
    public function testHasErrorsReturnsFalseWhenNoErrors(): void
    {
        // Act / Assert
        $this->assertFalse($this->obj->pubHasErrors());
    }

    /**
     * hasErrors() returns true after addError() adds an error.
     *
     * This covers the `count($this->_errors) != 0` branch.
     */
    public function testHasErrorsReturnsTrueAfterAddError(): void
    {
        // Arrange
        $this->obj->pubAddError('an error');

        // Act / Assert
        $this->assertTrue($this->obj->pubHasErrors());
    }

    /**
     * hasErrors() returns true when $_SESSION['_errors'] contains entries,
     * even if $this->_errors is empty.
     *
     * This covers the session array branch at the top of hasErrors().
     */
    public function testHasErrorsReturnsTrueWhenSessionHasErrors(): void
    {
        // Arrange — only session has errors, not the instance
        $_SESSION['_errors'] = ['session-only error'];

        // Act / Assert
        $this->assertTrue($this->obj->pubHasErrors(),
            'hasErrors() must return true when session has errors');
    }

    // =========================================================================
    // hasMessages()
    // =========================================================================

    /**
     * hasMessages() returns false when no messages exist anywhere.
     */
    public function testHasMessagesReturnsFalseWhenNoMessages(): void
    {
        // Act / Assert
        $this->assertFalse($this->obj->pubHasMessages());
    }

    /**
     * hasMessages() returns true after addMessage() adds a message.
     */
    public function testHasMessagesReturnsTrueAfterAddMessage(): void
    {
        // Arrange
        $this->obj->pubAddMessage('a message');

        // Act / Assert
        $this->assertTrue($this->obj->pubHasMessages());
    }

    /**
     * hasMessages() returns true when $_SESSION['_messages'] contains entries.
     *
     * This covers the session-messages branch at the top of hasMessages().
     */
    public function testHasMessagesReturnsTrueWhenSessionHasMessages(): void
    {
        // Arrange — session only
        $_SESSION['_messages'] = ['session message'];

        // Act / Assert
        $this->assertTrue($this->obj->pubHasMessages(),
            'hasMessages() must return true when session has messages');
    }

    // =========================================================================
    // _set() — fluent property setter
    // =========================================================================

    /**
     * _set($field, $value) stores the value via __set() and returns $this,
     * enabling a fluent chaining syntax: $obj->_set('x', 1)->_set('y', 2).
     */
    public function testSetStoresValueAndReturnsThis(): void
    {
        // Act
        $return = $this->obj->_set('myField', 42);

        // Assert — fluent return
        $this->assertSame($this->obj, $return, '_set() must return $this for chaining');

        // Assert — value stored and readable via __get
        $this->assertSame(42, $this->obj->myField,
            '_set() must store the value so __get can retrieve it');
    }

    /**
     * Multiple _set() calls can be chained; each value is stored independently.
     */
    public function testSetCanBeChained(): void
    {
        // Act
        $this->obj->_set('a', 1)->_set('b', 2)->_set('c', 3);

        // Assert — all three stored
        $this->assertSame(1, $this->obj->a);
        $this->assertSame(2, $this->obj->b);
        $this->assertSame(3, $this->obj->c);
    }

    // =========================================================================
    // _setParentObject() / _getParentObject()
    // =========================================================================

    /**
     * _setParentObject() stores a reference to the supplied object and
     * _getParentObject() returns that same reference.
     *
     * Because _setParentObject() takes $object by reference, modifying the
     * original variable after the call must be reflected in the result of
     * _getParentObject().
     */
    public function testSetAndGetParentObject(): void
    {
        // Arrange
        $parent = new Base();
        $this->obj->_setParentObject($parent);

        // Act
        $retrieved = $this->obj->_getParentObject();

        // Assert — same instance returned
        $this->assertSame($parent, $retrieved,
            '_getParentObject() must return the object set via _setParentObject()');
    }

    /**
     * _getParentObject() returns null when no parent has been set (the
     * $_parentObject property is initialised to null).
     */
    public function testGetParentObjectReturnsNullWhenNotSet(): void
    {
        // Act
        $result = $this->obj->_getParentObject();

        // Assert — null before any parent is set
        $this->assertNull($result,
            '_getParentObject() must return null when no parent has been set');
    }

    // =========================================================================
    // addError() writes to session when available
    // =========================================================================

    /**
     * addError() must sync the error to $_SESSION['_errors'] when a session is
     * active (i.e. $_SESSION exists as a global variable).
     *
     * This covers the `if (isset($_SESSION))` branch inside addError().
     */
    public function testAddErrorSyncsToSession(): void
    {
        // Arrange — ensure $_SESSION exists
        $_SESSION = [];

        // Act
        $this->obj->pubAddError('synced error');

        // Assert — session now contains the error
        $this->assertArrayHasKey('_errors', $_SESSION,
            'addError() must write to $_SESSION when session is available');
        $this->assertContains('synced error', $_SESSION['_errors']);
    }

    /**
     * addMessage() must sync the message to $_SESSION['_messages'] when the
     * session array exists.
     */
    public function testAddMessageSyncsToSession(): void
    {
        // Arrange
        $_SESSION = [];

        // Act
        $this->obj->pubAddMessage('synced message');

        // Assert
        $this->assertArrayHasKey('_messages', $_SESSION);
        $this->assertContains('synced message', $_SESSION['_messages']);
    }

    // =========================================================================
    // _errors / _messages public properties
    // =========================================================================

    /**
     * The $_errors property is public, initialised as an empty array, and can
     * be read directly.  This is used by views that iterate errors without
     * going through _getErrors().
     */
    public function testErrorsPropertyInitialisedAsEmptyArray(): void
    {
        // Act / Assert
        $this->assertIsArray($this->obj->_errors, '$_errors must be an array');
        $this->assertEmpty($this->obj->_errors, '$_errors must be empty on fresh instance');
    }

    /**
     * The $_messages property is public, initialised as an empty array, and
     * can be read directly.
     */
    public function testMessagesPropertyInitialisedAsEmptyArray(): void
    {
        // Act / Assert
        $this->assertIsArray($this->obj->_messages, '$_messages must be an array');
        $this->assertEmpty($this->obj->_messages, '$_messages must be empty on fresh instance');
    }
}
