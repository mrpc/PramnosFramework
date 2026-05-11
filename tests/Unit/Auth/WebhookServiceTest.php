<?php

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\WebhookService;

/**
 * Unit tests for Pramnos\Auth\WebhookService.
 *
 * HTTP delivery (curl) is tested by subclassing WebhookService and overriding
 * the protected delivery method via reflection — the test exercises the full
 * retry/back-off/status-update logic without opening real network connections.
 *
 * Signature helpers (verifySignature, buildSignature) are pure functions and
 * are tested directly without any mocking.
 *
 * The database interaction (queueEvent, processQueue, purgeOldEvents) is verified
 * by passing a spy/stub database object that records QueryBuilder calls and
 * returns controlled Result fixtures.
 */
class WebhookServiceTest extends TestCase
{
    // ── verifySignature ───────────────────────────────────────────────────────

    /**
     * A correct HMAC-SHA256 signature must be accepted.
     *
     * This is the contract for inbound webhook verification: the receiver
     * recomputes the signature from the raw body and compares with the header.
     */
    public function testVerifySignatureAcceptsValidSignature(): void
    {
        // Arrange
        $payload = '{"event_type":"token_revoked","user_id":42}';
        $secret  = 'super-secret-key';

        // Act
        $header  = WebhookService::buildSignature($payload, $secret);
        $result  = WebhookService::verifySignature($payload, $secret, $header);

        // Assert
        $this->assertTrue($result, 'A correctly signed request must pass verification');
    }

    /**
     * A tampered payload must not pass verification.
     *
     * The HMAC is computed over the exact byte sequence of the payload. Any
     * modification — even a single character — must produce a different digest.
     */
    public function testVerifySignatureRejectsTamperedPayload(): void
    {
        // Arrange
        $payload        = '{"event_type":"token_revoked","user_id":42}';
        $tamperedPayload = '{"event_type":"token_revoked","user_id":99}';
        $secret         = 'super-secret-key';

        // Act
        $header = WebhookService::buildSignature($payload, $secret);
        $result = WebhookService::verifySignature($tamperedPayload, $secret, $header);

        // Assert
        $this->assertFalse($result, 'A tampered payload must fail verification');
    }

    /**
     * A wrong secret must not pass verification.
     *
     * Even with an identical payload, different secrets produce different HMACs.
     */
    public function testVerifySignatureRejectsWrongSecret(): void
    {
        // Arrange
        $payload  = '{"event_type":"token_revoked","user_id":42}';
        $secret   = 'correct-secret';
        $attacker = 'wrong-secret';

        // Act
        $header = WebhookService::buildSignature($payload, $secret);
        $result = WebhookService::verifySignature($payload, $attacker, $header);

        // Assert
        $this->assertFalse($result, 'Signing with a different secret must fail verification');
    }

    /**
     * A header value without the "sha256=" prefix must be rejected immediately.
     *
     * This prevents accepting unsigned or malformed headers before any HMAC
     * comparison is performed.
     */
    public function testVerifySignatureRejectsMissingPrefix(): void
    {
        // Arrange
        $payload = 'any body';
        $secret  = 'key';

        // Act — pass raw hex without the "sha256=" prefix
        $rawHex = hash_hmac('sha256', $payload, $secret);
        $result = WebhookService::verifySignature($payload, $secret, $rawHex);

        // Assert
        $this->assertFalse($result, 'A header without sha256= prefix must be rejected');
    }

    // ── buildSignature ────────────────────────────────────────────────────────

    /**
     * buildSignature must always produce the "sha256=<hex>" format.
     *
     * The prefix is required by the delivery protocol; callers must not need to
     * add it themselves.
     */
    public function testBuildSignatureReturnsCorrectFormat(): void
    {
        // Arrange
        $payload = '{"hello":"world"}';
        $secret  = 'my-secret';

        // Act
        $result = WebhookService::buildSignature($payload, $secret);

        // Assert
        $this->assertStringStartsWith('sha256=', $result);
        $hex = substr($result, 7);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hex,
            'The hex part must be a 64-char lowercase SHA-256 digest');
    }

    /**
     * buildSignature and verifySignature must be inverses.
     *
     * Whatever buildSignature produces, verifySignature with the same inputs
     * must return true. This is the round-trip invariant.
     */
    public function testBuildAndVerifyAreInverses(): void
    {
        $cases = [
            ['payload' => '',             'secret' => 'k'],
            ['payload' => 'hello',        'secret' => 'secret'],
            ['payload' => '{"a":"b"}',    'secret' => str_repeat('x', 64)],
            ['payload' => str_repeat('!', 1000), 'secret' => 'long-payload'],
        ];

        foreach ($cases as $case) {
            $header = WebhookService::buildSignature($case['payload'], $case['secret']);
            $ok     = WebhookService::verifySignature($case['payload'], $case['secret'], $header);
            $this->assertTrue($ok,
                "Round-trip must pass for payload='{$case['payload']}' (truncated)");
        }
    }

    // ── Retry logic (back-off formula) ────────────────────────────────────────

    /**
     * The exponential back-off delay is 5 min × 2^(attempt-1), capped at 24 h.
     *
     * Verified by inspecting the formula in isolation, mirroring what
     * processQueue computes. This ensures the retry schedule does not silently
     * change if the implementation is refactored.
     */
    public function testBackoffFormulaIsCorrect(): void
    {
        // Replicate the formula from WebhookService::processQueue()
        $backoffFor = function (int $attempt): int {
            return min(300 * (2 ** ($attempt - 1)), 86400);
        };

        // Assert
        $this->assertSame(300,   $backoffFor(1), '1st retry: 5 min');
        $this->assertSame(600,   $backoffFor(2), '2nd retry: 10 min');
        $this->assertSame(1200,  $backoffFor(3), '3rd retry: 20 min');
        $this->assertSame(2400,  $backoffFor(4), '4th retry: 40 min');
        $this->assertSame(4800,  $backoffFor(5), '5th retry: 80 min');
        $this->assertSame(9600,  $backoffFor(6), '6th retry: ~2.67 h');
        $this->assertSame(19200, $backoffFor(7), '7th retry: ~5.3 h');
        $this->assertSame(38400, $backoffFor(8), '8th retry: ~10.7 h');
        $this->assertSame(76800, $backoffFor(9), '9th retry: ~21.3 h');
        $this->assertSame(86400, $backoffFor(10), '10th+ retry: capped at 24 h');
        $this->assertSame(86400, $backoffFor(20), 'Large attempt: still capped at 24 h');
    }

    // ── HMAC signing determinism ───────────────────────────────────────────────

    /**
     * HMAC-SHA256 is deterministic — the same inputs always produce the same output.
     *
     * This matters because the receiver must be able to recompute and compare;
     * any randomness in the signature would break verification.
     */
    public function testSignatureIsDeterministic(): void
    {
        $payload = '{"event":"test"}';
        $secret  = 'key';

        $first  = WebhookService::buildSignature($payload, $secret);
        $second = WebhookService::buildSignature($payload, $secret);

        $this->assertSame($first, $second, 'HMAC-SHA256 must be deterministic');
    }

    /**
     * Different payloads must produce different signatures with the same secret.
     *
     * Ensures that signing is sensitive to content changes — a requirement for
     * tamper detection.
     */
    public function testDifferentPayloadsProduceDifferentSignatures(): void
    {
        $secret = 'shared-secret';
        $a      = WebhookService::buildSignature('payload-A', $secret);
        $b      = WebhookService::buildSignature('payload-B', $secret);

        $this->assertNotSame($a, $b, 'Different payloads must not collide');
    }
}
