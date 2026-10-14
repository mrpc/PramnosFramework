<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\HelpController;
use Pramnos\Assist\Help;

/**
 * `/admin/Help` in each theme: the pages by section and a search, one page drawn with its links
 * and pictures, and a picture served only from inside a help directory.
 */
#[CoversClass(HelpController::class)]
#[CoversClass(Help::class)]
class HelpScreenTest extends TestCase
{
    private const THEMES = ['bootstrap', 'tailwind', 'plain-css'];

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pf-helpscreen-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/images', 0777, true);
        file_put_contents($this->dir . '/refunds.md', "---\nsection: Billing\nquestions:\n  - How do I refund?\nupdated: 2026-10-01\n---\n# Refunds\n\nSee [orders](help:orders) and ![The button](images/b.png).");
        file_put_contents($this->dir . '/orders.md', "# Orders\n\nEvery <order>.");
        file_put_contents($this->dir . '/images/b.png', "\x89PNG");
        Help::useDirectories([$this->dir]);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        Help::useDirectories(null);
        foreach (['/images/b.png', '/refunds.md', '/orders.md'] as $file) {
            @unlink($this->dir . $file);
        }
        @rmdir($this->dir . '/images');
        @rmdir($this->dir);
        $_GET = [];
    }

    /**
     * The list by section with each page's question, a search that finds the page, and one
     * that finds nothing — in every theme.
     */
    public function testTheListAndASearch(): void
    {
        foreach (self::THEMES as $theme) {
            // Act
            $_GET = [];
            $list = (string) (new HelpScreen($theme))->display();
            $_GET = ['q' => 'refund'];
            $found = (string) (new HelpScreen($theme))->display();
            $_GET = ['q' => 'zebra'];
            $none = (string) (new HelpScreen($theme))->display();

            // Assert
            $this->assertStringContainsString('>Billing</h2>', $list, $theme . ': by section');
            $this->assertStringContainsString('/Help/page?p=refunds">Refunds</a>', $list, $theme);
            $this->assertStringContainsString('How do I refund?', $list, $theme . ': what each page answers');
            $this->assertStringContainsString('Pages for “refund”', $found, $theme);
            $this->assertStringContainsString('value="refund"', $found, $theme . ': the search kept in its box');
            $this->assertStringContainsString('No page answers that.', $none, $theme);
        }
    }

    /** One page: its section, its text with links and pictures, and when it was updated. */
    public function testOnePage(): void
    {
        foreach (self::THEMES as $theme) {
            // Act
            $_GET = ['p' => 'refunds'];
            $page = (string) (new HelpScreen($theme))->page();
            $_GET = ['p' => 'orders'];
            $orders = (string) (new HelpScreen($theme))->page();

            // Assert
            $this->assertStringContainsString('· Billing', $page, $theme);
            $this->assertStringContainsString('<a href="/Help/page?p=orders">orders</a>', $page, $theme . ': a link by name');
            $this->assertStringContainsString('<img src="/Help/image?f=images%2Fb.png" alt="The button"', $page, $theme);
            $this->assertStringContainsString('Updated 2026-10-01', $page, $theme);
            $this->assertStringContainsString('Every &lt;order&gt;.', $orders, $theme . ': escaped like a model\'s answer');
        }

        // A page that is not there goes back to the list
        $_GET   = ['p' => 'nothing'];
        $screen = new HelpScreen('plain-css');
        $this->assertNull($screen->page());
        $this->assertSame(['/Help'], $screen->redirectedTo);
    }

    /** A picture from inside the directory is served; anything else is not found. */
    public function testPictures(): void
    {
        // Act
        $_GET   = ['f' => 'images/b.png'];
        $served = (new HelpScreen('plain-css'))->image();
        $_GET   = ['f' => '../../../etc/passwd'];
        $refused = (new HelpScreen('plain-css'))->image();

        // Assert
        $this->assertSame("\x89PNG", $served);
        $this->assertSame('', $refused);
        $this->assertSame(404, http_response_code());
        http_response_code(200);
    }

    /** Every action asks for somebody signed in; the screen is the `admin.help` menu item's. */
    public function testItNeedsSomebodySignedIn(): void
    {
        // Arrange
        $screen = new HelpController(null);

        // Act
        $auth    = (new \ReflectionProperty($screen, 'actions_auth'))->getValue($screen);
        $ability = (new \ReflectionProperty($screen, 'adminAbility'))->getValue($screen);

        // Assert
        $this->assertSame([], array_diff(['display', 'page', 'image'], $auth));
        $this->assertSame('admin.help', $ability);
    }

    /** Whoever may enter the area may read its help: the area's floor, not a screen's. */
    public function testTheFloorIsTheAreas(): void
    {
        // Arrange
        \Pramnos\Http\AdminArea::reset();
        $floor = new \ReflectionMethod(HelpController::class, 'adminFloor');

        // Act / Assert
        $this->assertSame(0, $floor->invoke(new HelpScreen('plain-css')));
    }
}

/** The screen, drawing the chosen theme's views from the scaffolding. */
final class HelpScreen extends HelpController
{
    /** @var list<string> */
    public array $redirectedTo = [];

    public function __construct(private string $theme)
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

    /** The view, as View draws it, from the chosen theme's scaffolding. */
    public function &getView($name = '', $type = '', $args = array())
    {
        $dir  = ROOT . '/scaffolding/themes/' . $this->theme . '/views/' . $name;
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
