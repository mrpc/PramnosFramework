<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Email;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\MailAction as Controller;
use Pramnos\Application\Controllers\Unsubscribe;

/**
 * The two public endpoints that answer a machine, and must not answer with a web page.
 *
 * Found by building the second one beside the first: `/unsubscribe` was returning its own
 * self-contained page **followed by 180 KB of the site's layout**, because a controller that
 * echoes and returns leaves the framework to render the page afterwards. The plain-text line a
 * mailbox provider reads was followed by an HTML document, under a `Content-Type: text/plain`
 * header; the page a person saw was followed by the site's header, navigation and footer.
 *
 * The fix is the document type the framework already has for self-contained output. What is
 * asserted here is that both controllers ask for it, because that single call is the difference
 * and nothing about the visible output would reveal its absence in a unit test.
 */
#[CoversClass(Controller::class)]
#[CoversClass(Unsubscribe::class)]
class MailActionControllerTest extends TestCase
{
    /**
     * Both controllers switch to the `raw` document before writing anything.
     *
     * Asserted on the source rather than by rendering, because the failure is *what happens
     * after the controller returns* — outside anything a unit test drives — and the whole
     * defect was invisible until somebody measured a response in kilobytes.
     */
    public function testBothEndpointsAskForASelfContainedDocument(): void
    {
        $root = dirname(__DIR__, 4) . '/src/Pramnos/Application/Controllers/';

        foreach (['MailAction.php', 'Unsubscribe.php'] as $file) {
            // Arrange
            $source = (string) file_get_contents($root . $file);

            // Assert
            $this->assertStringContainsString(
                "getDocument('raw')",
                $source,
                $file . ' would otherwise have the site layout rendered after its answer'
            );

            // …and before it writes, not after
            $this->assertLessThan(
                strpos($source, 'function answer') ?: strlen($source),
                strpos($source, "getDocument('raw')"),
                'the document type has to be chosen before anything is written'
            );
        }
    }

    /**
     * The endpoint stays public.
     *
     * A login requirement here would fail every one-click request, which arrives from a mailbox
     * provider's server that has never signed in to anything. It is the kind of thing added by
     * somebody tightening security on a sweep through the controllers, so it is pinned.
     */
    public function testTheEndpointIsPublic(): void
    {
        // Arrange
        $controller = new Controller();

        // Assert — `display` and nothing else. (The parent constructor adds it too, so the
        // array holds it twice; what matters is that no second action was declared.)
        $this->assertSame(['display'], array_values(array_unique($controller->actions)));

        $source = (string) file_get_contents(
            dirname(__DIR__, 4) . '/src/Pramnos/Application/Controllers/MailAction.php'
        );

        foreach (['requireLogin', 'requireMinUserType', 'addAuthAction'] as $guard) {
            $this->assertStringNotContainsString(
                $guard,
                $source,
                'a one-click request has no session to check'
            );
        }
    }

    /**
     * A controller whose two writers are recorded instead of printed.
     */
    private function probe(): object
    {
        return new class extends Controller {
            /** @var array<string, mixed> */
            public array $answered = [];

            /** @var array<string, string> */
            public array $rendered = [];

            /** @var array<int, string> */
            public array $plain = [];

            protected function page(string $title, string $body): void
            {
                $this->rendered = ['title' => $title, 'body' => $body];
            }

            protected function respond(int $status, string $message): void
            {
                $this->plain = [$status => $message];
            }

            protected function answer(bool $isPost, int $status, string $message, string $action): void
            {
                $this->answered = ['post' => $isPost, 'status' => $status, 'message' => $message];

                parent::answer($isPost, $status, $message, $action);
            }
        };
    }

    /**
     * Ask for one request, as the framework's Request reads it.
     */
    private function request(string $token, bool $post): void
    {
        $_GET = $_POST = $_REQUEST = [];

        if ($token !== '') {
            $_REQUEST['a'] = $token;
            $post ? $_POST['a'] = $token : $_GET['a'] = $token;
        }

        // `Request::create()` is how the method is set for a test — the static it reads is
        // populated from `$_SERVER` during the real request's bootstrap, which no unit test runs.
        $_SERVER['REQUEST_METHOD'] = $post ? 'POST' : 'GET';
        \Pramnos\Http\Request::create('mailaction', $post ? 'POST' : 'GET');
    }

    /**
     * A GET with no token is refused without asking any handler.
     */
    public function testAMissingTokenIsRefused(): void
    {
        // Arrange
        \Pramnos\Email\MailAction::reset();
        $probe = $this->probe();
        $this->request('', false);

        // Act
        $probe->display();

        // Assert
        $this->assertSame(400, $probe->answered['status']);
        $this->assertStringContainsString('not valid', $probe->answered['message']);
    }

    /**
     * A POST that works answers a machine in plain text, with the handler's own words.
     */
    public function testAPostThatWorksAnswersInPlainText(): void
    {
        // Arrange
        \Pramnos\Email\MailAction::reset();
        \Pramnos\Email\MailAction::register(
            'demo',
            static fn (): bool => true,
            false,
            'Your order is confirmed.'
        );

        $probe = $this->probe();
        $this->request(\Pramnos\Email\MailAction::token('demo', ['order' => 7]), true);

        // Act
        $probe->display();

        // Assert
        $this->assertSame(200, $probe->answered['status']);
        $this->assertSame([200 => 'Your order is confirmed.'], $probe->plain);
        $this->assertSame([], $probe->rendered, 'a machine is not sent a web page');
    }

    /**
     * A GET on an action that needs a POST shows a form, and does not perform it.
     *
     * The form is the point: a link scanner follows links and does not submit forms, so this is
     * what stops a corporate mail gateway confirming somebody's order on their behalf.
     */
    public function testAGetShowsAFormRatherThanActing(): void
    {
        // Arrange
        $ran = false;
        \Pramnos\Email\MailAction::reset();
        \Pramnos\Email\MailAction::register('demo', function () use (&$ran): bool {
            $ran = true;

            return true;
        });

        $token = \Pramnos\Email\MailAction::token('demo');
        $probe = $this->probe();
        $this->request($token, false);

        // Act
        $probe->display();

        // Assert
        $this->assertFalse($ran);
        $this->assertSame('One more tap', $probe->rendered['title']);
        $this->assertStringContainsString('method="post"', $probe->rendered['body']);
        $this->assertStringContainsString($token, $probe->rendered['body'],
            'the same token, so the button completes the same request');
        $this->assertSame([], $probe->answered, 'this is the confirmation step, not an answer');
    }

    /**
     * An expired token gets 410, and a person is told so in as many words.
     *
     * Safe to say, and useful: "ask for another" is the next step. The same sentence about a
     * forgery would be a hint, which is why only the expiry is distinguished.
     */
    public function testAnExpiredTokenIsReportedAsExpired(): void
    {
        // Arrange
        \Pramnos\Email\MailAction::reset();
        \Pramnos\Email\MailAction::register('demo', static fn (): bool => true, true);

        $probe = $this->probe();
        $this->request(\Pramnos\Email\MailAction::token('demo', [], -10), false);

        // Act
        $probe->display();

        // Assert
        $this->assertSame(410, $probe->answered['status']);
        $this->assertStringContainsString('expired', $probe->rendered['body']);
    }

    /**
     * A valid token for an unregistered action names the action.
     *
     * So the reader looks at the registration rather than at the token — the cause is almost
     * always a service provider that did not run.
     */
    public function testAnUnhandledActionNamesItself(): void
    {
        // Arrange
        \Pramnos\Email\MailAction::reset();
        $probe = $this->probe();
        $this->request(\Pramnos\Email\MailAction::token('nobody-handles-this'), true);

        // Act
        $probe->display();

        // Assert
        $this->assertSame(501, $probe->answered['status']);
        $this->assertStringContainsString('nobody-handles-this', $probe->answered['message']);
        $this->assertStringContainsString('service provider', $probe->answered['message']);
    }

    /**
     * A person whose action failed is not shown "500".
     */
    public function testAFailedActionIsAPageForAPerson(): void
    {
        // Arrange
        \Pramnos\Email\MailAction::reset();
        \Pramnos\Email\MailAction::register('demo', static fn (): bool => false, true);

        $probe = $this->probe();
        $this->request(\Pramnos\Email\MailAction::token('demo'), false);

        // Act
        $probe->display();

        // Assert
        $this->assertSame(500, $probe->answered['status']);
        $this->assertSame('This link did not work', $probe->rendered['title']);
        $this->assertStringContainsString('try again', $probe->rendered['body']);
    }

    // ── what actually reaches the wire ───────────────────────────────────────

    /**
     * A machine gets plain text and nothing else.
     *
     * The two writers were replaced by recorders in every test above — which is how a test suite
     * ends up covering every decision and none of the output. This drives them.
     */
    public function testTheMachineResponseIsPlainText(): void
    {
        // Arrange
        $writer = new class extends Controller {
            public function write(int $status, string $message): void
            {
                $this->respond($status, $message);
            }
        };

        // Act
        ob_start();
        $writer->write(410, 'This link has expired.');
        $body = (string) ob_get_clean();

        // Assert
        $this->assertSame("This link has expired.\n", $body);
        $this->assertStringNotContainsString('<', $body, 'no markup in a text/plain answer');
    }

    /**
     * The page a person sees is self-contained and not indexable.
     *
     * The URL carries a token. A search engine that indexed one would publish somebody's
     * ability to perform the action — so `noindex` is on the page as well as in the header,
     * because a crawler that ignores one may respect the other.
     */
    public function testThePageIsSelfContainedAndNotIndexable(): void
    {
        // Arrange
        $writer = new class extends Controller {
            public function render(string $title, string $body): void
            {
                $this->page($title, $body);
            }
        };

        // Act
        ob_start();
        $writer->render('Done', 'Everything worked.');
        $html = (string) ob_get_clean();

        // Assert
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<title>Done</title>', $html);
        $this->assertStringContainsString('Everything worked.', $html);
        $this->assertStringContainsString('noindex', $html);

        // Its own styles, because it is served without the site's
        $this->assertStringContainsString('<style>', $html);

        // A dark-mode block, since a mail client's browser follows the system
        $this->assertStringContainsString('prefers-color-scheme: dark', $html);

        // A way back to the site, when there is a site to go back to. Somebody who arrived here
        // from a mail client has no other navigation on the page.
        $siteUrl = (string) (\Pramnos\Application\Settings::getSetting('site_url')
            ?: (defined('sURL') ? sURL : ''));

        if ($siteUrl !== '') {
            $this->assertStringContainsString('Back to the site', $html);
            $this->assertStringContainsString(
                htmlspecialchars($siteUrl, ENT_QUOTES),
                $html
            );
        } else {
            $this->assertStringNotContainsString(
                'Back to the site',
                $html,
                'no link rather than one pointing nowhere'
            );
        }
    }

    /**
     * The title is escaped, and the body is not.
     *
     * Deliberately asymmetric: the title is a plain string, and the body carries the
     * confirmation form. Both are built here rather than by a caller, but the asymmetry is the
     * sort of thing that gets read the wrong way round later.
     */
    public function testTheTitleIsEscaped(): void
    {
        // Arrange
        $writer = new class extends Controller {
            public function render(string $title, string $body): void
            {
                $this->page($title, $body);
            }
        };

        // Act
        ob_start();
        $writer->render('Done "<script>"', '<b>markup survives here</b>');
        $html = (string) ob_get_clean();

        // Assert
        $this->assertStringNotContainsString('<title>Done "<script>"', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<b>markup survives here</b>', $html);
    }

    /**
     * The confirmation form carries the token and posts to the same place.
     *
     * The form is the mechanism that stops a link scanner performing the action, so its shape
     * matters: a `method="post"`, the same token, and no `action` attribute — the current URL.
     */
    public function testTheConfirmationFormIsAForm(): void
    {
        // Arrange
        $writer = new class extends Controller {
            public array $rendered = [];

            public function confirm(string $token): void
            {
                $this->confirmationPage($token, 'demo');
            }

            protected function page(string $title, string $body): void
            {
                $this->rendered = ['title' => $title, 'body' => $body];
            }
        };

        // Act
        $writer->confirm('a-token-value');

        // Assert
        $this->assertSame('One more tap', $writer->rendered['title']);
        $this->assertStringContainsString('method="post"', $writer->rendered['body']);
        $this->assertStringContainsString('name="a" value="a-token-value"', $writer->rendered['body']);
        $this->assertStringContainsString('<button type="submit"', $writer->rendered['body']);
    }
}
