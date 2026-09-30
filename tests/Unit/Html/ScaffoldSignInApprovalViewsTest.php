<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sign-in-approval screens exist, with the same hooks, in every scaffold theme.
 *
 * The controller and `scaffolding/assets/js/pf-auth.js` (`wireTrustDevice`,
 * `wirePushApproval`) find these screens by data-attributes and field names, not by theme.
 * A theme that renders the page without one of them does not fail loudly: the phone prompt
 * waits for ever, the trust box is ignored, or a setting silently falls back to "no". So
 * each theme is read and the hooks are looked for, as {@see ScaffoldSignInPracticesTest}
 * does — static checks on the files, because the point is that the markup is there at all.
 */
class ScaffoldSignInApprovalViewsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return [
            'tailwind'  => ['tailwind'],
            'bootstrap' => ['bootstrap'],
            'plain-css' => ['plain-css'],
        ];
    }

    private static function view(string $theme, string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/' . $relative;
        self::assertFileExists($path, $theme . ' has no ' . $relative);

        return (string) file_get_contents($path);
    }

    /**
     * The two-step page carries the phone prompt the script drives: the root it polls from,
     * the form it submits with `method=push` once the phone says yes, and the resend form it
     * reveals when the prompt failed. Missing any one, the page waits on a phone that can
     * never finish the sign-in.
     */
    #[DataProvider('themes')]
    public function testTwoStepPageCarriesThePushPrompt(string $theme): void
    {
        // Arrange
        $source = self::view($theme, 'login/login_2fa.html.php');

        // Act
        $finish = (int) strpos($source, 'data-pf-push-finish');
        $finishForm = substr($source, $finish, 800);

        // Assert
        $this->assertStringContainsString('data-pf-push-approval', $source);
        $this->assertStringContainsString('data-status-url=', $source);
        $this->assertStringContainsString('data-pf-push-state', $source);
        $this->assertGreaterThan(0, $finish, 'no data-pf-push-finish form');
        $this->assertStringContainsString('name="method" value="push"', $finishForm);
        $this->assertStringContainsString('data-pf-push-resend', $source);
        $this->assertStringContainsString('name="send_factor" value="push"', $source);
    }

    /**
     * "Try another way" must always exist beside the phone prompt: it is the element the
     * script opens after twenty seconds, and a theme missing it would strand everybody whose
     * phone is flat, offline or in another room.
     */
    #[DataProvider('themes')]
    public function testTwoStepPageAlwaysOffersAnotherWay(string $theme): void
    {
        // Arrange
        $source = self::view($theme, 'login/login_2fa.html.php');

        // Act
        $hasTarget = str_contains($source, 'data-other-ways="#pf-other-ways"');

        // Assert
        $this->assertTrue($hasTarget, 'the prompt does not name its other-ways block');
        $this->assertStringContainsString('id="pf-other-ways"', $source);
        $this->assertStringContainsString('Try another way', $source);
    }

    /**
     * "Don't ask again on this device" is one checkbox the script copies into whichever form
     * is submitted. Without the attribute the box is decoration and the choice never reaches
     * the server.
     */
    #[DataProvider('themes')]
    public function testTwoStepPageOffersToTrustTheDevice(string $theme): void
    {
        // Arrange
        $source = self::view($theme, 'login/login_2fa.html.php');

        // Act
        $box = (int) strpos($source, 'data-pf-trust-device');

        // Assert
        $this->assertGreaterThan(0, $box, 'no data-pf-trust-device checkbox');
        $this->assertStringContainsString('$this->canTrust', $source);
        $this->assertStringContainsString('$this->trustChosen', $source);
        $this->assertStringContainsString("Don't ask again on this device", $source);
    }

    /**
     * The phone's approval page exists in every theme — the controller renders `approve`
     * whichever theme is installed — and answers with `decision` approved or denied, the
     * number-matching buttons named `number` over the offered choices, and a way to say no.
     * There is no plain Yes: every ask needs the number.
     */
    #[DataProvider('themes')]
    public function testApprovalPageAnswersYesByNumberAndNo(string $theme): void
    {
        // Arrange
        $source = self::view($theme, 'login/approve.html.php');

        // Act
        $loop = (int) strpos($source, "foreach (\$approval['choices'] as \$choice)");
        $inLoop = substr($source, $loop, 400);

        // Assert
        $this->assertStringContainsString('name="decision" value="approved"', $source);
        $this->assertStringContainsString('name="decision" value="denied"', $source);
        $this->assertGreaterThan(0, $loop, 'the number buttons do not loop over the choices');
        $this->assertStringContainsString('name="number"', $inLoop);
        $this->assertStringContainsString("No, it's not me", $source);
        $this->assertStringNotContainsString("Yes, it's me", $source, 'a Yes without the number');
    }

    /**
     * A trusted device can approve sign-ins, so it must be revocable where the account's
     * security lives — one at a time and all at once. A trust nobody can withdraw is a
     * lost phone that keeps saying yes.
     */
    #[DataProvider('themes')]
    public function testSecurityPageRevokesTrustedDevices(string $theme): void
    {
        // Arrange
        $source = self::view($theme, 'OAuth2/security.html.php');

        // Act
        $forms = substr_count($source, '/revokedevice');

        // Assert
        $this->assertSame(2, $forms, 'expected one per-device and one forget-all form');
        $this->assertStringContainsString('name="device"', $source);
        $this->assertStringContainsString('name="all" value="1"', $source);
    }
}
