<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Conversations\Beacon\Config;
use Pramnos\Conversations\Beacon\EmergencyKey;
use Pramnos\Conversations\Beacon\Signature;

/**
 * The beacon's two kinds of trust: a device's emergency key, which the primary alone can make and
 * the beacon only check; and a signed request between servers, which is refused when it is old,
 * altered, or signed with another secret.
 */
#[CoversClass(EmergencyKey::class)]
#[CoversClass(Signature::class)]
#[CoversClass(Config::class)]
final class BeaconKeysTest extends TestCase
{
    /** Read the settings again for the next test. */
    protected function tearDown(): void
    {
        Config::readFrom(null);
        Config::use(null);
    }

    /**
     * The application's settings file for the primary's health: the one named — under the root,
     * or absolute — else `app/config/settings.php`, else `app/settings.php`, else none.
     */
    public function testTheApplicationsSettingsFile(): void
    {
        // Arrange
        $root = sys_get_temp_dir() . '/beacon-root-' . bin2hex(random_bytes(4));
        mkdir($root . '/app/config', 0777, true);

        // Act
        Config::use([]);
        $none = Config::settingsFile($root);
        touch($root . '/app/settings.php');
        $plain = Config::settingsFile($root);
        touch($root . '/app/config/settings.php');
        $nested = Config::settingsFile($root);
        Config::use(['settings_file' => 'etc/mine.php']);
        $named = Config::settingsFile($root);
        Config::use(['settings_file' => '/srv/settings.php']);
        $absolute = Config::settingsFile($root);
        exec('rm -rf ' . escapeshellarg($root));

        // Assert
        $this->assertNull($none);
        $this->assertSame([$root . '/app/settings.php', $root . '/app/config/settings.php', $root . '/etc/mine.php', '/srv/settings.php'],
            [$plain, $nested, $named, $absolute]);
    }

    /**
     * The settings file is read once, as a PHP file returning an array; one that returns anything
     * else is no settings, and app.php's are used.
     */
    public function testTheSettingsFile(): void
    {
        // Arrange
        $file = (string) tempnam(sys_get_temp_dir(), 'beacon');
        file_put_contents($file, "<?php return ['role' => 'beacon', 'channel' => 'έκτακτο'];");
        $bad = (string) tempnam(sys_get_temp_dir(), 'beacon');
        file_put_contents($bad, "<?php return 'nothing';");

        // Act
        Config::readFrom($file);
        $read = [Config::value('role'), Config::channel()];
        Config::readFrom($bad);
        $none = Config::value('role');
        @unlink($file);
        @unlink($bad);

        // Assert
        $this->assertSame(['beacon', 'έκτακτο'], $read);
        $this->assertNull($none, 'not an array: app.php\'s, which has none');
    }

    /**
     * A key made with the primary's private key is read by the beacon's public one, with who it
     * is; one altered, expired, signed by another key or naming an unknown key id is nothing; and
     * a primary without its signing key makes none.
     */
    public function testAKeyIsMadeByThePrimaryAndCheckedByTheBeacon(): void
    {
        // Arrange
        $pair  = EmergencyKey::generate();
        $other = EmergencyKey::generate();
        Config::use(['role' => 'primary', 'signing_key' => $pair['signing_key'], 'kid' => 'k1',
            'public_keys' => ['k1' => $pair['public_key'], 'k0' => $other['public_key']]]);

        // Act
        $key     = EmergencyKey::mint(5, 'Ελένη Σ.', 'device-1', 1_800_000_000);
        $claims  = EmergencyKey::verify($key, 1_800_000_100);
        $expired = EmergencyKey::verify($key, 1_800_000_000 + EmergencyKey::LIFETIME + 1);
        [$payload, $sig] = explode('.', $key);
        $altered = EmergencyKey::verify(rtrim(strtr(base64_encode(str_replace('"u":5', '"u":6', (string) base64_decode(strtr($payload, '-_', '+/')))), '+/', '-_'), '=') . '.' . $sig, 1_800_000_100);
        Config::use(['public_keys' => ['k1' => $other['public_key']]]);
        $wrongKey = EmergencyKey::verify($key, 1_800_000_100);
        Config::use(['public_keys' => []]);
        $unknown = EmergencyKey::verify($key, 1_800_000_100);
        $refused = null;
        try {
            EmergencyKey::mint(5, 'x', 'd');
        } catch (\RuntimeException $e) {
            $refused = $e->getMessage();
        }

        // Assert
        $this->assertSame([5, 'Ελένη Σ.', 'device-1', 'k1', 1_800_000_000 + EmergencyKey::LIFETIME],
            [$claims['u'], $claims['n'], $claims['d'], $claims['kid'], $claims['exp']]);
        $this->assertNull($expired, 'thirty days');
        $this->assertNull($altered, 'a key that says someone else is nobody');
        $this->assertNull($wrongKey, 'another key\'s signature');
        $this->assertNull($unknown, 'a key id the beacon does not know');
        $this->assertNull(EmergencyKey::verify('nonsense'));
        $this->assertNull(EmergencyKey::verify('a.b'));
        $this->assertStringContainsString('signing_key', (string) $refused);
    }

    /**
     * A request signed with the secret, now, is believed; altered, old, from another secret,
     * unsigned, or where there is no secret at all, it is not.
     */
    public function testASignedRequest(): void
    {
        // Arrange
        $headers = Signature::headers('s3cret', '{"a":1}', 1_800_000_000);

        // Act
        $checks = [
            Signature::verify('s3cret', $headers['X-Beacon-Time'], $headers['X-Beacon-Signature'], '{"a":1}', 1_800_000_100),
            Signature::verify('s3cret', $headers['X-Beacon-Time'], $headers['X-Beacon-Signature'], '{"a":2}', 1_800_000_100),
            Signature::verify('s3cret', $headers['X-Beacon-Time'], $headers['X-Beacon-Signature'], '{"a":1}', 1_800_000_000 + Signature::WINDOW + 1),
            Signature::verify('other', $headers['X-Beacon-Time'], $headers['X-Beacon-Signature'], '{"a":1}', 1_800_000_100),
            Signature::verify('s3cret', null, null, '{"a":1}'),
            Signature::verify('', $headers['X-Beacon-Time'], $headers['X-Beacon-Signature'], '{"a":1}', 1_800_000_100),
            Signature::verify('s3cret', 'soon', $headers['X-Beacon-Signature'], '{"a":1}'),
        ];

        // Assert
        $this->assertSame([true, false, false, false, false, false, false], $checks);
    }

    /**
     * The settings: a test's, then app.php's when there is no file; the beacon's own lines in the
     * team's words, else English; whether there is a beacon; the channel's name.
     */
    public function testTheSettings(): void
    {
        // Arrange
        Config::use(['role' => 'beacon', 'words' => ['down' => 'Πέσαμε: %s']]);

        // Act
        $words = [Config::words('down'), Config::words('back'), Config::words('nothing')];
        $state = [Config::enabled(), Config::channel(), Config::value('missing', 'x')];
        Config::use(['role' => 'neither', 'channel' => 'έκτακτο']);

        // Assert
        $this->assertSame(['Πέσαμε: %s', '✓ Back since %s, after %s minutes.', 'nothing'], $words);
        $this->assertSame([true, 'emergency', 'x'], $state);
        $this->assertSame([false, 'έκτακτο'], [Config::enabled(), Config::channel()]);
        Config::use(null);
        $this->assertIsArray(Config::get(), 'read from app.php when there is no app/beacon.php');
    }
}
