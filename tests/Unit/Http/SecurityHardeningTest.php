<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\General\Helpers;
use Pramnos\Http\ExceptionHandler;
use Pramnos\Http\Middleware\CorsMiddleware;
use Pramnos\Http\Request;

/**
 * A class whose deserialization would be noticed.
 *
 * Nothing dangerous happens in it — the point is only that constructing it is
 * observable, so a test can prove the check never got that far.
 */
class WakeupWitness
{
    /** @var bool Set when an instance of this class is ever brought to life. */
    public static bool $awoken = false;

    public function __wakeup(): void
    {
        self::$awoken = true;
    }
}

/**
 * Covers the hardening applied to error output, deserialization checks, CORS
 * configuration and request filtering.
 *
 * Each of these had the same shape of defect: something that looked like a
 * safeguard but was not one — a JSON error path that skipped the rule its HTML
 * twin applied, a "check" that performed the operation it screened for, a CORS
 * combination browsers silently reject, a filter parameter that filtered one
 * type out of the several its name implied.
 */
class SecurityHardeningTest extends TestCase
{
    protected function setUp(): void
    {
        WakeupWitness::$awoken = false;
    }

    // ── Error output ─────────────────────────────────────────────────────────

    /**
     * A 500 does not carry the exception's message to the client.
     *
     * Database exceptions in this framework embed the whole statement:
     * `ERROR: … ::: SQL QUERY: SELECT …`. The HTML renderer has always shown a
     * friendly page instead; the JSON renderer — the API path — returned the
     * message verbatim, in production.
     */
    public function testAServerErrorDoesNotLeakItsMessage(): void
    {
        // Arrange — a message shaped like the ones the database produces
        $exception = new \RuntimeException(
            'ERROR: relation "users" does not exist ::: SQL QUERY: SELECT * FROM users',
            500
        );

        // Act
        $body = (string) ExceptionHandler::render($exception, 'json', false)->getBody();

        // Assert
        $this->assertStringNotContainsString('SQL QUERY', $body);
        $this->assertStringNotContainsString('relation "users"', $body);
        $this->assertStringContainsString('could not be completed', $body);
    }

    /**
     * A 4xx still explains itself.
     *
     * The message is the answer to a client error — "missing parameter x" — and
     * withholding it would turn a usable API into a guessing game. The line is
     * drawn at 500, where the message describes the server rather than the call.
     */
    public function testAClientErrorKeepsItsMessage(): void
    {
        // Arrange
        $exception = new \RuntimeException('The "since" parameter must be a date.', 422);

        // Act
        $body = (string) ExceptionHandler::render($exception, 'json', false)->getBody();

        // Assert
        $this->assertStringContainsString('must be a date', $body);
    }

    /**
     * With debug on, the detail comes back — that is what debug is for.
     */
    public function testDebugStillShowsEverything(): void
    {
        // Arrange
        $exception = new \RuntimeException('ERROR: ::: SQL QUERY: SELECT 1', 500);

        // Act
        $body = (string) ExceptionHandler::render($exception, 'json', true)->getBody();

        // Assert
        $this->assertStringContainsString('SQL QUERY', $body);
        $this->assertStringContainsString('trace', $body);
    }

    // ── Deserialization ──────────────────────────────────────────────────────

    /**
     * Checking whether a string is serialized does not deserialize it.
     *
     * The check used to answer by calling `unserialize()` — performing the
     * operation it exists to screen for. Every gadget an attacker could reach
     * through `__wakeup` or `__destruct` ran inside the safety check, before the
     * caller had decided anything.
     */
    public function testTheSerializationCheckDoesNotInstantiateAnything(): void
    {
        // Arrange
        $payload = serialize(new WakeupWitness());

        // Act
        $isSerialized = Helpers::checkUnserialize($payload);

        // Assert
        $this->assertTrue($isSerialized, 'it is still recognised as serialized');
        $this->assertFalse(
            WakeupWitness::$awoken,
            'but nothing was brought to life to find that out'
        );
    }

    /**
     * The answer is unchanged for every kind of input.
     *
     * This is what makes the change safe to ship: applications branch on this
     * result and then deserialize themselves. A different answer anywhere would
     * change their behaviour, not just their safety.
     */
    public function testTheAnswersAreIdenticalToTheOldImplementation(): void
    {
        // Arrange — including the two edge cases the old code named explicitly
        $cases = [
            'b:0;'                     => true,
            'a:1:{i:0;s:1:"x";}'       => true,
            's:5:"plain";'             => true,
            'i:42;'                    => true,
            'N;'                       => true,
            'not serialized at all'    => false,
            ''                         => false,
            '{"json":true}'            => false,
        ];

        // Act + Assert
        foreach ($cases as $input => $expected) {
            $this->assertSame(
                $expected,
                Helpers::checkUnserialize((string) $input),
                'input: ' . var_export($input, true)
            );
        }
    }

    /**
     * A serialized object is still reported as serialized.
     *
     * `allowed_classes: false` turns it into `__PHP_Incomplete_Class`, which is
     * not `false` — so the check answers exactly as it did, which is what the
     * callers that branch on it need.
     */
    public function testASerializedObjectIsStillRecognised(): void
    {
        // Act + Assert
        $this->assertTrue(Helpers::checkUnserialize(serialize(new \stdClass())));
    }

    /**
     * Saying "no" is silent.
     *
     * `@unserialize()` suppresses the notice for *output*, but the error is
     * still raised: a set_error_handler sees it, and so does anything counting
     * lines in an error log. For a predicate whose callers mostly ask about
     * strings that are not serialized, being told so should cost nothing.
     *
     * Found in a consuming application when `usertokens.deviceinfo` started
     * holding JSON instead of an empty string. `unserialize('')` is silent, so
     * nothing had noticed; `unserialize('{…}')` raises "Error at offset 0", and
     * that column is read on every token check on every request. The test that
     * failed there had a strict error handler, and it pointed at the caller
     * rather than at this method.
     *
     * The handler is installed and removed inside the test so that a failure
     * cannot leave it behind for the rest of the run.
     */
    public function testCheckingANonSerializedStringRaisesNoError(): void
    {
        // Arrange — every shape a caller realistically passes in that is not
        // serialized data, including the JSON that exposed this.
        $inputs = [
            '{"device":"unknown|unknown","label":"an unrecognised browser"}',
            'not serialized at all',
            '',
            'x:1:{}',         // a type letter PHP does not use
            'plain text with: a colon in it',
        ];

        $errors = [];
        set_error_handler(
            static function (int $no, string $message) use (&$errors): bool {
                $errors[] = $message;
                return true;
            }
        );

        try {
            // Act
            foreach ($inputs as $input) {
                $this->assertFalse(
                    Helpers::checkUnserialize($input),
                    'input: ' . var_export($input, true)
                );
            }
        } finally {
            restore_error_handler();
        }

        // Assert — nothing was raised on the way to those answers.
        $this->assertSame([], $errors,
            'the check must answer without raising a diagnostic');
    }

    /**
     * A genuinely malformed serialized string — one that starts with a real
     * type prefix but is truncated — is still reported as not serialized.
     *
     * The pre-screen must not become a second, laxer answer: it only rules out
     * strings that cannot be serialized data, and everything past it is decided
     * by the parser exactly as before. These inputs get past the prefix check
     * and must still come back false.
     *
     * They are also the honest limit of the quiet guarantee above: a string
     * that really does look like serialized data is handed to the parser, and
     * the parser says what it says. What the pre-screen removes is the noise
     * from the overwhelmingly common case — asking about something that was
     * never serialized at all.
     */
    public function testATruncatedSerializedStringIsStillRejected(): void
    {
        // Act + Assert
        $this->assertFalse(Helpers::checkUnserialize('a:2:{i:0;s:1:"x";'));
        $this->assertFalse(Helpers::checkUnserialize('s:99:"short";'));
        $this->assertFalse(Helpers::checkUnserialize('b:0'));
    }

    // ── CORS ─────────────────────────────────────────────────────────────────

    /**
     * Credentials for every origin is refused, with a sentence.
     *
     * Browsers reject `Allow-Origin: *` together with `Allow-Credentials: true`,
     * so the configuration does not fail loudly — credentialed cross-origin
     * requests simply stop working, and nothing anywhere says why.
     */
    public function testWildcardOriginWithCredentialsIsRefused(): void
    {
        // Act + Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/credentials cannot be allowed for every origin/');
        new CorsMiddleware(['*'], ['GET'], ['Content-Type'], true);
    }

    /**
     * Named origins with credentials are exactly what the fix is for.
     */
    public function testNamedOriginsWithCredentialsAreAccepted(): void
    {
        // Act
        $cors = new CorsMiddleware(['https://app.example.com'], ['GET'], ['Content-Type'], true);

        // Assert
        $this->assertSame(['https://app.example.com'], $cors->getAllowedOrigins());
    }

    /**
     * The wildcard default is untouched when credentials are off.
     *
     * Every factory method the framework ships builds this shape, so it must
     * keep working exactly as before.
     */
    public function testTheWildcardDefaultStillWorks(): void
    {
        // Act
        $cors = CorsMiddleware::fromCorsData(false, null);

        // Assert
        $this->assertSame(['*'], $cors->getAllowedOrigins());
    }

    // ── Request filtering ────────────────────────────────────────────────────

    /**
     * An unfiltered request value comes back exactly as it arrived.
     *
     * The historical behaviour, and the one every existing caller relies on:
     * the type parameter filters when asked and never sanitises by default.
     */
    public function testAnUnknownTypeReturnsTheRawValue(): void
    {
        // Arrange
        $_GET['q'] = '<b>hi</b>';

        // Act + Assert
        $this->assertSame('<b>hi</b>', Request::staticGet('q', '', 'get'));
        $this->assertSame('<b>hi</b>', Request::staticGet('q', '', 'get', 'nonsense'));

        unset($_GET['q']);
    }

    /**
     * `int` behaves as it always has.
     */
    public function testIntFilteringIsUnchanged(): void
    {
        // Arrange
        $_GET['page'] = '42abc';

        // Act + Assert
        $this->assertSame(42, Request::staticGet('page', 0, 'get', 'int'));

        unset($_GET['page']);
    }

    /**
     * The filters that were missing now exist.
     *
     * `int` was the only one the method understood, which quietly implied the
     * others were there — a parameter named `$type` that silently ignores every
     * type but one invites exactly the assumption it does not honour.
     */
    public function testTheAddedFiltersWork(): void
    {
        // Arrange
        $_GET = [
            'ratio' => '1.5x',
            'flag'  => 'yes',
            'slug'  => 'hello world!<script>',
            'mail'  => 'not an email',
            'good'  => 'someone@example.com',
        ];

        // Act + Assert
        $this->assertSame(1.5, Request::staticGet('ratio', 0, 'get', 'float'));
        $this->assertTrue(Request::staticGet('flag', false, 'get', 'bool'));
        $this->assertSame('helloworldscript', Request::staticGet('slug', '', 'get', 'alnum'));
        $this->assertSame('', Request::staticGet('mail', '', 'get', 'email'));
        $this->assertSame('someone@example.com', Request::staticGet('good', '', 'get', 'email'));

        $_GET = [];
    }
}
