<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Unsubscribe as UnsubscribeController;
use Pramnos\Email\MailType;
use Pramnos\Email\MailTypes;
use Pramnos\Email\Unsubscribe;

/**
 * The endpoint the `List-Unsubscribe` headers point at.
 *
 * Its two methods are two different callers, and confusing them is what gets a sender's mail
 * filed as spam:
 *
 * - **POST** is a mailbox provider's server acting on the reader's behalf (RFC 8058). No
 *   session, no login, no confirmation page — unsubscribe and answer 200. Gmail and Yahoo
 *   require this of anyone sending in volume and report no failure back: mail from a sender
 *   whose endpoint refuses them is quietly filed as spam, including the mail people wanted.
 * - **GET** is a person, and gets a page.
 *
 * What the record does is asserted elsewhere, against a real store. Here the record is a seam,
 * so this can assert the two things only the controller decides: the status code a provider
 * reads, and whether the page tells the truth about what happened.
 */
#[CoversClass(UnsubscribeController::class)]
class UnsubscribeControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        unset($_SERVER['REQUEST_METHOD']);
        \Pramnos\Http\Request::resetInstance();
        Unsubscribe::reset();
        MailTypes::reset();
        parent::tearDown();
    }

    /**
     * @param bool $recorded What the record layer should claim
     */
    private function controller(bool $recorded = true): object
    {
        return new class($recorded) extends UnsubscribeController {
            /** @var array<int, array{0:string,1:string,2:string}> */
            public array $optOuts = [];

            public int $status = 0;

            public function __construct(private bool $recorded)
            {
                // No parent::__construct(): it registers actions against an application this
                // test does not have.
            }

            protected function optOut(string $email, string $list, string $source): bool
            {
                $this->optOuts[] = [$email, $list, $source];

                return $this->recorded;
            }

            protected function respond(int $status, string $message): void
            {
                $this->status = $status;
                echo $message;
            }

            /** @var array<int, array{0:string,1:string}> */
            public array $optIns = [];

            /** @var array<string, bool> list => off? */
            public array $off = [];

            protected function optIn(string $email, string $list): bool
            {
                $this->optIns[] = [$email, $list];

                return $this->recorded;
            }

            protected function isOptedOut(string $email, string $list): bool
            {
                return $this->off[$list] ?? false;
            }

            protected function page(string $title, string $body, string $extra = ''): void
            {
                $this->status = 200;
                echo $title . '|' . $body . '|' . $extra;
            }
        };
    }

    /**
     * Named `dispatch` rather than `run`: `TestCase::run()` is final, and overriding it is a
     * fatal error rather than a failing test.
     */
    private function dispatch(object $controller): string
    {
        ob_start();
        $controller->display();

        return (string) ob_get_clean();
    }

    /**
     * A one-click POST unsubscribes and answers 200, with no confirmation step.
     */
    public function testOneClickUnsubscribesImmediately(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['u'] = Unsubscribe::token('reader@example.com', 'marketing');
        $_REQUEST = $_POST;
        $controller = $this->controller();

        // Act
        $this->dispatch($controller);

        // Assert
        $this->assertSame(200, $controller->status);
        $this->assertSame(
            [['reader@example.com', 'marketing', 'one_click']],
            $controller->optOuts,
            'recorded as one-click, because that is a provider acting for somebody'
        );
    }

    /**
     * A person clicking the link gets a page naming the address that was removed.
     *
     * Named because people forward mail: the reader has to be able to see *which* of their
     * addresses this was, or the page cannot be trusted to have done anything.
     */
    public function testAPersonGetsAPageNamingTheAddress(): void
    {
        // Arrange
        $_GET['u'] = Unsubscribe::token('reader@example.com', 'marketing');
        $_REQUEST = $_GET;
        $controller = $this->controller();

        // Act
        $body = $this->dispatch($controller);

        // Assert
        $this->assertStringContainsString('unsubscribed', strtolower($body));
        $this->assertStringContainsString('reader@example.com', $body);
        $this->assertSame('page', $controller->optOuts[0][2]);
    }

    /**
     * An unreadable token is refused, and one-click is told so with a 400.
     *
     * A provider reading "we could not do it" as success is worse than the error: it stops
     * retrying, and the reader keeps receiving mail they pressed the button on.
     */
    public function testAnInvalidTokenIsRefused(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['u'] = 'not-a-token';
        $_REQUEST = $_POST;
        $controller = $this->controller();

        // Act
        $this->dispatch($controller);

        // Assert
        $this->assertSame(400, $controller->status);
        $this->assertSame([], $controller->optOuts, 'nothing may be recorded from a bad token');
    }

    /**
     * A missing token is refused the same way, on a page.
     */
    public function testAMissingTokenGetsAnExplanation(): void
    {
        // Arrange
        $controller = $this->controller();

        // Act
        $body = $this->dispatch($controller);

        // Assert
        $this->assertStringContainsString('not valid', strtolower($body));
        $this->assertSame([], $controller->optOuts);
    }

    /**
     * A record that could not be written is not reported as success.
     *
     * The page is a promise. "We have removed you" while nothing was stored is how somebody
     * unsubscribes three times and concludes the sender is lying — and a provider that reads
     * 200 stops retrying the one thing a retry would fix.
     */
    public function testAFailedRecordIsNotReportedAsDone(): void
    {
        // Arrange — one-click first
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['u'] = Unsubscribe::token('reader@example.com', 'marketing');
        $_REQUEST = $_POST;
        $oneClick = $this->controller(false);

        // Act
        $this->dispatch($oneClick);

        // Assert
        $this->assertSame(500, $oneClick->status, 'a 500 is what makes a provider retry');

        // …and the page says so rather than claiming it worked
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = $_POST;
        $_REQUEST = $_GET;
        $page = $this->controller(false);
        $body = $this->dispatch($page);

        $this->assertStringNotContainsString('have been unsubscribed', strtolower($body));
        $this->assertStringContainsString('could not', strtolower($body));
    }
    /**
     * The page offers the rest of what this address can receive, not only the one it left.
     *
     * A page whose only control says «none, ever» is how somebody who would have kept one of
     * four messages ends up receiving none — and the sender reads it as a clean unsubscribe
     * rather than the failure it was.
     */
    public function testThePageOffersTheOtherThingsThisAddressCanReceive(): void
    {
        // Arrange
        MailTypes::reset();
        MailTypes::register(new MailType('digest', 'Weekly digest', 'A summary, every Monday.', 'digest'));
        MailTypes::register(new MailType('receipt', 'Receipts', 'What you paid for.'));

        $_GET['u'] = Unsubscribe::token('reader@example.com', 'marketing');
        $_REQUEST  = $_GET;
        $controller = $this->controller();

        // Act
        $page = $this->dispatch($controller);

        // Assert
        $this->assertStringContainsString('Weekly digest', $page);
        $this->assertStringContainsString('A summary, every Monday.', $page);
        $this->assertStringNotContainsString('Receipts', $page,
            'transactional mail is not a choice, and showing it as one is a lie');
    }

    /**
     * A list this address has already left is offered back, and the link says so.
     *
     * The state has to be visible: a row that reads the same whether somebody is on the list or
     * off it makes the page a list of buttons whose effect nobody can predict.
     */
    public function testAListAlreadyLeftIsOfferedBack(): void
    {
        // Arrange
        MailTypes::reset();
        MailTypes::register(new MailType('digest', 'Weekly digest', 'A summary.', 'digest'));

        $_GET['u'] = Unsubscribe::token('reader@example.com', 'marketing');
        $_REQUEST  = $_GET;
        $controller = $this->controller();
        $controller->off = ['digest' => true];

        // Act
        $page = $this->dispatch($controller);

        // Assert
        $this->assertStringContainsString('not receiving', $page);
        $this->assertStringContainsString('Turn back on', $page);
        $this->assertStringContainsString('a=in', $page);
    }

    /**
     * `a=in` on a GET subscribes rather than unsubscribes.
     *
     * The same signed token authorises both directions — it names one address and one list, and
     * cannot be edited into naming somebody else's.
     */
    public function testTurningSomethingBackOnSubscribes(): void
    {
        // Arrange
        $_GET['u'] = Unsubscribe::token('reader@example.com', 'digest');
        $_GET['a'] = 'in';
        $_REQUEST  = $_GET;
        $controller = $this->controller();

        // Act
        $page = $this->dispatch($controller);

        // Assert
        $this->assertSame([['reader@example.com', 'digest']], $controller->optIns);
        $this->assertSame([], $controller->optOuts);
        $this->assertStringContainsString('subscribed again', $page);
    }

    /**
     * One-click never subscribes, whatever the request says.
     *
     * RFC 8058 says a POST to this endpoint unsubscribes. A provider that found a parameter
     * turning that into a subscribe would be right to stop trusting the endpoint — and the
     * consequence of losing that trust is every message being filed as spam, not just this one.
     */
    public function testOneClickIgnoresTheSubscribeParameter(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['u'] = Unsubscribe::token('reader@example.com', 'digest');
        $_POST['a'] = 'in';
        $_REQUEST = $_POST;
        $controller = $this->controller();

        // Act
        $this->dispatch($controller);

        // Assert
        $this->assertSame([], $controller->optIns);
        $this->assertSame(
            [['reader@example.com', 'digest', 'one_click']],
            $controller->optOuts
        );
        $this->assertSame(200, $controller->status);
    }

    /**
     * An installation that registered nothing still has something to offer.
     *
     * The framework's own optional mail — the sign-in alerts — is registered without anybody
     * asking, so the page is a preferences page on a plain installation rather than only on one
     * that thought to declare its types.
     */
    public function testTheFrameworksOwnOptionalMailIsOfferedWithoutRegistration(): void
    {
        // Arrange
        MailTypes::reset();

        $_GET['u'] = Unsubscribe::token('reader@example.com', 'marketing');
        $_REQUEST  = $_GET;
        $controller = $this->controller();

        // Act
        $page = $this->dispatch($controller);

        // Assert
        $this->assertStringContainsString('Sign-in alerts', $page);
        $this->assertStringNotContainsString('Sign-in codes', $page,
            'a code you need in order to sign in is not a preference');
    }

}
