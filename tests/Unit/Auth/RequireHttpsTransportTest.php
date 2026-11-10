<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\ApplicationSettings;

/**
 * Which plain-HTTP requests an application's `require_https` lets in (FW-403): loopback, no
 * address, and only the addresses the installation names — in app.php's `plain_http_from` or the
 * environment's `PRAMNOS_PLAIN_HTTP_FROM`.
 *
 * A browser on the host reaching a container through Docker's port mapping arrives from the
 * bridge's gateway, not from loopback, and was refused on a developer's own machine. The fix must
 * not be a wider default: a private range is also where a production proxy sits.
 */
#[CoversClass(ApplicationSettings::class)]
final class RequireHttpsTransportTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
        putenv('PRAMNOS_PLAIN_HTTP_FROM');
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        putenv('PRAMNOS_PLAIN_HTTP_FROM');
    }

    /** Whether `$address` over plain HTTP is refused, `require_https` on. */
    private static function refused(string $address, array $server = []): bool
    {
        return ApplicationSettings::refusesTransport(['require_https' => true], $address, $server);
    }

    /**
     * With nothing named, only loopback and no address pass over plain HTTP — the Docker gateway
     * and a private proxy's address are refused as a public one is — and HTTPS, direct or
     * forwarded, always passes.
     */
    public function testNothingNamedNothingWidened(): void
    {
        // Act / Assert
        $this->assertFalse(self::refused('127.0.0.1'));
        $this->assertFalse(self::refused('::1'));
        $this->assertFalse(self::refused(''), 'no address: no network');
        $this->assertTrue(self::refused('172.17.0.1'), 'the Docker gateway, not named: refused');
        $this->assertTrue(self::refused('10.0.0.5'), 'a private proxy, not named: refused');
        $this->assertTrue(self::refused('203.0.113.9'));
        $this->assertFalse(self::refused('203.0.113.9', ['HTTPS' => 'on']));
        $this->assertFalse(self::refused('203.0.113.9', ['HTTP_X_FORWARDED_PROTO' => 'https']));
        $this->assertFalse(ApplicationSettings::refusesTransport(['require_https' => false], '203.0.113.9', []), 'off: nothing refused');
        $this->assertSame([], ApplicationSettings::plainHttpFrom());
    }

    /**
     * Named in app.php, an address or a range passes; its neighbour does not. Named in the
     * environment — as a development container sets it — with commas between several, the same.
     */
    public function testNamedAddressesPass(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['plain_http_from'] = ['172.17.0.1', ' '];

        // Act
        $gateway   = self::refused('172.17.0.1');
        $neighbour = self::refused('172.17.0.2');
        putenv('PRAMNOS_PLAIN_HTTP_FROM=192.168.65.0/24, 10.9.0.0/16');
        $fromEnv   = [self::refused('192.168.65.1'), self::refused('10.9.8.7'), self::refused('10.10.0.1')];

        // Assert
        $this->assertFalse($gateway, 'named in app.php');
        $this->assertTrue($neighbour, 'only what is named');
        $this->assertSame([false, false, true], $fromEnv, 'named in the environment, both ranges; outside them refused');
        $this->assertSame(['172.17.0.1', '192.168.65.0/24', '10.9.0.0/16'], ApplicationSettings::plainHttpFrom(), 'blanks left out');
    }
}
