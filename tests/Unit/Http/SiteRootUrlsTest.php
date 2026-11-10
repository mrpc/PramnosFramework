<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Http\AdminArea;
use Pramnos\Http\SiteUrl;

/**
 * An address of the administration area, and a static file's, from a front controller that is not
 * at the site's root — an API served from `www/api/`, whose `sURL` ends in `api/`: built on the
 * site's configured root, not on `sURL`. Without a configured root, and without an area, as before.
 */
#[CoversClass(AdminArea::class)]
class SiteRootUrlsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $info = [];

    /** @var array<string, mixed> */
    private array $server = [];

    /** A scratch web root. */
    private string $root = '';

    /** The application, the request and a scratch web root kept. */
    protected function setUp(): void
    {
        $this->info   = Application::getInstance()->applicationInfo;
        $this->server = $_SERVER;
        $this->root   = sys_get_temp_dir() . '/pf-siteroot-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/api/assets', 0777, true);
        mkdir($this->root . '/assets', 0777, true);
        file_put_contents($this->root . '/assets/site.js', 'x');
        file_put_contents($this->root . '/api/assets/own.js', 'x');
        $_SERVER['DOCUMENT_ROOT']   = $this->root;
        $_SERVER['SCRIPT_FILENAME'] = $this->root . '/api/index.php';
        AdminArea::reset();
        SiteUrl::reset();
    }

    /** Everything given back. */
    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        $_SERVER = $this->server;
        AdminArea::reset();
        SiteUrl::reset();
        array_map('unlink', [$this->root . '/assets/site.js', $this->root . '/api/assets/own.js']);
        rmdir($this->root . '/api/assets');
        rmdir($this->root . '/api');
        rmdir($this->root . '/assets');
        rmdir($this->root);
    }

    /**
     * With `site_url` configured: the area's address is the site's root and the prefix; a file at
     * the site's root is under the site's root, one beside the front controller under `sURL`.
     * Without an area, an address is under `sURL` as ever.
     */
    public function testTheSitesRootWhenItIsConfigured(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['site_url'] = 'https://site.test/';
        AdminArea::detect('admin');

        // Act
        $area = AdminArea::url('Conversations');
        $site = assetUrl('assets/site.js');
        $own  = assetUrl('assets/own.js');
        $told = assetUrl('assets/site.js', 'https://cdn.test/');
        AdminArea::reset();
        $none = AdminArea::url('Conversations');

        // Assert
        $this->assertSame('https://site.test/admin/Conversations', $area);
        $this->assertStringStartsWith('https://site.test/assets/site.js?v=', $site);
        $this->assertStringStartsWith(\sURL . 'assets/own.js?v=', $own, 'beside the front controller: its own');
        $this->assertStringStartsWith('https://cdn.test/assets/site.js', $told, 'a base given is kept');
        $this->assertSame(\sURL . 'Conversations', $none, 'no area: sURL, as ever');
    }

    /** Without a configured root, `sURL` as before, for both. */
    public function testSurlWithoutAConfiguredRoot(): void
    {
        // Arrange
        unset(Application::getInstance()->applicationInfo['site_url']);
        putenv('APP_URL');
        unset($_ENV['APP_URL']);
        AdminArea::detect('admin');

        // Act / Assert
        $this->assertSame(\sURL . 'admin/Conversations', AdminArea::url('Conversations'));
        $this->assertStringStartsWith(\sURL . 'assets/site.js?v=', assetUrl('assets/site.js'));
    }
}
