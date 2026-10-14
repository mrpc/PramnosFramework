<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Assist\Help;
use Pramnos\Assist\HelpApi;
use Pramnos\Http\Request;
use Pramnos\Routing\Router;

/**
 * The help pages as JSON, for a SPA or a mobile application.
 *
 * The site's pages to anybody and the administration's only to its administrators — the same
 * wall as the server-rendered levels, at an endpoint that a token reaches without a session. And
 * a page arrives ready to draw: links between pages as `?help=` for the front end to open in
 * place, pictures inline, since an `<img>` carries no bearer token.
 */
#[CoversClass(HelpApi::class)]
class HelpApiTest extends TestCase
{
    private string $base = '';

    /** @var mixed The enabled features before this test */
    private mixed $features = null;

    protected function setUp(): void
    {
        $enabled        = new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled');
        $this->features = $enabled->getValue();
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['help']);

        $this->base = sys_get_temp_dir() . '/pf-helpapi-' . bin2hex(random_bytes(4));
        $files = [
            '/admin/users.md'      => "# Users, for editors\n\nEvery account.",
            '/site/posts.md'       => "---\nsection: Reading\nquestions:\n  - How do I read a post?\nupdated: 2026-10-01\n---\n# Reading a post\n\nSee [the questions](help:faq). ![A post](images/p.png) ![Big](images/big.png)",
            '/site/faq.md'         => "# Questions\n\nSign up from the top right.",
            '/site/images/p.png'   => "\x89PNG",
            '/site/images/big.png' => str_repeat('x', HelpApi::MAX_IMAGE_BYTES + 1),
        ];
        foreach ($files as $file => $content) {
            if (!is_dir(dirname($this->base . $file))) {
                mkdir(dirname($this->base . $file), 0777, true);
            }
            file_put_contents($this->base . $file, $content);
        }
        Help::useDirectories([$this->base . '/admin']);
        Help::useSiteDirectories([$this->base . '/site']);
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Http\AdminArea::reset();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, $this->features);
        Help::useDirectories(null);
        Help::useSiteDirectories(null);
        \Pramnos\Http\RequestIdentity::reset();
        $_GET = [];
        if (str_starts_with($this->base, sys_get_temp_dir() . '/pf-helpapi-')) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->base);
        }
    }

    /** The site's pages to a visitor, listed and searched, with no session and no token. */
    public function testTheSitesPagesToAVisitor(): void
    {
        // Act
        $list = (new HelpApi())->list();
        $_GET = ['q' => 'sign up'];
        $found = (new HelpApi())->list();

        // Assert
        $this->assertSame(200, $list['status']);
        $this->assertSame('site', $list['data']['level']);
        $this->assertSame(['faq', 'posts'], array_column($list['data']['pages'], 'slug'));
        $this->assertSame(['How do I read a post?'], $list['data']['pages'][1]['questions']);
        $this->assertSame('faq', $found['data']['pages'][0]['slug']);
        $this->assertSame('sign up', $found['data']['query']);
    }

    /**
     * A page arrives drawn: a link between pages as `?help=`, a picture inline, and one too big
     * to inline as its alt text.
     */
    public function testAPageArrivesReadyToDraw(): void
    {
        // Arrange
        $_GET = ['p' => 'posts'];

        // Act
        $page = (new HelpApi())->page()['data'];

        // Assert
        $this->assertSame('Reading a post', $page['title']);
        $this->assertSame('2026-10-01', $page['updated']);
        $this->assertStringContainsString('<a href="?help=faq">the questions</a>', $page['html']);
        $this->assertStringContainsString('src="data:image/png;base64,' . base64_encode("\x89PNG") . '"', $page['html']);
        $this->assertStringNotContainsString('src="data:image/png;base64,eHh4', $page['html'], 'the big one is not inlined');
        $this->assertStringContainsString('Big', $page['html'], 'its alt text instead');
    }

    /** A screen's page as the panel finds it; a screen or page without one is 404, not an error. */
    public function testAScreensPageAndWhatIsNotThere(): void
    {
        // Act
        $_GET = ['screen' => 'posts', 'action' => 'display'];
        $screen = (new HelpApi())->screen();
        $_GET = ['screen' => 'orders'];
        $none = (new HelpApi())->screen();
        $_GET = ['p' => 'users'];
        $adminPage = (new HelpApi())->page();

        // Assert
        $this->assertSame('posts', $screen['data']['slug']);
        $this->assertSame(['status' => 404, 'error' => 'This screen has no help page.'], $none);
        $this->assertSame(404, $adminPage['status'], 'the administration\'s page is not the site\'s');
    }

    /**
     * The administration's pages only to whoever may open its Help screen: refused to a visitor
     * and to a member, given to an administrator.
     */
    public function testTheAdministrationsPagesAreForItsAdministrators(): void
    {
        // Arrange
        $_GET = ['level' => 'admin', 'p' => 'users'];

        // Act
        $visitor = (new HelpApi())->page();
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 5, 'usertype' => 1], 'test');
        // The area's floor, as `admin.min_usertype` sets it — known on an API request too.
        \Pramnos\Http\AdminArea::detect('admin', 98);
        $member = (new HelpApi())->page();
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 2, 'usertype' => 99], 'test');
        $admin = (new HelpApi())->page();
        \Pramnos\Http\AdminArea::reset();

        // Assert
        $this->assertSame(403, $visitor['status']);
        $this->assertSame(403, $member['status']);
        $this->assertSame('Users, for editors', $admin['data']['title']);
    }

    /** Without the `help` feature there is no such endpoint. */
    public function testWithoutTheFeatureThereIsNothing(): void
    {
        // Arrange
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, []);

        // Act / Assert
        $this->assertSame(404, (new HelpApi())->list()['status']);
        $this->assertSame(404, (new HelpApi())->page()['status']);
        $this->assertSame(404, (new HelpApi())->screen()['status']);
    }

    /** The three routes, under the path given — the fixed ones answered by their own action. */
    public function testTheRoutes(): void
    {
        // Arrange
        $router = new Router(null);
        HelpApi::routes($router, 'docs/help/');
        $_GET = ['p' => 'faq', 'screen' => 'posts'];

        // Act
        $answers = [];
        foreach (['/docs/help', '/docs/help/page', '/docs/help/screen'] as $uri) {
            $route = $router->getMatchedRoute(Request::create($uri, 'GET'));
            $this->assertNotNull($route, $uri);
            $answers[$uri] = ($route->action)();
        }

        // Assert
        $this->assertArrayHasKey('pages', $answers['/docs/help']['data']);
        $this->assertSame('faq', $answers['/docs/help/page']['data']['slug']);
        $this->assertSame('posts', $answers['/docs/help/screen']['data']['slug']);
    }
}
