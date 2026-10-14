<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\SiteHelpController;
use Pramnos\Assist\Help;
use Pramnos\Assist\HelpBook;
use Pramnos\Assist\Page;
use Pramnos\Assist\Panel;
use Pramnos\Http\AdminArea;

/**
 * Help on two levels: the administration's pages and the site's, read apart.
 *
 * The administration's pages describe settings and workflows a visitor has no business reading,
 * so what matters most here is the wall between them: the site's help never falls back to the
 * administration's pages or the framework's, its screen is open to visitors, and the side panel
 * on a site page shows the site's page — to a visitor too.
 */
#[CoversClass(Help::class)]
#[CoversClass(HelpBook::class)]
#[CoversClass(SiteHelpController::class)]
#[CoversClass(Panel::class)]
class HelpLevelsTest extends TestCase
{
    private string $base = '';

    /** @var mixed The enabled features before this test */
    private mixed $features = null;

    protected function setUp(): void
    {
        $enabled        = new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled');
        $this->features = $enabled->getValue();
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['help']);

        $this->base = sys_get_temp_dir() . '/pf-helplevels-' . bin2hex(random_bytes(4));
        $files = [
            '/admin/posts.md'        => "# Posts, for editors\n\nThe moderation queue is under Settings.",
            '/framework/users.md'    => "# Users\n\nEvery account.",
            '/site/posts.md'         => "---\nsection: Reading\n---\n# Reading a post\n\nPick one from the list. ![A post](images/p.png)",
            '/site/faq.md'           => "---\nquestions:\n  - How do I sign up?\n---\n# Questions\n\nSign up from the top right.",
            '/site/images/p.png'     => "\x89PNG",
            '/admin/images/secret.png' => 'admin only',
        ];
        foreach ($files as $file => $content) {
            if (!is_dir(dirname($this->base . $file))) {
                mkdir(dirname($this->base . $file), 0777, true);
            }
            file_put_contents($this->base . $file, $content);
        }
        Help::useDirectories([$this->base . '/admin', $this->base . '/framework']);
        Help::useSiteDirectories([$this->base . '/site']);
        AdminArea::reset();
        Page::reset();
        \Pramnos\Http\RequestIdentity::reset();
        $_GET = [];
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, $this->features);
        Help::useDirectories(null);
        Help::useSiteDirectories(null);
        AdminArea::reset();
        Page::reset();
        \Pramnos\Http\RequestIdentity::reset();
        $_GET = [];
        unset($_SERVER['REQUEST_URI']);
        if (str_starts_with($this->base, sys_get_temp_dir() . '/pf-helplevels-')) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->base);
        }
    }

    /**
     * Each level reads its own pages only: the site has no administration page and none of the
     * framework's, even where a slug exists only there, and its pages are its own.
     */
    public function testTheTwoLevelsAreReadApart(): void
    {
        // Act
        $site  = Help::site();
        $admin = Help::admin();

        // Assert
        $this->assertSame(['faq', 'posts'], array_column($site->all(), 'slug'));
        $this->assertNull($site->page('users'), 'the framework\'s pages are the administration\'s');
        $this->assertSame('Reading a post', $site->forScreen('posts')['title']);
        $this->assertFalse($site->page('posts')['framework']);
        $this->assertSame('', $site->page('faq')['section'], 'no "Administration" section on the site');
        $this->assertSame('faq', $site->search('sign up')[0]['slug']);
        $this->assertNull($admin->page('faq'));
        $this->assertSame('Posts, for editors', Help::forScreen('posts')['title'], 'the static methods stay the administration\'s');
        $this->assertSame([$this->base . '/site'], $site->directories());
        $this->assertSame(HelpBook::SITE, $site->level());
    }

    /**
     * The level follows the administration area: the site's outside it, the administration's in
     * it — and the administration's everywhere in an application with no area, as before.
     */
    public function testTheLevelFollowsTheArea(): void
    {
        // Act / Assert — no area at all
        $this->assertSame(HelpBook::ADMIN, Help::level());
        $this->assertSame(HelpBook::ADMIN, Help::book()->level());

        // A site page, with an area configured
        $_GET = ['r' => 'Posts'];
        AdminArea::detect('admin');
        $this->assertSame(HelpBook::SITE, Help::level());
        $this->assertSame('Reading a post', Help::book()->forScreen('posts')['title']);

        // A page inside the area
        AdminArea::reset();
        $_GET = ['r' => 'admin/Posts'];
        AdminArea::detect('admin');
        $this->assertSame(HelpBook::ADMIN, Help::level());
        $this->assertSame(HelpBook::SITE, Help::book(HelpBook::SITE)->level(), 'a level asked for by name');
    }

    /**
     * The site's pages link to the site's help screen, and only pictures inside its directories
     * are served — not the administration's.
     */
    public function testTheSitesAddressesAndPictures(): void
    {
        // Arrange
        $site = Help::site();
        $root = defined('sURL') ? (string) \sURL : '/';

        // Act
        $html = $site->pageHtml($site->page('posts'));

        // Assert
        $this->assertSame($root . 'Help/page?p=faq', $site->url('faq'));
        $this->assertSame('https://example.com/Help', $site->url('', 'https://example.com/'), 'a worker\'s base');
        $this->assertStringContainsString('src="' . $root . 'Help/image?f=images%2Fp.png"', $html);
        $this->assertNotNull($site->image('images/p.png'));
        $this->assertNull($site->image('images/secret.png'), 'the administration\'s picture is not the site\'s');
        $this->assertNull($site->image('../admin/images/secret.png'));
        $this->assertNull($site->image('faq.md'), 'not a picture');
    }

    /**
     * The site's help screen: open to a visitor, with no ability or floor, and showing only the
     * site's pages — an administration page asked for by name is not there.
     */
    public function testTheSitesScreenIsOpenAndShowsOnlyItsPages(): void
    {
        // Arrange
        $screen = new SiteHelpScreen();
        $real   = new SiteHelpController(null);

        // Act
        $list = (string) $screen->display();
        $_GET = ['p' => 'posts'];
        $page = (string) $screen->page();
        $_GET = ['p' => 'users'];
        $missing = $screen->page();
        $_GET = ['f' => 'images/secret.png'];
        $picture = $screen->image();

        // Assert
        $this->assertStringContainsString('>Questions</a>', $list);
        $this->assertStringNotContainsString('Posts, for editors', $list);
        $this->assertStringNotContainsString('>Users</a>', $list, 'nor the framework\'s');
        $this->assertStringContainsString('Pick one from the list.', $page);
        $this->assertNull($missing);
        $this->assertSame([Help::site()->url()], $screen->redirectedTo);
        $this->assertSame('', $picture);
        $this->assertSame([], (new \ReflectionProperty($real, 'actions_auth'))->getValue($real), 'no sign-in');
        $this->assertSame('', (new \ReflectionProperty($real, 'adminAbility'))->getValue($real), 'not an administration screen');
        http_response_code(200);
    }

    /**
     * On a site page the header's button opens the site's page for that screen — to a visitor
     * as well — while a visitor gets nothing inside the administration.
     */
    public function testTheSitePanelShowsTheSitesHelpToAVisitor(): void
    {
        // Arrange — a visitor on the site's Posts page
        $_SERVER['REQUEST_URI'] = '/Posts';
        $_GET = ['r' => 'Posts'];
        AdminArea::detect('admin');
        (new \ReflectionProperty(Page::class, 'screen'))->setValue(null, 'posts');

        // Act
        $onSite = \Pramnos\Llm\Analyst\Analyst::headerButton('<li>', '</li>');
        AdminArea::reset();
        $_GET = ['r' => 'admin/Posts'];
        AdminArea::detect('admin');
        $inArea = \Pramnos\Llm\Analyst\Analyst::headerButton();

        // Assert
        $this->assertStringStartsWith('<li><a class="pf-ask"', $onSite);
        $this->assertStringContainsString('Pick one from the list.', $onSite);
        $this->assertStringNotContainsString('moderation queue', $onSite, 'never the administration\'s page');
        $this->assertStringNotContainsString('data-pf-assist-pane="ask"', $onSite, 'help alone for a visitor');
        $this->assertSame('', $inArea);
    }

    /**
     * Each level finds its own screen: the site's `Controllers\Help` extending
     * `SiteHelpController`, the administration's extending `HelpController` — and never the
     * other's, though one extends the other.
     */
    public function testEachLevelFindsItsOwnScreen(): void
    {
        // Arrange
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;

        try {
            $app->applicationInfo['namespace'] = 'Pramnos\\Tests\\Unit\\Assist\\Levels';
            $siteOnly = Help::site()->screen();
            $adminFromSite = Help::admin()->screen();
            $app->applicationInfo['namespace'] = 'Pramnos\\Tests\\Unit\\Assist\\NoScreens';
            $none = [Help::site()->screen(), Help::admin()->screen()];
            $app->applicationInfo['namespace'] = '';
            $empty = Help::site()->screen();
        } finally {
            $app->applicationInfo = $info;
        }

        // Assert
        $this->assertSame(Levels\Controllers\Help::class, $siteOnly);
        $this->assertSame(Levels\Admin\Controllers\Help::class, $adminFromSite, 'the area\'s, not the site\'s Controllers\\Help');
        $this->assertSame(['', ''], $none);
        $this->assertSame('', $empty);
    }

    /**
     * Where the site's pages are: `help.site.paths` when the application names them, else
     * `app/help-site` — and a missing directory is no pages rather than an error.
     */
    public function testTheSitesDirectoriesComeFromTheConfiguration(): void
    {
        // Arrange
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;
        Help::useSiteDirectories(null);

        try {
            // Act
            $default = Help::siteDirectories();
            $app->applicationInfo['help'] = ['site' => ['paths' => [$this->base . '/site', $this->base . '/nowhere']]];
            $named = Help::siteDirectories();
            Help::forget();
            $pages = array_column(Help::site()->all(), 'slug');
        } finally {
            $app->applicationInfo = $info;
            Help::forget();
        }

        // Assert
        $this->assertSame([ROOT . '/app/help-site'], $default);
        $this->assertSame([$this->base . '/site', $this->base . '/nowhere'], $named);
        $this->assertSame(['faq', 'posts'], $pages, 'the missing directory adds nothing');
    }

    /**
     * The edges of reading a level: a nested screen name is no screen, a page only the framework
     * wrote is still found, an empty question finds nothing, and forgetting reads again.
     */
    public function testTheEdgesOfALevel(): void
    {
        // Arrange
        $admin = Help::admin();

        // Act / Assert
        $this->assertNull($admin->forScreen('posts/edit'), 'a screen name has no slash');
        $this->assertSame('Users', $admin->forScreen('users', 'edit')['title'], 'the framework\'s, where the application wrote none');
        $this->assertSame([], $admin->search('a b'), 'no word long enough');
        Help::site()->all();
        file_put_contents($this->base . '/site/late.md', '# Late');
        $this->assertNull(Help::site()->page('late'), 'read once');
        Help::site()->forget();
        $this->assertSame('Late', Help::site()->page('late')['title'], 'and again after forget()');
    }

    /**
     * The administration's static methods are the administration book's: its addresses are the
     * area's, and its pages draw their pictures from there.
     */
    public function testTheStaticMethodsAreTheAdministrations(): void
    {
        // Arrange
        AdminArea::useServingUrl('https://example.com/admin/');

        try {
            // Act
            $image = Help::imageUrl('images/secret.png');
            $html  = Help::pageHtml(['body' => '![x](images/secret.png)', 'slug' => 'posts']);
            $url   = Help::admin()->url('posts');
        } finally {
            AdminArea::useServingUrl(null);
        }

        // Assert
        $this->assertSame('https://example.com/admin/Help/image?f=images%2Fsecret.png', $image);
        $this->assertStringContainsString('src="https://example.com/admin/Help/image?f=images%2Fsecret.png"', $html);
        $this->assertSame('https://example.com/admin/Help/page?p=posts', $url);
        $this->assertNotNull(Help::image('images/secret.png'));
    }

    /**
     * Where the site has its help screen, the panel on a site page links to it: the page, and
     * every page.
     */
    public function testTheSitePanelLinksToTheSitesScreen(): void
    {
        // Arrange
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;
        $_SERVER['REQUEST_URI'] = '/Posts';
        $_GET = ['r' => 'Posts'];
        AdminArea::detect('admin');
        (new \ReflectionProperty(Page::class, 'screen'))->setValue(null, 'posts');

        try {
            $app->applicationInfo['namespace'] = 'Pramnos\\Tests\\Unit\\Assist\\Levels';

            // Act
            $panel = Panel::render(false, '');
        } finally {
            $app->applicationInfo = $info;
        }

        // Assert
        $root = defined('sURL') ? (string) \sURL : '/';
        $this->assertStringContainsString('href="' . $root . 'Help/page?p=posts" data-pf-assist-open', $panel);
        $this->assertStringContainsString('<a href="' . $root . 'Help">All help</a>', $panel);
    }

    /**
     * The site's help button as the application says: the word by default, a muted question mark
     * with `'icon'`, or a `HelpButton` of its own — every one carrying the attribute the script
     * opens the panel by. An administrator's Ask is not changed by it.
     */
    public function testTheSitesButtonAsTheApplicationSays(): void
    {
        // Arrange
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;
        $_SERVER['REQUEST_URI'] = '/Posts';
        $_GET = ['r' => 'Posts'];
        AdminArea::detect('admin');
        (new \ReflectionProperty(Page::class, 'screen'))->setValue(null, 'posts');
        $button = static function (mixed $setting) use ($app, $info): array {
            $app->applicationInfo = $info;
            $app->applicationInfo['help'] = ['site' => ['button' => $setting]];

            return [Panel::render(false, ''), Panel::render(true, '/admin/Ai')];
        };

        try {
            // Act
            [$word]          = $button(null);
            [$icon, $askToo] = $button('icon');
            [$own]           = $button(OwnHelpButton::class);
            [$wrong]         = $button(\stdClass::class);
        } finally {
            $app->applicationInfo = $info;
        }

        // Assert
        $this->assertStringContainsString('<a class="pf-ask" href="#" data-pf-assist-open aria-haspopup="dialog">Help</a>', $word);
        $this->assertStringContainsString('<a class="pf-ask pf-help-icon" href="#" data-pf-assist-open aria-haspopup="dialog" aria-label="Help" title="Help">?</a>', $icon);
        $this->assertStringContainsString('<button class="mine" data-pf-assist-open aria-haspopup="dialog" data-page="posts">Help: Reading a post</button>', $own);
        $this->assertStringContainsString('<template data-pf-assist>', $own, 'the panel is still the framework\'s');
        $this->assertStringContainsString('>Help</a>', $wrong, 'not a HelpButton: the word');
        $this->assertStringContainsString('<a class="pf-ask" href="/admin/Ai" data-pf-assist-open aria-haspopup="dialog">Ask</a>', $askToo);
    }

    /**
     * The Help menu item takes the area's floor as the area knows it — from `AdminArea::serve()`
     * in an application that is the area, where app.php may have no `admin` block — rather than
     * opening to every member while the screen behind it refuses them.
     */
    public function testTheHelpItemTakesTheServedAreasFloor(): void
    {
        // Arrange
        $app  = \Pramnos\Application\Application::getInstance();
        $info = $app->applicationInfo;
        $app->applicationInfo['namespace'] = 'Pramnos\\Tests\\Unit\\Assist\\Levels';
        unset($app->applicationInfo['admin']);
        AdminArea::serve('admin', 90);

        try {
            // Act
            \Pramnos\Application\NavRegistry::reset();
            $app->registerDefaultNavItems(['help']);
            $items = array_column(array_map(static fn ($i) => ['id' => $i->id, 'item' => $i], \Pramnos\Application\NavRegistry::all()), 'item', 'id');
            $item  = $items['admin.help'] ?? null;
        } finally {
            $app->applicationInfo = $info;
            \Pramnos\Application\NavRegistry::reset();
        }

        // Assert
        $this->assertNotNull($item);
        $this->assertSame(90, $item->minUserType);
    }
}

/** An application's own site help button. */
final class OwnHelpButton implements \Pramnos\Assist\HelpButton
{
    public function render(string $href, string $label, string $attributes, array $page): string
    {
        return '<button class="mine" ' . $attributes . ' data-page="' . $page['slug'] . '">' . $label . ': ' . $page['title'] . '</button>';
    }
}

namespace Pramnos\Tests\Unit\Assist\Levels\Controllers;

/** A site help screen, as `init` writes it. */
class Help extends \Pramnos\Application\Controllers\SiteHelpController
{
}

namespace Pramnos\Tests\Unit\Assist\Levels\Admin\Controllers;

/** An administration help screen, as `init` writes it. */
class Help extends \Pramnos\Application\Controllers\HelpController
{
}

namespace Pramnos\Tests\Unit\Assist;

/** The site's screen, drawing the plain-css theme's views from the scaffolding. */
final class SiteHelpScreen extends \Pramnos\Application\Controllers\SiteHelpController
{
    /** @var list<string> */
    public array $redirectedTo = [];

    public function __construct()
    {
        // Not parent::__construct(): it registers actions against an application this test does not have.
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo[] = (string) $url;
    }

    protected function addError($error)
    {
        return $this;
    }

    /** The view, as View draws it, from the plain-css theme's scaffolding. */
    public function &getView($name = '', $type = '', $args = array())
    {
        $dir  = ROOT . '/scaffolding/themes/plain-css/views/' . $name;
        $view = new class (new \Pramnos\Application\Controller(), $dir, $name) extends \Pramnos\Application\View {
            public function display($tpl = '', $render = false)
            {
                $file = $this->path . '/' . $tpl . '.html.php';
                ob_start();
                \Closure::bind(function () use ($file): void {
                    include $file;
                }, $this, \Pramnos\Application\View::class)();

                return (string) ob_get_clean();
            }
        };

        return $view;
    }
}
