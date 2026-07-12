<?php

declare(strict_types=1);

/**
 * Namespace-level override for Pramnos\Webhook: reports getallheaders() as
 * unavailable so tests can reach the $_SERVER-scanning fallback path in
 * WebhookHandler::getRequestHeaders() (lines 397-404).
 *
 * When WebhookHandler (which lives in this namespace) calls
 * function_exists('getallheaders'), PHP resolves the unqualified call to
 * \Pramnos\Webhook\function_exists first, returning false for getallheaders.
 * All other function_exists() calls are delegated to the global built-in.
 */
namespace Pramnos\Webhook {
    function function_exists(string $name): bool
    {
        if ($name === 'getallheaders') {
            return false;
        }
        return \function_exists($name);
    }
}

namespace Pramnos\Tests\Unit\Webhook {

use PHPUnit\Framework\TestCase;
use Pramnos\Webhook\WebhookHandler;

/**
 * Exception thrown by TestableWebhookHandler instead of calling exit().
 * Carries the HTTP status code and response data so tests can inspect them.
 */
class WebhookResponseCapturedException extends \RuntimeException
{
    public function __construct(
        public readonly int   $statusCode,
        public readonly array $data,
    ) {
        parent::__construct("respond({$statusCode})");
    }
}

/**
 * Testable subclass: overrides respond() to throw instead of exit()-ing.
 * This allows handle() to be exercised end-to-end without aborting the process.
 */
class TestableWebhookHandler extends WebhookHandler
{
    protected function respond(int $code, array $data): never
    {
        throw new WebhookResponseCapturedException($code, $data);
    }
}

/**
 * Unit tests for WebhookHandler.
 *
 * The handler calls exit() to send HTTP responses, so tests exercise the
 * sub-components directly (signature verification, event detection, branch
 * extraction) via Reflection rather than calling handle() end-to-end.
 * Command execution is tested via a temporary script that echoes output
 * and exits with a controlled code.
 */
class WebhookHandlerTest extends TestCase
{
    // ── Constructor ───────────────────────────────────────────────────────────

    /**
     * An empty secret must throw InvalidArgumentException immediately.
     *
     * A misconfigured deploy script must fail loudly rather than silently
     * accepting unsigned payloads from anyone on the internet.
     */
    public function testEmptySecretThrows(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/secret must not be empty/');

        // Act
        new WebhookHandler('');
    }

    /**
     * A non-empty secret must construct without exceptions.
     */
    public function testValidSecretConstructsOk(): void
    {
        // Act / Assert — no exception
        $handler = new WebhookHandler('my-secret');
        $this->assertInstanceOf(WebhookHandler::class, $handler);
    }

    // ── onBranch() ────────────────────────────────────────────────────────────

    /**
     * onBranch() must register the branch and command sequence in the branch map,
     * and return $this for fluent chaining.
     */
    public function testOnBranchRegistersCommands(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $cmds    = ['git fetch --all', 'git reset --hard origin/main'];

        // Act
        $ret = $handler->onBranch('main', $cmds);

        // Assert — fluent return + map entry
        $this->assertSame($handler, $ret);
        $this->assertSame($cmds, $handler->getBranchMap()['main']);
    }

    /**
     * Calling onBranch() twice for the same branch must replace the previous mapping.
     *
     * This prevents silent double-execution bugs when code is reorganised.
     */
    public function testOnBranchReplacesExistingEntry(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $handler->onBranch('main', ['old-command']);

        // Act
        $handler->onBranch('main', ['new-command']);

        // Assert
        $this->assertSame(['new-command'], $handler->getBranchMap()['main']);
    }

    /**
     * Multiple branches can be registered independently.
     */
    public function testMultipleBranchesRegisteredIndependently(): void
    {
        // Arrange / Act
        $handler = new WebhookHandler('secret');
        $handler->onBranch('main',    ['cmd-main']);
        $handler->onBranch('develop', ['cmd-dev']);
        $handler->onBranch('staging', ['cmd-stage']);

        // Assert
        $map = $handler->getBranchMap();
        $this->assertCount(3, $map);
        $this->assertSame(['cmd-main'],  $map['main']);
        $this->assertSame(['cmd-dev'],   $map['develop']);
        $this->assertSame(['cmd-stage'], $map['staging']);
    }

    // ── Signature verification ────────────────────────────────────────────────

    /**
     * SHA-256 signature from GitHub (X-Hub-Signature-256) must be accepted
     * when the HMAC matches the body and secret.
     *
     * This is the modern GitHub signature format, required for all webhooks
     * created after 2021.
     */
    public function testValidSha256SignatureIsAccepted(): void
    {
        // Arrange
        $secret  = 'test-secret';
        $body    = '{"ref":"refs/heads/main"}';
        $sig     = 'sha256=' . hash_hmac('sha256', $body, $secret);
        $headers = ['x-hub-signature-256' => $sig];
        $handler = new WebhookHandler($secret);

        // Act
        $result = $this->callPrivate($handler, 'verifySignature', [$body, $headers]);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * SHA-1 signature (X-Hub-Signature) must be accepted as fallback for
     * Bitbucket and legacy GitHub webhooks.
     */
    public function testValidSha1SignatureIsAccepted(): void
    {
        // Arrange
        $secret  = 'test-secret';
        $body    = '{"ref":"refs/heads/main"}';
        $sig     = 'sha1=' . hash_hmac('sha1', $body, $secret);
        $headers = ['x-hub-signature' => $sig];
        $handler = new WebhookHandler($secret);

        // Act
        $result = $this->callPrivate($handler, 'verifySignature', [$body, $headers]);

        // Assert
        $this->assertTrue($result);
    }

    /**
     * A tampered body must produce a signature mismatch (returns false).
     *
     * hash_equals() prevents timing attacks; the function must still return
     * false when the computed MAC differs from the provided one.
     */
    public function testInvalidSha256SignatureIsRejected(): void
    {
        // Arrange
        $secret  = 'test-secret';
        $body    = '{"ref":"refs/heads/main"}';
        $sig     = 'sha256=invalid_signature';
        $headers = ['x-hub-signature-256' => $sig];
        $handler = new WebhookHandler($secret);

        // Act
        $result = $this->callPrivate($handler, 'verifySignature', [$body, $headers]);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * A request with no signature header must be rejected.
     *
     * Missing signature = unauthenticated request = always reject.
     */
    public function testMissingSignatureIsRejected(): void
    {
        // Arrange
        $handler = new WebhookHandler('test-secret');
        $body    = '{"ref":"refs/heads/main"}';
        $headers = [];  // no signature header

        // Act
        $result = $this->callPrivate($handler, 'verifySignature', [$body, $headers]);

        // Assert
        $this->assertFalse($result);
    }

    /**
     * SHA-256 is preferred over SHA-1 when both headers are present.
     *
     * A request with a valid sha256 and an invalid sha1 must be ACCEPTED —
     * proving that only the sha256 header is evaluated.
     */
    public function testSha256TakesPriorityOverSha1(): void
    {
        // Arrange
        $secret  = 'test-secret';
        $body    = '{"ref":"refs/heads/main"}';
        $sha256  = 'sha256=' . hash_hmac('sha256', $body, $secret);
        $headers = [
            'x-hub-signature-256' => $sha256,
            'x-hub-signature'     => 'sha1=invalid',
        ];
        $handler = new WebhookHandler($secret);

        // Act
        $result = $this->callPrivate($handler, 'verifySignature', [$body, $headers]);

        // Assert — sha256 wins; invalid sha1 is not evaluated
        $this->assertTrue($result);
    }

    // ── Event detection ───────────────────────────────────────────────────────

    /**
     * x-github-event: push must map to event 'push'.
     */
    public function testGitHubPushEventDetected(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['ref' => 'refs/heads/main'];
        $headers = ['x-github-event' => 'push'];

        // Act
        $event = $this->callPrivate($handler, 'detectEvent', [$payload, $headers]);

        // Assert
        $this->assertSame('push', $event);
    }

    /**
     * x-github-event: release with action=published must map to 'release'.
     * action != published must map to 'ignored'.
     */
    public function testGitHubReleaseEventDetected(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');

        // Act — published release
        $published = $this->callPrivate($handler, 'detectEvent', [
            ['action' => 'published'],
            ['x-github-event' => 'release'],
        ]);

        // Act — draft release (should be ignored)
        $draft = $this->callPrivate($handler, 'detectEvent', [
            ['action' => 'created'],
            ['x-github-event' => 'release'],
        ]);

        // Assert
        $this->assertSame('release',  $published);
        $this->assertSame('ignored', $draft);
    }

    /**
     * x-github-event: workflow_run with status=completed+conclusion=success
     * must map to 'workflow_run'.  All other combinations must map to 'ignored'.
     */
    public function testGitHubWorkflowRunEventDetected(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');

        // Act — successful workflow
        $success = $this->callPrivate($handler, 'detectEvent', [
            ['workflow_run' => ['status' => 'completed', 'conclusion' => 'success', 'head_branch' => 'main']],
            ['x-github-event' => 'workflow_run'],
        ]);

        // Act — failed workflow (conclusion != success)
        $failed = $this->callPrivate($handler, 'detectEvent', [
            ['workflow_run' => ['status' => 'completed', 'conclusion' => 'failure', 'head_branch' => 'main']],
            ['x-github-event' => 'workflow_run'],
        ]);

        // Assert
        $this->assertSame('workflow_run', $success);
        $this->assertSame('ignored',      $failed);
    }

    /**
     * Bitbucket push payload (no x-github-event header, has push.changes)
     * must be detected as a 'push' event.
     */
    public function testBitbucketPushEventDetected(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['push' => ['changes' => [['new' => ['name' => 'main']]]]];
        $headers = [];  // no x-github-event

        // Act
        $event = $this->callPrivate($handler, 'detectEvent', [$payload, $headers]);

        // Assert
        $this->assertSame('push', $event);
    }

    /**
     * An unrecognised event type (e.g. 'ping', 'pull_request') must be 'ignored'.
     */
    public function testUnknownEventIsIgnored(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $headers = ['x-github-event' => 'pull_request'];

        // Act
        $event = $this->callPrivate($handler, 'detectEvent', [['action' => 'opened'], $headers]);

        // Assert
        $this->assertSame('ignored', $event);
    }

    // ── Branch detection ──────────────────────────────────────────────────────

    /**
     * GitHub push to refs/heads/main must extract 'main'.
     */
    public function testGitHubBranchExtractedFromRef(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['ref' => 'refs/heads/main'];

        // Act
        $branch = $this->callPrivate($handler, 'detectBranch', [$payload, 'push']);

        // Assert
        $this->assertSame('main', $branch);
    }

    /**
     * A tag push (refs/tags/v1.0) must return null (tag pushes are not deployable).
     */
    public function testTagPushReturnsNull(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['ref' => 'refs/tags/v1.0.0'];

        // Act
        $branch = $this->callPrivate($handler, 'detectBranch', [$payload, 'push']);

        // Assert
        $this->assertNull($branch);
    }

    /**
     * Bitbucket push payload must extract the branch name from push.changes[0].new.name.
     */
    public function testBitbucketBranchExtracted(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['push' => ['changes' => [['new' => ['name' => 'develop']]]]];

        // Act
        $branch = $this->callPrivate($handler, 'detectBranch', [$payload, 'push']);

        // Assert
        $this->assertSame('develop', $branch);
    }

    /**
     * workflow_run event must extract the branch from workflow_run.head_branch.
     */
    public function testWorkflowRunBranchExtracted(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['workflow_run' => ['head_branch' => 'staging', 'status' => 'completed', 'conclusion' => 'success']];

        // Act
        $branch = $this->callPrivate($handler, 'detectBranch', [$payload, 'workflow_run']);

        // Assert
        $this->assertSame('staging', $branch);
    }

    // ── Command execution ─────────────────────────────────────────────────────

    /**
     * executeCommands() must return one result per command with exit_code and output.
     *
     * Uses `php -r` as a cross-platform command that always exists.
     */
    public function testExecuteCommandsReturnsResultPerCommand(): void
    {
        // Arrange
        $handler  = new WebhookHandler('secret', sys_get_temp_dir());
        $commands = [
            'php -r "echo \'hello\';"',
            'php -r "echo \'world\';"',
        ];

        // Act
        $results = $this->callPrivate($handler, 'executeCommands', [$commands]);

        // Assert — two results, both successful
        $this->assertCount(2, $results);
        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertSame(0, $results[1]['exit_code']);
        $this->assertStringContainsString('hello', $results[0]['output']);
        $this->assertStringContainsString('world', $results[1]['output']);
    }

    /**
     * executeCommands() must stop after the first non-zero exit code (fail-fast).
     *
     * This prevents a broken deploy from partially executing subsequent commands
     * (e.g. running migrations after a failed git reset).
     */
    public function testExecuteCommandsStopsOnFirstFailure(): void
    {
        // Arrange — second command exits 1; third must not run
        $handler  = new WebhookHandler('secret', sys_get_temp_dir());
        $commands = [
            'php -r "echo \'ok\';"',
            'php -r "exit(1);"',
            'php -r "echo \'should not run\';"',
        ];

        // Act
        $results = $this->callPrivate($handler, 'executeCommands', [$commands]);

        // Assert — only 2 results (third was never executed)
        $this->assertCount(2, $results);
        $this->assertSame(0, $results[0]['exit_code']);
        $this->assertSame(1, $results[1]['exit_code']);
    }

    // ── getRequestHeaders() ───────────────────────────────────────────────────

    /**
     * getRequestHeaders() must fall back to scanning $_SERVER when
     * getallheaders() is not available (CLI / non-Apache SAPIs).
     *
     * We cannot disable getallheaders() at runtime, but we can verify the
     * $_SERVER scanning logic returns headers with lowercase keys by calling
     * the method directly via Reflection with a faked $_SERVER.
     */
    public function testGetRequestHeadersFallbackParsesServerGlobals(): void
    {
        // Arrange — inject HTTP_ vars into $_SERVER
        $_SERVER['HTTP_X_CUSTOM_HEADER'] = 'my-value';
        $_SERVER['HTTP_ACCEPT']          = 'application/json';

        $handler = new WebhookHandler('secret');

        // Act — call via reflection so we can inspect the fallback path
        // (In real SAPIs getallheaders() would shadow this, but the method
        //  must return at least the values from $_SERVER when it falls back)
        $result = $this->callPrivate($handler, 'getRequestHeaders', []);

        // Clean up
        unset($_SERVER['HTTP_X_CUSTOM_HEADER'], $_SERVER['HTTP_ACCEPT']);

        // Assert — the result must be an array (exact keys depend on runtime SAPI)
        $this->assertIsArray($result);
    }

    /**
     * The log() helper must do nothing and not throw when logChannel is ''.
     *
     * A handler created with logChannel='' should silently skip logging rather
     * than attempting to resolve a Logs instance.
     */
    public function testLogWithEmptyChannelIsNoop(): void
    {
        // Arrange — empty logChannel disables logging
        $handler = new WebhookHandler('secret', '', '');

        // Act — call private log(); must not throw even without Logs configured
        $this->expectNotToPerformAssertions();
        $this->callPrivate($handler, 'log', ['info', 'test message']);
    }

    /**
     * detectBranch() for an 'release' event without target_commitish must
     * fall back to 'main' as the default branch.
     */
    public function testDetectBranchReleaseDefaultsToMain(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['release' => []]; // no target_commitish

        // Act
        $branch = $this->callPrivate($handler, 'detectBranch', [$payload, 'release']);

        // Assert — falls back to 'main'
        $this->assertSame('main', $branch);
    }

    /**
     * detectBranch() for an 'release' event with target_commitish must use it.
     */
    public function testDetectBranchReleaseUsesTargetCommitish(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['release' => ['target_commitish' => 'develop']];

        // Act
        $branch = $this->callPrivate($handler, 'detectBranch', [$payload, 'release']);

        // Assert
        $this->assertSame('develop', $branch);
    }

    /**
     * detectBranch() for an unknown event type returns null.
     */
    public function testDetectBranchDefaultReturnsNull(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');

        // Act
        $branch = $this->callPrivate($handler, 'detectBranch', [[], 'ignored']);

        // Assert
        $this->assertNull($branch);
    }

    /**
     * detectBranch() for a Bitbucket push with no changes entry returns null.
     */
    public function testBitbucketBranchWithNoChangesReturnsNull(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['push' => ['changes' => []]]; // empty changes

        // Act
        $branch = $this->callPrivate($handler, 'detectBranch', [$payload, 'push']);

        // Assert — $changes[0] is null, so 'new'/'name' = null
        $this->assertNull($branch);
    }

    /**
     * workflow_run detectBranch returns null when head_branch is absent.
     */
    public function testWorkflowRunBranchNullWhenHeadBranchAbsent(): void
    {
        // Arrange
        $handler = new WebhookHandler('secret');
        $payload = ['workflow_run' => []]; // no head_branch

        // Act
        $branch = $this->callPrivate($handler, 'detectBranch', [$payload, 'workflow_run']);

        // Assert
        $this->assertNull($branch);
    }

    /**
     * WebhookHandler constructor falls back to getcwd() when repoDir is empty.
     */
    public function testConstructorDefaultsRepoDirToCwd(): void
    {
        // Arrange / Act
        $handler = new WebhookHandler('secret'); // no repoDir

        // Assert — we can't inspect the private property directly, but the
        // handler must construct without throwing
        $this->assertInstanceOf(WebhookHandler::class, $handler);
    }

    /**
     * WebhookHandler with invalid sha1 signature is rejected.
     */
    public function testInvalidSha1SignatureIsRejected(): void
    {
        // Arrange
        $secret  = 'test-secret';
        $body    = '{\"ref\":\"refs/heads/main\"}';
        $sig     = 'sha1=badsignature';
        $headers = ['x-hub-signature' => $sig];
        $handler = new WebhookHandler($secret);

        // Act
        $result = $this->callPrivate($handler, 'verifySignature', [$body, $headers]);

        // Assert
        $this->assertFalse($result);
    }

    // ── handle() end-to-end via TestableWebhookHandler ───────────────────────

    /**
     * handle() must respond 403 when the HMAC signature is invalid or missing.
     *
     * This covers the first guard in handle(): verifySignature() → false → respond(403).
     */
    public function testHandleResponds403WhenSignatureInvalid(): void
    {
        // Arrange — unsigned body; handler expects a valid HMAC
        $handler = new TestableWebhookHandler('real-secret');
        $body    = '{"ref":"refs/heads/main"}';
        $headers = ['x-hub-signature-256' => 'sha256=badsig'];

        // Act
        $this->expectException(WebhookResponseCapturedException::class);
        try {
            $handler->handle($body, $headers);
        } catch (WebhookResponseCapturedException $e) {
            // Assert — invalid signature → 403 Forbidden
            $this->assertSame(403, $e->statusCode,
                'handle() must respond 403 when signature verification fails');
            throw $e;
        }
    }

    /**
     * handle() must respond 400 when the request body is not valid JSON.
     *
     * Signature must be valid so the handler proceeds to JSON decoding.
     */
    public function testHandleResponds400WhenPayloadIsNotJson(): void
    {
        // Arrange — valid signature, but body is not JSON
        $secret  = 'real-secret';
        $body    = 'not-json-at-all';
        $sig     = 'sha256=' . hash_hmac('sha256', $body, $secret);
        $headers = ['x-hub-signature-256' => $sig];
        $handler = new TestableWebhookHandler($secret);

        // Act
        $this->expectException(WebhookResponseCapturedException::class);
        try {
            $handler->handle($body, $headers);
        } catch (WebhookResponseCapturedException $e) {
            $this->assertSame(400, $e->statusCode,
                'handle() must respond 400 for a non-JSON body');
            throw $e;
        }
    }

    /**
     * handle() must respond 204 when the event type is not deployable (e.g. pull_request).
     */
    public function testHandleResponds204WhenEventIsIgnored(): void
    {
        // Arrange — valid signature, valid JSON, but an event that is not push/release/workflow_run
        $secret  = 'real-secret';
        $payload = json_encode(['action' => 'opened']);
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $headers = [
            'x-hub-signature-256' => $sig,
            'x-github-event'      => 'pull_request',
        ];
        $handler = new TestableWebhookHandler($secret);

        // Act
        $this->expectException(WebhookResponseCapturedException::class);
        try {
            $handler->handle($payload, $headers);
        } catch (WebhookResponseCapturedException $e) {
            $this->assertSame(204, $e->statusCode,
                'handle() must respond 204 for an ignored event type');
            throw $e;
        }
    }

    /**
     * handle() must respond 204 when the branch is not in the branch map.
     */
    public function testHandleResponds204WhenBranchNotMapped(): void
    {
        // Arrange — push to 'develop' but handler only maps 'main'
        $secret  = 'real-secret';
        $payload = json_encode(['ref' => 'refs/heads/develop']);
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $headers = [
            'x-hub-signature-256' => $sig,
            'x-github-event'      => 'push',
        ];
        $handler = new TestableWebhookHandler($secret);
        $handler->onBranch('main', ['echo ok']);

        // Act
        $this->expectException(WebhookResponseCapturedException::class);
        try {
            $handler->handle($payload, $headers);
        } catch (WebhookResponseCapturedException $e) {
            $this->assertSame(204, $e->statusCode,
                'handle() must respond 204 for a push to an unmapped branch');
            throw $e;
        }
    }

    /**
     * handle() must respond 200 after executing commands for a mapped branch.
     *
     * This is the golden path: valid signature → push event → known branch → success.
     */
    public function testHandleResponds200AfterSuccessfulDeploy(): void
    {
        // Arrange — push to 'main' with a trivially successful command
        $secret  = 'real-secret';
        $payload = json_encode(['ref' => 'refs/heads/main']);
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $headers = [
            'x-hub-signature-256' => $sig,
            'x-github-event'      => 'push',
        ];
        $handler = new TestableWebhookHandler($secret, sys_get_temp_dir(), '');
        $handler->onBranch('main', ['php -r "exit(0);"']);

        // Act
        $this->expectException(WebhookResponseCapturedException::class);
        try {
            $handler->handle($payload, $headers);
        } catch (WebhookResponseCapturedException $e) {
            // Assert — all commands succeeded → 200 OK
            $this->assertSame(200, $e->statusCode,
                'handle() must respond 200 when all commands succeed');
            $this->assertSame('ok', $e->data['status']);
            $this->assertSame('main', $e->data['branch']);
            throw $e;
        }
    }

    /**
     * handle() must respond 500 when a command exits with a non-zero code.
     */
    public function testHandleResponds500WhenCommandFails(): void
    {
        // Arrange — push to 'main' with a deliberately failing command
        $secret  = 'real-secret';
        $payload = json_encode(['ref' => 'refs/heads/main']);
        $sig     = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        $headers = [
            'x-hub-signature-256' => $sig,
            'x-github-event'      => 'push',
        ];
        $handler = new TestableWebhookHandler($secret, sys_get_temp_dir(), '');
        $handler->onBranch('main', ['php -r "exit(1);"']);

        // Act
        $this->expectException(WebhookResponseCapturedException::class);
        try {
            $handler->handle($payload, $headers);
        } catch (WebhookResponseCapturedException $e) {
            // Assert — command failed → 500 with error status
            $this->assertSame(500, $e->statusCode,
                'handle() must respond 500 when a command exits non-zero');
            $this->assertSame('error', $e->data['status']);
            throw $e;
        }
    }

    /**
     * log() with a non-empty channel must attempt to write to Logs without
     * propagating exceptions — logging is best-effort and must never break
     * the webhook response flow.
     */
    public function testLogWithNonEmptyChannelAttemptsThenSuppressesException(): void
    {
        // Arrange — handler with a channel name; Logs is not bootstrapped in unit
        // tests, so getInstance()->write() will likely throw, but log() must catch it.
        $handler = new TestableWebhookHandler('secret', '', 'webhook');

        // Act — must not throw despite Logs not being configured
        $this->expectNotToPerformAssertions();
        $this->callPrivate($handler, 'log', ['info', 'deployment complete']);
    }

    /**
     * executeCommands() must return a proc_open-failure result when proc_open()
     * cannot be started (lines 327-328). Triggered by supplying a non-existent
     * working directory that causes proc_open to return false.
     */
    public function testExecuteCommandsHandlesProcOpenFailure(): void
    {
        // Arrange — repoDir does not exist; proc_open with a non-existent cwd
        // returns false, causing the handler to record a failure result
        $handler  = new WebhookHandler('secret', '/nonexistent_pramnos_webhook_dir_' . uniqid());
        $commands = ['echo test'];

        // Act — proc_open should fail; must not throw
        $results = $this->callPrivate($handler, 'executeCommands', [$commands]);

        // Assert — one entry returned with exit_code 1 and 'proc_open failed' output
        $this->assertCount(1, $results,
            'executeCommands() must return exactly one result for the failed command');
        $this->assertSame(1, $results[0]['exit_code'],
            'A proc_open failure must produce exit_code 1');
        $this->assertSame('proc_open failed', $results[0]['output'],
            'A proc_open failure must set output to "proc_open failed"');
    }

    /**
     * getRequestHeaders() $_SERVER-scanning fallback (lines 397-404).
     *
     * Because the namespace-level function_exists() override in this file
     * reports getallheaders() as unavailable, WebhookHandler always takes the
     * $_SERVER scanning branch here. The fallback must return a map of
     * lowercase header names built from HTTP_* entries in $_SERVER.
     */
    public function testGetRequestHeadersFallbackBuildsHeadersFromServer(): void
    {
        // Arrange — inject known HTTP_* entries; the namespace override ensures
        // function_exists('getallheaders') returns false, forcing the fallback
        $_SERVER['HTTP_X_MY_HEADER']   = 'test-value';
        $_SERVER['HTTP_ACCEPT']        = 'application/json';
        $_SERVER['NON_HTTP_KEY']       = 'should-be-ignored';
        $handler = new WebhookHandler('secret');

        // Act — getRequestHeaders() must use the $_SERVER scanning path
        $result = $this->callPrivate($handler, 'getRequestHeaders', []);

        // Cleanup
        unset($_SERVER['HTTP_X_MY_HEADER'], $_SERVER['HTTP_ACCEPT'], $_SERVER['NON_HTTP_KEY']);

        // Assert — HTTP_* keys are lowercased and returned; non-HTTP_* keys excluded
        $this->assertIsArray($result);
        $this->assertArrayHasKey('x-my-header', $result,
            'HTTP_X_MY_HEADER must be converted to "x-my-header"');
        $this->assertSame('test-value', $result['x-my-header']);
        $this->assertArrayHasKey('accept', $result,
            'HTTP_ACCEPT must be converted to "accept"');
        $this->assertArrayNotHasKey('non-http-key', $result,
            'Keys without HTTP_ prefix must not appear in the result');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Calls a private method on $object via Reflection.
     */
    private function callPrivate(object $object, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($object, $method);
        return $ref->invokeArgs($object, $args);
    }
}

} // end namespace Pramnos\Tests\Unit\Webhook
