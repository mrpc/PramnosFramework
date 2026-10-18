<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\Oauth;
use Pramnos\Auth\OAuth2\ScopePolicy;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;

/** A policy under which nobody may grant `email`. */
final class NobodyGrantsEmail extends ScopePolicy
{
    /** @param list<string> $scopes */
    public function refused(int $userId, array $scopes, ?string $clientId = null): array
    {
        return array_values(array_intersect($scopes, ['email']));
    }
}

/** The authorization endpoint with a signed-in user, the consent view kept to be read. */
final class ScopeDropTestableOauth extends Oauth
{
    /** The consent view the endpoint filled, when it got that far. */
    public ?object $consent = null;

    protected function terminate(): void
    {
    }

    /** A signed-in administrator. */
    protected function getLoggedInUser(): ?object
    {
        $user = new \Pramnos\User\User(0);
        $user->userid   = 9;
        $user->usertype = 90;

        return $user;
    }

    /** Where it was sent. */
    public function redirect($url = null, $quit = true, $code = '302')
    {
        echo 'REDIRECTED_TO:' . $url;
    }

    /** The consent view, kept. */
    public function &getView($name = '', $type = '', $args = [])
    {
        $this->consent = new #[\AllowDynamicProperties] class {
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                return 'CONSENT_FORM';
            }
        };

        return $this->consent;
    }
}

/**
 * `'oauth' => ['scope_refusal' => 'drop']` at the authorization endpoint (FW-158 §4).
 *
 * An assistant asks for every scope a resource lists. One the account may not grant refused the
 * whole request, so an administrator below the role of one scope could not connect at all. Set
 * to drop, the endpoint leaves that scope out and goes on: the consent page asks for the rest.
 * Left at its default, it refuses as before; with nothing left after dropping, it refuses too.
 */
#[CoversClass(Oauth::class)]
#[CoversClass(ScopePolicy::class)]
class OauthScopeDropTest extends TestCase
{
    use \Pramnos\Tests\Support\PreservesAppKeys;

    private ?Database $originalDb = null;

    private ?Database $originalFactoryDb = null;

    private ?array $savedOauth = null;

    /** @var list<array<string, mixed>> What the endpoint inserted: the consent row among them */
    private array $inserted = [];

    protected function setUp(): void
    {
        $this->snapshotAppKeys();
        \Pramnos\Http\Session::getInstance();
        $dbRef            = &Database::getInstance();
        $this->originalDb = $dbRef;
        $builder          = $this->createMock(QueryBuilder::class);
        foreach (['table', 'select', 'where', 'join', 'leftJoin', 'orderBy', 'limit'] as $chain) {
            $builder->method($chain)->willReturnSelf();
        }
        $row = new \stdClass();
        $row->numRows = 1;
        $row->fields  = ['appid' => 42, 'name' => 'An Assistant', 'apikey' => 'client-42', 'apisecret' => '$2y$10$hashed',
            'is_confidential' => 1, 'status' => 1, 'scope' => '', 'callback' => 'https://assistant.example/cb'];
        $builder->method('first')->willReturn($row);
        $builder->method('insert')->willReturnCallback(function (array $values): bool {
            $this->inserted[] = $values;

            return true;
        });
        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($builder);
        $dbRef = $db;
        // The consent row is written through the factory's database, a singleton of its own.
        $this->originalFactoryDb = \Pramnos\Framework\Factory::getDatabase();
        $factoryRef              = &\Pramnos\Framework\Factory::getDatabase();
        $factoryRef              = $db;
        $_GET  = [];
        $_POST = [];
        $this->inserted = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $app              = Application::currentInstance() ?? Application::getInstance();
        $this->savedOauth = $app->applicationInfo['oauth'] ?? null;
    }

    protected function tearDown(): void
    {
        $_GET  = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $dbRef = &Database::getInstance();
        $dbRef = $this->originalDb;
        $factoryRef = &\Pramnos\Framework\Factory::getDatabase();
        $factoryRef = $this->originalFactoryDb;
        $app   = Application::currentInstance() ?? Application::getInstance();
        if ($this->savedOauth === null) {
            unset($app->applicationInfo['oauth']);
        } else {
            $app->applicationInfo['oauth'] = $this->savedOauth;
        }
        $this->restoreAppKeys();
    }

    /** Ask for these scopes under these settings; the controller, and what it produced. */
    private function authorize(string $scope, array $oauth): array
    {
        $app = Application::currentInstance() ?? Application::getInstance();
        $app->applicationInfo['oauth'] = $oauth + ['scope_policy' => NobodyGrantsEmail::class];
        $_GET = ['client_id' => 'client-42', 'response_type' => 'code', 'scope' => $scope, 'redirect_uri' => 'https://assistant.example/cb'];
        $controller = new ScopeDropTestableOauth(null);
        ob_start();
        $controller->authorize();
        $out = (string) ob_get_clean() . \Pramnos\Framework\Factory::getDocument('html')->getContent();

        return [$controller, $out];
    }

    /** Dropped: the consent page asks for what the account may grant, and only that. */
    public function testTheConsentPageAsksForTheRest(): void
    {
        // Act
        [$controller, $out] = $this->authorize('profile email openid', ['scope_refusal' => 'drop']);

        // Assert
        $this->assertStringNotContainsString('cannot grant', $out);
        $this->assertNotNull($controller->consent, 'the consent page was shown');
        $this->assertSame(['profile', 'openid'], array_values($controller->consent->requestedScopes));
        $this->assertSame('profile openid', $controller->consent->params['scope'], 'and the code will carry those');
    }

    /** By default the request is refused, as before; dropped down to nothing, it is refused too. */
    public function testRefusedByDefaultAndWhenNothingIsLeft(): void
    {
        // Act
        [, $default] = $this->authorize('profile email', []);
        [, $nothing] = $this->authorize('email', ['scope_refusal' => 'drop']);

        // Assert
        $this->assertStringContainsString('cannot grant email', $default);
        $this->assertStringContainsString('cannot grant email', $nothing);
    }

    /** The scope the consent row was written with, after a POST of the consent form. */
    private function consentAfter(string $requested, array $post): ?string
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $post + ['authorize' => 'yes'];
        $level = ob_get_level();
        try {
            // The framework's own policy: every scope may be granted, so only the boxes decide.
            $this->authorize($requested, ['scope_policy' => ScopePolicy::class]);
        } catch (\Throwable) {
            // Issuing the code reaches past the mocked database; the consent row is written first.
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        foreach ($this->inserted as $row) {
            if (isset($row['applicationid'], $row['scope'])) {
                return (string) $row['scope'];
            }
        }

        return null;
    }

    /**
     * The consent page's boxes decide what is granted (FW-158 §5): what was left ticked, and the
     * required scopes (`openid` here), which have no box. A form with no boxes — a theme's own
     * consent view from before them, which sends no `scope_choice` — grants everything asked for,
     * as it did. Nothing ticked and nothing required grants nothing: no consent is recorded.
     */
    public function testTheBoxesDecideWhatIsGranted(): void
    {
        // Act
        $chosen  = $this->consentAfter('profile email openid', ['scope_choice' => '1', 'scopes' => ['profile']]);
        $this->inserted = [];
        $oldForm = $this->consentAfter('profile email openid', []);
        $this->inserted = [];
        $none    = $this->consentAfter('profile email', ['scope_choice' => '1']);

        // Assert
        $this->assertSame('profile openid', $chosen, 'email unticked; openid required');
        $this->assertSame('profile email openid', $oldForm);
        $this->assertNull($none, 'nothing granted, nothing recorded');
        $this->inserted = [];
        $this->assertSame('', $this->consentAfter('', ['scope_choice' => '1']), 'nothing asked for is not a refusal');
    }

    /**
     * Each theme's consent view offers a box per requested scope, ticked, inside the form; a
     * required one is ticked and cannot be cleared; the form says the boxes are a choice.
     */
    public function testEveryThemeOffersTheBoxes(): void
    {
        foreach (['plain-css', 'bootstrap', 'tailwind'] as $theme) {
            // Arrange
            $view = new class {
                public object $application;
                public object $user;
                public array $allScopes = ['Profile' => ['profile' => ['description' => 'Read your profile'], 'openid' => ['description' => 'Sign you in']]];
                public array $requestedScopes = ['profile', 'openid'];
                public array $requiredScopes = ['openid'];
            };
            $view->application = (object) ['name' => 'An Assistant'];
            $view->user        = (object) ['username' => 'maria', 'email' => 'm@example.com'];
            $_SERVER['REQUEST_URI'] = '/oauth/authorize';
            $file = ROOT . '/scaffolding/themes/' . $theme . '/views/OAuth2/authorize.html.php';

            // Act
            ob_start();
            \Closure::bind(function (string $file): void {
                include $file;
            }, $view, null)($file);
            $html = (string) ob_get_clean();

            // Assert
            $this->assertMatchesRegularExpression('/<input type="checkbox" form="oauth-consent" name="scopes\[\]" value="profile" checked>/', $html, $theme);
            $this->assertMatchesRegularExpression('/value="openid" checked disabled>/', $html, $theme . ': required, locked');
            $this->assertStringContainsString('id="oauth-consent"', $html, $theme);
            $this->assertStringContainsString('name="scope_choice" value="1"', $html, $theme);
        }
    }
}
