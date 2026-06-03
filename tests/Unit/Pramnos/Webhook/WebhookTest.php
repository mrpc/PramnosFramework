<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Webhook;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Webhook\WebhookHandler;
use Pramnos\Webhook\WebhookServiceProvider;

class TestableWebhookHandler extends WebhookHandler
{
    public array $responseLog = [];

    protected function respond(int $code, array $data): never
    {
        $this->responseLog[] = ['code' => $code, 'data' => $data];
        throw new \RuntimeException("Response: {$code}");
    }
}

class TestableApp extends \Pramnos\Application\Application
{
    public $applicationInfo = [];
    public $container;

    public function __construct()
    {
        $this->container = new class {
            public array $bindings = [];
            public function singleton(string $name, callable $resolver)
            {
                $this->bindings[$name] = $resolver();
            }
            public function bind(string $name, callable $resolver)
            {
                $this->bindings[$name] = $resolver();
            }
            public function get(string $name)
            {
                return $this->bindings[$name] ?? null;
            }
            public function has(string $name): bool
            {
                return isset($this->bindings[$name]);
            }
        };
    }
}

#[CoversClass(WebhookHandler::class)]
#[CoversClass(WebhookServiceProvider::class)]
class WebhookTest extends TestCase
{
    private string $tempRepoDir;

    protected function setUp(): void
    {
        $this->tempRepoDir = sys_get_temp_dir() . '/webhook_test_repo_' . bin2hex(random_bytes(4));
        mkdir($this->tempRepoDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempRepoDir)) {
            rmdir($this->tempRepoDir);
        }
    }

    public function testConstructorValidatesSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('secret must not be empty');
        new WebhookHandler('', $this->tempRepoDir);
    }

    public function testOnBranchRegistersCommands(): void
    {
        $handler = new WebhookHandler('secret', $this->tempRepoDir);
        $handler->onBranch('main', ['git status', 'composer install']);
        $map = $handler->getBranchMap();

        $this->assertArrayHasKey('main', $map);
        $this->assertSame(['git status', 'composer install'], $map['main']);
    }

    public function testHandleInvalidSignatureForbidden(): void
    {
        $handler = new TestableWebhookHandler('my_secret', $this->tempRepoDir);
        $handler->onBranch('main', ['echo 123']);

        $headers = [
            'x-hub-signature-256' => 'invalid_signature_hash'
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Response: 403');

        try {
            $handler->handle('{}', $headers);
        } catch (\RuntimeException $e) {
            $this->assertCount(1, $handler->responseLog);
            $this->assertSame(403, $handler->responseLog[0]['code']);
            $this->assertSame('forbidden', $handler->responseLog[0]['data']['status']);
            throw $e;
        }
    }

    public function testHandleValidGitHubSignatureTriggersCommands(): void
    {
        $secret = 'my_super_secret';
        $body = json_encode([
            'ref' => 'refs/heads/main',
            'repository' => ['name' => 'test-repo']
        ]);
        
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $handler = new TestableWebhookHandler($secret, $this->tempRepoDir);
        $handler->onBranch('main', ['echo "Hello Webhook"']);

        $headers = [
            'x-hub-signature-256' => $signature,
            'x-github-event' => 'push'
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Response: 200');

        try {
            $handler->handle($body, $headers);
        } catch (\RuntimeException $e) {
            $this->assertCount(1, $handler->responseLog);
            $this->assertSame(200, $handler->responseLog[0]['code']);
            $this->assertSame('ok', $handler->responseLog[0]['data']['status']);
            $this->assertSame(1, $handler->responseLog[0]['data']['commands_run']);
            throw $e;
        }
    }

    public function testHandleUnsupportedEventIgnored(): void
    {
        $secret = 'my_super_secret';
        $body = json_encode(['foo' => 'bar']);
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $handler = new TestableWebhookHandler($secret, $this->tempRepoDir);
        $headers = [
            'x-hub-signature-256' => $signature,
            'x-github-event' => 'issue_comment'
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Response: 204');

        try {
            $handler->handle($body, $headers);
        } catch (\RuntimeException $e) {
            $this->assertCount(1, $handler->responseLog);
            $this->assertSame(204, $handler->responseLog[0]['code']);
            throw $e;
        }
    }

    public function testWebhookServiceProvider(): void
    {
        $app = new TestableApp();
        \Pramnos\Application\Settings::setSetting('webhook.secret', 'provider_secret');
        \Pramnos\Application\Settings::setSetting('webhook.repo_dir', $this->tempRepoDir);

        $provider = new WebhookServiceProvider($app);
        $provider->register();

        $this->assertTrue($app->container->has('webhook'));
        $handler = $app->container->get('webhook');
        $this->assertInstanceOf(WebhookHandler::class, $handler);
    }
}
