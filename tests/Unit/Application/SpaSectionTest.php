<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\SpaSection;
use Pramnos\Cache\Page\PageCache;
use Pramnos\Http\AdminArea;
use Pramnos\Testing\TestClient;

/**
 * A single-page section inside a server page: the mount element, the entry's tags, who may open
 * it, and that every address under its controller serves it.
 *
 * What a section relies on: the page hands it its base and the API configuration without an
 * inline script; its tags come from the dev server while it runs, then the build's manifest, then
 * the entry file; a viewer the ability refuses gets nothing; the page is never page-cached; and
 * a deep link — `/admin/Users/edit/5` — is the section's page too, so a reload lands where it was.
 */
#[CoversClass(SpaSection::class)]
#[CoversClass(Controller::class)]
class SpaSectionTest extends TestCase
{
    private string $web = '';

    /** @var array<string, mixed> */
    private array $info = [];

    /** @var array{0: mixed, 1: mixed} The application registry before the test, put back after it */
    private array $registry = [];

    protected function setUp(): void
    {
        $this->web = sys_get_temp_dir() . '/pf-section-' . bin2hex(random_bytes(4));
        mkdir($this->web . '/assets/spa/.vite', 0777, true);
        SpaSection::useWebRoot($this->web);
        $this->info = Application::getInstance()->applicationInfo;
        $this->registry = [
            (new \ReflectionProperty(Application::class, 'appInstances'))->getValue(),
            (new \ReflectionProperty(Application::class, 'lastUsedApplication'))->getValue(),
        ];
        Application::getInstance()->applicationInfo['spa_sections'] = [
            'users'  => [],
            'secret' => ['ability' => 'admin.secret', 'entry' => 'assets/js/sections/secret.js'],
        ];
        Application::getInstance()->applicationInfo['api_prefix'] = '/api/v2/';
        PageCache::resetRuntime();
        AdminArea::reset();
        \Pramnos\Http\RequestIdentity::reset();
    }

    protected function tearDown(): void
    {
        SpaSection::useWebRoot(null);
        // The stub application of the deep-link test made itself the current one: put the
        // registry back, then the config of the application it held.
        (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->registry[0]);
        (new \ReflectionProperty(Application::class, 'lastUsedApplication'))->setValue(null, $this->registry[1]);
        Application::getInstance()->applicationInfo = $this->info;
        PageCache::resetRuntime();
        AdminArea::reset();
        \Pramnos\Http\RequestIdentity::reset();
        http_response_code(200);
        if (str_starts_with($this->web, sys_get_temp_dir() . '/pf-section-')) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->web, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->web);
        }
    }

    /**
     * The mount element carries the base, the API's prefix and key, and the props, escaped — no
     * inline script for a CSP to refuse — and the page is kept out of the page cache.
     */
    public function testTheMountElementCarriesWhatTheSectionNeeds(): void
    {
        // Arrange
        file_put_contents($this->web . '/assets/spa/.vite/manifest.json', json_encode([
            'frontend/sections/users/main.js' => ['file' => 'section-users-abc.js', 'css' => ['section-users-def.css']],
        ]));

        // Act
        $html = SpaSection::render('users', '/admin/Users/', ['name' => '<Anna>']);

        // Assert
        $this->assertStringStartsWith('<div data-pf-section="users" data-base="/admin/Users"', $html);
        $this->assertStringContainsString('data-config="{&quot;apiPrefix&quot;:&quot;/api/v2&quot;,&quot;apiKey&quot;:&quot;', $html);
        $this->assertStringContainsString('data-props="{&quot;name&quot;:&quot;&lt;Anna&gt;&quot;}"', $html);
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/spa/section-users-def.css"><script type="module" src="/assets/spa/section-users-abc.js"></script>', $html);
        $this->assertStringNotContainsString('<script>', $html, 'nothing inline');
        $this->assertSame('spa section', PageCache::bypassReason());
    }

    /**
     * The dev server first, while its hot file is there; a build-less entry from the web root,
     * stamped; and nothing at all is a comment and a log line, not a broken tag.
     */
    public function testTheTagsComeFromTheDevServerTheBuildOrTheFile(): void
    {
        // Arrange
        file_put_contents($this->web . '/assets/spa/.vite/hot', "http://localhost:5173\n");

        // Act
        $dev = SpaSection::tags('frontend/sections/users/main.js');
        unlink($this->web . '/assets/spa/.vite/hot');
        mkdir($this->web . '/assets/js/sections', 0777, true);
        file_put_contents($this->web . '/assets/js/sections/secret.js', 'export {};');
        $file = SpaSection::tags('assets/js/sections/secret.js');
        file_put_contents($this->web . '/assets/spa/.vite/manifest.json', '{}');
        $none = SpaSection::tags('frontend/sections/nothing/main.js');

        // Assert
        $this->assertSame('<script type="module" src="http://localhost:5173/assets/spa/@vite/client"></script>'
            . '<script type="module" src="http://localhost:5173/assets/spa/frontend/sections/users/main.js"></script>', $dev);
        $this->assertMatchesRegularExpression('#^<script type="module" src="/assets/js/sections/secret\.js\?v=\d+"></script>$#', $file);
        $this->assertSame('<!-- spa section: frontend/sections/nothing/main.js is not built -->', $none);
    }

    /** A viewer the section's ability refuses gets nothing, with a 403; an undeclared section is an error. */
    public function testWhoMayOpenIt(): void
    {
        // Act
        $refused = SpaSection::render('secret', '/admin/Secret');
        $status  = http_response_code();

        // Assert
        $this->assertSame('', $refused);
        $this->assertSame(403, $status);
        $this->expectException(\InvalidArgumentException::class);
        SpaSection::render('nowhere', '/');
    }

    /**
     * Every address under the controller is the section's page: `/admin/Users/edit/5` reaches
     * `display()`, whose section works under `/admin/Users` — inside the area, and on the site
     * without one.
     */
    public function testADeepLinkServesTheSection(): void
    {
        // Arrange — an application with an area, whose Users screen is a section. Its config is
        // taken first: constructing it makes it the instance getInstance() answers.
        $info = Application::getInstance()->applicationInfo;
        $app = new class extends Application {
            public $initialized = true;

            public function getController($controller, $userPermissions = [])
            {
                return new SectionScreen($this);
            }
        };
        $app->applicationInfo = $info;
        $app->applicationInfo['admin'] = ['prefix' => 'admin'];
        $client = new TestClient($app);

        // Act
        $inArea = (string) $client->get('/admin/Users/edit/5')->getResponse()->getBody();
        $onSite = (string) $client->get('/Users/edit/5')->getResponse()->getBody();

        // Assert
        $this->assertStringContainsString('<div data-pf-section="users" data-base="/admin/Users"', $inArea);
        $this->assertStringContainsString('<div data-pf-section="users" data-base="/Users"', $onSite);
    }
}

/** A screen whose page is a section. */
final class SectionScreen extends Controller
{
    public function __construct($application = null)
    {
        parent::__construct($application);
        $this->addaction('display');
    }

    public function display()
    {
        return $this->spaSection('users');
    }
}
