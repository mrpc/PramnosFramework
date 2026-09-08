<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/**
 * A registered `redirect_uri`, or nothing at all.
 *
 * The authorization endpoint read `redirect_uri` from the request, required it to be
 * non-empty, and then used it as a redirect target with an authorization code attached —
 * without ever comparing it to `applications.callback`, the column the admin screen fills
 * in for exactly this purpose. That is an open redirect that delivers a real code to a
 * destination an attacker chose, and shows the user their own authorization server's login
 * form on the way.
 *
 * RFC 6749 §3.1.2 requires registration and an exact match, and the exact match is the
 * point of most of what is below: every near miss a prefix or host comparison would have
 * accepted is a working attack.
 *
 * A client with **nothing** registered is a separate question, and the answer here is not
 * to refuse it: there is no registration to check against, and refusing would stop
 * authorization requests that work today on every installation whose `callback` column is
 * empty. It is recorded and it is accepted — and, since the endpoint accepted it, the
 * `form-action` policy is widened to what it asked for.
 *
 * That last clause was the other way round for a day, gated on the registration, and it
 * caused an outage: a customer's localhost login stopped working while production kept
 * working, reported as «when I log in from localhost it does not redirect me back», with
 * nothing server-side to see because the refusal happens in the browser. The lesson is in
 * `testAnUnregisteredClientStillGetsThePolicyItNeeds()` — a CSP cannot be the enforcement
 * point for a decision the layer below it does not make.
 */
#[CoversClass(Oauth::class)]
class OauthRedirectUriRegistrationTest extends TestCase
{
    use \Pramnos\Tests\Support\PreservesAppKeys;

    private RegistrationTestableOauth $controller;
    private $queryBuilderMock;
    private $originalDb;

    protected function setUp(): void
    {
        // Constructing the controller writes an RSA key pair under ROOT/app/keys.
        $this->snapshotAppKeys();

        \Pramnos\Http\Session::getInstance();

        $dbRef = &Database::getInstance();
        $this->originalDb = clone $dbRef;

        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->queryBuilderMock->method('table')->willReturnSelf();
        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('join')->willReturnSelf();
        $this->queryBuilderMock->method('leftJoin')->willReturnSelf();

        $dbMock = $this->createMock(Database::class);
        $dbMock->method('queryBuilder')->willReturn($this->queryBuilderMock);
        $dbRef = $dbMock;

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SESSION[Oauth::FORM_ACTION_SESSION_KEY]);

        $this->controller = new RegistrationTestableOauth(null);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        unset($_SESSION[Oauth::FORM_ACTION_SESSION_KEY]);

        $dbRef = &Database::getInstance();
        $dbRef = $this->originalDb;

        $this->restoreAppKeys();
    }

    /**
     * Make the client the endpoint will load answer with this `callback` column.
     */
    private function clientRegisters(?string $callback): void
    {
        $row = new \stdClass();
        $row->numRows = 1;
        $row->fields = [
            'appid'    => 42,
            'name'     => 'A Client',
            'apikey'   => 'client-42',
            'status'   => 1,
            'scope'    => 'profile',
            'callback' => $callback,
        ];

        $this->queryBuilderMock->method('first')->willReturn($row);
    }

    /**
     * Ask to authorize with a given `redirect_uri` and return what the endpoint produced.
     */
    private function authorizeWith(string $redirectUri): string
    {
        $_GET['client_id']     = 'client-42';
        $_GET['response_type'] = 'code';
        $_GET['scope']         = 'profile';
        $_GET['redirect_uri']  = $redirectUri;

        ob_start();
        $this->controller->authorize();
        $echoed = (string) ob_get_clean();

        // The error page is *added to the document*, not echoed — an echo would have gone
        // out above the HTML the framework is about to render. So a refusal shows up in
        // the document content and a redirect in what the stub echoed, and the two
        // together are what the endpoint did.
        return $echoed . \Pramnos\Framework\Factory::getDocument('html')->getContent();
    }

    /**
     * The exact registered URI is accepted, and the flow proceeds.
     *
     * The positive case has to be here, or every assertion below could be satisfied by an
     * endpoint that refuses everything.
     */
    public function testTheRegisteredUriIsAccepted(): void
    {
        // Arrange
        $this->clientRegisters('https://client.example/login-sso');

        // Act — no user is logged in, so proceeding means the login redirect
        $out = $this->authorizeWith('https://client.example/login-sso');

        // Assert
        $this->assertStringContainsString('REDIRECTED_TO:', $out);
        $this->assertStringNotContainsString('Authorization Error', $out);
    }

    /**
     * A `redirect_uri` the client never registered is refused, and no code is issued.
     *
     * This is the defect: without the check, a crafted `authorize` link named any
     * destination and a real authorization code was delivered to it.
     */
    public function testAnUnregisteredUriIsRefused(): void
    {
        // Arrange
        $this->clientRegisters('https://client.example/login-sso');

        // Act
        $out = $this->authorizeWith('https://attacker.example/collect');

        // Assert — the error page, not a redirect anywhere
        $this->assertStringContainsString('Authorization Error', $out);
        $this->assertStringNotContainsString('REDIRECTED_TO:', $out);
    }

    /**
     * The refusal does not echo the URI it refused.
     *
     * An error page that prints an attacker-controlled URL turns a refusal into a place to
     * put a link — the destination is unreachable but the text is not, and the page is
     * being shown on the authorization server's own origin.
     */
    public function testTheRefusalDoesNotEchoTheRequestedUri(): void
    {
        // Arrange
        $this->clientRegisters('https://client.example/login-sso');

        // Act
        $out = $this->authorizeWith('https://attacker.example/collect');

        // Assert
        $this->assertStringNotContainsString('attacker.example', $out);
    }

    /**
     * Every near miss an inexact comparison would have accepted.
     *
     * Each of these is a separate published attack on OAuth2 deployments, and each is why
     * the RFC says exact: a prefix test accepts a longer host, a host test accepts any
     * path, a "starts with" test accepts a subdomain, and `@` moves the authority.
     *
     * @param string $requested What the request asked for
     */
    #[DataProvider('nearMisses')]
    public function testANearMissIsRefused(string $requested): void
    {
        // Arrange
        $this->clientRegisters('https://client.example/cb');

        // Act
        $out = $this->authorizeWith($requested);

        // Assert
        $this->assertStringContainsString(
            'Authorization Error',
            $out,
            $requested . ' must not satisfy a registration of https://client.example/cb'
        );
    }

    /**
     * @return array<string,array{string}>
     */
    public static function nearMisses(): array
    {
        return [
            'longer host'        => ['https://client.example.attacker.test/cb'],
            'subdomain'          => ['https://evil.client.example/cb'],
            'userinfo authority' => ['https://client.example@attacker.test/cb'],
            'different path'     => ['https://client.example/collect'],
            'appended path'      => ['https://client.example/cb/../collect'],
            'added query'        => ['https://client.example/cb?next=https://attacker.test'],
            'trailing slash'     => ['https://client.example/cb/'],
            'scheme downgrade'   => ['http://client.example/cb'],
            'uppercase host'     => ['https://CLIENT.EXAMPLE/cb'],
            'default port shown' => ['https://client.example:443/cb'],
            'open redirect'      => ['//attacker.test'],
            'relative'           => ['/cb'],
        ];
    }

    /**
     * A client with nothing registered keeps working, and is recorded.
     *
     * Deliberately **not** refused. There is no registration to check against, and
     * refusing would stop authorization requests that work today on every installation
     * whose `callback` column is empty — a behaviour change on the strength of a rule the
     * framework has never enforced. So the condition is written down instead, naming the
     * client, so somebody can fill the registration in deliberately rather than under a
     * login that has stopped working.
     */
    public function testAClientWithNoRegisteredCallbackStillWorks(): void
    {
        // Arrange
        $this->clientRegisters(null);

        // Act
        $out = $this->authorizeWith('https://client.example/cb');

        // Assert
        $this->assertStringNotContainsString('Authorization Error', $out);
        $this->assertStringContainsString('REDIRECTED_TO:', $out);
    }

    /**
     * And it gets the policy it needs, because the endpoint accepted the request.
     *
     * **This test asserted the opposite, and the opposite was wrong.** The reasoning was
     * that widening the policy for a destination no registration vouches for converts a
     * broken feature into a working attack. It had the layers backwards.
     *
     * `form-action` cannot answer *should this request be allowed*, only *may the form post
     * toward the place this request names*. The endpoint above accepts an unregistered
     * client's `redirect_uri` — deliberately, see the previous test — so refusing here
     * added no security, only a second place to be inconsistent.
     *
     * It was not even the protection it looked like: `form-action` governs form
     * submissions, so a user who already holds a session takes `GET /oauth/authorize` →
     * 302 → client with no form in it, and the code is delivered regardless. What the gate
     * stopped was the legitimate half — a customer's localhost login, reported as «when I
     * log in from localhost it does not redirect me back», with nothing server-side to see
     * because the refusal happens in the browser.
     */
    public function testAnUnregisteredClientStillGetsThePolicyItNeeds(): void
    {
        // Arrange
        $this->clientRegisters(null);

        // Act
        $this->authorizeWith('https://client.example/cb');

        // Assert
        $this->assertSame(
            'https://client.example',
            $_SESSION[Oauth::FORM_ACTION_SESSION_KEY] ?? null,
            'the policy refused a destination the endpoint had just accepted'
        );
    }

    /**
     * An empty string, a comma and whitespace are no registration either.
     *
     * A `callback` column holding `,` parses into two empty entries, and an empty entry
     * would match a request that sent nothing. `validateAuthorizeParams()` also rejects an
     * empty `redirect_uri`, but only because of the order the two checks happen to run in.
     *
     * @param string $callback The stored column value
     */
    #[DataProvider('emptyRegistrations')]
    public function testAnEmptyRegistrationIsNoRegistration(string $callback): void
    {
        // Arrange
        $this->clientRegisters($callback);

        // Act
        $out = $this->authorizeWith('https://client.example/cb');

        // Assert — treated as unregistered, which means accepted and widened, not refused
        $this->assertStringNotContainsString('Authorization Error', $out);
        $this->assertSame(
            'https://client.example',
            $_SESSION[Oauth::FORM_ACTION_SESSION_KEY] ?? null
        );
    }

    /**
     * @return array<string,array{string}>
     */
    public static function emptyRegistrations(): array
    {
        return [
            'empty string'   => [''],
            'whitespace'     => ['   '],
            'a comma'        => [','],
            'commas only'    => [' , , '],
            'empty JSON'     => ['[]'],
            'JSON of blanks' => ['["", "  "]'],
        ];
    }

    /**
     * A client with several registered callbacks: any one of them, exactly.
     *
     * The column is historically either a JSON array or a comma-separated list, and both
     * shapes are in production data.
     *
     * @param string $callback  The stored column value
     * @param string $requested A URI that must be accepted
     */
    #[DataProvider('multipleRegistrations')]
    public function testAnyOneOfSeveralRegisteredUrisIsAccepted(string $callback, string $requested): void
    {
        // Arrange
        $this->clientRegisters($callback);

        // Act
        $out = $this->authorizeWith($requested);

        // Assert
        $this->assertStringNotContainsString('Authorization Error', $out);
        $this->assertStringContainsString('REDIRECTED_TO:', $out);
    }

    /**
     * @return array<string,array{string,string}>
     */
    public static function multipleRegistrations(): array
    {
        return [
            'comma list, first'  => ['https://a.example/cb,https://b.example/cb', 'https://a.example/cb'],
            'comma list, second' => ['https://a.example/cb,https://b.example/cb', 'https://b.example/cb'],
            'comma list, spaced' => ['https://a.example/cb , https://b.example/cb', 'https://b.example/cb'],
            'json array'         => ['["https://a.example/cb","https://b.example/cb"]', 'https://b.example/cb'],
            'native scheme'      => ['hwmapp://oauth', 'hwmapp://oauth'],

            // The reported scenario, exactly: one client, three environments, and the
            // localhost one is what stopped working when a single production value was
            // read as authoritative. All three separators, because a human types this.
            'the three environments, spaces' => [
                'http://localhost:3000/callback https://staging.app.example/callback '
                . 'https://app.example/callback',
                'http://localhost:3000/callback',
            ],
            'the three environments, newlines' => [
                "https://app.example/callback\nhttps://staging.app.example/callback\n"
                . 'http://localhost:3000/callback',
                'http://localhost:3000/callback',
            ],
            'production still works' => [
                'http://localhost:3000/callback https://app.example/callback',
                'https://app.example/callback',
            ],
        ];
    }

    /**
     * A `redirect_uri` whose scheme is only ever script is refused on the request.
     *
     * Checked on the request and not only against the registration, because a client with
     * nothing registered is accepted — so the registration is not a check that runs for
     * everybody. This one does.
     *
     * `javascript://x/%0aalert(1)` satisfies every structural rule a URL parser applies: a
     * scheme, a host, a path. And this value reaches a `Location` header and a CSP
     * `form-action` source.
     *
     * @param string $uri A scheme that is never a callback
     */
    #[DataProvider('scriptSchemes')]
    public function testAScriptSchemeIsRefusedEvenWithNothingRegistered(string $uri): void
    {
        // Arrange — the client that is *not* protected by an exact match
        $this->clientRegisters(null);

        // Act
        $out = $this->authorizeWith($uri);

        // Assert
        $this->assertStringContainsString('Authorization Error', $out);
        $this->assertStringNotContainsString('REDIRECTED_TO:', $out);
        $this->assertArrayNotHasKey(
            Oauth::FORM_ACTION_SESSION_KEY,
            $_SESSION,
            'a refused scheme must not reach the policy either'
        );
    }

    /**
     * @return array<string,array{string}>
     */
    public static function scriptSchemes(): array
    {
        return [
            'javascript'           => ['javascript:alert(1)'],
            'javascript with host' => ['javascript://x/%0aalert(1)'],
            'uppercase'            => ['JavaScript:alert(1)'],
            'data'                 => ['data:text/html;base64,PHNjcmlwdD4='],
            'vbscript'             => ['vbscript:msgbox(1)'],
            'file'                 => ['file:///etc/passwd'],
        ];
    }

    /**
     * The login redirect uses the field name the login form actually reads.
     *
     * `Account::returnUrl()` reads `return`; this endpoint sent `return_url`. So the
     * parameter arrived, was ignored, and a user who signed in here landed on the
     * dashboard instead of back at the authorization request — the sign-in succeeded and
     * lost its purpose on the way, which is indistinguishable from an SSO client that
     * simply never gets a code.
     */
    public function testTheLoginRedirectNamesTheFieldTheLoginFormReads(): void
    {
        // Arrange
        $this->clientRegisters('https://client.example/cb');

        // Act
        $out = $this->authorizeWith('https://client.example/cb');

        // Assert
        $this->assertStringContainsString('login?return=', $out);
        $this->assertStringNotContainsString('return_url=', $out);
    }

    /**
     * The validated destination is stored for the login page, which is another request.
     *
     * `form-action` governs the document holding the form — the login page — and that page
     * is rendered after this redirect, by a controller with no way to know which client is
     * being authorised. Storing the source *after* the registration check is what lets it
     * widen its policy to a destination that has already been verified, instead of
     * believing a `?return=` it was handed.
     */
    public function testTheValidatedOriginIsLeftForTheLoginPage(): void
    {
        // Arrange
        $this->clientRegisters('https://client.example/cb');

        // Act
        $this->authorizeWith('https://client.example/cb');

        // Assert — the origin, not the full callback: a policy names origins
        $this->assertSame(
            'https://client.example',
            $_SESSION[Oauth::FORM_ACTION_SESSION_KEY] ?? null
        );
    }

    /**
     * A registered callback that has no origin to name widens nothing, and still works.
     *
     * A relative `callback` is a misregistration rather than an attack — but it is in
     * production data, and the flow must not depend on the policy step succeeding. The
     * exact match accepts it, no source is derived, and the request proceeds; there is
     * nothing to widen because the destination is this origin, which `'self'` already
     * covers.
     */
    public function testARegisteredCallbackWithNoOriginWidensNothing(): void
    {
        // Arrange
        $this->clientRegisters('/cb');

        // Act
        $out = $this->authorizeWith('/cb');

        // Assert
        $this->assertStringContainsString('REDIRECTED_TO:', $out);
        $this->assertArrayNotHasKey(Oauth::FORM_ACTION_SESSION_KEY, $_SESSION);
    }

    /**
     * A refused request leaves nothing behind.
     *
     * If the allowance were stored before the check, the refusal above would still have
     * widened the next login page's policy to the attacker's origin — the open redirect
     * re-entering through the CSP after being closed at the endpoint.
     */
    public function testARefusedRequestStoresNoAllowance(): void
    {
        // Arrange
        $this->clientRegisters('https://client.example/cb');

        // Act
        $this->authorizeWith('https://attacker.example/collect');

        // Assert
        $this->assertArrayNotHasKey(Oauth::FORM_ACTION_SESSION_KEY, $_SESSION);
    }
}

/**
 * The controller with `exit`, the view layer and the current user taken out of the way.
 *
 * No user is ever logged in here, so proceeding means the login redirect — which the stub
 * echoes rather than sending. A refusal is `Authorization Error` in the document instead.
 */
class RegistrationTestableOauth extends Oauth
{
    protected function terminate(): void
    {
    }

    protected function getLoggedInUser(): ?\Pramnos\User\User
    {
        return null;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        echo 'REDIRECTED_TO:' . $url;
    }

    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new #[\AllowDynamicProperties] class {
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                echo 'CONSENT_FORM';
                return true;
            }
            public function assign(string $key, mixed $val): void
            {
                $this->$key = $val;
            }
        };
        return $view;
    }
}
