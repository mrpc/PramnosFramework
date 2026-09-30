<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\TestCase;

/**
 * The shipped auth client on the two-step page: the phone prompt's wait, and the
 * "Don't ask again on this device" box.
 *
 * Run under Node against the real `pf-auth.js` (see `tests/Unit/Support/auth-sign-in-approval.mjs`),
 * because what matters is what the browser is served. The promise under test is the one the
 * owner insisted on: **another way is always one click away**, and the page opens it by itself
 * when the phone is not answering.
 */
class SignInApprovalClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private function scenario(string $scenario): array
    {
        exec('node --version 2>/dev/null', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped('node is not on this machine, so the shipped auth client cannot be run.');
        }

        $raw = (string) shell_exec(
            'node ' . escapeshellarg(dirname(__DIR__) . '/Support/auth-sign-in-approval.mjs')
            . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scaffolding/assets/js/pf-auth.js')
            . ' ' . escapeshellarg($scenario) . ' 2>&1'
        );
        $decoded = json_decode(trim($raw), true);

        $this->assertIsArray($decoded, 'the harness did not produce a result: ' . $raw);

        return $decoded;
    }

    /**
     * Approved on the phone, the page says it was delivered and finishes the sign-in by
     * itself — nothing to press — and stops asking. It finishes through the submit event, so
     * "don't ask again" goes with it: the server trusts only a box it was sent.
     */
    public function testAnApprovalFinishesTheSignInByItself(): void
    {
        // Act
        $page = $this->scenario('push-approved');

        // Assert
        $this->assertSame(1, $page['finished']);
        $this->assertSame(3, $page['polls'], 'no more questions once approved');
        $this->assertSame('1', $page['finishTrust'], 'the ticked box travels with the finishing form');
        $this->assertStringContainsString('Delivered', $page['state']);
        $this->assertFalse($page['othersOpen']);
    }

    /**
     * Twenty seconds with no receipt from the phone, and the page says so and opens the
     * other ways — while still listening, in case the phone is merely slow.
     */
    public function testAPhoneThatDoesNotAnswerOpensTheOtherWays(): void
    {
        // Act
        $page = $this->scenario('push-slow');

        // Assert
        $this->assertTrue($page['othersOpen']);
        $this->assertStringContainsString('another way', $page['state']);
        $this->assertSame(0, $page['finished']);
        $this->assertGreaterThan(2, $page['polls'], 'it keeps listening');
    }

    /**
     * Refused, or expired: the page stops, says which, opens the other ways and offers to
     * send the prompt again.
     */
    public function testARefusalOrExpiryStopsAndOffersEveryOtherWay(): void
    {
        foreach (['push-denied' => 'refused', 'push-expired' => 'expired'] as $scenario => $word) {
            // Act
            $page = $this->scenario($scenario);

            // Assert
            $this->assertStringContainsString($word, $page['state'], $scenario);
            $this->assertTrue($page['othersOpen'], $scenario);
            $this->assertFalse($page['resendHidden'], $scenario . ': "send it again" is shown');
            $this->assertSame(0, $page['finished'], $scenario);
        }
    }

    /**
     * The box's state goes with whichever form is submitted, as 1 or 0, and onto the
     * passkey's address — rewritten, not appended to, each time it changes.
     */
    public function testTheTrustBoxTravelsWithEveryWayThrough(): void
    {
        // Act
        $unticked = $this->scenario('trust-unticked');
        $ticked   = $this->scenario('trust-ticked');

        // Assert — submitted as it was, then toggled
        $this->assertSame('0', $unticked['trustSubmitted']);
        $this->assertSame('/Account/passkeyVerify?trust=1', $unticked['passkeyUrl']);
        $this->assertSame('1', $ticked['trustSubmitted']);
        $this->assertSame('/Account/passkeyVerify?trust=0', $ticked['passkeyUrl']);
    }
}
