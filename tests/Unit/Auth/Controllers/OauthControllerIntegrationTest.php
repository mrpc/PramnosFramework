<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Nyholm\Psr7\Factory\Psr17Factory;

class TestableOauth extends Oauth
{
    public $loggedInUser = null;

    protected function terminate(): void
    {
        // bypass exit; for tests
    }

    protected function getLoggedInUser(): ?\Pramnos\User\User
    {
        return $this->loggedInUser;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        echo "REDIRECTED_TO:" . $url;
    }
    
    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new #[\AllowDynamicProperties] class($name) {
            public $apps = [];
            public function __construct($name) {
                $this->name = $name;
            }
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                $out = "oauth-view";
                if ($return) return $out;
                echo $out;
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

class OauthControllerIntegrationTest extends TestCase
{
    use \Pramnos\Tests\Support\PreservesAppKeys;

    private TestableOauth $controller;
    private $dbMock;
    private $queryBuilderMock;
    private $originalDb;

    protected function setUp(): void
    {
        // Constructing the real Oauth controller generates RSA keys under
        // ROOT/app/keys — snapshot first so tearDown() removes only ours.
        $this->snapshotAppKeys();

        \Pramnos\Http\Session::getInstance();

        // Save original database reference
        $dbRef = &\Pramnos\Database\Database::getInstance();
        /*
         * **The object, not a copy of it.**
         *
         * This parks the live connection while a mock takes the singleton's place, and
         * `tearDown()` puts it back. Cloning put back a *different* Database — one whose
         * connection parameters were frozen at clone time and whose `connected` flag said
         * true while nothing behind it was usable. Every class that ran afterwards and
         * asked for the singleton got that, and the next `connect()` fell through to a
         * unix socket: `RuntimeException: No such file or directory`, in a test that had
         * touched none of this.
         *
         * Two other classes carry a `$dbRef = null` guard in their own setUp written to
         * survive exactly that. Nothing is being protected by the copy — the original is
         * parked, not mutated.
         */
        $this->originalDb = $dbRef;

        // Mock QueryBuilder
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->queryBuilderMock->method('table')->willReturnSelf();
        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')->willReturnSelf();
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('join')->willReturnSelf();
        $this->queryBuilderMock->method('leftJoin')->willReturnSelf();

        /*
         * A mocked builder answers whatever it is asked, which is why these two tests were green
         * while introspection and revocation matched the wrong column for a whole day: the mock
         * returned the prepared row no matter what the WHERE said. Kept as they are — they cover
         * the controller's decisions, not its SQL — with the column itself pinned by
         * `TokenAtRestTest::testNoLookupMatchesOnTheTokenColumn()`, which reads the source.
         */

        // Mock Database
        $this->dbMock = $this->createMock(Database::class);
        $this->dbMock->method('queryBuilder')->willReturn($this->queryBuilderMock);

        // Inject Database via reference
        $dbRef = $this->dbMock;

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->controller = new TestableOauth(null);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];

        // Restore original database
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = $this->originalDb;

        // Remove the keys the controller generated under ROOT/app/keys;
        // pre-existing keys are preserved.
        $this->restoreAppKeys();
    }

    /**
     * One row of the applications table, as validateCredentials() reads it.
     *
     * @param string $secret Value of the `apisecret` column.
     */
    private function applicationRow(string $secret): \stdClass
    {
        $row          = new \stdClass();
        $row->numRows = 1;
        $row->fields  = [
            'appid'     => 1,
            'apikey'    => '123',
            'apisecret' => $secret,
            'status'    => 1,
        ];

        return $row;
    }

    public function testDisplay()
    {
        $mockResult = new class {
            public $numRows = 1;
            public $fields = ['appid' => 1, 'name' => 'App 1', 'description' => '', 'apikey' => 'key', 'status' => 1, 'created' => '2023-01-01'];
            private $fetched = false;
            public function fetch() {
                if (!$this->fetched) {
                    $this->fetched = true;
                    return true;
                }
                return false;
            }
        };
        
        $this->queryBuilderMock->method('get')->willReturn($mockResult);

        ob_start();
        $this->controller->display();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
        $this->assertStringContainsString('oauth-view', $echoed);
    }

    public function testAuthorizeNoLogin()
    {
        $_GET['client_id'] = '123';
        $_GET['response_type'] = 'code';
        $_GET['redirect_uri'] = 'http://localhost/callback';
        $_GET['state'] = 'abc';
        $_GET['scope'] = 'profile';

        $mockClient = new \stdClass();
        $mockClient->numRows = 1;
        $mockClient->fields = ['appid' => 123, 'name' => 'App 1', 'callback' => 'http://localhost/callback'];

        $this->queryBuilderMock->method('first')->willReturn($mockClient);

        ob_start();
        $this->controller->authorize();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('login?return=', $echoed);
    }

    /**
     * Run authorize() and return what it printed and where it redirected.
     *
     * A redirect ends the request through `Application::redirect()`, which under the test
     * runner throws `ApplicationClosedException` with the destination recorded on the
     * application — so the redirect is an assertion, not an unobservable header.
     *
     * @return array{echoed: string, redirect: ?string}
     */
    private function runAuthorize(): array
    {
        ob_start();
        try {
            $this->controller->authorize();
        } catch (\Pramnos\Application\ApplicationClosedException) {
            // The redirect ends the request; where it went is asserted below.
        }

        return ['echoed' => (string) ob_get_clean(), 'redirect' => $this->controller->application->getRedirect()];
    }

    /**
     * A signed-in user on a valid request either sees the consent form or, having consented
     * before, is sent straight back to the client with a code and the state.
     */
    public function testAuthorizeWithLogin()
    {
        $_GET['client_id'] = '123';
        $_GET['response_type'] = 'code';
        $_GET['redirect_uri'] = 'http://localhost/callback';
        $_GET['state'] = 'abc';
        $_GET['scope'] = 'profile';

        $mockClient = new \stdClass();
        $mockClient->numRows = 1;
        $mockClient->fields = ['appid' => 123, 'name' => 'App 1', 'callback' => 'http://localhost/callback', 'scope' => 'profile'];

        $this->queryBuilderMock->method('first')->willReturn($mockClient);

        $user = $this->createMock(\Pramnos\User\User::class);
        $user->userid = 10;
        $user->username = 'testuser';
        $this->controller->loggedInUser = $user;

        // Act
        $result = $this->runAuthorize();

        // Assert — the mocked consent lookup finds a row, so this is the auto-approve branch
        $this->assertStringStartsWith('http://localhost/callback?code=', (string) $result['redirect']);
        $this->assertStringContainsString('state=abc', (string) $result['redirect']);
    }

    public function testAuthorizeTrustedClientSkipsConsent()
    {
        // Arrange — a logged-in user and a GET authorize request.
        $_GET['client_id']     = '123';
        $_GET['response_type'] = 'code';
        $_GET['redirect_uri']  = 'http://localhost/callback';
        $_GET['state']         = 'abc';
        $_GET['scope']         = 'profile';

        // Client is flagged trusted=1 → silent flow. The same mock row satisfies
        // both loadClient() and generateAuthCode()'s appid lookup (numRows=1, appid).
        $mockClient = new \stdClass();
        $mockClient->numRows = 1;
        $mockClient->fields  = [
            'appid'         => 123,
            'name'          => 'Trusted App',
            'callback' => 'http://localhost/callback',
            'scope'         => 'profile',
            'trusted'       => 1,
        ];
        $this->queryBuilderMock->method('first')->willReturn($mockClient);

        $user = $this->createMock(\Pramnos\User\User::class);
        $user->userid   = 10;
        $user->username = 'testuser';
        $this->controller->loggedInUser = $user;

        // Act
        $result = $this->runAuthorize();

        // Assert — silent flow: the consent view is NOT rendered, and the visitor is sent
        // back to the client with a code instead
        $this->assertStringNotContainsString('oauth-view', $result['echoed'],
            'Trusted client must skip the consent screen');
        $this->assertStringStartsWith('http://localhost/callback?code=', (string) $result['redirect'],
            'Trusted silent flow must redirect to the callback with a code');
    }

    /**
     * A consent POST that does not say `authorize=yes` is a refusal, and the client is told
     * so with `access_denied` and its own state — not handed a code.
     */
    public function testAuthorizePostConsent()
    {
        $_GET['client_id'] = '123';
        $_GET['response_type'] = 'code';
        $_GET['redirect_uri'] = 'http://localhost/callback';
        $_GET['state'] = 'abc';
        $_GET['scope'] = 'profile';

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['decision'] = 'approve';

        $mockClient = new \stdClass();
        $mockClient->numRows = 1;
        $mockClient->fields = ['appid' => 123, 'name' => 'App 1', 'callback' => 'http://localhost/callback', 'scope' => 'profile'];

        $this->queryBuilderMock->method('first')->willReturn($mockClient);

        $user = $this->createMock(\Pramnos\User\User::class);
        $user->userid = 10;
        $user->username = 'testuser';
        $this->controller->loggedInUser = $user;

        // Act
        $result = $this->runAuthorize();

        // Assert — `decision=approve` is not the form's field, so this is a denial
        $this->assertSame('http://localhost/callback?error=access_denied&state=abc', $result['redirect']);
    }

    public function testRevokeMethodNotAllowed()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $response = $this->controller->revoke();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('method_not_allowed', $response->getBody());
        $this->assertEquals(405, $response->getStatusCode());
    }

    /**
     * A revoke from a caller that names no client is refused before the token is read.
     *
     * RFC 7009 §2.1: the client authenticates, and revokes only what was issued to it.
     */
    public function testRevokeWithoutAClientIsRefused()
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [];

        // Act
        $response = $this->controller->revoke();

        // Assert
        $this->assertStringContainsString('invalid_client', $response->getBody());
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Holding a token is not enough to revoke it: without client authentication nothing is written.
     */
    public function testRevokeWithATokenButNoClientWritesNothing()
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['token'] = 'valid-token';
        $this->queryBuilderMock->expects($this->never())->method('update');

        // Act
        $response = $this->controller->revoke();

        // Assert
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testIntrospectMethodNotAllowed()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        
        $response = $this->controller->introspect();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('method_not_allowed', $response->getBody());
        $this->assertEquals(405, $response->getStatusCode());
    }

    public function testIntrospectMissingToken()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'client_id' => '123',
            'client_secret' => 'secret'
        ];

        // Client authentication reads the application row and compares the stored
        // secret, so the row is what has to be mocked. It used to be satisfied by
        // count() alone, back when a missing secret skipped the comparison.
        $this->queryBuilderMock->method('first')->willReturn($this->applicationRow('secret'));

        $response = $this->controller->introspect();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('Missing token parameter', $response->getBody());
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testIntrospectSuccess()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'client_id' => '123',
            'client_secret' => 'secret',
            'token' => 'valid-token'
        ];
        
        // Mock token fetch
        $mockResult = new \stdClass();
        $mockResult->numRows = 1;
        $mockResult->fields = ['token' => 'valid-token', 'client_id' => '123', 'status' => 1, 'expires' => time() + 3600, 'scope' => 'read', 'userid' => 1, 'username' => 'testuser'];

        // Two rows are read in order: the application, so the presented secret can
        // be compared against the stored one, and then the token being introspected.
        $this->queryBuilderMock->method('first')->willReturnOnConsecutiveCalls(
            $this->applicationRow('secret'),
            $mockResult
        );

        $response = $this->controller->introspect();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('active', $response->getBody());
    }

    public function testIntrospectInvalidClient()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['token'] = 'valid-token';
        // missing client_id and client_secret
        
        $response = $this->controller->introspect();
        $this->assertInstanceOf(\Pramnos\Http\Response::class, $response);
        $this->assertStringContainsString('invalid_client', $response->getBody());
        $this->assertEquals(401, $response->getStatusCode());
    }
}
