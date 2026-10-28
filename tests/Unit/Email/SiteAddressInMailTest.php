<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Email\EmailTheme;
use Pramnos\Email\Unsubscribe;
use Pramnos\Http\SiteUrl;

/**
 * Links in mail lead to the site the application names (APP_URL), not to whatever `sURL` a cron
 * run was given: the unsubscribe link, and the wrapper's `$siteurl` where no `site_url` setting
 * says otherwise.
 */
#[CoversClass(Unsubscribe::class)]
#[CoversClass(EmailTheme::class)]
class SiteAddressInMailTest extends TestCase
{
    private string|false $saved = false;

    protected function setUp(): void
    {
        $this->saved = getenv('APP_URL');
        putenv('APP_URL=https://site.example.test');
        $_ENV['APP_URL'] = 'https://site.example.test';
        SiteUrl::reset();
    }

    protected function tearDown(): void
    {
        $this->saved === false ? putenv('APP_URL') : putenv('APP_URL=' . $this->saved);
        unset($_ENV['APP_URL']);
        SiteUrl::reset();
        Settings::setSetting('site_url', null, false);
    }

    public function testTheUnsubscribeLinkFollowsTheSitesAddress(): void
    {
        // Act
        $url = Unsubscribe::url('tok');

        // Assert
        $this->assertSame('https://site.example.test/unsubscribe?u=tok', $url);
    }

    public function testTheWrapperGetsTheSitesAddressWithoutASetting(): void
    {
        // Arrange — a wrapper that prints its address
        $dir = sys_get_temp_dir() . '/pf-wrap-' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir . '/w.php', '<?php echo $siteurl;');
        Settings::setSetting('site_url', '', false);

        // Act
        $html = (new \ReflectionMethod(EmailTheme::class, 'render'))->invoke(null, $dir . '/w.php', '', []);
        exec('rm -rf ' . escapeshellarg($dir));

        // Assert
        $this->assertSame('https://site.example.test/', $html);
    }
}
