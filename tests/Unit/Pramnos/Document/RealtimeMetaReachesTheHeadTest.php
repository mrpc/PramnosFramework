<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Document\Document;
use Pramnos\Document\DocumentTypes\Html;
use Pramnos\Framework\Factory;
use Pramnos\Http\Request;

/**
 * A signed-in page carries `<meta name="pf-realtime">` where the application has a socket
 * transport, and `pf-utils.js` connects from it so the bell refreshes the moment a notification
 * arrives. Nothing for an anonymous page — the channel is a user's own and the endpoint answers to
 * the session — and nothing for a transport the page's client does not speak.
 */
#[CoversClass(Document::class)]
class RealtimeMetaReachesTheHeadTest extends TestCase
{
    /** @var array<string, mixed>|null The application instances before the test replaced them */
    private ?array $savedInstances = null;

    protected function setUp(): void
    {
        if (!class_exists('pramnos_request')) {
            class_alias(Request::class, 'pramnos_request');
        }
        Factory::getLanguage()->addlang(['LangShort' => 'en', 'CHARSET' => 'UTF-8']);
        Request::$originalRequestNoChange = '/a-page';
        Document::_setContent('');
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        unset($_SESSION['usertoken'], $_SESSION['csrf_token'], $_SESSION['logged'], $_SESSION['uid']);
    }

    protected function tearDown(): void
    {
        Document::_setContent('');
        unset($_SESSION['usertoken'], $_SESSION['csrf_token'], $_SESSION['logged'], $_SESSION['uid']);
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))->setValue(null, $this->savedInstances);
        }
    }

    /** The current application, with this `broadcasting` configuration. */
    private function broadcasting(array $config): void
    {
        $stub = new class extends Application {
            /** Nothing to boot: only the configuration is read. */
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['broadcasting' => $config];
        $property = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances ??= $property->getValue() ?? [];
        $last = (new \ReflectionProperty(Application::class, 'lastUsedApplication'))->getValue() ?? 'default';
        $property->setValue(null, [$last => $stub] + $this->savedInstances);
    }

    /** A web session, as the login path leaves it. */
    private function signIn(): void
    {
        $token            = new \Pramnos\User\Token();
        $token->tokentype = \Pramnos\User\Token::TYPE_WEB_SESSION;
        $token->status    = 1;
        $_SESSION['usertoken']  = $token;
        $_SESSION['csrf_token'] = 'a1b2c3d4';
    }

    /**
     * Signed in, on a websocket transport: the tag carries the client config and the endpoint,
     * escaped for the attribute.
     */
    public function testASignedInPageCarriesTheConnection(): void
    {
        // Arrange
        $this->broadcasting(['transport' => 'websocket', 'websocket' => ['host' => 'rt.test', 'app_key' => 'k1'], 'auth_endpoint' => '/broadcasting/auth']);
        $this->signIn();

        // Act
        $output = (new Html())->render();
        preg_match('/<meta name="pf-realtime" content="([^"]*)" \/>/', $output, $m);
        $config = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);

        // Assert
        $this->assertSame('websocket', $config['transport'] ?? null, 'the tag pf-utils.js reads must be on the page');
        $this->assertSame('rt.test', $config['host']);
        $this->assertSame('/broadcasting/auth', $config['authEndpoint']);
    }

    /**
     * An application whose login is its own sets `uid` and `logged` and no token; its signed-in
     * pages carry the connection too. Gated on the token alone, no admin who signed in with a
     * password got it, and the bell never went live.
     */
    public function testASessionSignedInWithoutATokenCarriesTheConnection(): void
    {
        // Arrange
        $this->broadcasting(['transport' => 'websocket', 'websocket' => ['host' => 'rt.test', 'app_key' => 'k1']]);
        $_SESSION['logged'] = true;
        $_SESSION['uid']    = 42;

        // Act
        $output = (new Html())->render();

        // Assert
        $this->assertStringContainsString('<meta name="pf-realtime"', $output);
    }

    /**
     * Nothing for an anonymous page, nor for a signed-in one whose transport the client does not
     * speak — the bell keeps polling.
     */
    public function testNoConnectionWithoutASessionOrASocketTransport(): void
    {
        // Arrange
        $this->broadcasting(['transport' => 'websocket']);

        // Act
        $anonymous = (new Html())->render();
        Document::_setContent('');
        $this->broadcasting(['transport' => 'sse']);
        $this->signIn();
        $sse = (new Html())->render();

        // Assert
        $this->assertStringNotContainsString('pf-realtime', $anonymous);
        $this->assertStringNotContainsString('pf-realtime', $sse);
    }
}
