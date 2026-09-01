<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Passkey;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Offering a passkey inside the username autofill — by running the shipped client.
 *
 * The framework has had passkeys for a while, behind a «Sign in with a passkey» button. Conditional
 * mediation is what turns that into the thing people actually use: the passkey appears in the
 * username field's own autofill list, so signing in is one tap on a suggestion instead of noticing a
 * second button and choosing it. The evaluation that asked for this called it the largest gain
 * available from what was already built, and it is — the ceremony, the endpoints and the credentials
 * all existed.
 *
 * ## Why it is tested by running it
 *
 * A test that described what the script *should* do would agree with itself. So this loads
 * `scaffolding/assets/js/pf-webauthn.js` — the exact bytes a browser is served — under Node against
 * a stubbed `navigator.credentials` that records what it was asked for. The same approach as
 * `HumanCheckClientAgreementTest`, for the same reason.
 *
 * ## The interaction that matters
 *
 * **A browser allows one outstanding `credentials.get()`.** A conditional request sits waiting for
 * the whole life of the page — that is its nature — so starting any other ceremony while it is
 * pending is refused. Switch conditional UI on naively and the «Sign in with a passkey» button
 * silently stops working: it was there yesterday, it does nothing today, and nothing in the console
 * says why unless somebody is looking.
 *
 * That is what `authenticate()` cancelling first is for, and it is the assertion this file exists to
 * make.
 */
class ConditionalMediationClientTest extends TestCase
{
    private function harness(): string
    {
        return dirname(__DIR__, 2) . '/Support/webauthn-conditional.mjs';
    }

    private function clientScript(): string
    {
        return dirname(__DIR__, 4) . '/scaffolding/assets/js/pf-webauthn.js';
    }

    private function requireNode(): void
    {
        exec('node --version 2>/dev/null', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped(
                'node is not on this machine, so the shipped WebAuthn client cannot be run. This is '
                . 'the only test that proves conditional mediation does not break the passkey '
                . 'button — run it somewhere with Node before shipping a change to pf-webauthn.js.'
            );
        }
    }

    /**
     * Run one scenario and return what the stubbed browser recorded.
     *
     * @return array<string, mixed>
     */
    private function scenario(string $name): array
    {
        $this->requireNode();

        $command = 'node ' . escapeshellarg($this->harness())
            . ' ' . escapeshellarg($this->clientScript())
            . ' ' . escapeshellarg($name)
            . ' 2>&1';

        exec($command, $output, $status);
        $raw = implode("\n", $output);

        $this->assertSame(0, $status, "node failed:\n" . $raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "node did not print a result:\n" . $raw);

        return $decoded;
    }

    // ── The ceremony ──────────────────────────────────────────────────────────

    /**
     * The conditional ceremony asks for `mediation: 'conditional'` and can be cancelled.
     *
     * Both halves are the contract. Without the mediation the browser shows its own modal picker,
     * which is the button experience with extra steps; without a signal there is no way to get out of
     * the way of anything else.
     */
    public function testTheCeremonyIsConditionalAndCancellable(): void
    {
        // Act
        $result = $this->scenario('conditional-waits');

        // Assert
        $this->assertCount(1, $result['calls'], 'the ceremony was not started');
        $this->assertSame('conditional', $result['calls'][0]['mediation']);
        $this->assertTrue($result['calls'][0]['hasSignal'], 'the ceremony cannot be cancelled');
    }

    /**
     * It waits rather than resolving, and asks the server for options exactly once.
     *
     * Waiting *is* the feature: the promise settles when somebody picks a passkey from the autofill
     * list, which may be never. A version that resolved immediately would be a button again.
     */
    public function testItWaitsForSomebodyToPickAPasskey(): void
    {
        // Act
        $result = $this->scenario('conditional-waits');

        // Assert
        $this->assertSame('still-waiting', $result['result']);
        $this->assertSame(
            ['/passkey/options'],
            array_column($result['posts'], 'url'),
            'a ceremony nobody has answered should not have posted an assertion'
        );
    }

    /**
     * Pressing the button cancels the waiting ceremony, and the sign-in completes.
     *
     * The whole reason this file exists. A browser allows one outstanding `credentials.get()`, and a
     * conditional request holds it for the life of the page — so without the cancellation the button
     * below the field is refused, silently, from the day conditional UI is switched on.
     */
    public function testPressingTheButtonCancelsTheWaitingCeremony(): void
    {
        // Act
        $result = $this->scenario('button-cancels-conditional');

        // Assert
        $this->assertSame(1, $result['aborted'], 'the pending conditional ceremony was not cancelled');

        $this->assertCount(2, $result['calls']);
        $this->assertSame('conditional', $result['calls'][0]['mediation']);
        $this->assertNull(
            $result['calls'][1]['mediation'],
            "the button's own ceremony must not be conditional — it is a deliberate choice"
        );

        $this->assertSame('ok', $result['result'], 'the button no longer signs anybody in');

        // Exactly one assertion was verified: the button's. The cancelled one had none to send.
        $this->assertSame(
            1,
            count(array_filter(
                array_column($result['posts'], 'url'),
                static fn (string $url): bool => str_contains($url, 'verify')
            )),
            'two assertions were posted for one sign-in'
        );
    }

    // ── When the browser cannot do it ─────────────────────────────────────────

    /**
     * A browser that says conditional mediation is unavailable is left alone.
     *
     * No ceremony is started at all — which matters because starting one and having it fail would
     * hold the single outstanding `credentials.get()` and break the button for exactly the browsers
     * that need it most.
     */
    public function testABrowserThatCannotDoItIsLeftAlone(): void
    {
        // Act
        $result = $this->scenario('unavailable');

        // Assert
        $this->assertSame([], $result['calls'], 'a ceremony was started on a browser that refused');
        $this->assertNull($result['result']);
        $this->assertSame([], $result['posts'], 'the server was asked for options pointlessly');
    }

    /**
     * An older browser without the feature-detection method is not asked.
     *
     * `isConditionalMediationAvailable` is absent in older browsers, and calling it there is a
     * `TypeError` on the sign-in page — a broken form for everybody on that browser, in exchange for
     * a convenience.
     */
    public function testAnOlderBrowserIsNotAsked(): void
    {
        // Act
        $result = $this->scenario('no-method');

        // Assert
        $this->assertFalse($result['conditionalSupported'], 'the feature was not detected');
        $this->assertSame([], $result['calls']);
        $this->assertNull($result['error'] ?? null, 'it raised instead of standing down');
    }

    /**
     * Cancelling is not an error.
     *
     * The person used the password form instead, or pressed the button. Both are the normal ending
     * of a conditional ceremony, and reporting either as a failure would put an error on a form
     * where nothing went wrong.
     */
    public function testCancellingIsNotAnError(): void
    {
        // Act
        $result = $this->scenario('conditional-aborted');

        // Assert
        $this->assertNull($result['result'], 'an aborted ceremony reported a result');
        $this->assertNull($result['error'] ?? null, 'cancelling was reported as a failure');
    }
}
