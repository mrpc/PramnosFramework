<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Push;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\PushLogController;

/**
 * The screen exists, and something links to it.
 *
 * The recurring failure this whole area keeps producing: machinery that is complete and never
 * reached. A `PushLogController` with a view in every theme and no navigation item is a screen
 * only somebody who read the source can find — which, for a screen that exists to answer «τα
 * push που στάλθηκαν πού τα βλέπω;», is the same as not having built it.
 */
#[CoversClass(PushLogController::class)]
class PushLogScreenTest extends TestCase
{
    private const THEMES = ['tailwind', 'bootstrap', 'plain-css'];

    /**
     * Every theme has the view the controller asks for.
     *
     * `getView('pushlog')` resolves per theme, and a theme without the file gets the
     * framework's scaffold view — a different screen from the other two, with none of this on it.
     */
    public function testEveryThemeHasTheView(): void
    {
        foreach (self::THEMES as $theme) {
            // Assert
            $this->assertFileExists(
                dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme
                . '/views/pushlog/pushlog.html.php',
                $theme . ' has no push log view'
            );
        }
    }

    /**
     * The refusals are on the same screen as the deliveries.
     *
     * "Nothing subscribed" is the commonest answer to why a notification never arrived, and it
     * is only useful beside the sends that worked: a screen listing successes alone makes an
     * installation with no key pair look identical to one where everything is arriving.
     */
    public function testTheScreenShowsWhatWasNotSent(): void
    {
        foreach (self::THEMES as $theme) {
            // Act
            $view = (string) file_get_contents(
                dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme
                . '/views/pushlog/pushlog.html.php'
            );

            // Assert
            $this->assertStringContainsString('Not sent', $view, $theme);
            $this->assertStringContainsString('Subscription gone', $view, $theme);
            $this->assertStringContainsString("\$row['error']", $view,
                $theme . ' does not show why nothing was sent');
            $this->assertStringNotContainsString("\$row['endpoint']", $view,
                $theme . ' must not put the endpoint on a screen — it is a credential, and the '
                . 'table does not hold it');
        }
    }

    /**
     * The navigation reaches it.
     */
    public function testTheNavigationReachesIt(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Application/Application.php'
        );

        // Assert
        $this->assertStringContainsString("'admin.pushlog'", $source);
        $this->assertStringContainsString("\$admin('PushLog')", $source);
    }

    /**
     * And a scaffolded project gets the wrapper that puts it on an address.
     *
     * The area resolves `src/Admin/Controllers` first; without a wrapper there, `/admin/PushLog`
     * is a 404 in every project the framework generates.
     */
    public function testAScaffoldedProjectGetsTheWrapper(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Pramnos/Console/Commands/Init.php'
        );

        // Assert
        $this->assertStringContainsString("src/Admin/Controllers/PushLog.php", $source);
        $this->assertStringContainsString('class PushLog extends FrameworkPushLogController', $source);
    }

    /**
     * The user card links to this account's own notifications.
     *
     * «They say they did not get it» is asked about one person, on their screen, and a link that
     * lands on every notification the installation ever sent is a link nobody follows twice.
     */
    public function testTheUserCardLinksToThisAccountsPushes(): void
    {
        // Arrange
        $view = (string) file_get_contents(
            dirname(__DIR__, 3) . '/scaffolding/themes/tailwind/views/users/view.html.php'
        );

        // Assert
        $this->assertStringContainsString("adminUrl('PushLog')", $view);
        $this->assertStringContainsString('?userid=', $view);
        $this->assertStringContainsString('Recent pushes', $view);
    }
    /**
     * The action assembles what the screen renders: the rows, the week's shape, the filters.
     *
     * Everything above asserts the screen exists and is reachable. This asserts it is *fed* — a
     * controller whose view variables are wrong renders an empty page that looks like an
     * installation with no notifications.
     */
    public function testTheActionFeedsTheScreen(): void
    {
        // Arrange
        $_GET = [];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $this->assertSame('pushlog', $controller->view->name);
        $this->assertCount(1, $controller->view->rows);
        $this->assertSame(4, $controller->view->stats['total']);
        $this->assertSame(0, $controller->view->userId);
        $this->assertSame('', $controller->view->only);
        $this->assertSame(PushLogController::PAGE, $controller->limit);
        $this->assertSame([], $controller->filter, 'no filter unless one was asked for');
    }

    /**
     * `?userid=` narrows to one account.
     *
     * The user card links here with one. A filter that did not reach the query would put every
     * account's notifications on somebody's page — a disclosure rather than a wrong list.
     */
    public function testTheAccountFilterReachesTheQuery(): void
    {
        // Arrange
        $_GET = ['userid' => '42'];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $this->assertSame(['userid' => 42], $controller->filter);
        $this->assertSame(42, $controller->view->userId);
    }

    /**
     * `?show=failed` asks for everything that did not arrive.
     *
     * Not one status: a 410 is a dead subscription, a 429 a busy service and a 0 never reached
     * one. The reader wants all three, which is why it is a flag rather than a status.
     */
    public function testTheFailedFilterReachesTheQuery(): void
    {
        // Arrange
        $_GET = ['show' => 'failed'];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $this->assertSame(['failed' => true], $controller->filter);
        $this->assertSame('failed', $controller->view->only);
    }

    /**
     * A `userid` of zero is not a filter.
     *
     * `?userid=` with nothing after it, or a crawler following the link without the number.
     * Filtering on account 0 shows an empty page where the answer is "everything".
     */
    public function testAnEmptyAccountParameterIsNotAFilter(): void
    {
        // Arrange
        $_GET = ['userid' => '0'];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $this->assertSame([], $controller->filter);
    }

    /**
     * A visitor below the floor gets nothing rendered.
     *
     * The log names accounts and what was sent to them. `requireMinUserType()` answering true
     * has to stop the action, not merely colour it.
     */
    public function testAVisitorBelowTheFloorSeesNothing(): void
    {
        // Arrange
        $controller = $this->controller(refused: true);

        // Act
        $result = $controller->display();

        // Assert
        $this->assertNull($result);
        $this->assertNull($controller->view);
    }

    /** A controller whose store, view and usertype check are all given. */
    private function controller(bool $refused = false): object
    {
        return new class ($refused) extends PushLogController {
            public ?object $view = null;

            public int $limit = 0;

            /** @var array<string, mixed> */
            public array $filter = [];

            public function __construct(private bool $refused)
            {
                // Deliberately not parent::__construct(): it registers actions against an
                // application this test does not have.
            }

            protected function requireMinUserType($type): bool
            {
                return $this->refused;
            }

            protected function rows(int $limit, array $filter): array
            {
                $this->limit  = $limit;
                $this->filter = $filter;

                return [['pushid' => 1, 'userid' => 42, 'title' => 'New sign-in',
                         'status' => 201, 'error' => '', 'endpoint_hash' => 'a',
                         'notification' => 'X', 'sent' => '2026-08-29 10:00:00']];
            }

            protected function stats(): array
            {
                return ['total' => 4, 'delivered' => 2, 'gone' => 1, 'refused' => 1,
                        'failed' => 0];
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $this->view = new class ($name) {
                    public array $rows = [];

                    public array $stats = [];

                    public int $userId = 0;

                    public string $only = '';

                    public function __construct(public string $name)
                    {
                    }

                    public function display($layout = '')
                    {
                        return 'rendered';
                    }
                };

                return $this->view;
            }
        };
    }

}
