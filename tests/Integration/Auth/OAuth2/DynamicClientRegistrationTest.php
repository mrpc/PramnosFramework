<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Auth\OAuth2\Repositories\ClientRepository;
use Pramnos\Cache\Cache;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Http\Middleware\RateLimitMiddleware;
use Pramnos\Http\Request;

/**
 * `POST /oauth/register` writes a client the rest of the server can use.
 *
 * The unit tests cover every refusal, all of which return before the database. This covers the
 * one path that writes: the row lands, it is a **public** client with no secret, and the two
 * pieces of the server that decide whether a client may get a token — `validateCredentials()`
 * and the League client repository — agree that it is exactly that. Were either to read the row
 * as confidential, the client would register successfully and then fail at the token endpoint,
 * after the person had already approved the consent screen.
 *
 * {@see FullAuthorizationCodeFlowTest::testAPublicClientExchangesItsCodeWithPkceAndNoSecret()}
 * takes a client of this shape through the whole code-with-PKCE exchange.
 *
 * Both backends: {@see DynamicClientRegistrationPostgreSQLTest} re-runs it.
 */
#[CoversClass(Oauth::class)]
class DynamicClientRegistrationTest extends BaseTestCase
{
    private $db;

    /** @var list<string> */
    private array $registered = [];

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        // Other tests leave a hand-rolled `applications` behind with no `callback`, and a
        // migration is a no-op on a table that exists — so that shape is rebuilt, the way
        // FullAuthorizationCodeFlowTest does. Only that shape: dropping a sound table would take
        // the foreign key `usertokens` holds on it for no reason. The second migration adds the
        // column a public client is marked by.
        if ($this->db->schema()->hasTable('applications') && !$this->db->schema()->hasColumn('applications', 'callback')) {
            $this->db->schema()->dropTableIfExists('applications');
        }
        $this->runMigrations([
            \Pramnos\Framework\Migrations\AuthServer\CreateApplicationsTable::class,
            \Pramnos\Framework\Migrations\AuthServer\AddIsConfidentialToApplications::class,
        ], $this->db);

        // On PostgreSQL, other suites insert applications with explicit ids, which leaves the
        // serial behind the rows; the endpoint inserts without one and collided with appid 1.
        // Brought level with the table, as a real installation's sequence always is.
        if ($this->db->type === 'postgresql') {
            $this->db->query(
                "SELECT setval(pg_get_serial_sequence('applications', 'appid'), "
                . "GREATEST(COALESCE((SELECT MAX(appid) FROM applications), 0), 1))"
            );
        }

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR']    = '203.0.113.7';
    }

    protected function tearDown(): void
    {
        foreach ($this->registered as $clientId) {
            $this->db->queryBuilder()->table('#PREFIX#applications')->where('apikey', $clientId)->delete();
        }
        Request::setRawInput(null);
        $_SERVER['REQUEST_METHOD'] = 'GET';

        parent::tearDown();
    }

    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /**
     * What Claude.ai sends, and what the server does with it.
     *
     * The answer is RFC 7591 §3.2.1: `201`, a fresh `client_id`, the metadata as registered,
     * and no `client_secret` — a public client has none, and handing it one would suggest it
     * should keep it.
     */
    public function testAPublicClientIsRegisteredAndUsableByTheTokenEndpoint(): void
    {
        // Arrange
        Request::setRawInput((string) json_encode([
            'client_name'                => 'Claude',
            'redirect_uris'              => ['https://claude.ai/api/mcp/auth_callback', 'http://localhost:6274/cb'],
            'grant_types'                => ['authorization_code', 'refresh_token'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => 'none',
        ]));

        // Act
        $response = $this->controller()->register();
        $answer   = (array) json_decode($response->getBody(), true);
        $this->registered[] = (string) ($answer['client_id'] ?? '');

        // Assert — the response
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $answer['client_id']);
        $this->assertArrayNotHasKey('client_secret', $answer);
        $this->assertSame('none', $answer['token_endpoint_auth_method']);
        $this->assertSame(['authorization_code', 'refresh_token'], $answer['grant_types']);

        // Assert — the row: public, no secret, both callbacks, active
        $row = $this->db->queryBuilder()->table('#PREFIX#applications')
            ->where('apikey', $answer['client_id'])->first();
        $this->assertSame('Claude', $row->fields['name']);
        $this->assertSame(0, (int) $row->fields['is_confidential']);
        $this->assertSame('', (string) $row->fields['apisecret']);
        $this->assertSame(1, (int) $row->fields['status']);
        $this->assertSame(
            ['https://claude.ai/api/mcp/auth_callback', 'http://localhost:6274/cb'],
            \Pramnos\Auth\Application::parseRedirectUris((string) $row->fields['callback'])
        );

        // Assert — the server reads it as a public client that authenticates with nothing
        $model = (new \ReflectionClass(\Pramnos\Auth\Application::class))->newInstanceWithoutConstructor();
        $this->assertTrue($model->validateCredentials($answer['client_id'], null));
        $this->assertFalse($model->validateCredentials($answer['client_id'], 'a-guessed-secret'));

        $entity = (new ClientRepository($this->controller()))->getClientEntity($answer['client_id']);
        $this->assertNotNull($entity);
        $this->assertFalse($entity->isConfidential(), 'League must see it as public, so PKCE replaces the secret');
    }

    /**
     * A body that names nothing still gets a usable client, with a placeholder name.
     *
     * `client_name` is optional in RFC 7591, and `grant_types` defaults to the code grant. The
     * consent screen needs some name to show; an empty one would read as a bug.
     */
    public function testTheOptionalFieldsFallBackToTheirDefaults(): void
    {
        // Arrange
        Request::setRawInput((string) json_encode([
            'redirect_uris'              => ['https://chatgpt.com/connector/oauth/cb'],
            'token_endpoint_auth_method' => 'none',
        ]));

        // Act
        $response = $this->controller()->register();
        $answer   = (array) json_decode($response->getBody(), true);
        $this->registered[] = (string) ($answer['client_id'] ?? '');

        // Assert
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Unnamed client', $answer['client_name']);
        $this->assertSame(['authorization_code'], $answer['grant_types']);
    }

    /** The controller with registration open and a limiter over an in-memory cache. */
    private function controller(): Oauth
    {
        $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()->getMock();
        $app->applicationInfo = ['oauth_dynamic_registration' => true];
        $app->database        = $this->db;

        $controller = new class () extends Oauth {
            public function __construct()
            {
            }

            protected function registrationLimiter(): RateLimitMiddleware
            {
                return new RateLimitMiddleware(100, 3600, 'test-register:', new Cache(null, null, 'array'));
            }
        };
        $controller->application = $app;

        return $controller;
    }
}
