<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Auth\ApplicationService;
use Pramnos\Auth\ApplicationSettings;
use Pramnos\Auth\OAuth2\ClientPolicy;
use Pramnos\Auth\OAuth2\GrantPolicy;
use Pramnos\Database\Database;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Schema;
use Pramnos\Http\Middleware\ApplicationPolicyMiddleware;

/**
 * What an application may do, and how much — chosen on its page, and enforced.
 *
 * The tables existed and nothing edited them; almost nothing read them. An administrator now
 * picks an application's grant types, its client authentication methods and its limits, and
 * each one is checked: the grant and the method and the address at the token endpoint, the
 * rate, the address, the transport and the browser origin on the API, the page sizes in every
 * list. Each test sets one thing and watches it be applied.
 */
#[CoversClass(ApplicationSettings::class)]
#[CoversClass(ClientPolicy::class)]
#[CoversClass(GrantPolicy::class)]
#[CoversClass(ApplicationService::class)]
#[CoversClass(ApplicationPolicyMiddleware::class)]
class ApplicationAccessAndLimitsTest extends BaseTestCase
{
    private const APP = 990601;

    private const PUBLIC_APP = 990602;

    private Database $db;

    private ?Database $previous = null;

    /** @var array<string, mixed> */
    private array $server = [];

    /** The engine under test: the suite's own connection here, PostgreSQL in the subclass. */
    protected function connection(): Database
    {
        $db = Factory::getDatabase();
        if (!$db->connected) {
            $db->connect();
        }

        return $db;
    }

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');

        $this->previous = Factory::getDatabase();
        $this->db       = $this->connection();
        $singleton      = &Factory::getDatabase();
        $singleton      = $this->db;

        foreach (['applications', 'applications.application_settings', 'applications.oauth2_application_grants',
                  'applications.oauth2_client_auth_methods'] as $table) {
            Schema::table($table, $this->db);
        }
        $this->cleanUp();
        foreach ([[self::APP, 'limited-app', 'secret'], [self::PUBLIC_APP, 'public-app', '']] as [$appId, $clientId, $secret]) {
            $this->db->queryBuilder()->table('applications')->insert([
                'appid' => $appId, 'name' => $clientId, 'status' => 1, 'apikey' => $clientId, 'apisecret' => $secret,
            ]);
        }
        ApplicationSettings::reset();
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        ApplicationSettings::reset();
        $_SERVER   = $this->server;
        $singleton = &Factory::getDatabase();
        $singleton = $this->previous;
    }

    /** Remove this class's rows; the policy rows go with the applications. */
    private function cleanUp(): void
    {
        foreach (['applications.application_settings', 'applications.oauth2_application_grants',
                  'applications.oauth2_client_auth_methods', 'applications'] as $table) {
            $this->db->queryBuilder()->table($table)->whereIn('appid', [self::APP, self::PUBLIC_APP])->delete();
        }
    }

    /**
     * A complete limits form, with some fields replaced.
     *
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function form(array $override = []): array
    {
        return array_merge([
            'rate_limit_requests' => '1000', 'rate_limit_window_seconds' => '3600', 'rate_limit_burst' => '100',
            'enforce_pagination' => '1', 'default_page_size' => '20', 'max_page_size' => '100',
            'require_https' => '1', 'ip_lock_enabled' => '', 'allowed_ips' => '', 'blocked_ips' => '',
            'cors_enabled' => '', 'cors_origins' => '',
        ], $override);
    }

    /** The middleware's answer for a request from this application. */
    private function apiRequest(int $appId, ?\Pramnos\Http\TokenBucket $bucket = null): array
    {
        $middleware = new ApplicationPolicyMiddleware(static fn () => (object) ['appid' => $appId], $bucket);
        $passed     = false;
        $answer     = $middleware->handle(new \Pramnos\Http\Request(), function () use (&$passed) {
            $passed = true;

            return 'ok';
        });

        return ['passed' => $passed, 'body' => $passed ? null : json_decode((string) $answer, true), 'headers' => $middleware->headers];
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    /**
     * An application never configured has the defaults, without enforced pagination.
     */
    public function testAnUnconfiguredApplicationHasTheDefaults(): void
    {
        // Act
        $settings = ApplicationSettings::for(self::APP);

        // Assert
        $this->assertSame(1000, $settings['rate_limit_requests']);
        $this->assertTrue($settings['require_https']);
        $this->assertFalse($settings['enforce_pagination'], 'a list that returned every row keeps doing so');
    }

    /**
     * Saved settings come back as they were given, lists included, on either driver.
     */
    public function testSettingsRoundTrip(): void
    {
        // Act
        $errors = ApplicationSettings::save(self::APP, $this->form([
            'rate_limit_requests' => '50', 'enforce_pagination' => '', 'ip_lock_enabled' => '1',
            'allowed_ips' => "10.0.0.0/8\n203.0.113.7", 'blocked_ips' => '2001:db8::/32',
            'cors_enabled' => 'on', 'cors_origins' => 'https://app.example, https://admin.app.example',
        ]));
        ApplicationSettings::reset();
        $settings = ApplicationSettings::for(self::APP);

        // Assert
        $this->assertSame([], $errors);
        $this->assertSame(50, $settings['rate_limit_requests']);
        $this->assertFalse($settings['enforce_pagination']);
        $this->assertTrue($settings['ip_lock_enabled']);
        $this->assertSame(['10.0.0.0/8', '203.0.113.7'], $settings['allowed_ips']);
        $this->assertSame(['2001:db8::/32'], $settings['blocked_ips']);
        $this->assertSame(['https://app.example', 'https://admin.app.example'], $settings['cors_origins']);

        // And saving again updates the one row.
        ApplicationSettings::save(self::APP, $this->form(['rate_limit_requests' => '60']));
        ApplicationSettings::reset();
        $this->assertSame(60, ApplicationSettings::for(self::APP)['rate_limit_requests']);
        $this->assertSame([], ApplicationSettings::for(self::APP)['allowed_ips']);
    }

    /**
     * A row written elsewhere — by an older administration screen, with NULL lists — reads as empty lists.
     */
    public function testARowWithNullListsReadsAsEmptyLists(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('applications.application_settings')->insert(['appid' => self::APP, 'rate_limit_requests' => 5]);

        // Act
        $settings = ApplicationSettings::for(self::APP);

        // Assert
        $this->assertSame(5, $settings['rate_limit_requests']);
        $this->assertSame([], $settings['allowed_ips']);
        $this->assertSame([], $settings['cors_origins']);
        $this->assertTrue($settings['enforce_pagination'], 'a saved row has the column\'s own default');
    }

    /**
     * Anything that would quietly lock an application out — or let one in — is refused, by name.
     */
    public function testInvalidSettingsAreRefusedAndNothingIsSaved(): void
    {
        // Act
        $errors = ApplicationSettings::save(self::APP, $this->form([
            'rate_limit_requests' => '0', 'default_page_size' => '200', 'allowed_ips' => '10.0.0.300',
            'cors_origins' => 'app.example/path', 'rate_limit_burst' => 'many',
        ]));

        // Assert
        $this->assertCount(5, $errors, implode(' | ', $errors));
        $this->assertStringContainsString('"10.0.0.300"', implode(' ', $errors));
        $this->assertStringContainsString('"app.example/path"', implode(' ', $errors));
        $this->assertSame(0, $this->db->queryBuilder()->table('applications.application_settings')->where('appid', self::APP)->count());
    }

    /**
     * With pagination enforced, everything becomes the first page; no page exceeds the maximum.
     */
    public function testListsArePaginatedForTheCallingApplication(): void
    {
        // Arrange — the calling application, as the API would have it after authentication
        ApplicationSettings::save(self::APP, $this->form(['default_page_size' => '25', 'max_page_size' => '50']));
        $api = (new \ReflectionClass(\Pramnos\Application\Api::class))->newInstanceWithoutConstructor();
        $api->apiKey = (object) ['appid' => self::APP];
        $instances = new \ReflectionProperty(Application::class, 'appInstances');
        $last      = new \ReflectionProperty(Application::class, 'lastUsedApplication');
        $before    = [$instances->getValue(), $last->getValue()];
        $instances->setValue(null, ['api' => $api]);
        $last->setValue(null, 'api');

        try {
            // Act
            $everything = ApplicationSettings::paginate(0, 10);
            $tooLarge   = ApplicationSettings::paginate(3, 500);
            $fine       = ApplicationSettings::paginate(2, 40);
        } finally {
            $instances->setValue(null, $before[0]);
            $last->setValue(null, $before[1]);
        }
        $outsideTheApi = ApplicationSettings::paginate(0, 10);

        // Assert
        $this->assertSame([1, 25], $everything);
        $this->assertSame([3, 50], $tooLarge);
        $this->assertSame([2, 40], $fine);
        $this->assertSame([0, 10], $outsideTheApi, 'a list outside an API request is answered as asked');
    }

    // ── The API ──────────────────────────────────────────────────────────────

    /**
     * Over the rate, the API answers 429 with Retry-After, and tells every caller where it stands.
     */
    public function testTheRateLimitRefusesWithRetryAfter(): void
    {
        // Arrange — two at once, then one an hour; a bucket of its own
        ApplicationSettings::save(self::APP, $this->form(['rate_limit_requests' => '1', 'rate_limit_burst' => '2', 'require_https' => '']));
        $bucket = new \Pramnos\Http\TokenBucket(new \Pramnos\Tests\Support\InMemoryBucketCache());

        // Act
        $answers = [$this->apiRequest(self::APP, $bucket), $this->apiRequest(self::APP, $bucket), $this->apiRequest(self::APP, $bucket)];

        // Assert
        $this->assertSame([true, true, false], array_column($answers, 'passed'));
        $this->assertSame('1', $answers[0]['headers']['X-RateLimit-Limit']);
        $this->assertSame('1', $answers[0]['headers']['X-RateLimit-Remaining']);
        $this->assertSame(['status' => 429, 'error' => 'TooManyRequests'], array_intersect_key($answers[2]['body'], ['status' => 1, 'error' => 1]));
        $this->assertGreaterThan(3000, (int) $answers[2]['headers']['Retry-After']);
    }

    /**
     * Plain HTTP from the network is refused where HTTPS is required; HTTPS, a proxy's word for it,
     * and this machine are not.
     */
    public function testHttpsIsRequired(): void
    {
        // Arrange
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);

        // Act + Assert
        $this->assertSame('HTTPSRequired', $this->apiRequest(self::APP)['body']['error'] ?? null);
        $_SERVER['HTTPS'] = 'on';
        $this->assertTrue($this->apiRequest(self::APP)['passed']);
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue($this->apiRequest(self::APP)['passed']);
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $this->assertTrue($this->apiRequest(self::APP)['passed'], 'a developer\'s own server');
    }

    /**
     * A blocked address is refused always; with the lock on, only the allowed ones pass.
     */
    public function testTheIpLock(): void
    {
        // Arrange
        ApplicationSettings::save(self::APP, $this->form([
            'require_https' => '', 'ip_lock_enabled' => '1', 'allowed_ips' => '10.0.0.0/8', 'blocked_ips' => '10.6.6.6',
        ]));

        // Act + Assert
        $_SERVER['REMOTE_ADDR'] = '10.1.2.3';
        $this->assertTrue($this->apiRequest(self::APP)['passed']);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $this->assertSame('AddressNotAllowed', $this->apiRequest(self::APP)['body']['error'] ?? null);
        $_SERVER['REMOTE_ADDR'] = '10.6.6.6';
        $this->assertSame('AddressNotAllowed', $this->apiRequest(self::APP)['body']['error'] ?? null, 'blocked inside the allowed range');
    }

    /**
     * With CORS restricted, a browser from another origin is refused; a server, sending none, is not.
     */
    public function testBrowserOriginsAreRestricted(): void
    {
        // Arrange
        ApplicationSettings::save(self::APP, $this->form(['require_https' => '', 'cors_enabled' => '1', 'cors_origins' => 'https://app.example']));

        // Act + Assert
        $_SERVER['HTTP_ORIGIN'] = 'https://app.example/';
        $this->assertTrue($this->apiRequest(self::APP)['passed']);
        $_SERVER['HTTP_ORIGIN'] = 'https://elsewhere.example';
        $this->assertSame('OriginNotAllowed', $this->apiRequest(self::APP)['body']['error'] ?? null);
        unset($_SERVER['HTTP_ORIGIN']);
        $this->assertTrue($this->apiRequest(self::APP)['passed']);
    }

    /**
     * A request with no application — the site's own key, a signed-in page — is not limited here.
     */
    public function testARequestWithoutAnApplicationPasses(): void
    {
        // Arrange
        $middleware = new ApplicationPolicyMiddleware(static fn () => null);

        // Act
        $answer = $middleware->handle(new \Pramnos\Http\Request(), static fn () => 'ok');

        // Assert
        $this->assertSame('ok', $answer);
        $this->assertSame([], $middleware->headers);
    }

    // ── The token endpoint ───────────────────────────────────────────────────

    /**
     * Each check at the token endpoint refuses on its own: the grant, the method, the address.
     */
    public function testTheTokenEndpointChecksGrantMethodAndAddress(): void
    {
        // Arrange
        $refusal = static fn (string $client, ?string $grant, string $method, string $address = '') =>
            ClientPolicy::refusal($client, $grant, $method, $address, [])['error_description'] ?? null;

        // Assert — the defaults: password is one, jwt-bearer is not
        $this->assertNull($refusal('limited-app', 'password', 'client_secret_post'));
        $this->assertStringContainsString('jwt_bearer', (string) $refusal('limited-app', 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'client_secret_post'));
        // A confidential client may not skip its secret; a public one may
        $this->assertStringContainsString('none', (string) $refusal('limited-app', 'authorization_code', 'none'));
        $this->assertNull($refusal('public-app', 'authorization_code', 'none'));

        // Its own policy
        GrantPolicy::setGrants(self::APP, ['client_credentials']);
        ClientPolicy::setMethods(self::APP, ['private_key_jwt']);
        ApplicationSettings::save(self::APP, $this->form(['require_https' => '', 'blocked_ips' => '192.0.2.0/24']));
        $this->assertStringContainsString('password', (string) $refusal('limited-app', 'password', 'private_key_jwt'));
        $this->assertStringContainsString('client_secret_basic', (string) $refusal('limited-app', 'client_credentials', 'client_secret_basic'));
        $this->assertStringContainsString('address', (string) $refusal('limited-app', 'client_credentials', 'private_key_jwt', '192.0.2.4'));
        $this->assertNull($refusal('limited-app', 'client_credentials', 'private_key_jwt', '198.51.100.1'));
        $this->assertNull($refusal('nobody', 'password', 'none'), 'an unknown client is the authentication\'s to refuse');
    }

    /**
     * Plain HTTP from the network is refused at the token endpoint too, where HTTPS is required.
     */
    public function testTheTokenEndpointRequiresHttps(): void
    {
        // Act
        $plain  = ClientPolicy::refusal('limited-app', 'password', 'client_secret_post', '203.0.113.9', []);
        $secure = ClientPolicy::refusal('limited-app', 'password', 'client_secret_post', '203.0.113.9', ['HTTPS' => 'on']);

        // Assert
        $this->assertSame('invalid_request', $plain['error'] ?? null);
        $this->assertNull($secure);
    }

    /**
     * A grant or method the server does not know cannot be stored.
     */
    public function testUnknownGrantsAndMethodsAreRefused(): void
    {
        // Act + Assert
        foreach ([fn () => GrantPolicy::setGrants(self::APP, ['magic']), fn () => ClientPolicy::setMethods(self::APP, ['carrier_pigeon'])] as $store) {
            try {
                $store();
                $this->fail('stored');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Unknown', $e->getMessage());
            }
        }
    }

    /**
     * An installation without the policy tables has the defaults, and nothing fails.
     */
    public function testWithoutThePolicyTablesTheDefaultsApply(): void
    {
        // Arrange
        // CASCADE on PostgreSQL: the grants table has views over it, rebuilt with it below.
        $cascade = $this->db->schema()->getCapabilities()->isPostgreSQL() ? ' CASCADE' : '';
        foreach (['applications.oauth2_client_auth_methods', 'applications.oauth2_application_grants', 'applications.application_settings'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->schema()->quoteTable($table) . $cascade);
        }

        try {
            // Act
            $methods  = ClientPolicy::effectiveMethods(self::APP, false);
            $grants   = GrantPolicy::effective(self::APP);
            $settings = ApplicationSettings::for(self::APP);
        } finally {
            foreach (['applications.application_settings', 'applications.oauth2_application_grants', 'applications.oauth2_client_auth_methods'] as $table) {
                Schema::table($table, $this->db);
            }
        }

        // Assert
        $this->assertSame(\Pramnos\Auth\OAuthPolicyHelper::getDefaultAllowedAuthMethods(), $methods);
        $this->assertSame(\Pramnos\Auth\OAuthPolicyHelper::getDefaultAllowedGrantTypes(), $grants);
        $this->assertSame(1000, $settings['rate_limit_requests']);
    }

    /**
     * How a request authenticates is read from what it sent.
     */
    public function testTheMethodIsReadFromTheRequest(): void
    {
        // Assert
        $this->assertSame('private_key_jwt', ClientPolicy::methodOf(['client_assertion' => 'x.y.z', 'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'], []));
        $this->assertSame('client_secret_basic', ClientPolicy::methodOf([], ['HTTP_AUTHORIZATION' => 'Basic YTpi']));
        $this->assertSame('client_secret_post', ClientPolicy::methodOf(['client_secret' => 's'], []));
        $this->assertSame('none', ClientPolicy::methodOf(['client_id' => 'a'], []));
    }

    /**
     * The token endpoint itself answers a grant the application is not allowed, before any grant runs.
     */
    public function testTheTokenEndpointRefusesAGrantNotAllowed(): void
    {
        // Arrange
        GrantPolicy::setGrants(self::APP, ['authorization_code']);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['grant_type' => 'client_credentials', 'client_id' => 'limited-app', 'client_secret' => 'secret'];

        try {
            // Act
            $response = (new \Pramnos\Auth\Controllers\Oauth(new Application()))->token();
        } finally {
            $_POST = [];
        }

        // Assert
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('unauthorized_client', json_decode((string) $response->getBody(), true)['error']);
    }

    // ── The administration screen's service ──────────────────────────────────

    /**
     * Saving the defaults keeps no rows, so the application follows the defaults when they change;
     * an empty selection is stored as none — never mistaken for the defaults.
     */
    public function testTheScreenStoresOnlyWhatDiffersFromTheDefaults(): void
    {
        // Arrange
        $service = new ApplicationService($this->db);
        $form    = $this->form([
            'grants'       => \Pramnos\Auth\OAuthPolicyHelper::getDefaultAllowedGrantTypes(),
            'auth_methods' => \Pramnos\Auth\OAuthPolicyHelper::getDefaultAllowedAuthMethods(),
        ]);

        // Act + Assert — the defaults
        $this->assertSame([], $service->updatePolicy(self::APP, $form));
        $policy = $service->policy(self::APP);
        $this->assertTrue($policy['grants_default']);
        $this->assertTrue($policy['methods_default']);

        // Nothing at all
        $this->assertSame([], $service->updatePolicy(self::APP, ['grants' => [], 'auth_methods' => []] + $form));
        $this->assertSame([], $service->policy(self::APP)['grants']);
        $this->assertFalse(GrantPolicy::allows(self::APP, 'authorization_code'));
        $this->assertFalse(ClientPolicy::allowsMethod(self::APP, 'client_secret_basic', false));

        // A selection of its own
        $service->updatePolicy(self::APP, ['grants' => ['jwt_bearer', 'client_credentials'], 'auth_methods' => ['private_key_jwt']] + $form);
        $this->assertEqualsCanonicalizing(['jwt_bearer', 'client_credentials'], $service->policy(self::APP)['grants']);
        $this->assertSame(['private_key_jwt'], $service->policy(self::APP)['methods']);
    }

    /**
     * The screen saves the tab with the application, refuses it with a reason, and leaves it
     * alone when an older copy of the form does not carry it.
     */
    public function testTheScreenSavesTheTabOnlyWhenItIsSent(): void
    {
        // Arrange
        $controller = new class extends \Pramnos\Auth\Controllers\ApplicationsController {
            public array $errors = [];

            public function __construct()
            {
                $this->application = Application::getInstance();
            }

            protected function requireMinUserType(int $minType): bool
            {
                return false;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }

            protected function addMessage($message)
            {
                return $this;
            }
        };
        $save = function (array $post) use ($controller): void {
            $_POST = $post;
            try {
                $controller->save();
            } finally {
                $_POST = [];
            }
        };

        // Act + Assert — with the tab
        $save(['appid' => (string) self::APP, 'name' => 'limited-app', 'policy_submitted' => '1', 'grants' => ['client_credentials']] + $this->form(['rate_limit_requests' => '7']));
        $this->assertSame(['client_credentials'], GrantPolicy::grants(self::APP));
        ApplicationSettings::reset();
        $this->assertSame(7, ApplicationSettings::for(self::APP)['rate_limit_requests']);

        // A refusal names what is wrong, and the policy stays
        $save(['appid' => (string) self::APP, 'name' => 'limited-app', 'policy_submitted' => '1', 'grants' => []] + $this->form(['blocked_ips' => 'nope']));
        $this->assertStringContainsString('"nope" is not an IP address or range.', $controller->errors[0] ?? '');
        $this->assertSame(['client_credentials'], GrantPolicy::grants(self::APP));

        // Without the tab, nothing about it changes
        $save(['appid' => (string) self::APP, 'name' => 'renamed']);
        $this->assertSame(['client_credentials'], GrantPolicy::grants(self::APP));
    }

    /**
     * Anything refused leaves the policy as it was.
     */
    public function testARefusedFormSavesNothing(): void
    {
        // Arrange
        $service = new ApplicationService($this->db);

        // Act
        $unknown = $service->updatePolicy(self::APP, $this->form(['grants' => ['magic']]));
        $badIp   = $service->updatePolicy(self::APP, $this->form(['grants' => ['password'], 'blocked_ips' => 'nope']));

        // Assert
        $this->assertSame(['"magic" is not a grant type.'], $unknown);
        $this->assertSame(['"nope" is not an IP address or range.'], $badIp);
        $this->assertTrue($service->policy(self::APP)['grants_default']);
        $this->assertSame(['That record no longer exists.'], $service->updatePolicy(990699, $this->form()));
    }
}
