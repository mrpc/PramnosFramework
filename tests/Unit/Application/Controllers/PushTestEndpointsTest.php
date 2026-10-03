<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Push as PushController;
use Pramnos\Push\TestPush;

/**
 * `/push/test`, `/push/testack` and `/push/teststatus`: a person testing their own devices.
 *
 * The decisions are the endpoints' own — who may send, which browser a body names, whose test
 * a status may be read for — so the sender is a fake and nothing is pushed.
 */
#[CoversClass(PushController::class)]
class PushTestEndpointsTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * A test is a POST from a signed-in account: a GET and a visitor are refused before
     * anything is sent.
     */
    public function testATestIsAPostFromASignedInAccount(): void
    {
        // Arrange & Act — a GET
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $get = $this->controller(user: 42);
        $get->test();

        // a visitor
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $visitor = $this->controller(user: null);
        $visitor->test();

        // Assert
        $this->assertSame(405, $get->status);
        $this->assertSame(401, $visitor->status);
        $this->assertSame([], $get->sender->sent);
        $this->assertSame([], $visitor->sender->sent);
    }

    /**
     * With an endpoint in the body only that browser is tested; without one, every browser.
     */
    public function testTheBodyNamesTheBrowser(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $one = $this->controller(user: 42, body: '{"endpoint":"https://push.example/abc"}');
        $all = $this->controller(user: 42);

        // Act
        $one->test();
        $all->test();

        // Assert
        $this->assertSame([[42, hash('sha256', 'https://push.example/abc')]], $one->sender->sent);
        $this->assertSame([[42, null]], $all->sender->sent);
        $this->assertSame(200, $one->status);
        $this->assertSame('tok', $one->payload['tests'][0]['token']);
        $this->assertTrue($one->payload['tests'][0]['sent']);
    }

    /**
     * Nothing subscribed is a 404 that says so, worded for the case it is.
     */
    public function testNothingSubscribedIsA404(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = $this->controller(user: 42, body: '{"endpoint":"https://push.example/x"}');
        $controller->sender->answer = [];

        // Act
        $controller->test();

        // Assert
        $this->assertSame(404, $controller->status);
        $this->assertStringContainsString('This browser is not subscribed', $controller->payload['error']);
    }

    /**
     * A receipt needs only its token; an unknown one is a 404.
     */
    public function testAReceiptNeedsOnlyItsToken(): void
    {
        // Arrange
        $controller = $this->controller(user: null);

        // Act & Assert
        $_GET = ['token' => 'good'];
        $controller->testack();
        $this->assertSame(200, $controller->status);

        $_GET = ['token' => 'bad'];
        $controller->testack();
        $this->assertSame(404, $controller->status);
    }

    /**
     * A test's status is the account's own: another account's token reads as no such test.
     */
    public function testStatusIsOnlyForTheAccountsOwnTests(): void
    {
        // Arrange
        $_GET = ['token' => 'good'];

        // Act
        $owner    = $this->controller(user: 42);
        $owner->teststatus();
        $other    = $this->controller(user: 7);
        $other->teststatus();
        $visitor  = $this->controller(user: null);
        $visitor->teststatus();

        // Assert
        $this->assertSame(200, $owner->status);
        $this->assertTrue($owner->payload['received']);
        $this->assertSame(404, $other->status, "another account's test");
        $this->assertSame(401, $visitor->status);
    }

    /**
     * A controller with a signed-in user, a body and a fake sender.
     */
    private function controller(?int $user, string $body = ''): object
    {
        return new class ($user, $body) extends PushController {
            public int $status = 0;

            /** @var array<string, mixed> */
            public array $payload = [];

            public object $sender;

            public function __construct(private ?int $user, private string $rawBody)
            {
                $this->sender = new class extends TestPush {
                    /** @var list<array{0: int, 1: ?string}> */
                    public array $sent = [];

                    /** @var list<array<string, mixed>> */
                    public array $answer = [['token' => 'tok', 'endpoint_hash' => 'h', 'user_agent' => 'Chrome',
                        'status' => 201, 'outcome' => ['kind' => 'delivered', 'label' => 'Delivered', 'explanation' => 'x']]];

                    public function send(int $userId, ?string $endpointHash = null): array
                    {
                        $this->sent[] = [$userId, $endpointHash];

                        return $this->answer;
                    }

                    public function acknowledge(string $token): bool
                    {
                        return $token === 'good';
                    }

                    public function status(string $token): ?array
                    {
                        return $token === 'good'
                            ? ['token' => 'good', 'userid' => 42, 'endpoint_hash' => 'h', 'sent_at' => 1, 'received_at' => 2, 'expired' => false]
                            : null;
                    }
                };
            }

            protected function currentUser(): ?int
            {
                return $this->user;
            }

            protected function rawBody(): string
            {
                return $this->rawBody;
            }

            protected function testPush(): TestPush
            {
                return $this->sender;
            }

            /** @param array<string, mixed> $data */
            protected function json(array $data, int $status = 200): mixed
            {
                $this->payload = $data;
                $this->status  = $status;

                return null;
            }
        };
    }
}
