<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sign-in form saying that it heard the button — by running the shipped client.
 *
 * The sixth of eight findings from an evaluation against
 * <https://web.dev/articles/sign-in-form-best-practices>: pressing «Sign in» changed nothing on the
 * page. The human-check proof holds the submit for a moment, the network takes another, and in
 * between there is a form that looks exactly as it did before the press — so somebody presses again.
 *
 * ## Why it is tested by running it
 *
 * A test asserting that `pf-auth.js` *contains* `disabled = true` would pass on a script that never
 * reaches that line. So this loads the exact bytes a browser is served under Node, against a DOM
 * small enough to submit a form in, and reads back what happened to the button.
 *
 * ## The distinction the whole design rests on
 *
 * A submit listener that called `preventDefault()` did one of two opposite things:
 *
 *  - **refused** the submit — validation failed, and the person has to fix the form. Disabling the
 *    button here hands them a form that can never be submitted.
 *  - **held** the submit — the proof is nearly done and the form will go on its own. That hold is
 *    exactly when the second press happens.
 *
 * Nothing in the event distinguishes them, so the script skips both and whoever is holding marks the
 * form itself through `window.PramnosAuth.markSubmitBusy`. `validation-refused` and `held-then-busy`
 * are the two halves; if they ever agree, the design has quietly broken.
 */
class SubmitProgressClientTest extends TestCase
{
    private function harness(): string
    {
        return dirname(__DIR__) . '/Support/auth-submit-progress.mjs';
    }

    private function clientScript(): string
    {
        return dirname(__DIR__, 3) . '/scaffolding/assets/js/pf-auth.js';
    }

    private function requireNode(): void
    {
        exec('node --version 2>/dev/null', $output, $status);

        if ($status !== 0) {
            $this->markTestSkipped(
                'node is not on this machine, so the shipped auth client cannot be run. This is the '
                . 'only test that proves a refused submit leaves its button alone — run it somewhere '
                . 'with Node before shipping a change to pf-auth.js.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function scenario(string $scenario): array
    {
        $this->requireNode();

        $command = 'node ' . escapeshellarg($this->harness())
            . ' ' . escapeshellarg($this->clientScript())
            . ' ' . escapeshellarg($scenario)
            . ' 2>&1';

        $raw = (string) shell_exec($command);
        $decoded = json_decode(trim($raw), true);

        $this->assertIsArray($decoded, 'the harness did not produce a result: ' . $raw);

        return $decoded;
    }

    /**
     * An ordinary submit disables the button, says so, and says so accessibly.
     *
     * Three things, and each covers a different reader. `disabled` is what stops the second press.
     * The label is what tells a sighted person why the button stopped responding — a button that
     * went dead and said nothing reads as a broken form. `aria-busy` on the form is the same news
     * for a screen reader.
     */
    public function testAnOrdinarySubmitMarksTheButtonBusy(): void
    {
        // Act
        $state = $this->scenario('plain-submit');

        // Assert
        $this->assertTrue($state['buttonDisabled']);
        $this->assertSame('Sign in…', $state['buttonLabel'], 'the label says nothing about waiting');
        $this->assertSame('true', $state['ariaBusy']);
        $this->assertContains('pf-busy', $state['buttonClasses'], 'a theme has nothing to style');
    }

    /**
     * A refused submit leaves the button exactly as it was.
     *
     * This is the failure the deferral exists to prevent: the password-policy check in this same
     * file refuses a submit and leaves the person on the page to fix the field. A button disabled on
     * the way out is a form that can no longer be submitted at all — a worse bug than the one the
     * indicator was added for, and one that only appears when validation fails.
     */
    public function testARefusedSubmitLeavesTheButtonAlone(): void
    {
        // Act
        $state = $this->scenario('validation-refused');

        // Assert
        $this->assertFalse($state['buttonDisabled']);
        $this->assertSame('Sign in', $state['buttonLabel']);
        $this->assertNull($state['ariaBusy']);
        $this->assertNull($state['formBusyAttribute']);
    }

    /**
     * A *held* submit is marked busy — by the holder, through the exported hook.
     *
     * The human-check script prevents the default only to wait for a proof, then submits the form
     * itself. Indistinguishable from a refusal in the event, and the case the finding was actually
     * about, so it calls `markSubmitBusy()` directly. Without the export it could not, and the one
     * pause long enough for a second press would be the one pause with no indicator.
     */
    public function testAHeldSubmitCanMarkItselfBusy(): void
    {
        // Act
        $state = $this->scenario('held-then-busy');

        // Assert
        $this->assertSame('function', $state['exported'], 'the hook the human check needs is gone');
        $this->assertTrue($state['buttonDisabled']);
        $this->assertSame('true', $state['ariaBusy']);
    }

    /**
     * A caller can give the waiting label, and the default is not a translation.
     *
     * The default appends «…» to whatever the button already said, in whatever language it said it.
     * A hardcoded «Please wait…» would be English on a Greek form, and this class ships to projects
     * whose language it cannot know. `data-pf-busy-label` is for a screen that wants real words.
     */
    public function testTheWaitingLabelCanBeGiven(): void
    {
        // Act
        $state = $this->scenario('custom-label');

        // Assert
        $this->assertSame('One moment', $state['buttonLabel']);
    }

    /**
     * A form that did not ask is untouched.
     *
     * `data-pf-progress` is opt-in because this script loads on pages with forms it knows nothing
     * about — a search box, a filter, a form some other script submits twice on purpose.
     */
    public function testAFormWithoutTheAttributeIsUntouched(): void
    {
        // Act
        $state = $this->scenario('unmarked-form');

        // Assert
        $this->assertFalse($state['buttonDisabled']);
        $this->assertNull($state['formBusyAttribute']);
    }
}
