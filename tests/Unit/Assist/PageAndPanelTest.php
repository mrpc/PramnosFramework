<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Controller;
use Pramnos\Assist\Help;
use Pramnos\Assist\Page;
use Pramnos\Assist\Panel;
use Pramnos\Llm\Analyst\AnalystAssistant;
use Pramnos\Llm\Analyst\Desk;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Turn;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * The page an administrator is on, as the side panel knows it and the analyst is told it: the
 * screen each controller run records, the record a screen's id names, what comes back from the
 * browser checked — and the panel offering Ask, help, both, or nothing.
 */
#[CoversClass(Page::class)]
#[CoversClass(Panel::class)]
#[CoversClass(Controller::class)]
#[CoversClass(Desk::class)]
#[CoversClass(AnalystAssistant::class)]
class PageAndPanelTest extends LlmTestCase
{
    private string $help = '';

    /** @var mixed The enabled features before this test */
    private mixed $features = null;

    protected function setUp(): void
    {
        parent::setUp();
        $enabled        = new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled');
        $this->features = $enabled->getValue();
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['help']);
        Page::reset();
        $this->help = sys_get_temp_dir() . '/pf-page-' . bin2hex(random_bytes(4));
        mkdir($this->help);
        file_put_contents($this->help . '/posts.md', "# Posts\n\nEvery post, **published** or not.");
        Help::useDirectories([$this->help]);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, $this->features);
        Page::reset();
        Help::useDirectories(null);
        @unlink($this->help . '/posts.md');
        @rmdir($this->help);
        $_GET = [];
        unset($_SERVER['REQUEST_URI']);
        parent::tearDown();
    }

    /** A controller of the application's, which says what one record of it is. */
    private function posts(): Controller
    {
        return new class (null) extends Controller {
            protected string $assistSubject = 'post';

            public function __construct($app)
            {
                parent::__construct($app);
            }

            public function edit()
            {
                return 'edited';
            }
        };
    }

    /**
     * The first controller of a request is the page: its screen named after its class, its action,
     * and — where it declares what a record is — the record the address's id names.
     */
    public function testAControllerRunRecordsThePage(): void
    {
        // Arrange
        $_GET['_option'] = '12';

        // Act
        $this->posts()->exec('edit');
        $first = [Page::screen(), Page::action(), Page::subject()];
        Page::entered(new \stdClass(), 'other', 'thing');

        // Assert
        $this->assertSame('', $first[0], 'an anonymous class has no name a help page could have');
        $this->assertSame('edit', $first[1]);
        $this->assertSame(['kind' => 'post', 'id' => '12', 'label' => ''], $first[2]);
        $this->assertSame('edit', Page::action(), 'a later controller in the same request is not the page');
    }

    /** A screen named `UsersController` is `users`; one named after its address keeps that name. */
    public function testTheScreensName(): void
    {
        // Act
        Page::entered(new \Pramnos\Application\Controllers\UsersController(null), '');
        $framework = Page::screen();
        $action    = Page::action();
        Page::reset();
        Page::about('user', 5, 'maria');
        Page::entered(new \Pramnos\Queue\Controllers\QueueController(null), 'retry');

        // Assert
        $this->assertSame('users', $framework);
        $this->assertSame('display', $action, 'no action is display');
        $this->assertSame(['kind' => 'user', 'id' => '5', 'label' => 'maria'], Page::subject(), 'an action that named its record keeps it');
    }

    /**
     * What the browser sends back is kept to the known keys, short, and a screen name only of
     * the characters a file name can have — it finds a help page, and nothing else.
     */
    public function testWhatComesBackIsChecked(): void
    {
        // Act
        $page = Page::fromInput([
            'screen' => '../Users', 'action' => 'Edit', 'title' => "Edit\nmaria", 'url' => '/admin/users/edit/5',
            'kind' => 'user', 'id' => ['5'], 'label' => str_repeat('x', 500), 'extra' => 'dropped',
        ]);

        // Assert
        $this->assertSame('users', $page['screen']);
        $this->assertSame('edit', $page['action']);
        $this->assertSame('Edit maria', $page['title'], 'no line breaks into the prompt');
        $this->assertSame('', $page['id'], 'not a value');
        $this->assertSame(200, mb_strlen($page['label']));
        $this->assertArrayNotHasKey('extra', $page);
        $this->assertNull(Page::fromInput('nothing'));
        $this->assertNull(Page::fromInput(['title' => 'no screen, no address']));
    }

    /**
     * The analyst is told the page, what "this" means on it, and the page's help — and an
     * address or a label is redacted like everything else that reaches the provider.
     */
    public function testTheAnalystIsToldThePage(): void
    {
        // Arrange
        $assistant = new AnalystAssistant();
        $page      = Page::fromInput(['screen' => 'posts', 'title' => 'Edit post', 'url' => '/admin/posts/edit/12?by=maria@example.com', 'kind' => 'post', 'id' => '12', 'label' => 'Spring']);
        $turn      = new Turn(new Scope(1), null, false, [], [], ['page' => $page], new Conversations($assistant));
        $plain     = new Turn(new Scope(1), null, false, [], [], [], new Conversations($assistant));

        // Act
        $prompt = $assistant->systemPrompt($turn);
        $none   = $assistant->systemPrompt($plain);
        $bare   = Page::prompt(Page::fromInput(['url' => '/admin/x']));

        // Assert
        $this->assertStringContainsString('"Edit post" at /admin/posts/edit/12?by=m***@e***.com', $prompt);
        $this->assertStringContainsString('It shows one post, id 12 ("Spring")', $prompt);
        $this->assertStringContainsString('Every post, **published** or not.', $prompt, 'the page\'s help, as background');
        $this->assertStringNotContainsString('asking from a page', $none, 'no page, nothing said');
        $this->assertStringContainsString('When the question says "this" or "here", it means that page.', $bare);
        $this->assertSame('', Page::prompt(null));
    }

    /** The panel sends the page with a question; the desk keeps only what Page lets through. */
    public function testTheDeskSendsThePage(): void
    {
        // Arrange
        $desk  = new Desk(7);
        $extra = new \ReflectionMethod($desk, 'extra');

        // Act
        $with    = $extra->invoke($desk, ['content' => 'x', 'page' => ['screen' => 'posts', 'url' => '/admin/posts']]);
        $without = $extra->invoke($desk, ['content' => 'x']);

        // Assert
        $this->assertSame('posts', $with['page']['screen']);
        $this->assertSame(['area'], array_keys($without), 'only the area\'s URL, for the links the worker makes');
        $this->assertSame(\Pramnos\Http\AdminArea::url(), $without['area']);
    }

    /**
     * A link made in a queue job goes under the area's URL the asking request handed it — the
     * worker knows neither its host nor, running the site's application, the area's prefix —
     * and anything handed in that is not an address is not linked to.
     */
    public function testAnAddressInTheAreaFromAWorker(): void
    {
        // Arrange
        \Pramnos\Http\AdminArea::reset();
        \Pramnos\Http\SiteUrl::reset();
        $app = \Pramnos\Application\Application::getInstance();
        $was = $app->applicationInfo['admin'] ?? null;

        try {
            // Act
            $handed   = \Pramnos\Http\AdminArea::address('Ai/export/7-x', 'https://example.com/admin/');
            $relative = \Pramnos\Http\AdminArea::address('/Help', '/admin');
            $bogus    = \Pramnos\Http\AdminArea::address('Help', 'javascript:alert(1)//');
            $app->applicationInfo['admin'] = ['prefix' => '/manage/'];
            $prefixed = \Pramnos\Http\AdminArea::address('Help');
            \Pramnos\Http\AdminArea::detect('manage');
            $inside   = \Pramnos\Http\AdminArea::address('Help');
        } finally {
            $app->applicationInfo['admin'] = $was;
            \Pramnos\Http\AdminArea::reset();
        }

        // Assert
        $this->assertSame('https://example.com/admin/Ai/export/7-x', $handed);
        $this->assertSame('/admin/Help', $relative);
        $this->assertStringNotContainsString('javascript', $bogus);
        $this->assertStringEndsWith('manage/Help', $prefixed, 'no base: the configured prefix');
        $this->assertStringEndsWith('/manage/Help', $inside, 'inside the area: its own URL');
        $this->assertSame('https://example.com/admin/Help/page?p=users', Help::url('users', 'https://example.com/admin/'));
        $this->assertStringStartsWith('https://example.com/admin/Help', (string) ((new \Pramnos\Llm\Analyst\Tools\HelpTool('https://example.com/admin'))->execute(['page' => 'posts'])['link']));

        // And the analyst hands the tools the area its question came with
        $assistant = new AnalystAssistant();
        $turn      = new Turn(new Scope(7), null, false, [], [], ['area' => 'https://example.com/admin/'], new Conversations($assistant));
        $link      = ($assistant->tools($turn)['help']['run'])(['page' => 'posts']);
        $this->assertStringContainsString('https://example.com/admin/Help/page?p=posts', $link);
    }

    /**
     * Ask and help, Ask alone, help alone, or nothing — and on Ask's own page the link it
     * always was, since the panel would be the page twice.
     */
    public function testWhatThePanelOffers(): void
    {
        // Arrange
        $_SERVER['REQUEST_URI'] = '/admin/posts';
        \Pramnos\Framework\Factory::getDocument()->title = 'All <posts>';
        Page::entered(new class (null) extends Controller {
            public function __construct($app)
            {
                parent::__construct($app);
            }
        }, '');
        $screen = new \ReflectionProperty(Page::class, 'screen');
        $screen->setValue(null, 'posts');

        // Act
        $both     = Panel::render(true, '/admin/Ai', '<li>', '</li>');
        $helpOnly = Panel::render(false, '/admin/Ai');
        $screen->setValue(null, 'orders');
        $askOnly  = Panel::render(true, '/admin/Ai');
        $nothing  = Panel::render(false, '/admin/Ai');
        $screen->setValue(null, 'ai');
        $onAsk    = Panel::render(true, '/admin/Ai');

        // Assert
        $this->assertStringStartsWith('<li><a class="pf-ask" href="/admin/Ai" data-pf-assist-open', $both);
        $this->assertStringContainsString('data-pf-assist-tab="help"', $both, 'two tabs');
        $this->assertStringContainsString('<strong>published</strong>', $both, 'the help, already drawn');
        $this->assertStringContainsString('&quot;title&quot;:&quot;All &lt;posts&gt;&quot;', $both, 'the page, escaped, to send back');
        $this->assertStringContainsString('<strong style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">All &lt;posts&gt;</strong>', $both);
        $this->assertStringEndsWith('pf-assist.js" defer></script></li>', $both);
        $this->assertStringContainsString('>Help</a>', $helpOnly, 'without the analyst, the help');
        $this->assertStringNotContainsString('data-pf-assist-pane="ask"', $helpOnly);
        $this->assertStringNotContainsString('data-pf-assist-tab', $askOnly, 'one pane, no tabs');
        $this->assertStringContainsString('data-pf-assist-pane="ask"', $askOnly);
        $this->assertSame('', $nothing, 'neither: no button');
        $this->assertSame('<a class="pf-ask" href="/admin/Ai">Ask</a>', $onAsk);

        // Without the `help` feature: no Help tab, and no help for the analyst either
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, []);
        $screen->setValue(null, 'posts');
        $this->assertStringNotContainsString('data-pf-assist-pane="help"', Panel::render(true, '/admin/Ai'), 'Ask alone');
        $this->assertSame('', Panel::render(false, '/admin/Ai'), 'and without the analyst, nothing');
        $this->assertStringNotContainsString('help says', Page::prompt(Page::fromInput(['screen' => 'posts'])));
        $this->assertArrayNotHasKey('help', \Pramnos\Llm\Analyst\Analyst::tools(7));
    }
}
