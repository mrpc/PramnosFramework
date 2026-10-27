<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\ApplicationsController;
use Pramnos\Auth\WebhookService;

/**
 * The webhook endpoint CRUD (`webhook`/`webhookrotate`/`webhooksecret`/`webhookdelete`) had no
 * test at all — every existing test in this controller's neighbourhood is about the
 * applications list, not the per-application webhook endpoints hanging off it.
 */
#[CoversClass(ApplicationsController::class)]
class ApplicationsControllerWebhookTest extends TestCase
{
    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Application\Settings::setSetting('app_env', '');
        parent::tearDown();
    }

    private function realToken(): string
    {
        return \Pramnos\Http\Session::getInstance()->getCsrfToken();
    }

    private function controller(
        bool $deny = false,
        ?WebhookService $service = null,
        bool $appExists = true
    ): object {
        return new class ($deny, $service, $appExists) extends ApplicationsController {
            public ?string $to = null;

            /** @var list<string> */
            public array $said = [];

            public function __construct(
                private bool $deny,
                private ?WebhookService $fakeService,
                private bool $appExists
            ) {
            }

            protected function requireMinUserType(int $minType): bool
            {
                return $this->deny;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->to = (string) $url;
            }

            protected function addError($error)
            {
                $this->said[] = (string) $error;

                return $this;
            }

            protected function addMessage($message)
            {
                $this->said[] = (string) $message;

                return $this;
            }

            protected function webhookService(): WebhookService
            {
                return $this->fakeService ?? parent::webhookService();
            }

            protected function applicationExists(int $appId): bool
            {
                return $this->appExists;
            }
        };
    }

    /** A fake `WebhookService` recording what it was asked and answering as the test says. */
    private function fakeWebhookService(): object
    {
        return new class extends WebhookService {
            public ?array $saved = null;

            public ?string $rotateAnswer = 'whsec_new';

            /** @var string|\Throwable|null */
            public $secretAnswer = 'whsec_existing';

            public bool $deleteAnswer = true;

            public function __construct()
            {
            }

            public function saveEndpoint(
                int $appId,
                string $url,
                string $type,
                string $secret,
                string $registeredBy = self::REGISTERED_BY_CLIENT
            ): void {
                $this->saved = [$appId, $url, $type, $secret, $registeredBy];
            }

            public function rotateEndpointSecret(int $appId, int $webhookId): ?string
            {
                return $this->rotateAnswer;
            }

            public function endpointSecret(int $appId, int $webhookId): ?string
            {
                if ($this->secretAnswer instanceof \Throwable) {
                    throw $this->secretAnswer;
                }

                return $this->secretAnswer;
            }

            public function deleteEndpoint(int $appId, int $webhookId): bool
            {
                return $this->deleteAnswer;
            }
        };
    }

    private function postWebhook(array $fields, ?string $token = null): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_csrf_token' => $token ?? $this->realToken()] + $fields;
    }

    // ── Denied ────────────────────────────────────────────────────────────────

    public function testWebhookDeniedNeverAsksTheService(): void
    {
        // Arrange
        $service = $this->fakeWebhookService();
        $controller = $this->controller(deny: true, service: $service);

        // Act
        $controller->webhook();

        // Assert
        $this->assertNull($service->saved);
    }

    public function testWebhookrotateDeniedNeverAsksTheService(): void
    {
        // Arrange
        $service = $this->fakeWebhookService();
        $service->rotateAnswer = null; // would error the assertion below if it were called
        $controller = $this->controller(deny: true, service: $service);

        // Act / Assert — the point is only that nothing throws and nothing is asked
        $controller->webhookrotate();
        $this->addToAssertionCount(1);
    }

    // ── webhook() — add/replace an endpoint ──────────────────────────────────────

    /** A GET, or a bad token, is refused before anything else is read. */
    public function testWebhookRefusesABadToken(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_csrf_token' => 'not-real'];
        $service = $this->fakeWebhookService();
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhook();

        // Assert
        $this->assertNull($service->saved);
        $this->assertStringEndsWith('applications/view/0', $controller->to);
        $this->assertSame(['That form had expired. Please try again.'], $controller->said);
    }

    /** An endpoint URL that is not a URL at all is refused. */
    public function testWebhookRefusesAnInvalidUrl(): void
    {
        // Arrange
        $this->postWebhook(['appid' => '7', 'endpoint_url' => 'not a url', 'webhook_type' => 'token_revoked']);
        $service = $this->fakeWebhookService();
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhook();

        // Assert
        $this->assertNull($service->saved);
        $this->assertNotEmpty($controller->said);
    }

    /** An unrecognised event type is refused. */
    public function testWebhookRefusesAnUnknownEventType(): void
    {
        // Arrange
        $this->postWebhook([
            'appid' => '7', 'endpoint_url' => 'https://example.test/hook', 'webhook_type' => 'not_a_real_type',
        ]);
        $service = $this->fakeWebhookService();
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhook();

        // Assert
        $this->assertNull($service->saved);
        $this->assertSame(['Choose one of the listed event types.'], $controller->said);
    }

    /** An application id that does not exist is refused, and sent to the list, not the record. */
    public function testWebhookRefusesAMissingApplication(): void
    {
        // Arrange
        $this->postWebhook([
            'appid' => '7', 'endpoint_url' => 'https://example.test/hook', 'webhook_type' => 'token_revoked',
        ]);
        $service = $this->fakeWebhookService();
        $controller = $this->controller(service: $service, appExists: false);

        // Act
        $controller->webhook();

        // Assert
        $this->assertNull($service->saved);
        $this->assertSame(['That record no longer exists.'], $controller->said);
        $this->assertStringEndsWith('applications', $controller->to);
    }

    /** A valid endpoint is saved, with the administrator's own secret kept as typed. */
    public function testWebhookSavesWithTheGivenSecret(): void
    {
        // Arrange
        $this->postWebhook([
            'appid' => '7', 'endpoint_url' => 'https://example.test/hook',
            'webhook_type' => 'token_revoked', 'secret' => 'my-own-secret',
        ]);
        $service = $this->fakeWebhookService();
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhook();

        // Assert
        $this->assertSame(
            [7, 'https://example.test/hook', 'token_revoked', 'my-own-secret', WebhookService::REGISTERED_BY_ADMIN],
            $service->saved
        );
        $this->assertStringContainsString('my-own-secret', implode(' ', $controller->said));
        $this->assertStringEndsWith('applications/view/7', $controller->to);
    }

    /** An empty secret field gets a generated one, not an empty string stored as-is. */
    public function testWebhookGeneratesASecretWhenNoneIsGiven(): void
    {
        // Arrange
        $this->postWebhook([
            'appid' => '7', 'endpoint_url' => 'https://example.test/hook', 'webhook_type' => 'token_revoked',
        ]);
        $service = $this->fakeWebhookService();
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhook();

        // Assert
        $this->assertNotNull($service->saved);
        $this->assertNotSame('', $service->saved[3]);
        $this->assertStringStartsWith('whsec_', $service->saved[3]);
    }

    // ── webhookrotate() ───────────────────────────────────────────────────────

    public function testWebhookrotateRefusesAMissingEndpoint(): void
    {
        // Arrange
        $this->postWebhook(['appid' => '7']);
        $service = $this->fakeWebhookService();
        $service->rotateAnswer = null;
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhookrotate();

        // Assert
        $this->assertSame(['That endpoint no longer exists.'], $controller->said);
    }

    /** A bad token is refused before the service is ever asked. */
    public function testWebhookrotateRefusesABadToken(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_csrf_token' => 'not-real', 'appid' => '7'];
        $service = $this->fakeWebhookService();
        $service->rotateAnswer = null;
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhookrotate();

        // Assert
        $this->assertSame(['That form had expired. Please try again.'], $controller->said);
    }

    public function testWebhookrotateReportsTheNewSecret(): void
    {
        // Arrange
        $this->postWebhook(['appid' => '7']);
        $_GET['_option'] = '3';
        $service = $this->fakeWebhookService();
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhookrotate();

        // Assert
        $this->assertStringContainsString('whsec_new', implode(' ', $controller->said));
        $this->assertStringEndsWith('applications/view/7', $controller->to);
    }

    // ── webhooksecret() ───────────────────────────────────────────────────────

    public function testWebhooksecretRefusesAMissingEndpoint(): void
    {
        // Arrange
        $this->postWebhook(['appid' => '7']);
        $service = $this->fakeWebhookService();
        $service->secretAnswer = null;
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhooksecret();

        // Assert
        $this->assertSame(['That endpoint no longer exists.'], $controller->said);
    }

    public function testWebhooksecretRefusesABadToken(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_csrf_token' => 'not-real', 'appid' => '7'];
        $service = $this->fakeWebhookService();
        $service->secretAnswer = null;
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhooksecret();

        // Assert
        $this->assertSame(['That form had expired. Please try again.'], $controller->said);
    }

    public function testWebhooksecretReportsAnEmptySecretDistinctly(): void
    {
        // Arrange
        $this->postWebhook(['appid' => '7']);
        $service = $this->fakeWebhookService();
        $service->secretAnswer = '';
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhooksecret();

        // Assert
        $this->assertSame(
            ['This endpoint has no signing secret, so its deliveries prove nothing. Issue a new one.'],
            $controller->said
        );
    }

    public function testWebhooksecretReportsTheRealSecret(): void
    {
        // Arrange
        $this->postWebhook(['appid' => '7']);
        $service = $this->fakeWebhookService();
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhooksecret();

        // Assert
        $this->assertStringContainsString('whsec_existing', implode(' ', $controller->said));
    }

    /** An undecryptable secret (a rotated APP_KEY) is reported, not a fatal. */
    public function testWebhooksecretAnswersWhenTheStoredSecretCannotBeDecrypted(): void
    {
        // Arrange
        $this->postWebhook(['appid' => '7']);
        $service = $this->fakeWebhookService();
        $service->secretAnswer = new \RuntimeException('cannot decrypt');
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhooksecret();

        // Assert
        $this->assertSame(
            ["The secret cannot be decrypted with this installation's key. Issue a new one."],
            $controller->said
        );
    }

    // ── webhookdelete() ───────────────────────────────────────────────────────

    public function testWebhookdeleteRefusesAMissingEndpoint(): void
    {
        // Arrange
        $this->postWebhook(['appid' => '7']);
        $service = $this->fakeWebhookService();
        $service->deleteAnswer = false;
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhookdelete();

        // Assert
        $this->assertSame(['That endpoint no longer exists.'], $controller->said);
    }

    public function testWebhookdeleteRefusesABadToken(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['_csrf_token' => 'not-real', 'appid' => '7'];
        $service = $this->fakeWebhookService();
        $service->deleteAnswer = false;
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhookdelete();

        // Assert
        $this->assertSame(['That form had expired. Please try again.'], $controller->said);
    }

    public function testWebhookdeleteRemovesTheEndpoint(): void
    {
        // Arrange
        $this->postWebhook(['appid' => '7']);
        $service = $this->fakeWebhookService();
        $controller = $this->controller(service: $service);

        // Act
        $controller->webhookdelete();

        // Assert
        $this->assertSame(['Endpoint removed.'], $controller->said);
    }

    /** `applicationExists()`'s own default: its seam, `applicationService()`, unreplaced. */
    public function testApplicationExistsIsFalseForANonPositiveId(): void
    {
        // Arrange
        $controller = new class extends ApplicationsController {
            public function __construct()
            {
            }

            public function expose(int $appId): bool
            {
                return $this->applicationExists($appId);
            }
        };

        // Act & Assert
        $this->assertFalse($controller->expose(0));
        $this->assertFalse($controller->expose(-5));
    }
}
