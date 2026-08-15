<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\SignInFingerprint;

/**
 * The fingerprint is coarse on purpose, and the tests are about the coarseness.
 *
 * A new-sign-in notification is worth having only while it stays rare. The two ways
 * to destroy that are both easy to write and neither shows up in a unit test that
 * only checks "does it produce a string":
 *
 *   - **using the IP** — dynamic on most consumer connections, so it fires on a router
 *     reboot;
 *   - **using the browser version** — Chrome and Firefox ship a major version about
 *     every four weeks, so it fires monthly for every user. The dynamic-IP problem one
 *     step removed, and much less obvious.
 *
 * So the assertions here are mostly *stability* assertions: the same browser across
 * versions, across OS point releases, and across architectures must produce one value.
 * The discrimination tests are the easy half.
 */
class SignInFingerprintTest extends TestCase
{
    /**
     * A browser update does not change the fingerprint.
     *
     * The test this class exists for. Two Chrome user agents a year apart, on the same
     * machine, must be the same device — otherwise every user is notified monthly and
     * learns to delete the mail.
     *
     * @return void
     */
    public function testABrowserUpdateDoesNotLookLikeANewDevice(): void
    {
        // Arrange — Chrome 109 and Chrome 133, same machine
        $old = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/109.0.0.0 Safari/537.36';
        $new = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36';

        // Act & Assert
        $this->assertSame(
            SignInFingerprint::fromUserAgent($old),
            SignInFingerprint::fromUserAgent($new)
        );
    }

    /**
     * Neither does an operating-system update.
     *
     * iOS puts its point release in the user agent, so `17_2` becomes `17_4` without
     * the person doing anything at all.
     *
     * @return void
     */
    public function testAnOsUpdateDoesNotLookLikeANewDevice(): void
    {
        // Arrange
        $before = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) '
            . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1';
        $after  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) '
            . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1';

        // Act & Assert
        $this->assertSame(
            SignInFingerprint::fromUserAgent($before),
            SignInFingerprint::fromUserAgent($after)
        );
        $this->assertSame('safari|ios', SignInFingerprint::fromUserAgent($after));
    }

    /**
     * A genuinely different browser is a different fingerprint.
     *
     * @return void
     */
    public function testADifferentBrowserIsDetected(): void
    {
        // Arrange — same machine, two browsers
        $chrome  = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36';
        $firefox = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:124.0) '
            . 'Gecko/20100101 Firefox/124.0';

        // Act & Assert
        $this->assertNotSame(
            SignInFingerprint::fromUserAgent($chrome),
            SignInFingerprint::fromUserAgent($firefox)
        );
    }

    /**
     * A different kind of device is a different fingerprint.
     *
     * @return void
     */
    public function testADifferentPlatformIsDetected(): void
    {
        // Arrange — Chrome on Windows against Chrome on Android
        $desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36';
        $phone   = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/133.0.0.0 Mobile Safari/537.36';

        // Act & Assert
        $this->assertSame('chrome|windows', SignInFingerprint::fromUserAgent($desktop));
        $this->assertSame('chrome|android', SignInFingerprint::fromUserAgent($phone));
    }

    /**
     * Browsers that impersonate other browsers are resolved to themselves.
     *
     * This is the user-agent tar pit and the reason the match tables are ordered:
     * Edge claims to be Chrome, Chrome claims to be Safari, and everything claims to
     * be Mozilla. Matched in the wrong order, every desktop browser collapses into
     * `safari` and the feature silently stops detecting anything.
     *
     * @return void
     */
    public function testImpersonatingBrowsersAreResolvedCorrectly(): void
    {
        // Arrange
        $cases = [
            'edge|windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36 Edg/133.0.0.0',
            'opera|windows' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . '(KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36 OPR/119.0.0.0',
            'chrome|ios' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/133.0.0.0 Mobile/15E148 Safari/604.1',
            'firefox|ios' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) FxiOS/124.0 Mobile/15E148 Safari/605.1.15',
            'safari|mac' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
                . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
        ];

        // Act & Assert
        foreach ($cases as $expected => $userAgent) {
            $this->assertSame(
                $expected,
                SignInFingerprint::fromUserAgent($userAgent),
                'Matching order is wrong for: ' . $expected
            );
        }
    }

    /**
     * A 32/64-bit or architecture difference is not a new device.
     *
     * These change with a reinstall and mean nothing to the person reading the email.
     *
     * @return void
     */
    public function testArchitectureIsIgnored(): void
    {
        // Arrange
        $x64 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36';
        $arm = 'Mozilla/5.0 (Windows NT 10.0; ARM64) AppleWebKit/537.36 '
            . '(KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36';

        // Act & Assert
        $this->assertSame(
            SignInFingerprint::fromUserAgent($x64),
            SignInFingerprint::fromUserAgent($arm)
        );
    }

    /**
     * Requests with no user agent are one group, not one each.
     *
     * A script, a stripped proxy, a privacy tool. They are indistinguishable from each
     * other, so treating each as new would notify on every such sign-in, forever.
     *
     * @return void
     */
    public function testMissingUserAgentsCollapseIntoOneValue(): void
    {
        // Act & Assert
        $this->assertSame('unknown|unknown', SignInFingerprint::fromUserAgent(null));
        $this->assertSame('unknown|unknown', SignInFingerprint::fromUserAgent(''));
        $this->assertSame('unknown|unknown', SignInFingerprint::fromUserAgent('   '));
    }

    /**
     * An unrecognised agent still yields a stable value rather than a unique one.
     *
     * Curl, a monitoring probe, a new browser nobody has heard of. It must be the same
     * value every time or it is a permanent alarm.
     *
     * @return void
     */
    public function testAnUnrecognisedAgentIsStable(): void
    {
        // Act
        $first  = SignInFingerprint::fromUserAgent('curl/8.5.0');
        $second = SignInFingerprint::fromUserAgent('curl/8.5.0');

        // Assert
        $this->assertSame($first, $second);
        $this->assertSame('unknown|unknown', $first);
    }

    /**
     * The description is for a person, not for a log.
     *
     * @return void
     */
    public function testTheDescriptionIsReadable(): void
    {
        // Act & Assert
        $this->assertSame('Chrome on Windows', SignInFingerprint::describe('chrome|windows'));
        $this->assertSame('Safari on iPhone or iPad', SignInFingerprint::describe('safari|ios'));
        $this->assertSame(
            'an unrecognised browser',
            SignInFingerprint::describe('unknown|unknown')
        );
    }

    /**
     * The current request's fingerprint comes from its own header.
     *
     * @return void
     */
    public function testTheCurrentRequestIsRead(): void
    {
        // Arrange
        $original = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) '
            . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15';

        try {
            // Act & Assert
            $this->assertSame('safari|mac', SignInFingerprint::current());
        } finally {
            if ($original === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $original;
            }
        }
    }
}
