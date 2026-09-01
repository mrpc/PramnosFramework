<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\LoginFlowResult;

/**
 * The three parts of the sign-in controller that had never executed.
 *
 * `renderForgot()`, `renderReset()` and `authlink()` — twenty-four statements between them, zero
 * hits across the whole suite. They are not obscure: two of them draw the pages somebody uses when
 * they cannot get in, and the third *is* the passwordless sign-in.
 *
 * ## Why they were at zero, and why that is the interesting part
 *
 * `AccountPasswordResetScreenTest` drives `forgotpassword()` and `resetpassword()` thoroughly — the
 * anti-enumeration property, the CSRF refusal, the spent link — and it replaces the two render
 * methods with a recorder, on the stated grounds that a real render would need a view stack to
 * assert one string. That is a fair trade for what that file is about, and it is also how a method
 * ends up never running while the action above it is well covered.
 *
 * So the seam moves down. `getView()` is stubbed instead, which is the one collaborator these
 * methods have, and everything they actually do then executes: the document title, the standalone
 * layout, the fixed context every view gets, and the caller's context copied over the top.
 *
 * ## What is worth asserting about a renderer
 *
 * Not the HTML — that is a theme's business. What matters is the handful of values a view cannot
 * work without and cannot ask for itself:
 *
 *  - **`routeBase`**, because every link the page draws is built from it. Wrong, and the form posts
 *    somewhere else.
 *  - **`humanCheck`** on the forgot page and *not* on the reset page, which is a real distinction
 *    rather than an oversight: the forgot form sends mail to an address the submitter chose, and the
 *    reset form is reached only by holding a link that was mailed.
 *  - **the caller's context wins**, since that is where the error key and the flash live.
 */
#[CoversClass(Account::class)]
class AccountRenderersTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        \Pramnos\Http\Request::resetInstance();

        parent::tearDown();
    }

    /**
     * An Account whose only stubbed collaborators are the view, the document and the flow.
     *
     * Deliberately not the render methods: they are the subject. The document is a stub because
     * `Factory::getDocument()` in a unit test is a singleton shared with every other class in the
     * run, and a title written into it is a value another test can read.
     */
    private function probe(?LoginFlowResult $authLinkResult = null): object
    {
        return new class ($authLinkResult) extends Account {
            /** @var list<array{template: string, assigned: array<string, mixed>}> */
            public array $displayed = [];

            public array $redirects = [];

            public object $doc;

            /** Whether `useStandaloneLayout()` reached the theme. */
            public string $contentType = '';

            public function __construct(private ?LoginFlowResult $authLinkResult)
            {
                $recorder = new class {
                    public string $contentType = '';

                    public function setContentType(string $type): void
                    {
                        $this->contentType = $type;
                    }
                };

                $this->doc = new class ($recorder) {
                    public string $title = '';

                    public function __construct(public object $themeObject)
                    {
                    }
                };
            }

            protected function document(): object
            {
                return $this->doc;
            }

            /**
             * A view that records what it was given.
             *
             * By reference, because the parent's signature is `&getView()` — PHP rejects an override
             * that is not, and a test class that cannot load proves nothing.
             */
            public function &getView($name = '', $type = '', $args = array())
            {
                $owner = $this;

                $view = new class ($owner) {
                    /** @var array<string, mixed> */
                    public array $assigned = [];

                    public function __construct(private object $owner)
                    {
                    }

                    public function __set(string $key, $value): void
                    {
                        $this->assigned[$key] = $value;
                    }

                    public function display($template = '')
                    {
                        $this->owner->displayed[] = [
                            'template' => (string) $template,
                            'assigned' => $this->assigned,
                        ];

                        return 'rendered:' . $template;
                    }
                };

                return $view;
            }

            protected function humanCheckChallenge(string $form): ?array
            {
                return ['form' => $form, 'challenge' => 'a-challenge'];
            }

            protected function setting(string $key): string
            {
                return $key === 'sitename' ? 'Probe Site' : '';
            }

            protected function flow(): \Pramnos\Auth\LoginFlow
            {
                $result = $this->authLinkResult ?? LoginFlowResult::failed();

                /** @var \Pramnos\Auth\LoginFlow $stub */
                $stub = new class ($result) extends \Pramnos\Auth\LoginFlow {
                    public function __construct(private LoginFlowResult $result)
                    {
                    }

                    public function completeAuthLink(string $token): LoginFlowResult
                    {
                        return $this->result;
                    }
                };

                return $stub;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function renderLogin(array $ctx = []): mixed
            {
                $this->displayed[] = ['template' => 'login', 'assigned' => $ctx];

                return null;
            }

            public function callRenderForgot(array $ctx): mixed
            {
                return $this->renderForgot($ctx);
            }

            public function callRenderReset(array $ctx): mixed
            {
                return $this->renderReset($ctx);
            }

            /** Recorded rather than executed: `terminate()` is `exit`, which ends the test run. */
            public bool $terminated = false;

            public array $errors = [];

            /** @var array<string, mixed>|null what buildExportData() should hand back */
            public ?array $exportData = ['profile' => ['userid' => 42]];

            /** Set to throw from buildExportData(), for the failure path. */
            public bool $exportBlowsUp = false;

            public string $echoed = '';

            protected function terminate(): void
            {
                $this->terminated = true;
            }

            protected function addError($message)
            {
                $this->errors[] = (string) $message;

                return $this;
            }

            protected function buildExportData(int $userId): array
            {
                if ($this->exportBlowsUp) {
                    throw new \Exception('the export query failed');
                }

                return (array) $this->exportData;
            }
        };
    }

    /**
     * The forgot page is titled, standalone, and carries a human check.
     *
     * The human check is the assertion with teeth. This form sends mail to an address the submitter
     * typed, which makes it the cheapest way to use somebody else's site to deliver unwanted mail —
     * so a page rendered without a challenge is not a cosmetic defect, it is an open relay for one
     * message at a time.
     */
    public function testTheForgotPageCarriesWhatItsFormNeeds(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->routeBase = 'Account';

        // Act
        $returned = $probe->callRenderForgot([]);

        // Assert
        $this->assertSame('rendered:forgotpassword', $returned, 'the wrong template was drawn');
        $this->assertSame('Forgot password', $probe->doc->title);
        $this->assertSame(
            'login',
            $probe->doc->themeObject->contentType,
            'the page kept the site chrome around a sign-in screen'
        );

        $assigned = $probe->displayed[0]['assigned'];
        $this->assertSame('Account', $assigned['routeBase'], 'the form would post somewhere else');
        $this->assertSame('forgot', $assigned['humanCheck']['form'] ?? null);
        $this->assertSame('Probe Site', $assigned['brand']['name'] ?? null);
    }

    /**
     * The reset page carries no human check, and that is the correct difference.
     *
     * It is reached only by holding a link that was mailed to the address it belongs to, so the
     * proof-of-human has already been done — by the forgot form, before the mail went out. A
     * challenge here would be a second obstacle in front of somebody who has already been verified,
     * on the screen where they are most likely to give up.
     */
    public function testTheResetPageHasNoChallengeAndSaysWhy(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->routeBase = 'Account';

        // Act
        $returned = $probe->callRenderReset(['token' => 'abc']);

        // Assert
        $this->assertSame('rendered:resetpassword', $returned);
        $this->assertSame('Reset password', $probe->doc->title);

        $assigned = $probe->displayed[0]['assigned'];
        $this->assertArrayNotHasKey('humanCheck', $assigned);
        $this->assertSame('abc', $assigned['token'], 'the token never reached the form');
    }

    /**
     * The caller's context reaches the view, and beats the fixed values.
     *
     * The loop that copies it is one line and the whole point of the method: the error key, the
     * flash and the token all arrive that way. A renderer that assigned the fixed context *after*
     * the loop would silently drop every one of them, and the page would look right on the happy
     * path.
     */
    public function testTheCallersContextWins(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->routeBase = 'Account';

        // Act
        $probe->callRenderForgot([
            'error'     => 'invalid_token',
            'routeBase' => 'Somewhere',
        ]);

        // Assert
        $assigned = $probe->displayed[0]['assigned'];
        $this->assertSame('invalid_token', $assigned['error']);
        $this->assertSame(
            'Somewhere',
            $assigned['routeBase'],
            'the fixed context was assigned last, so the caller cannot override anything'
        );
    }

    /**
     * A valid sign-in link signs the holder in and sends them on.
     *
     * `authlink()` **is** the passwordless sign-in, and it had never run. The redirect target is the
     * assertion that matters rather than the absence of an error: a link that authenticated somebody
     * and then left them on the sign-in page is indistinguishable, to them, from a link that did not
     * work — so they ask for another one.
     */
    public function testAValidLinkSignsInAndRedirects(): void
    {
        // Arrange
        $probe = $this->probe(LoginFlowResult::success(42));
        $probe->routeBase = 'Account';
        $_GET['token'] = 'a-good-token';
        \Pramnos\Http\Request::resetInstance();

        // Act
        $returned = $probe->authlink();

        // Assert
        $this->assertNull($returned);
        $this->assertCount(1, $probe->redirects, 'a valid link did not send anybody anywhere');
        $this->assertSame([], $probe->displayed, 'a valid link also drew the sign-in form');
    }

    /**
     * A spent or forged link renders the sign-in form with a named error.
     *
     * Named, not blank: «this link is no longer valid» tells somebody holding a two-day-old email to
     * ask for a new one. A bare sign-in page tells them their password is wrong, which it is not, and
     * the next thing they do is reset a password they never forgot.
     */
    public function testAnInvalidLinkExplainsItself(): void
    {
        // Arrange
        $probe = $this->probe(LoginFlowResult::failed());
        $probe->routeBase = 'Account';
        $_GET['token'] = 'a-spent-token';
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->authlink();

        // Assert
        $this->assertSame([], $probe->redirects, 'a failed link signed somebody in');
        $this->assertSame('login', $probe->displayed[0]['template'] ?? null);
        $this->assertSame('authlink_invalid', $probe->displayed[0]['assigned']['error'] ?? null);
    }

    /**
     * A data export without a valid CSRF token exports nothing.
     *
     * GDPR Article 15 makes this endpoint answer with everything the site knows about a person, in
     * one file. That makes it the single most useful thing on the account for somebody else to
     * trigger: a page anybody writes, a signed-in visitor's browser, and the response is the whole
     * profile. The token is what stops it, and a refusal that still built the data would leak it into
     * a log or an error page on the way to saying no.
     */
    public function testAnExportWithoutTheTokenBuildsNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->routeBase = 'Account';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        \Pramnos\Http\Request::resetInstance();

        // Act
        $returned = $probe->exportdata();

        // Assert
        $this->assertNull($returned);
        $this->assertSame('', $probe->echoed, 'the export was written out anyway');
        $this->assertFalse($probe->terminated);
        $this->assertNotSame([], $probe->errors, 'the refusal was silent');
        $this->assertCount(1, $probe->redirects);
    }

    /**
     * A failed export says so and does not send half a file.
     *
     * The branch matters because of what precedes it in the method: the headers that turn the
     * response into a download are sent *before* the JSON is built in the success path, so a build
     * that throws after them would hand the browser a file named
     * `user_data_export_….json` containing an error page. The order here — build, then send — is what
     * this pins, by asserting that a throw produces a redirect and no output at all.
     */
    public function testAFailedExportSendsNoFile(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->routeBase = 'Account';
        $probe->exportBlowsUp = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST[\Pramnos\Http\Session::getInstance()->getToken()] = '1';
        \Pramnos\Http\Request::resetInstance();

        // Act
        $probe->exportdata();

        // Assert
        $this->assertSame('', $probe->echoed);
        $this->assertFalse($probe->terminated, 'a failed export still ended the response as a download');
        $this->assertNotSame([], $probe->errors);
        $this->assertCount(1, $probe->redirects);
    }

    /**
     * The GET side is a confirmation page, not the export.
     *
     * Which is the correct shape and worth pinning: a `GET` that produced the file would put every
     * fact the site holds about somebody behind a URL — shareable, in browser history, in a proxy
     * log, and fetchable by any `<img src>` on any page they visit.
     */
    public function testTheExportPageIsAConfirmationAndNotTheFile(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->routeBase = 'Account';

        // Act
        $returned = $probe->exportdata();

        // Assert
        $this->assertSame('rendered:export_data', $returned);
        $this->assertSame('', $probe->echoed, 'a GET produced the export');
        $this->assertFalse($probe->terminated);
        $this->assertNotSame(
            [],
            $probe->displayed[0]['assigned']['exportSections'] ?? [],
            'the page cannot say what it is about to export'
        );
    }

    /**
     * Revoking with no `client_id` is refused, and the AJAX caller is told in JSON.
     *
     * The shape of the answer is the assertion. An XHR that receives an HTML flash page instead of
     * JSON fails in the browser at `response.json()`, which surfaces as «something went wrong» with
     * no clue that the request was simply missing a field.
     */
    public function testRevokingWithoutAClientIdIsRefusedInKind(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->routeBase = 'Account';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_POST = [];
        \Pramnos\Http\Request::resetInstance();

        // Act
        ob_start();
        $probe->revokeapplication();
        $output = (string) ob_get_clean();
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);

        // Assert
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'an XHR was answered with something that is not JSON');
        $this->assertFalse($decoded['success']);
        $this->assertStringContainsString('client_id', (string) $decoded['message']);
        $this->assertTrue($probe->terminated, 'the response continued past the JSON body');
    }

    /**
     * And a non-AJAX refusal becomes a flash message *and a page to read it on*.
     *
     * Same refusal, two audiences. A form post answered with a bare JSON body would show the visitor
     * `{"success":false,…}` as the entire page — and this test found the mirror of that: the redirect
     * lived at the end of `revokeapplication()`, after the try/catch, so both early returns skipped
     * it. A browser form with no `client_id` got a flash queued for a page that was never rendered,
     * which is a blank response and a message that surfaces later on something unrelated.
     */
    public function testANonAjaxRefusalIsAFlashMessageOnAPage(): void
    {
        // Arrange
        $probe = $this->probe();
        $probe->routeBase = 'Account';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];
        \Pramnos\Http\Request::resetInstance();

        // Act
        ob_start();
        $probe->revokeapplication();
        $output = (string) ob_get_clean();

        // Assert
        $this->assertSame('', $output, 'a browser form was answered with a JSON body');
        $this->assertNotSame([], $probe->errors, 'the refusal was silent');
        $this->assertCount(
            1,
            $probe->redirects,
            'the flash was queued for a page nobody is sent to'
        );
        $this->assertStringEndsWith('Account/applications', $probe->redirects[0]);
    }
}
