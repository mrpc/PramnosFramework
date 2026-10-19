<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\HelpController;
use Pramnos\Assist\Help;
use Pramnos\Assist\HelpBook;
use Pramnos\Assist\WhatsNew;
use Pramnos\User\User;

/** A person of the administration whose settings are kept in memory, not in the database. */
final class WhatsNewReader extends User
{
    /** @var array<string, mixed> */
    public array $settings = [];

    public function __construct(int $userid = 7, int $usertype = 90)
    {
        $this->userid   = $userid;
        $this->usertype = $usertype;
    }

    /** The setting, from memory. */
    public function getSetting(string $setting, $default = null)
    {
        return $this->settings[$setting] ?? $default;
    }

    /** The setting, into memory. */
    public function setSetting(string $setting, $value, ?int $by = null): bool
    {
        $this->settings[$setting] = $value;

        return true;
    }
}

/** The help screen without an application, its redirects caught. */
final class WhatsNewScreen extends HelpController
{
    /** @var list<string> */
    public array $redirectedTo = [];

    public function __construct()
    {
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo[] = (string) $url;
    }
}

/**
 * What's new (FW-168): the help pages announced by `whatsnew:` in their front matter, in a window
 * each person of the administration closes for themselves, and a header button that opens it
 * again while something was announced lately.
 */
#[CoversClass(WhatsNew::class)]
#[CoversClass(HelpBook::class)]
#[CoversClass(HelpController::class)]
final class WhatsNewTest extends TestCase
{
    private string $dir = '';

    private mixed $features = null;

    private ?array $instances = null;

    protected function setUp(): void
    {
        $enabled        = new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled');
        $this->features = $enabled->getValue();
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['help']);

        $this->dir = sys_get_temp_dir() . '/pf-whatsnew-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $day = static fn (int $ago): string => date('Y-m-d', strtotime('-' . $ago . ' days'));
        file_put_contents($this->dir . '/today.md', "---\ntitle: Two <currencies>\nsection: Changelog\ndate: " . $day(0) . "\nwhatsnew:\n  - Invoices in dollars.\n  - Reports in both.\n---\nBody.");
        file_put_contents($this->dir . '/week.md', "---\ntitle: Faster search\nsection: Changelog\nupdated: " . $day(5) . "\nwhatsnew: [Search answers at once.]\n---\nBody.");
        file_put_contents($this->dir . '/old.md', "---\ntitle: Dark mode\nsection: Changelog\ndate: " . $day(30) . "\nwhatsnew:\n  - A dark theme.\n---\nBody.");
        file_put_contents($this->dir . '/plain.md', "---\ntitle: Not announced\nsection: Changelog\ndate: " . $day(0) . "\n---\nBody.");
        Help::useDirectories([$this->dir]);
        \Pramnos\Http\AdminArea::useServingUrl('https://example.com/admin/');
        $this->app([]);
        WhatsNew::forget();
        $_POST = [];
    }

    protected function tearDown(): void
    {
        \Pramnos\Http\AdminArea::useServingUrl(null);
        \Pramnos\Http\RequestIdentity::reset();
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, $this->features);
        (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))->setValue(null, $this->instances);
        Help::useDirectories(null);
        WhatsNew::forget();
        array_map('unlink', glob($this->dir . '/*.md') ?: []);
        @rmdir($this->dir);
        $_POST = [];
    }

    /** An application with these `help.whatsnew` settings. */
    private function app(array $whatsnew): void
    {
        $app = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $app->applicationInfo = ['help' => ['whatsnew' => $whatsnew]];
        $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $this->instances ??= $ref->getValue() ?? [];
        $ref->setValue(null, ['default' => $app]);
        WhatsNew::forget();
    }

    /**
     * Only a page with `whatsnew:` is announced — as a list on lines or on one — newest first by
     * its `date` (a post's) or `updated` (a page's), and the lines are on the page.
     */
    public function testWhatIsAnnounced(): void
    {
        // Act
        $announced = WhatsNew::announced();
        $page      = Help::admin()->page('today');

        // Assert
        $this->assertSame(['today', 'week', 'old'], array_column($announced, 'slug'));
        $this->assertSame(['Invoices in dollars.', 'Reports in both.'], $page['whatsnew']);
        $this->assertSame(date('Y-m-d'), $page['updated'], 'a post\'s date is when it is from');
        $this->assertSame([], Help::admin()->page('plain')['whatsnew']);
    }

    /**
     * The first time, everything dated before today counts as seen — a newcomer is not shown the
     * history, but today's announcement still reaches them — and that is kept. Closing it makes
     * everything announced seen. Nobody else has a window: a visitor, the anonymous user, a
     * person below the area's floor, or anybody with the help off.
     */
    public function testWhoSeesWhat(): void
    {
        // Arrange
        $me = new WhatsNewReader();

        // Act
        $first  = array_column(WhatsNew::unseen($me), 'slug');
        $kept   = $me->settings[WhatsNew::SETTING];
        $again  = array_column(WhatsNew::unseen($me), 'slug');
        WhatsNew::dismiss($me);
        $closed = WhatsNew::unseen($me);
        $this->app(['min_usertype' => 95]);
        $below  = WhatsNew::unseen(new WhatsNewReader());

        // Assert
        $this->assertSame(['today'], $first);
        $this->assertSame(['week', 'old'], $kept);
        $this->assertSame(['today'], $again);
        $this->assertSame([], $closed);
        $this->assertSame(['today', 'week', 'old'], $me->settings[WhatsNew::SETTING]);
        $this->assertSame([], $below, 'below the floor app.php sets');
        $this->assertFalse(WhatsNew::eligible(null));
        $this->assertFalse(WhatsNew::eligible(new WhatsNewReader(1)), 'the anonymous user');
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, []);
        $this->assertFalse(WhatsNew::eligible(new WhatsNewReader()), 'the help off');
    }

    /**
     * Recent is what was announced in the last fourteen days, today included — or as many as
     * app.php says; and the window shows three in full unless it says otherwise.
     */
    public function testWhatIsRecent(): void
    {
        // Act
        $default  = array_column(WhatsNew::recent(), 'slug');
        $tomorrow = array_column(WhatsNew::recent(null, date('Y-m-d', strtotime('+1 day'))), 'slug');
        $this->app(['recent_days' => 3, 'shown' => 0]);
        $short   = array_column(WhatsNew::recent(), 'slug');

        // Assert
        $this->assertSame(['today', 'week'], $default);
        $this->assertSame(['today'], $short);
        $this->assertSame(1, WhatsNew::shown(), 'never fewer than one');
        $this->assertSame(['today', 'week'], $tomorrow, 'counted from the day given');
    }

    /**
     * The window opens by itself while something is unseen: its posts in full, escaped, each with
     * More, the rest counted, and Close a POST with the session's token. Closed and opened again
     * from the header it holds what was announced lately, and Close is only a click. With
     * nothing unseen or recent there is neither window nor button.
     */
    public function testTheWindowAndTheButton(): void
    {
        // Arrange
        $me = new WhatsNewReader();
        $me->settings[WhatsNew::SETTING] = [];
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $this->app(['shown' => 1]);

        // Act
        $open   = WhatsNew::window();
        $button = WhatsNew::button('<li>', '</li>');
        WhatsNew::dismiss($me);
        $again  = WhatsNew::window();
        $this->app(['recent_days' => 0]);
        file_put_contents($this->dir . '/today.md', str_replace(date('Y-m-d'), '2000-01-01', (string) file_get_contents($this->dir . '/today.md')));
        Help::useDirectories([$this->dir]);
        WhatsNew::forget();
        $none     = WhatsNew::window();
        $noButton = WhatsNew::button('<li>', '</li>');

        // Assert
        $this->assertStringContainsString('class="pf-whatsnew-toggle" checked', $open, 'open as drawn');
        $this->assertStringContainsString('Two &lt;currencies&gt;', $open);
        $this->assertStringContainsString('<li>Invoices in dollars.</li>', $open);
        $this->assertStringContainsString('name="more" value="today"', $open);
        $this->assertStringContainsString('2 more announcements in the help', $open);
        $this->assertStringContainsString('action="https://example.com/admin/Help/whatsnew"', $open);
        $this->assertStringContainsString('name="_csrf_token"', $open);
        $this->assertStringContainsString('<button type="submit" class="pf-whatsnew-close">', $open);
        $this->assertStringStartsWith('<li><label for="pf-whatsnew" class="pf-whatsnew-button"', $button);
        $this->assertStringNotContainsString('checked', $again, 'closed, to be opened from the header');
        $this->assertStringContainsString('1 more announcement in the help', $again);
        $this->assertStringContainsString('<label for="pf-whatsnew" class="pf-whatsnew-close">', $again);
        $this->assertSame('', $none);
        $this->assertSame('', $noButton, 'nor its list item');
    }

    /** Back after closing it: a path of this site only — not another host, however it is written. */
    public function testWhereClosingItGoesBack(): void
    {
        // Act + Assert
        $this->assertSame('/admin/Users?page=2', WhatsNew::back('/admin/Users?page=2'));
        foreach (['//evil.example/x', '/\\evil.example', 'https://evil.example/', ''] as $elsewhere) {
            $this->assertSame('https://example.com/admin/', WhatsNew::back($elsewhere), $elsewhere);
        }
    }

    /**
     * The screen's action marks everything seen and goes on: to the post whose More was pressed,
     * to the help for the older ones, or back to the page — one of this site's. A More that names
     * no page goes back. With the help off it is not there.
     */
    public function testTheScreensAction(): void
    {
        // Arrange
        $me = new WhatsNewReader();
        \Pramnos\Http\RequestIdentity::seal($me, 'test');
        $screen = new WhatsNewScreen();

        // Act
        foreach ([['more' => 'week'], ['more' => 'news'], ['more' => 'nope', 'return' => '/admin/Users'], ['return' => '//evil.example']] as $post) {
            $_POST = $post;
            $screen->whatsnew();
        }
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, []);
        $off = $screen->whatsnew();

        // Assert
        $this->assertSame([
            'https://example.com/admin/Help/page?p=week',
            'https://example.com/admin/Help',
            '/admin/Users',
            'https://example.com/admin/',
        ], $screen->redirectedTo);
        $this->assertSame(['today', 'week', 'old'], $me->settings[WhatsNew::SETTING], 'everything seen');
        $this->assertSame('', $off);
    }

    /**
     * Every theme `init` writes has both hooks — the button in its header, the window in its
     * footer — so an application gets What's new without adding it; one missing in one theme is
     * the failure this is for.
     */
    public function testEveryThemeHasTheHooks(): void
    {
        // Arrange
        $init   = new \Pramnos\Console\Commands\Init();
        $header = new \ReflectionMethod($init, 'buildThemeHeader');
        $footer = new \ReflectionMethod($init, 'buildThemeFooter');

        foreach (['bootstrap', 'tailwind', 'plain'] as $ui) {
            // Act
            $top    = (string) $header->invoke($init, $ui, 'App', ['libraries' => []]);
            $bottom = (string) $footer->invoke($init, $ui, 'App', ['libraries' => []]);

            // Assert
            $this->assertStringContainsString('\Pramnos\Assist\WhatsNew::button(', $top, $ui . ': the header\'s button');
            $this->assertStringContainsString('\Pramnos\Assist\WhatsNew::window()', $bottom, $ui . ': the footer\'s window');
        }
    }
}

