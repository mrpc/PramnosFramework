<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\Controllers\Oauth;

/**
 * The login form is the document a browser judges, and it is a different request.
 *
 * `form-action` governs the page that *contains* the form, and it applies to every redirect
 * the submission passes through. So the page that has to carry the client's origin is the
 * login page — rendered after the authorization endpoint has already redirected, by a
 * controller with no way of knowing which client is being authorised.
 *
 * Which is why the validated origin travels in the session rather than being re-derived
 * from `?return=`: `Oauth::authorize()` writes it *after* checking the `redirect_uri`
 * against the client's registered callbacks. Reading it out of the return URL instead would
 * let a crafted `?return=` name any destination and be believed — the open redirect that was
 * just closed at the endpoint, re-entering through the policy.
 */
#[CoversClass(Account::class)]
class LoginFormAllowsOauthCallbackTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SESSION[Oauth::FORM_ACTION_SESSION_KEY]);
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        unset($_SESSION[Oauth::FORM_ACTION_SESSION_KEY]);
        \Pramnos\Http\Request::resetInstance();

        parent::tearDown();
    }

    /**
     * The stored origin reaches the login page's policy.
     *
     * Without it, `POST /login` → 302 `/oauth/authorize` → 302 `https://client.example/…`
     * is cancelled at the last hop and the console blames the same-origin URL the chain
     * started at.
     */
    public function testTheStoredOriginReachesTheLoginPagePolicy(): void
    {
        // Arrange — what the authorization endpoint left behind after validating it
        $_SESSION[Oauth::FORM_ACTION_SESSION_KEY] = 'https://client.example';
        $probe = $this->probe();

        // Act
        $probe->render('login');

        // Assert
        $this->assertContains(
            'https://client.example',
            $this->formAction($probe->app)
        );
    }

    /**
     * The second-factor screen too, because the chain can pause there.
     *
     * A user with 2FA posts the login form, gets the step-up form, and posts *that* — so
     * the step-up page is the document containing the form that completes the flow. Missing
     * it would make the failure depend on whether the account has a second factor, which is
     * the kind of intermittency that took a day to run down the first time.
     */
    public function testTheStepUpScreenCarriesItAsWell(): void
    {
        // Arrange
        $_SESSION[Oauth::FORM_ACTION_SESSION_KEY] = 'https://client.example';
        $probe = $this->probe();

        // Act
        $probe->render('stepup');

        // Assert
        $this->assertContains(
            'https://client.example',
            $this->formAction($probe->app)
        );
    }

    /**
     * An ordinary login carries no extra source.
     *
     * Most visits to this page have nothing to do with OAuth2, and a policy widened for
     * every one of them is the default quietly weakened for the whole site.
     */
    public function testAnOrdinaryLoginPageIsUnchanged(): void
    {
        // Arrange — nothing stored
        $probe = $this->probe();

        // Act
        $probe->render('login');

        // Assert
        $this->assertSame(["'self'"], $this->formAction($probe->app));
    }

    /**
     * An empty stored value is not a source.
     *
     * `cspSourceForUri()` answers '' when there is nothing usable to name, and a session
     * that has been through a serialise/restore cycle can hold that.
     */
    public function testAnEmptyStoredValueIsIgnored(): void
    {
        // Arrange
        $_SESSION[Oauth::FORM_ACTION_SESSION_KEY] = '';
        $probe = $this->probe();

        // Act
        $probe->render('login');

        // Assert
        $this->assertSame(["'self'"], $this->formAction($probe->app));
    }

    /**
     * The `form-action` sources in an application's policy.
     *
     * @return list<string>
     */
    private function formAction(Application $app): array
    {
        foreach (explode('; ', $app->cspPolicy()) as $segment) {
            $parts = preg_split('/\s+/', trim($segment)) ?: [];
            if (array_shift($parts) === 'form-action') {
                return $parts;
            }
        }

        return [];
    }

    /**
     * An Account whose view, document and flow are stubs, and whose application is real
     * enough to build a policy — the constructor is what boots a database and a session,
     * and none of that is needed to answer what the header would say.
     */
    private function probe(): object
    {
        $app = new class extends Application {
            public function __construct()
            {
            }
        };

        return new class ($app) extends Account {
            public function __construct(public Application $app)
            {
                $this->application = $app;
            }

            /** Render one of the two screens under test. */
            public function render(string $which): void
            {
                if ($which === 'login') {
                    $this->renderLogin([]);
                    return;
                }
                $this->renderStepUp([]);
            }

            protected function document(): object
            {
                return new class {
                    public string $title = '';
                };
            }

            protected function useStandaloneLayout(): void
            {
            }

            protected function brand(): array
            {
                return [];
            }

            protected function humanCheckChallenge(string $form): ?array
            {
                return null;
            }

            protected function flow(): \Pramnos\Auth\LoginFlow
            {
                return new class extends \Pramnos\Auth\LoginFlow {
                    public function __construct()
                    {
                    }
                    public function pendingUserId(): ?int
                    {
                        return 7;
                    }
                };
            }

            /**
             * By reference, because the parent's signature is `&getView()`: PHP refuses to
             * load an override that is not, and a test class that cannot load proves nothing.
             */
            public function &getView($name = '', $type = '', $args = array())
            {
                $view = new #[\AllowDynamicProperties] class {
                    public function display($template = '', $return = false, $outputBuffer = true)
                    {
                        return 'rendered';
                    }
                };

                return $view;
            }
        };
    }
}
