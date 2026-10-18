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

    private ?array $savedOauth = null;

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
        $db = $this->createMock(Database::class);
        $db->method('queryBuilder')->willReturn($builder);
        $dbRef = $db;
        $_GET  = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $app              = Application::currentInstance() ?? Application::getInstance();
        $this->savedOauth = $app->applicationInfo['oauth'] ?? null;
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $dbRef = &Database::getInstance();
        $dbRef = $this->originalDb;
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
        $app->applicationInfo['oauth'] = ['scope_policy' => NobodyGrantsEmail::class] + $oauth;
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
}
