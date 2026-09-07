<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Logs\Logger;

/**
 * An authorization server that could not say why it refused.
 *
 * The controller had one `Logger::log` call, and it was about a session that would not
 * close during logout. Nothing recorded what the endpoint *decided* — and it can end a
 * request four ways without the client getting a code: the token endpoint refusing, the
 * consent form being shown, the user denying, a parameter being rejected.
 *
 * Measured on one installation in one day: 23 responses of 4xx from the token endpoint,
 * none of them explainable afterwards. Diagnosing one failure came down to comparing the
 * byte counts of two 302s in an access log — 755 for the success, 642 for the failure —
 * which is arithmetic with a hypothesis attached rather than diagnosis.
 *
 * What is asserted here is the two halves of the contract: that a refusal is written down
 * with the inputs that produced it, and that a credential never is.
 */
#[CoversClass(Oauth::class)]
class OauthDecisionLogTest extends TestCase
{
    private mixed $previousMode = null;
    private $stream = null;

    protected function setUp(): void
    {
        // Read the log back from a stream rather than a file: a file under LOG_PATH
        // accumulates between runs, and a test that greps an accumulating file is a test
        // that passes on somebody else's line.
        $property = new \ReflectionProperty(Logger::class, 'outputMode');
        $this->previousMode = $property->getValue();

        $this->stream = fopen('php://memory', 'w+');
        Logger::setStreamTarget($this->stream);
        Logger::setOutputMode(Logger::OUTPUT_STREAM);

        $_POST = [];
        $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
    }

    protected function tearDown(): void
    {
        Logger::setStreamTarget(null);
        $property = new \ReflectionProperty(Logger::class, 'outputMode');
        $property->setValue(null, $this->previousMode);

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }

        $_POST = [];
        $_GET = [];
        unset($_SERVER['REMOTE_ADDR']);
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * The controller with the constructor's side effects — RSA keys, CORS headers, a
     * League factory — out of the way, and the two private methods under test exposed.
     *
     * The subject is the log line, not the endpoints that call it; the call sites are
     * covered where those endpoints are.
     */
    private function probe(bool $verbose): object
    {
        return new class ($verbose) extends Oauth {
            public function __construct(private bool $verbose)
            {
            }

            protected function decisionLogIsVerbose(): bool
            {
                return $this->verbose;
            }

            /** @param array<string,mixed> $context */
            public function decide(string $outcome, array $context = [], bool $verboseOnly = false): void
            {
                $this->logDecision($outcome, $context, $verboseOnly);
            }

            /** @param array<string,mixed> $body */
            public function refuse(array $body, int $status): void
            {
                $this->respondJson($body, $status);
            }

            public function tokenOutcome(int $status, string $body): void
            {
                $this->logTokenEndpointOutcome($status, $body);
            }
        };
    }

    /**
     * A refusal is written down with the inputs that produced it.
     *
     * The outcome alone is what an access log already has. What was missing is which
     * client, which grant, and which of the eight refusal sites answered — and the reports
     * worth investigating are the ones where the request looked fine.
     */
    public function testARefusalRecordsTheInputsThatProducedIt(): void
    {
        // Arrange
        $_POST['grant_type'] = 'authorization_code';

        // Act
        $this->probe(false)->refuse(
            ['error' => 'invalid_client', 'error_description' => 'Missing client_secret'],
            401
        );

        // Assert
        $line = $this->logged();
        $this->assertStringContainsString('refused', $line);
        $this->assertStringContainsString('status=401', $line);
        $this->assertStringContainsString('error=invalid_client', $line);
        $this->assertStringContainsString('error_description=Missing client_secret', $line);
        $this->assertStringContainsString('ip=198.51.100.7', $line);
    }

    /**
     * A successful response writes nothing at all.
     *
     * `respondJson()` is every JSON answer this controller gives, successes included, so
     * the status test is what keeps the log a diagnostic rather than a transcript.
     */
    public function testASuccessfulJsonResponseIsNotLogged(): void
    {
        // Act
        $this->probe(false)->refuse(['active' => true], 200);

        // Assert
        $this->assertSame('', $this->logged());
    }

    /**
     * A credential handed to the logger is dropped, whether or not anybody meant it to be.
     *
     * The allow-list is the reason: a log that answers *why did this fail* must not also
     * answer *what was the secret*. The file is readable by anyone with the server, and an
     * authorization code in it is a code somebody can replay inside its lifetime. A
     * deny-list would depend on the next person to add a call site remembering the rule.
     */
    public function testCredentialsNeverReachTheLog(): void
    {
        // Arrange — every secret this endpoint handles, offered to the logger at once
        $context = [
            'client_id'     => 'client-42',
            'code'          => 'AUTHCODE-SHOULD-NOT-APPEAR',
            'access_token'  => 'ACCESSTOKEN-SHOULD-NOT-APPEAR',
            'refresh_token' => 'REFRESH-SHOULD-NOT-APPEAR',
            'client_secret' => 'SECRET-SHOULD-NOT-APPEAR',
            'code_verifier' => 'VERIFIER-SHOULD-NOT-APPEAR',
        ];

        // Act
        $this->probe(false)->decide('code issued', $context);

        // Assert
        $line = $this->logged();
        $this->assertStringContainsString('client_id=client-42', $line, 'the public part is the point');
        $this->assertStringNotContainsString('SHOULD-NOT-APPEAR', $line);
    }

    /**
     * The successful half is silent until the setting is on.
     *
     * A user id and a destination per sign-in, kept for ever, is not a diagnostic —
     * `usertokens` already holds the row and `user_activity_log` already holds
     * `application_authorized`. It is a retention decision, and it should be one somebody
     * made.
     */
    public function testTheSuccessfulHalfIsOffUntilTheSettingIsOn(): void
    {
        // Act — the same call, twice, with the switch in each position
        $this->probe(false)->decide('code issued', ['client_id' => 'c'], true);
        $this->assertSame('', $this->logged(), 'off by default');

        $this->probe(true)->decide('code issued', ['client_id' => 'c'], true);

        // Assert
        $this->assertStringContainsString('code issued', $this->logged());
    }

    /**
     * A refusal is logged whichever way the switch is set.
     *
     * The switch governs retention of successes; a refusal carries no retention question
     * and is the thing somebody is looking for. Making it conditional would mean the
     * afternoon a complaint arrives starts with a release.
     */
    public function testARefusalIsLoggedEvenWithTheSwitchOff(): void
    {
        // Act
        $this->probe(false)->decide('authorize refused', ['client_id' => 'c']);

        // Assert
        $this->assertStringContainsString('authorize refused', $this->logged());
    }

    /**
     * Both halves of the token endpoint's own outcome, read out of the response body.
     *
     * Every response the League server produces leaves through `emitPsrResponse()` —
     * refusals included, because `OAuthServerException::generateHttpResponse()` is emitted
     * the same way as a successful grant. So one line covers eight call sites, and the body
     * is JSON, so the specific error is read rather than guessed at.
     */
    public function testTheTokenEndpointOutcomeIsReadOutOfTheResponse(): void
    {
        // Arrange
        $_POST['grant_type'] = 'refresh_token';
        $_POST['client_id']  = 'client-42';

        // Act
        $this->probe(false)->tokenOutcome(
            400,
            '{"error":"invalid_grant","error_description":"The refresh token is invalid."}'
        );

        // Assert
        $line = $this->logged();
        $this->assertStringContainsString('token endpoint refused', $line);
        $this->assertStringContainsString('grant_type=refresh_token', $line);
        $this->assertStringContainsString('client_id=client-42', $line);
        $this->assertStringContainsString('error=invalid_grant', $line);
    }

    /**
     * An issued token is recorded only when the switch is on, and never the token.
     */
    public function testAnIssuedTokenIsVerboseOnlyAndCarriesNoToken(): void
    {
        // Arrange
        $_POST['grant_type'] = 'authorization_code';

        // Act — off
        $this->probe(false)->tokenOutcome(200, '{"access_token":"SECRET-TOKEN","expires_in":3600}');
        $this->assertSame('', $this->logged());

        // Act — on
        $this->probe(true)->tokenOutcome(200, '{"access_token":"SECRET-TOKEN","expires_in":3600}');

        // Assert
        $line = $this->logged();
        $this->assertStringContainsString('token issued', $line);
        $this->assertStringNotContainsString('SECRET-TOKEN', $line);
    }

    /**
     * A refusal whose body is not JSON still produces a line.
     *
     * A 502 from in front of the application, or a fatal that printed HTML, is exactly when
     * somebody is reading this file. Losing the line because the body would not parse would
     * lose it in the case it is most needed.
     */
    public function testANonJsonRefusalStillProducesALine(): void
    {
        // Act
        $this->probe(false)->tokenOutcome(500, '<html>Internal Server Error</html>');

        // Assert
        $line = $this->logged();
        $this->assertStringContainsString('token endpoint refused', $line);
        $this->assertStringContainsString('status=500', $line);
    }

    /**
     * A newline in an input cannot forge a second log entry.
     *
     * `error_description` reaches the line from an exception message, and one of those is
     * built from request data. A line-oriented log where a value may contain a newline is a
     * log where an attacker writes whatever entries they like.
     */
    public function testAValueCannotForgeASecondEntry(): void
    {
        // Act
        $this->probe(false)->decide('refused', [
            'error_description' => "first\ntoken issued | client_id=forged",
        ]);

        // Assert — one entry, and the injected text is on it rather than below it
        $this->assertSame(1, substr_count(trim($this->logged()), "\n") + 1);
        $this->assertStringContainsString('first token issued', $this->logged());
    }

    /**
     * An empty or absent value is left out rather than written as `key=`.
     *
     * Most refusals have no user and no scope, and a line of empty pairs is a line nobody
     * scans twice.
     */
    public function testEmptyValuesAreOmitted(): void
    {
        // Act
        $this->probe(false)->decide('refused', [
            'client_id' => 'client-42',
            'scope'     => '',
            'userid'    => null,
        ]);

        // Assert
        $line = $this->logged();
        $this->assertStringContainsString('client_id=client-42', $line);
        $this->assertStringNotContainsString('scope=', $line);
        $this->assertStringNotContainsString('userid=', $line);
    }

    /**
     * An outcome with nothing to say about it is still an outcome.
     *
     * Guards the separator: `outcome | pairs` with no pairs must not leave a trailing `|`.
     */
    public function testAnOutcomeWithNoContextHasNoSeparator(): void
    {
        // Act
        $this->probe(false)->decide('denied by user');

        // Assert
        $this->assertStringContainsString('denied by user', $this->logged());
        $this->assertStringNotContainsString('|', $this->logged());
    }
}
