<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Push\Vapid;

/**
 * The VAPID key pair — the identity of every notification an application will ever send.
 *
 * There is no registration and no shared secret with a push service: the key pair *is* the
 * identity. Which makes one property worth pinning above all others — the encoding has to be
 * right **every time**, not almost every time.
 */
#[CoversClass(Vapid::class)]
class VapidTest extends TestCase
{
    /**
     * A generated pair is the size and shape the Push API requires.
     *
     * 65 bytes for the public key — `0x04` and two 32-byte coordinates — and 32 for the private
     * one. A browser rejects anything else outright.
     */
    public function testAPairHasTheRequiredShape(): void
    {
        // Act
        $pair = Vapid::generate();

        // Assert
        $public = Vapid::decode($pair['publicKey']);
        $this->assertSame(65, strlen($public));
        $this->assertSame("\x04", $public[0], 'the uncompressed point marker');
        $this->assertSame(32, strlen(Vapid::decode($pair['privateKey'])));
    }

    /**
     * Every pair, not most of them.
     *
     * `openssl_pkey_get_details()` returns the coordinates as big-endian integers with leading
     * zeroes stripped, so roughly one key in 256 comes back 31 bytes long. Unpadded, that is a
     * 64-byte public key which is occasionally 63 — a key that works until one day it does not,
     * on a machine nobody can reproduce. Fifty pairs is enough to catch a missing pad reliably.
     */
    public function testEveryPairIsPaddedToLength(): void
    {
        for ($i = 0; $i < 50; $i++) {
            // Act
            $pair = Vapid::generate();

            // Assert
            $this->assertSame(65, strlen(Vapid::decode($pair['publicKey'])), 'iteration ' . $i);
            $this->assertSame(32, strlen(Vapid::decode($pair['privateKey'])), 'iteration ' . $i);
        }
    }

    /**
     * The encoding is base64url, which is the only one the Push API accepts.
     *
     * `+`, `/` and `=` are not valid in an `applicationServerKey`, and a browser given one
     * refuses to subscribe.
     */
    public function testTheEncodingIsUrlSafe(): void
    {
        // Act
        $pair = Vapid::generate();

        // Assert
        foreach ($pair as $key) {
            $this->assertDoesNotMatchRegularExpression('~[+/=]~', $key);
        }
    }

    /**
     * Encoding round-trips, including bytes that are not valid UTF-8.
     */
    public function testTheEncodingRoundTrips(): void
    {
        // Arrange
        $binary = random_bytes(65);

        // Assert
        $this->assertSame($binary, Vapid::decode(Vapid::encode($binary)));
        $this->assertSame('', Vapid::decode('!!! not base64 !!!'));
    }

    /**
     * An installation with no pair says so rather than half-answering.
     */
    public function testAnInstallationWithNoPairIsUnconfigured(): void
    {
        // Arrange
        $empty = sys_get_temp_dir() . '/no-vapid-' . bin2hex(random_bytes(4));
        mkdir($empty);

        try {
            // Assert
            $this->assertNull(Vapid::load($empty));
            $this->assertFalse(Vapid::configured($empty));
        } finally {
            @rmdir($empty);
        }
    }

    /**
     * A pair on disk is loaded, with the contact subject beside it.
     */
    public function testAPairOnDiskIsLoaded(): void
    {
        // Arrange
        $root = sys_get_temp_dir() . '/vapid-' . bin2hex(random_bytes(4));
        mkdir($root . '/' . Vapid::DIRECTORY, 0700, true);

        $pair = Vapid::generate();
        file_put_contents($root . '/' . Vapid::DIRECTORY . '/' . Vapid::PRIVATE_FILE, $pair['privateKey']);
        file_put_contents($root . '/' . Vapid::DIRECTORY . '/' . Vapid::PUBLIC_FILE, $pair['publicKey']);

        try {
            // Act
            $loaded = Vapid::load($root);

            // Assert
            $this->assertSame($pair['publicKey'], $loaded['publicKey']);
            $this->assertSame($pair['privateKey'], $loaded['privateKey']);
            $this->assertArrayHasKey('subject', $loaded);
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * With nothing configured, the contact subject is empty rather than invented.
     *
     * RFC 8292's `sub` is where a push service writes when something is wrong with what you are
     * sending. A guessed one — `mailto:webmaster@` — is an address nobody reads, which is worse
     * than none: the provider believes it warned you.
     */
    public function testAnUnconfiguredInstallationHasNoContactSubject(): void
    {
        // Arrange
        \Pramnos\Application\Settings::clearSettings();

        // Assert
        $this->assertSame('', Vapid::subject());
    }

    /**
     * `app.php` wins over everything else.
     *
     * The administrator's mailbox is a reasonable fallback and a poor default for this: it is
     * the address a *person* is contacted at, and the `sub` claim is where a push provider
     * writes about a sending problem. An application that wants those separate says so, and
     * saying so has to actually take effect.
     */
    public function testTheApplicationConfigurationWins(): void
    {
        // Arrange
        $app      = \Pramnos\Application\Application::getInstance();
        $original = $app->applicationInfo['push'] ?? null;
        $app->applicationInfo['push'] = ['subject' => '  mailto:push@example.com  '];

        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::setSetting('admin_mail', 'somebody@example.com', false);

        try {
            // Assert — trimmed, and the administrator's address is not consulted
            $this->assertSame('mailto:push@example.com', Vapid::subject());
        } finally {
            if ($original === null) {
                unset($app->applicationInfo['push']);
            } else {
                $app->applicationInfo['push'] = $original;
            }

            \Pramnos\Application\Settings::clearSettings();
        }
    }

    /**
     * An administrator's address becomes a `mailto:`, which is what a provider expects.
     */
    public function testTheAdministratorsAddressBecomesTheSubject(): void
    {
        // Arrange
        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::setSetting('admin_mail', 'ops@example.com', false);

        try {
            // Assert
            $this->assertSame('mailto:ops@example.com', Vapid::subject());
        } finally {
            \Pramnos\Application\Settings::clearSettings();
        }
    }

    /**
     * A setting that is not an address is not turned into one.
     *
     * `mailto:not an email` is rejected by some push services and silently accepted by others,
     * which is the worse of the two outcomes — it works until the day it does not.
     */
    public function testSomethingThatIsNotAnAddressIsNotUsedAsOne(): void
    {
        // Arrange
        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::setSetting('admin_mail', 'the office', false);
        \Pramnos\Application\Settings::setSetting('site_url', 'https://example.com/', false);

        try {
            // Assert — falls through to the site URL, which RFC 8292 also allows
            $this->assertSame('https://example.com', Vapid::subject());
        } finally {
            \Pramnos\Application\Settings::clearSettings();
        }
    }

    /**
     * A loaded pair carries whatever subject is configured at the time.
     */
    public function testTheLoadedPairCarriesTheConfiguredSubject(): void
    {
        // Arrange
        $root = sys_get_temp_dir() . '/vapid-subject-' . bin2hex(random_bytes(4));
        mkdir($root . '/' . Vapid::DIRECTORY, 0700, true);

        $pair = Vapid::generate();
        file_put_contents($root . '/' . Vapid::DIRECTORY . '/' . Vapid::PRIVATE_FILE, $pair['privateKey']);
        file_put_contents($root . '/' . Vapid::DIRECTORY . '/' . Vapid::PUBLIC_FILE, $pair['publicKey']);

        \Pramnos\Application\Settings::clearSettings();
        \Pramnos\Application\Settings::setSetting('admin_mail', 'ops@example.com', false);

        try {
            // Assert
            $this->assertSame('mailto:ops@example.com', Vapid::load($root)['subject']);
            $this->assertTrue(Vapid::configured($root));
        } finally {
            \Pramnos\Application\Settings::clearSettings();
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    /**
     * An empty key file is treated as no key at all.
     *
     * A truncated write — a full disk, a killed process — leaves a file that exists and says
     * nothing. Loading it would produce a pair that fails on every send with no explanation.
     */
    public function testAnEmptyKeyFileIsNotAPair(): void
    {
        // Arrange
        $root = sys_get_temp_dir() . '/vapid-empty-' . bin2hex(random_bytes(4));
        mkdir($root . '/' . Vapid::DIRECTORY, 0700, true);
        touch($root . '/' . Vapid::DIRECTORY . '/' . Vapid::PRIVATE_FILE);
        touch($root . '/' . Vapid::DIRECTORY . '/' . Vapid::PUBLIC_FILE);

        try {
            // Assert
            $this->assertNull(Vapid::load($root));
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
