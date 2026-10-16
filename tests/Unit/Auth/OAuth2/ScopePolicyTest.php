<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\OAuth2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Auth\OAuth2\ScopePolicy;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/** The policy with the accounts it judges in memory, by userid. */
class InMemoryScopePolicy extends ScopePolicy
{
    /** @var array<int, object> */
    public static array $users = [];

    /** The account from memory, not the database. */
    protected function user(int $userId): ?object
    {
        return self::$users[$userId] ?? null;
    }
}

/** A rule a role cannot say: `mcp:*` only for client `trusted`, whoever the person. */
final class ClientBoundScopePolicy extends InMemoryScopePolicy
{
    /** Refuses `mcp:*` to every client but `trusted`; the configured rules otherwise. */
    protected function allows(object $user, string $scope, ?string $clientId, ?array $rule): bool
    {
        if (str_starts_with($scope, 'mcp:') && $clientId !== 'trusted') {
            return false;
        }

        return parent::allows($user, $scope, $clientId, $rule);
    }
}

/** The authorize endpoint with a signed-in person, and nothing sent anywhere. */
final class SignedInOauth extends Oauth
{
    public static ?\Pramnos\User\User $user = null;

    /** Ends nothing. */
    protected function terminate(): void
    {
    }

    /** The person of the test. */
    protected function getLoggedInUser(): ?\Pramnos\User\User
    {
        return self::$user;
    }

    /** Recorded, not sent. */
    public function redirect($url = null, $quit = true, $code = '302')
    {
        echo 'REDIRECTED_TO:' . $url;
    }

    /** A consent form that only says it was shown. */
    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new #[\AllowDynamicProperties] class {
            /** Says it was shown. */
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                return 'CONSENT_FORM';
            }

            /** Keeps what it is given. */
            public function assign(string $key, mixed $val): void
            {
                $this->$key = $val;
            }
        };

        return $view;
    }
}

/**
 * Which scopes a person may grant, by role.
 *
 * The rule app.php gives a scope — exact, or by glob, the exact one winning — is a floor of role
 * or usertype; a scope with no rule is open as before; a rule that cannot be read refuses, so a
 * typo never opens a scope; and an application's own policy class decides what a role cannot.
 * The consent screen is never shown for a scope the person may not grant.
 */
#[CoversClass(ScopePolicy::class)]
#[CoversClass(Oauth::class)]
final class ScopePolicyTest extends TestCase
{
    private Application $app;

    private mixed $savedOauth = null;

    private mixed $savedRoles = null;

    protected function setUp(): void
    {
        $this->app        = Application::currentInstance() ?? Application::getInstance();
        $this->savedOauth = $this->app->applicationInfo['oauth'] ?? null;
        $this->savedRoles = $this->app->applicationInfo['roles'] ?? null;
        InMemoryScopePolicy::$users = [
            5  => (object) ['userid' => 5, 'usertype' => 10],
            50 => (object) ['userid' => 50, 'usertype' => 50],
            90 => (object) ['userid' => 90, 'usertype' => 90],
        ];
    }

    protected function tearDown(): void
    {
        foreach (['oauth' => $this->savedOauth, 'roles' => $this->savedRoles] as $key => $value) {
            if ($value === null) {
                unset($this->app->applicationInfo[$key]);
            } else {
                $this->app->applicationInfo[$key] = $value;
            }
        }
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        SignedInOauth::$user = null;
    }

    /** app.php's `oauth` block for this test, with the in-memory policy unless one is named. */
    private function configure(array $scopes, string $policy = InMemoryScopePolicy::class): void
    {
        $this->app->applicationInfo['oauth'] = ['scopes' => $scopes, 'scope_policy' => $policy];
        $this->app->applicationInfo['roles'] = ['staff' => 50];
    }

    /**
     * A role floor by glob, an exact rule over the glob, a usertype floor, and a scope with no
     * rule open to anybody — each judged per person, in the order asked.
     */
    public function testFloorsByRoleAndUsertypeExactOverGlob(): void
    {
        // Arrange
        $this->configure([
            'mcp:*'        => ['min_role' => 'staff'],
            'mcp:db_write' => ['min_role' => 'admin'],
            'reports:read' => ['min_usertype' => 20],
        ]);
        $scopes = ['openid', 'mcp:db_read', 'mcp:db_write', 'reports:read', ''];

        // Act
        $policy   = ScopePolicy::current();
        $customer = $policy->refused(5, $scopes);
        $staff    = $policy->refused(50, $scopes);
        $admin    = $policy->refused(90, $scopes);

        // Assert
        $this->assertInstanceOf(InMemoryScopePolicy::class, $policy, 'the class app.php names');
        $this->assertSame(['mcp:db_read', 'mcp:db_write', 'reports:read'], $customer);
        $this->assertSame(['mcp:db_write'], $staff, 'the exact rule wins over the glob');
        $this->assertSame([], $admin);
    }

    /**
     * A rule naming a role nobody defined refuses, and says so in the log; an account that
     * cannot be found is refused every scope with a rule and none without.
     */
    public function testWhatCannotBeReadRefuses(): void
    {
        // Arrange
        $this->configure(['mcp:*' => ['min_role' => 'stafff']]);

        // Act
        $typo    = ScopePolicy::current()->refused(90, ['mcp:db_read', 'openid']);
        $nobody  = ScopePolicy::current()->refused(404, ['mcp:db_read', 'openid']);

        // Assert
        $this->assertSame(['mcp:db_read'], $typo, 'a typo must not open a scope');
        $this->assertSame(['mcp:db_read'], $nobody);
    }

    /**
     * With no rules and no policy of the application's own, nothing is refused and no account
     * is loaded; an unknown class name falls back to the framework's policy.
     */
    public function testNoRulesNoCost(): void
    {
        // Arrange
        $this->app->applicationInfo['oauth'] = ['scope_policy' => 'Not\\A\\Class'];

        // Act
        $policy  = ScopePolicy::current();
        $refused = $policy->refused(999999, ['mcp:db_read']);

        // Assert
        $this->assertSame(ScopePolicy::class, $policy::class);
        $this->assertSame([], $refused, 'no rule, nothing loaded, nothing refused');
    }

    /**
     * The framework's own account loading: the system account and an id with no account are
     * nobody, so a scope with a rule is refused to them.
     */
    public function testTheFrameworksPolicyLoadsTheAccount(): void
    {
        // Arrange
        $this->app->applicationInfo['oauth'] = ['scopes' => ['mcp:*' => ['min_usertype' => 1]]];
        $user = (new \ReflectionMethod(ScopePolicy::class, 'user'));

        // Act
        $system = $user->invoke(new ScopePolicy(), 1);

        // Assert
        $this->assertNull($system, 'the system account grants nothing on a person\'s behalf');
        $this->assertSame(['mcp:x'], (new ScopePolicy())->refused(0, ['mcp:x']));
    }

    /** An application's own policy decides what a role cannot: here, which client. */
    public function testAnApplicationsPolicyDecidesWhatARoleCannot(): void
    {
        // Arrange
        $this->configure(['reports:read' => ['min_usertype' => 20]], ClientBoundScopePolicy::class);

        // Act
        $other   = ScopePolicy::current()->refused(90, ['mcp:db_read', 'reports:read'], 'claude');
        $trusted = ScopePolicy::current()->refused(5, ['mcp:db_read', 'reports:read'], 'trusted');

        // Assert
        $this->assertSame(['mcp:db_read'], $other);
        $this->assertSame(['reports:read'], $trusted, 'and the configured rules still apply');
    }

    /**
     * The consent screen is never shown for a scope the person may not grant: the authorize
     * endpoint answers with the error page naming it.
     */
    public function testTheAuthorizeEndpointRefusesBeforeConsent(): void
    {
        // Arrange — a scope every installation's catalogue has, given a floor this person is below
        $this->configure(['prof*' => ['min_role' => 'staff']]);
        $row          = new \stdClass();
        $row->numRows = 1;
        $row->fields  = ['appid' => 42, 'name' => 'Claude', 'apikey' => 'client-42', 'apisecret' => '', 'is_confidential' => 0,
            'status' => 1, 'scope' => '', 'callback' => 'https://client.example/cb'];
        $builder = $this->createMock(QueryBuilder::class);
        foreach (['table', 'select', 'where', 'join', 'leftJoin', 'whereIn', 'orderBy', 'limit'] as $chain) {
            $builder->method($chain)->willReturnSelf();
        }
        $builder->method('first')->willReturn($row);
        $database = $this->createMock(Database::class);
        $database->method('queryBuilder')->willReturn($builder);
        $parked = &Database::getInstance();
        $saved  = $parked;
        $parked = $database;
        $customer = new \Pramnos\User\User();
        $customer->userid = 5;
        SignedInOauth::$user = $customer;
        $ask = function (string $scope): string {
            $_GET = ['client_id' => 'client-42', 'response_type' => 'code', 'redirect_uri' => 'https://client.example/cb',
                'scope' => $scope, 'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256'];
            \Pramnos\Document\Document::reset();
            ob_start();
            (new SignedInOauth(null))->authorize();

            return (string) ob_get_clean() . \Pramnos\Framework\Factory::getDocument('html')->getContent();
        };

        try {
            // Act
            $refused = $ask('openid profile');
        } finally {
            $parked = $saved;
        }

        // Assert
        $this->assertStringContainsString('Authorization Error', $refused);
        $this->assertStringContainsString('cannot grant profile', $refused, 'and names it');
        $this->assertStringNotContainsString('CONSENT_FORM', $refused);
    }
}
