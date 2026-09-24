<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\ApplicationsController;
use Pramnos\Auth\WebhookService;

/**
 * A WebhookService that records what it was asked and answers what the test says.
 */
class RecordingWebhookService extends WebhookService
{
    /** @var list<array> */
    public array $saved = [];

    public ?string $rotateAnswer = 'new-secret';

    public bool $deleteAnswer = true;

    /** @var list<array{int, int}> */
    public array $asked = [];

    public function __construct()
    {
        // No database: every method a test reaches is overridden.
    }

    public function saveEndpoint(
        int $appId,
        string $url,
        string $type,
        string $secret,
        string $registeredBy = self::REGISTERED_BY_CLIENT
    ): void {
        $this->saved[] = compact('appId', 'url', 'type', 'secret', 'registeredBy');
    }

    public function rotateEndpointSecret(int $appId, int $webhookId): ?string
    {
        $this->asked[] = [$appId, $webhookId];
        return $this->rotateAnswer;
    }

    public function deleteEndpoint(int $appId, int $webhookId): bool
    {
        $this->asked[] = [$appId, $webhookId];
        return $this->deleteAnswer;
    }
}

/**
 * The controller with its gate open and its outputs captured.
 */
class WebhookActionsController extends ApplicationsController
{
    public RecordingWebhookService $service;

    public bool $exists = true;

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $messages = [];

    public ?string $redirectedTo = null;

    /** Whether the usertype floor turns this visitor away. */
    public bool $belowTheFloor = false;

    protected function requireMinUserType(int $minType): bool
    {
        return $this->belowTheFloor;
    }

    protected function webhookService(): WebhookService
    {
        return $this->service;
    }

    protected function applicationExists(int $appId): bool
    {
        return $this->exists;
    }

    protected function addError($error)
    {
        $this->errors[] = (string) $error;
    }

    protected function addMessage($message)
    {
        $this->messages[] = (string) $message;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo = (string) $url;
    }
}

/**
 * The webhook card on an application's page: add, new secret, remove.
 *
 * An administrator's endpoint is approved as it is entered and recorded as
 * `registered_by = admin`, which makes it delivered to as written — so the address checks
 * that apply to a client do not apply here, and the tests assert the ones that do: a
 * POST, a valid CSRF token, `https`, a known event type, an application that exists.
 */
#[CoversClass(ApplicationsController::class)]
class ApplicationsWebhookActionsTest extends TestCase
{
    private WebhookActionsController $controller;

    protected function setUp(): void
    {
        // The session's own token, rather than one written into $_SESSION: starting the
        // session later would replace the array, and the test would depend on order.
        $token = \Pramnos\Http\Session::getInstance()->getCsrfToken();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET  = [];
        $_POST = ['_csrf_token' => $token, 'appid' => '7'];

        $this->controller          = new WebhookActionsController(null);
        $this->controller->service = new RecordingWebhookService();
    }

    protected function tearDown(): void
    {
        $_GET  = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /**
     * The three actions are behind the controller's authentication, like the others.
     */
    public function testTheWebhookActionsAreAuthProtected(): void
    {
        // Arrange
        $actions = (new \ReflectionProperty(ApplicationsController::class, 'actions_auth'))
            ->getValue(new ApplicationsController(null));

        // Assert
        foreach (['webhook', 'webhookrotate', 'webhookdelete'] as $action) {
            $this->assertContains($action, $actions, "$action must be registered via addAuthAction()");
        }
    }

    /**
     * An administrator's endpoint is saved as theirs, and its secret is shown once.
     *
     * A private address is accepted without any setting: that is the point of entering it
     * here. The flash message is the only readable copy of the secret, so it must carry
     * the one that was stored.
     */
    public function testAnAdministratorsEndpointIsSavedAsTheirsWithItsSecretShownOnce(): void
    {
        // Arrange
        $_POST += ['endpoint_url' => 'https://10.8.0.5/hooks', 'webhook_type' => 'token_revoked'];

        // Act
        $this->controller->webhook();

        // Assert
        $saved = $this->controller->service->saved;
        $this->assertCount(1, $saved);
        $this->assertSame(7, $saved[0]['appId']);
        $this->assertSame('https://10.8.0.5/hooks', $saved[0]['url']);
        $this->assertSame(WebhookService::REGISTERED_BY_ADMIN, $saved[0]['registeredBy']);
        $this->assertSame(64, strlen($saved[0]['secret']));
        $this->assertStringContainsString($saved[0]['secret'], $this->controller->messages[0] ?? '');
        $this->assertSame([], $this->controller->errors);
        $this->assertStringEndsWith('applications/view/7', (string) $this->controller->redirectedTo);
    }

    /**
     * What is refused before anything is written.
     *
     * @param array  $post  What the form sent, over the valid defaults
     * @param string $error What the administrator is told
     */
    #[DataProvider('refusedEndpoints')]
    public function testAnUnusableEndpointIsRefused(array $post, string $error): void
    {
        // Arrange — https pinned on, so the plaintext case does not depend on the fixture
        $_POST = array_merge(
            $_POST,
            ['endpoint_url' => 'https://10.8.0.5/hooks', 'webhook_type' => 'token_revoked'],
            $post
        );

        // Act
        $this->withWebhookConfig(['require_https' => true], fn() => $this->controller->webhook());

        // Assert
        $this->assertSame([], $this->controller->service->saved, 'nothing may be written');
        $this->assertStringContainsString($error, implode(' ', $this->controller->errors));
    }

    /** @return array<string, array{array, string}> */
    public static function refusedEndpoints(): array
    {
        return [
            'plaintext'          => [['endpoint_url' => 'http://10.8.0.5/hooks'], 'https://'],
            'not a url'          => [['endpoint_url' => 'somewhere'], 'https://'],
            'empty'              => [['endpoint_url' => ''], 'https://'],
            'unknown event type' => [['webhook_type' => 'everything'], 'event types'],
            'no csrf token'      => [['_csrf_token' => ''], 'expired'],
            'a stale csrf token' => [['_csrf_token' => 'another'], 'expired'],
        ];
    }

    /**
     * With `require_https` off, an administrator can enter an `http://` receiver — one on
     * a VPN, or one under development — but still not another scheme.
     */
    public function testWithHttpsNotRequiredAPlaintextEndpointIsSaved(): void
    {
        // Arrange
        $_POST += ['endpoint_url' => 'http://localhost:8080/hooks', 'webhook_type' => 'token_revoked'];

        // Act
        $this->withWebhookConfig(['require_https' => false], fn() => $this->controller->webhook());
        $_POST['endpoint_url'] = 'ftp://localhost/hooks';
        $this->withWebhookConfig(['require_https' => false], fn() => $this->controller->webhook());

        // Assert
        $this->assertCount(1, $this->controller->service->saved);
        $this->assertSame('http://localhost:8080/hooks', $this->controller->service->saved[0]['url']);
        $this->assertSame(
            ['The endpoint must be a full http:// or https:// URL.'],
            $this->controller->errors
        );
    }

    /** Run $act with `authserver.webhooks` set to $config, and put it back. */
    private function withWebhookConfig(array $config, callable $act): mixed
    {
        $app   = \Pramnos\Application\Application::getInstance();
        $had   = isset($app->applicationInfo['authserver']);
        $saved = $app->applicationInfo['authserver'] ?? null;
        $app->applicationInfo['authserver'] = ['webhooks' => $config];

        try {
            return $act();
        } finally {
            if ($had) {
                $app->applicationInfo['authserver'] = $saved;
            } else {
                unset($app->applicationInfo['authserver']);
            }
        }
    }

    /**
     * A GET changes nothing.
     *
     * These actions change where signed events go and what signs them; a link or a
     * prefetch must not do that.
     */
    public function testAGetChangesNothing(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST += ['endpoint_url' => 'https://10.8.0.5/hooks', 'webhook_type' => 'token_revoked'];
        $_GET['_option'] = '3';

        // Act
        $this->controller->webhook();
        $this->controller->webhookrotate();
        $this->controller->webhookdelete();

        // Assert
        $this->assertSame([], $this->controller->service->saved);
        $this->assertSame([], $this->controller->service->asked);
        $this->assertCount(3, $this->controller->errors);
    }

    /**
     * Below the usertype floor, none of the three does anything.
     *
     * The floor check redirects on its own; what matters here is that nothing after it
     * runs — no save, no rotation, no removal — even with a valid form.
     */
    public function testBelowTheFloorNothingHappens(): void
    {
        // Arrange
        $this->controller->belowTheFloor = true;
        $_POST += ['endpoint_url' => 'https://10.8.0.5/hooks', 'webhook_type' => 'token_revoked'];
        $_GET['_option'] = '3';

        // Act
        $this->controller->webhook();
        $this->controller->webhookrotate();
        $this->controller->webhookdelete();

        // Assert
        $this->assertSame([], $this->controller->service->saved);
        $this->assertSame([], $this->controller->service->asked);
        $this->assertNull($this->controller->redirectedTo, 'nothing past the floor check ran');
    }

    /** An endpoint for an application that no longer exists is refused. */
    public function testAnEndpointForAMissingApplicationIsRefused(): void
    {
        // Arrange
        $this->controller->exists = false;
        $_POST += ['endpoint_url' => 'https://10.8.0.5/hooks', 'webhook_type' => 'token_revoked'];

        // Act
        $this->controller->webhook();

        // Assert
        $this->assertSame([], $this->controller->service->saved);
        $this->assertSame(['That record no longer exists.'], $this->controller->errors);
    }

    /**
     * A new secret is asked for by application and endpoint, and shown once.
     */
    public function testRotatingShowsTheNewSecret(): void
    {
        // Arrange
        $_GET['_option'] = '3';

        // Act
        $this->controller->webhookrotate();

        // Assert
        $this->assertSame([[7, 3]], $this->controller->service->asked);
        $this->assertStringContainsString('new-secret', $this->controller->messages[0] ?? '');
    }

    /** Rotating an endpoint the application does not have says so. */
    public function testRotatingAMissingEndpointSaysSo(): void
    {
        // Arrange
        $_GET['_option'] = '3';
        $this->controller->service->rotateAnswer = null;

        // Act
        $this->controller->webhookrotate();

        // Assert
        $this->assertSame(['That endpoint no longer exists.'], $this->controller->errors);
        $this->assertSame([], $this->controller->messages);
    }

    /** Removing an endpoint is asked for by application and endpoint. */
    public function testRemovingAnEndpoint(): void
    {
        // Arrange
        $_GET['_option'] = '3';

        // Act
        $this->controller->webhookdelete();

        // Assert
        $this->assertSame([[7, 3]], $this->controller->service->asked);
        $this->assertSame(['Endpoint removed.'], $this->controller->messages);
    }

    /** Removing an endpoint the application does not have says so. */
    public function testRemovingAMissingEndpointSaysSo(): void
    {
        // Arrange
        $_GET['_option'] = '3';
        $this->controller->service->deleteAnswer = false;

        // Act
        $this->controller->webhookdelete();

        // Assert
        $this->assertSame(['That endpoint no longer exists.'], $this->controller->errors);
    }
}
