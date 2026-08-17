<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Request;

/**
 * Flash messages can be read back — which, until now, they could not.
 *
 * `Base::addMessage()` and `addError()` write `$_SESSION['_messages']` and
 * `$_SESSION['_errors']`, and **nothing in the framework** read them back: `_getMessages()` and
 * `_getErrors()` are `protected` and were called from nowhere in `src/`.
 *
 * They are read by **consuming applications**, though, which is the whole point of their living
 * on `Base` — a reference application calls `_getErrors()` in three API controllers to put the
 * errors in a JSON response. So the mechanism had a display side; it was just outside this
 * repository, and invisible to a grep of it.
 *
 * What was missing here is a path a *framework* view can use, which is why sixty-seven
 * controllers passed `?error=…` in a redirect instead. That is what `messages()` and
 * `flashErrors()` add.
 *
 * The `Base` tests below are the important ones. Draining the session for the new path broke the
 * old one **twice**: first the destructive readers, then the non-destructive gates that a
 * reference application puts in front of them. The second was found only by driving three real
 * HTTP requests against two framework versions and counting how many times a message appeared —
 * 1 before, 0 after. Neither this suite nor the application's 5497 tests could see it.
 *
 * The one-shot capture is the part worth testing. A flash that survives a reload is not a
 * flash — it is the query parameter's defect with a different storage medium.
 */
class FlashMessagesTest extends TestCase
{
    /** @var array<string, mixed> The session as it was found */
    private array $originalSession = [];

    /**
     * Clears the per-request capture and the session bags.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
        $this->resetCapture();
    }

    /**
     * Restores them.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        $this->resetCapture();
    }

    /**
     * Puts the static capture back to "not yet read".
     *
     * The bags are static because the capture is per request, and a test run is one process
     * pretending to be many requests.
     *
     * @return void
     */
    private function resetCapture(): void
    {
        foreach (['validationErrors', 'oldInput', 'flashMessages', 'flashErrors'] as $name) {
            (new \ReflectionProperty(Request::class, $name))->setValue(null, null);
        }
    }

    /**
     * A message written on the previous request is readable on this one.
     *
     * @return void
     */
    public function testAFlashedMessageIsReadable(): void
    {
        // Arrange — what addMessage() leaves behind
        $_SESSION['_messages'] = ['Saved.'];

        // Act
        $messages = (new Request())->messages();

        // Assert
        $this->assertSame(['Saved.'], $messages);
    }

    /**
     * So is a flashed error.
     *
     * @return void
     */
    public function testAFlashedErrorIsReadable(): void
    {
        // Arrange
        $_SESSION['_errors'] = ['That record no longer exists.'];

        // Act & Assert
        $this->assertSame(
            ['That record no longer exists.'],
            (new Request())->flashErrors()
        );
    }

    /**
     * Reading consumes: the session entry is gone afterwards.
     *
     * **The property that makes this a flash.** A query parameter is shown again on every
     * reload, stays in history, and is in whatever the user pastes when asking for help. If
     * this left the session entry in place it would have the same defect with better spelling.
     *
     * @return void
     */
    public function testReadingConsumesTheSessionEntry(): void
    {
        // Arrange
        $_SESSION['_messages'] = ['Saved.'];
        $_SESSION['_errors']   = ['Nope.'];

        // Act
        $request = new Request();
        $request->messages();

        // Assert — both bags are cleared by the single capture, not one at a time
        $this->assertArrayNotHasKey('_messages', $_SESSION);
        $this->assertArrayNotHasKey('_errors', $_SESSION);
    }

    /**
     * Within one request the values stay readable after the session is cleared.
     *
     * The capture is per request, so a template reading messages after the controller has
     * already read them must see the same thing. Otherwise whichever of the two ran first
     * would silently consume the other's.
     *
     * @return void
     */
    public function testTheCaptureSurvivesRepeatedReadsInOneRequest(): void
    {
        // Arrange
        $_SESSION['_messages'] = ['Saved.'];

        // Act
        $first  = (new Request())->messages();
        $second = (new Request())->messages();

        // Assert
        $this->assertSame(['Saved.'], $first);
        $this->assertSame(['Saved.'], $second, 'A second reader sees the same messages.');
    }

    /**
     * A second request does not see the previous one's messages.
     *
     * Modelled by resetting the static capture, which is what a new request does.
     *
     * @return void
     */
    public function testASecondRequestSeesNothing(): void
    {
        // Arrange
        $_SESSION['_messages'] = ['Saved.'];
        (new Request())->messages();

        // Act — a new request
        $this->resetCapture();

        // Assert
        $this->assertSame([], (new Request())->messages());
    }

    /**
     * Nothing flashed is an empty array, not null.
     *
     * A template iterating the result must not have to check the type first.
     *
     * @return void
     */
    public function testNothingFlashedIsAnEmptyArray(): void
    {
        // Act & Assert
        $request = new Request();
        $this->assertSame([], $request->messages());
        $this->assertSame([], $request->flashErrors());
    }

    /**
     * A non-array in the session is ignored rather than returned.
     *
     * The bags are session data, which an application may have written by hand or which may
     * be left over from an older version. A template iterating a string would emit its
     * characters.
     *
     * @return void
     */
    public function testANonArrayInTheSessionIsIgnored(): void
    {
        // Arrange
        $_SESSION['_messages'] = 'a single message, not a list';
        $_SESSION['_errors']   = 42;

        // Act & Assert
        $request = new Request();
        $this->assertSame([], $request->messages());
        $this->assertSame([], $request->flashErrors());
    }

    /**
     * Keys are renumbered, so a template can rely on a list.
     *
     * `addMessage()` appends to an array it re-reads from the session, and a gap in the keys
     * would make `$messages[0]` absent while the array is non-empty.
     *
     * @return void
     */
    public function testKeysAreRenumbered(): void
    {
        // Arrange — a bag with a hole in it
        $_SESSION['_messages'] = [3 => 'Third', 7 => 'Seventh'];

        // Act
        $messages = (new Request())->messages();

        // Assert
        $this->assertSame(['Third', 'Seventh'], $messages);
    }

    /**
     * Flash errors and validation errors are separate bags.
     *
     * `errors()` is the per-field output of a validator; `flashErrors()` is whole sentences a
     * controller wrote. Merging them would put "That record no longer exists." where a
     * template expects a field name as the key.
     *
     * @return void
     */
    public function testFlashErrorsAreNotValidationErrors(): void
    {
        // Arrange
        $_SESSION['_errors']            = ['That record no longer exists.'];
        $_SESSION['_validation_errors'] = ['email' => ['Not an email address.']];

        // Act
        $request = new Request();

        // Assert
        $this->assertSame(['That record no longer exists.'], $request->flashErrors());
        $this->assertSame(['email' => ['Not an email address.']], $request->errors());
    }

    /**
     * `clearValidationState()` clears the flash bags too.
     *
     * It exists so a request can discard flashed state deliberately — after handling it in a
     * way that must not repeat. Leaving two of the four bags behind would make that partial.
     *
     * @return void
     */
    public function testClearingValidationStateClearsTheFlashToo(): void
    {
        // Arrange
        $_SESSION['_messages'] = ['Saved.'];
        $_SESSION['_errors']   = ['Nope.'];
        $request = new Request();

        // Act
        $request->clearValidationState();

        // Assert
        $this->assertSame([], $request->messages());
        $this->assertSame([], $request->flashErrors());
        $this->assertArrayNotHasKey('_messages', $_SESSION);
        $this->assertArrayNotHasKey('_errors', $_SESSION);
    }

    /**
     * `Base::_getErrors()` still returns the errors after the capture has drained the session.
     *
     * **The regression this change nearly shipped.** `_getErrors()` reads `$_SESSION['_errors']`
     * and, finding nothing, returns `false` — it does not fall back to the instance bag. The new
     * capture unsets that key, and `View::__construct()` triggers the capture on essentially
     * every request, so an application reading its flash through `Base` would have got `false`
     * for errors that were flashed perfectly well.
     *
     * A reference application does exactly this in three API controllers:
     * `'errors' => $this->_getErrors()`. The response would have carried `false` instead of the
     * errors, and nothing about it would have looked wrong.
     *
     * The sequence below is that application's, in order: add an error, let something read the
     * request (as the view does), then read through `Base`.
     *
     * @return void
     */
    public function testTheBaseReaderStillWorksAfterTheCaptureHasRun(): void
    {
        // Arrange — a consumer of Base, which is every controller and every model
        $consumer = new class extends \Pramnos\Framework\Base {
            /**
             * Flashes an error, as a controller does before redirecting.
             *
             * @param  string $error The message
             * @return void
             */
            public function flashError(string $error): void
            {
                $this->addError($error);
            }

            /**
             * Flashes a message.
             *
             * @param  string $message The message
             * @return void
             */
            public function flashMessage(string $message): void
            {
                $this->addMessage($message);
            }

            /**
             * Reads them back the way an application does.
             *
             * @return array|bool
             */
            public function readErrors()
            {
                return $this->_getErrors();
            }

            /**
             * @return array|bool
             */
            public function readMessages()
            {
                return $this->_getMessages();
            }
        };

        $consumer->flashError('Something went wrong');
        $consumer->flashMessage('Saved.');

        // Act — this is what View::__construct() does, and it unsets both session keys
        (new Request())->errors();

        // Assert — the application's reader is unaffected
        $this->assertSame(['Something went wrong'], $consumer->readErrors());
        $this->assertSame(['Saved.'], $consumer->readMessages());
    }

    /**
     * With nothing flashed at all, the `Base` reader still answers `false`.
     *
     * Its documented shape, and what a consuming application branches on. Returning an empty
     * array instead would make `if ($errors)` keep working by accident and
     * `if ($errors === false)` stop working silently.
     *
     * @return void
     */
    public function testTheBaseReaderStillAnswersFalseWhenNothingWasFlashed(): void
    {
        // Arrange
        $consumer = new class extends \Pramnos\Framework\Base {
            /**
             * @return array|bool
             */
            public function readErrors()
            {
                return $this->_getErrors();
            }
        };

        // Act — drain first, so the fallback is what answers
        (new Request())->errors();

        // Assert
        $this->assertFalse($consumer->readErrors());
    }

    /**
     * `hasErrors()` sees a flash the capture has already drained.
     *
     * **The regression that shipped past the first fix.** The destructive readers got a fallback
     * and these gates did not — and a reference application gates *every* flash it displays on
     * them, in its theme header and in five views:
     *
     * ```php
     * if ($this->hasErrors()) { echo $this->_printErrors(); }
     * ```
     *
     * So `hasErrors()` answered `false`, the printer was never reached, and the entire flash UI
     * went silent: invalid-login messages, lockout notices, CSRF errors, and around sixty
     * `addError()`/`addMessage()` calls across its admin controllers. Nothing failed anywhere.
     *
     * Measured, not reasoned about: three real HTTP requests sharing a cookie jar showed the
     * message once before the change and zero times after.
     *
     * @return void
     */
    public function testTheGatesSeeAFlashTheCaptureHasDrained(): void
    {
        // Arrange — one object flashes, as a controller does before redirecting
        $controller = $this->baseConsumer();
        $controller->flashError('Invalid Login Details');
        $controller->flashMessage('Saved.');

        // Act — the capture runs, as View::__construct() makes it
        (new Request())->errors();

        // Assert — a *different* object gates on it, as a theme header does
        $theme = $this->baseConsumer();
        $this->assertTrue($theme->gateErrors(), 'The header must know there is an error.');
        $this->assertTrue($theme->gateMessages());
    }

    /**
     * The gate does not consume, and the printer that follows it does.
     *
     * Both halves matter. A gate that consumed would leave the printer with nothing — the same
     * silence by the opposite route. And a printer that did not consume would show the message
     * again on the next page, which is the query parameter's defect.
     *
     * @return void
     */
    public function testTheGateDoesNotConsumeAndThePrinterDoes(): void
    {
        // Arrange
        $this->baseConsumer()->flashError('Invalid Login Details');
        (new Request())->errors();

        // Act & Assert — gating twice does not use it up
        $theme = $this->baseConsumer();
        $this->assertTrue($theme->gateErrors());
        $this->assertTrue($theme->gateErrors(), 'A gate is a question, not a read.');

        // …the printer gets it…
        $this->assertStringContainsString('Invalid Login Details', $theme->printErrors());

        // …and the next object on the next page finds nothing
        $next = $this->baseConsumer();
        $this->assertFalse($next->gateErrors(), 'Consumed; not shown twice.');
        $this->assertSame('', $next->printErrors());
    }

    /**
     * A `Base` consumer exposing the four protected members an application uses.
     *
     * Applications reach these by inheritance, which is why a grep of this repository found only
     * three call sites and missed the ones that mattered: the search was for `_getErrors`, and
     * the real readers were `hasErrors()` and `_printErrors()`.
     *
     * @return object
     */
    private function baseConsumer(): object
    {
        return new class extends \Pramnos\Framework\Base {
            /**
             * @param  string $error The message
             * @return void
             */
            public function flashError(string $error): void
            {
                $this->addError($error);
            }

            /**
             * @param  string $message The message
             * @return void
             */
            public function flashMessage(string $message): void
            {
                $this->addMessage($message);
            }

            /**
             * @return bool
             */
            public function gateErrors(): bool
            {
                return $this->hasErrors();
            }

            /**
             * @return bool
             */
            public function gateMessages(): bool
            {
                return $this->hasMessages();
            }

            /**
             * @return string
             */
            public function printErrors(): string
            {
                return (string) $this->_printErrors();
            }
        };
    }
}
