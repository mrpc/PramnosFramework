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

            /** @var array<string, mixed>|null What find() answers */
            public ?array $found = ['pushid' => 7, 'userid' => 42, 'title' => 'Editorial report',
                'status' => 201, 'error' => '', 'endpoint_hash' => 'abc', 'notification' => 'App\\Report',
                'sent' => '2026-10-03 07:05:50'];

            /** @var list<string> Where redirect() was asked to go */
            public array $redirectedTo = [];

            /** @var object|null The fake test sender */
            public ?object $sender = null;

            /** The fake sender, built on first use. */
            protected function testPush(): \Pramnos\Push\TestPush
            {
                return $this->sender ??= new class extends \Pramnos\Push\TestPush {
                    /** @var list<array{0: int, 1: ?string}> */
                    public array $sent = [];

                    /** @var list<array<string, mixed>> */
                    public array $answer = [['token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'endpoint_hash' => 'abc', 'user_agent' => 'Chrome',
                        'status' => 201, 'outcome' => ['kind' => 'delivered', 'label' => 'Delivered', 'explanation' => '']]];

                    public function send(int $userId, ?string $endpointHash = null): array
                    {
                        $this->sent[] = [$userId, $endpointHash];

                        return $this->answer;
                    }

                    public function status(string $token): ?array
                    {
                        return ['token' => $token, 'userid' => 42, 'endpoint_hash' => 'abc', 'sent_at' => 1, 'received_at' => 5, 'expired' => false];
                    }
                };
            }

            /** Recorded rather than flashed. */
            protected function addMessage($message)
            {
            }

            /** Make the sender answer as if the device had unsubscribed. */
            public function nothingSubscribed(): void
            {
                $this->testPush();
                $this->sender->answer = [];
            }

            /** @return list<array{0: int, 1: ?string}> What the sender was asked to send, if it was built */
            public function testPushSent(): array
            {
                return $this->sender?->sent ?? [];
            }

            /** @return array<string, mixed>|null */
            protected function find(int $pushId): ?array
            {
                return $this->found !== null && $pushId === 7 ? $this->found : null;
            }

            /** @return array<string, mixed>|null */
            protected function subscription(int $userId, string $hash): ?array
            {
                return ['id' => 3, 'user_agent' => 'Chrome on Android', 'created_at' => 1, 'last_success_at' => 2, 'failure_count' => 0];
            }

            /** Recorded rather than sent. */
            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirectedTo[] = (string) $url;
            }

            /** Recorded rather than flashed. */
            protected function addError($error)
            {
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $this->view = new class ($name) {
                    public array $rows = [];

                    public array $stats = [];

                    public int $userId = 0;

                    public string $only = '';

                    public mixed $row = null;

                    public mixed $outcome = null;

                    public mixed $subscription = null;

                    public mixed $history = null;

                    public mixed $receipt = null;

                    public string $layout = '';

                    public function __construct(public string $name)
                    {
                    }

                    public function display($layout = '')
                    {
                        $this->layout = (string) $layout;

                        return 'rendered';
                    }
                };

                return $this->view;
            }
        };
    }

    /**
     * The detail page is fed the row, what its outcome means, the device and its history.
     *
     * The device's history is asked for by the row's endpoint hash, so it is that browser's
     * pushes and nobody else's.
     */
    public function testTheDetailPageIsFed(): void
    {
        // Arrange
        $_GET = ['_option' => '7'];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();

        // Act
        $controller->view();

        // Assert
        $this->assertSame('view', $controller->view->layout);
        $this->assertSame(7, $controller->view->row['pushid']);
        $this->assertSame('delivered', $controller->view->outcome['kind']);
        $this->assertSame('Chrome on Android', $controller->view->subscription['user_agent']);
        $this->assertSame(['endpoint_hash' => 'abc'], $controller->filter);
        $this->assertSame(20, $controller->limit);
    }

    /**
     * A push that was never sent has no device and no device history to ask for.
     */
    public function testANotSentPushHasNoHistory(): void
    {
        // Arrange
        $_GET = ['_option' => '7'];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();
        $controller->found['endpoint_hash'] = '';
        $controller->found['status'] = 0;

        // Act
        $controller->view();

        // Assert
        $this->assertSame([], $controller->view->history);
        $this->assertSame('not_sent', $controller->view->outcome['kind']);
        $this->assertSame([], $controller->filter, 'no query for a device that does not exist');
    }

    /**
     * An id that is not in the log goes back to the list.
     */
    public function testAnUnknownPushGoesBackToTheList(): void
    {
        // Arrange
        $_GET = ['_option' => '999'];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();

        // Act
        $result = $controller->view();

        // Assert
        $this->assertNull($result);
        $this->assertNull($controller->view);
        $this->assertCount(1, $controller->redirectedTo);
    }

    /**
     * Below the floor, the detail page renders nothing either.
     */
    public function testTheDetailPageIsBehindTheFloor(): void
    {
        // Arrange
        $controller = $this->controller(refused: true);

        // Act & Assert
        $this->assertNull($controller->view());
        $this->assertNull($controller->view);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function outcomes(): array
    {
        return [
            'delivered'      => [['status' => 201, 'endpoint_hash' => 'a'], 'delivered'],
            'gone'           => [['status' => 410, 'endpoint_hash' => 'a'], 'gone'],
            'not sent'       => [['status' => 0, 'endpoint_hash' => ''], 'not_sent'],
            'busy'           => [['status' => 503, 'endpoint_hash' => 'a'], 'busy'],
            'rate limited'   => [['status' => 429, 'endpoint_hash' => 'a'], 'busy'],
            'refused'        => [['status' => 400, 'endpoint_hash' => 'a'], 'failed'],
            'never reached'  => [['status' => 0, 'endpoint_hash' => 'a'], 'failed'],
        ];
    }

    /**
     * Every status has one meaning, worded once, with what to check next.
     *
     * @param array<string, mixed> $row
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
    public function testEveryOutcomeIsExplained(array $row, string $kind): void
    {
        // Act
        $outcome = \Pramnos\Push\Log::outcome($row);

        // Assert
        $this->assertSame($kind, $outcome['kind']);
        $this->assertNotSame('', $outcome['label']);
        $this->assertGreaterThan(40, strlen($outcome['explanation']), 'an explanation, not a word');
    }

    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['bootstrap' => ['bootstrap'], 'tailwind' => ['tailwind'], 'plain-css' => ['plain-css']];
    }

    /**
     * Every theme renders the detail page: the message, what the outcome means, the device,
     * and the device's history, with names escaped.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function testEveryThemeRendersTheDetailPage(string $theme): void
    {
        // Arrange
        $row = ['pushid' => 7, 'userid' => 42, 'title' => '<b>Report</b>', 'body' => 'Ready',
                'url' => 'https://example.test/r', 'tag' => 'report', 'status' => 201, 'error' => '',
                'endpoint_hash' => 'abc', 'notification' => 'App\\Report', 'sent' => '2026-10-03 07:05:50'];
        $view = new class {
            public string $activeNav = '';

            public mixed $row = null;

            public mixed $outcome = null;

            public mixed $subscription = null;

            public mixed $history = null;

            public mixed $receipt = null;

            /** The breadcrumb partial is the theme chrome's; nothing to render here. */
            public function insert(string $partial): void
            {
            }
        };
        $view->row          = $row;
        $view->outcome      = \Pramnos\Push\Log::outcome($row);
        $view->subscription = ['id' => 3, 'user_agent' => 'Chrome on Android', 'created_at' => 1700000000,
                               'last_success_at' => 1700000100, 'failure_count' => 0];
        $view->history      = [$row, ['pushid' => 6, 'title' => 'Earlier', 'status' => 410, 'endpoint_hash' => 'abc', 'sent' => '2026-10-02 07:00:00']];
        $file = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/pushlog/view.html.php';

        // Act
        ob_start();
        try {
            (\Closure::bind(function () use ($file): void {
                include $file;
            }, $view, null))();
        } finally {
            $html = (string) ob_get_clean();
        }

        // Assert
        $this->assertStringContainsString('Push notification #7', $html);
        $this->assertStringContainsString('Whether it was shown is up to the device', $html);
        $this->assertStringContainsString('Chrome on Android', $html);
        $this->assertStringContainsString('Subscription gone', $html, 'the history row');
        $this->assertStringContainsString('PushLog/view/6', $html);
        $this->assertStringContainsString('&lt;b&gt;Report&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>Report</b>', $html);
    }

    /**
     * Each list row links to its detail page.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('themes')]
    public function testTheListLinksToTheDetailPage(string $theme): void
    {
        // Act
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/pushlog/pushlog.html.php');

        // Assert
        $this->assertStringContainsString("adminUrl('PushLog/view/')", $source);
        $this->assertStringContainsString('Log::outcome($row)', $source, 'the list words outcomes the way the detail page does');
    }

    /**
     * "Send a test" goes to the device the row went to, for that row's account, and opens the
     * test's own page.
     */
    public function testATestGoesToTheRowsDeviceAndOpensItsPage(): void
    {
        // Arrange
        $_GET = ['_option' => '7'];
        \Pramnos\Http\Request::resetInstance();
        $controller = $this->controller();

        // Act
        $controller->test();

        // Assert — to account 42's browser `abc`, then to the new row (the stub's rows() answers #1)
        $this->assertSame([[42, 'abc']], $controller->sender->sent);
        $this->assertSame(['tag' => \Pramnos\Push\Notifications\TestPushNotification::TAG_PREFIX . str_repeat('a', 32)], $controller->filter);
        $this->assertStringEndsWith('PushLog/view/1', $controller->redirectedTo[0]);
    }

    /**
     * A row with no device, or a device that has gone, sends nothing and says why.
     */
    public function testNoDeviceSendsNothing(): void
    {
        // Arrange — a refusal row: no endpoint
        $_GET = ['_option' => '7'];
        \Pramnos\Http\Request::resetInstance();
        $refused = $this->controller();
        $refused->found['endpoint_hash'] = '';

        // a device that unsubscribed since
        $gone = $this->controller();
        $gone->nothingSubscribed();

        // Act
        $refused->test();
        $gone->test();

        // Assert
        $this->assertSame([], $refused->testPushSent());
        $this->assertStringEndsWith('PushLog', $refused->redirectedTo[0]);
        $this->assertStringEndsWith('PushLog/view/7', $gone->redirectedTo[0]);
    }

    /**
     * The detail page of a test is fed its receipt; any other push has none.
     */
    public function testATestsPageIsFedItsReceipt(): void
    {
        // Arrange
        $_GET = ['_option' => '7'];
        \Pramnos\Http\Request::resetInstance();
        $test  = $this->controller();
        $test->found['tag'] = \Pramnos\Push\Notifications\TestPushNotification::TAG_PREFIX . str_repeat('b', 32);
        $plain = $this->controller();

        // Act
        $test->view();
        $plain->view();

        // Assert
        $this->assertSame(5, $test->view->receipt['received_at']);
        $this->assertNull($plain->view->receipt);
    }

    /** @return array<string, array{string, array<string, mixed>, string}> */
    public static function receipts(): array
    {
        $cases = [];
        foreach (['bootstrap', 'tailwind', 'plain-css'] as $theme) {
            $cases[$theme . ': received'] = [$theme, ['received_at' => 1700000000, 'expired' => false], 'The device received this test'];
            $cases[$theme . ': waiting']  = [$theme, ['received_at' => null, 'expired' => false], 'Waiting for the device to confirm'];
            $cases[$theme . ': expired']  = [$theme, ['received_at' => null, 'expired' => true], 'The device never confirmed this test'];
        }

        return $cases;
    }

    /**
     * Each theme says which of the three a test is in, and offers another test.
     *
     * @param array<string, mixed> $receipt
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('receipts')]
    public function testEveryThemeShowsTheReceipt(string $theme, array $receipt, string $expected): void
    {
        // Arrange
        $row  = ['pushid' => 9, 'userid' => 42, 'title' => 'Test notification', 'status' => 201,
                 'endpoint_hash' => 'abc', 'tag' => 'pramnos-test:x', 'sent' => '2026-10-03 08:00:00'];
        $view = new class {
            public string $activeNav = '';

            public mixed $row = null;

            public mixed $outcome = null;

            public mixed $subscription = null;

            public mixed $history = null;

            public mixed $receipt = null;

            /** No chrome here. */
            public function insert(string $partial): void
            {
            }
        };
        $view->row          = $row;
        $view->outcome      = \Pramnos\Push\Log::outcome($row);
        $view->subscription = ['id' => 1, 'user_agent' => 'Chrome', 'created_at' => 1, 'last_success_at' => null, 'failure_count' => 0];
        $view->history      = [];
        $view->receipt      = $receipt;
        $file = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/pushlog/view.html.php';

        // Act
        ob_start();
        try {
            (\Closure::bind(function () use ($file): void {
                include $file;
            }, $view, null))();
        } finally {
            $html = (string) ob_get_clean();
        }

        // Assert
        $this->assertStringContainsString($expected, $html);
        $this->assertStringContainsString('PushLog/test/9', $html, 'the button to send another');
        $this->assertStringContainsString(\Pramnos\Http\Session::getInstance()->getTokenField(), $html);
    }
}
