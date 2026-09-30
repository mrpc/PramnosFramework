<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\Push as PushController;
use Pramnos\Auth\PushApprovals;

/**
 * The two endpoints a phone's service worker calls about a sign-in approval.
 *
 * `ack` is the receipt, and its token is the whole credential — the worker may have no page
 * and no session. `respond` is the notification's Yes and No, and it is the opposite: a POST
 * only, from a signed-in account, with the service deciding whether this browser may answer.
 */
#[CoversClass(PushController::class)]
class PushApprovalEndpointsTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    /**
     * A receipt for a live ask is accepted without a session; one for an unknown or
     * expired ask is a 404.
     */
    public function testAReceiptNeedsOnlyItsToken(): void
    {
        // Arrange
        $_GET = ['token' => 'good'];
        $controller = $this->controller(user: null);

        // Act
        $controller->ack();
        $accepted = $controller->status;
        $_GET = ['token' => 'bad'];
        $controller->ack();

        // Assert
        $this->assertSame(200, $accepted);
        $this->assertSame(404, $controller->status);
        $this->assertSame(['good', 'bad'], $controller->approvals->acknowledged);
    }

    /**
     * An answer is a POST from a signed-in account: a GET is refused before anything is
     * decided, and so is an answer with no session.
     */
    public function testAnAnswerIsAPostFromASignedInAccount(): void
    {
        // Arrange
        $_GET = ['token' => 'good', 'decision' => 'approved'];
        $controller = $this->controller(user: 42);

        // Act — a GET
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller->respond();
        $asGet = $controller->status;

        // Act — no session
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $anonymous = $this->controller(user: null);
        $anonymous->respond();

        // Assert
        $this->assertSame(405, $asGet);
        $this->assertSame(401, $anonymous->status);
        $this->assertSame([], $controller->approvals->decisions);
        $this->assertSame([], $anonymous->approvals->decisions);
    }

    /**
     * The service decides; the endpoint reports. A refusal it accepts is a 200 naming the
     * decision; one it refuses is a 403 with the reason. An approval is not the endpoint's to
     * give — it needs the number, which the lock screen cannot pick — so it is a 400 that
     * never reaches the service.
     */
    public function testTheServiceDecidesAndTheEndpointReports(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = $this->controller(user: 42);

        // Act
        $_GET = ['token' => 'good', 'decision' => 'denied'];
        $controller->respond();
        $denied = [$controller->status, $controller->payload];
        $_GET = ['token' => 'good', 'decision' => 'approved'];
        $controller->respond();
        $approved = $controller->status;
        $_GET = ['token' => 'bad', 'decision' => 'denied'];
        $controller->respond();

        // Assert
        $this->assertSame([200, ['ok' => true, 'decision' => 'denied']], $denied);
        $this->assertSame(400, $approved);
        $this->assertSame(403, $controller->status);
        $this->assertSame('invalid', $controller->payload['reason']);
        $this->assertSame([['good', 42, 'denied'], ['bad', 42, 'denied']], $controller->approvals->decisions);
    }

    /**
     * Without a trust cookie a subscription is stored and linked to nothing — the ordinary
     * case, and the one that must not make a browser an approver.
     */
    public function testAnUntrustedBrowserSubscribesWithoutBecomingAnApprover(): void
    {
        // Arrange
        unset($_COOKIE[\Pramnos\Auth\TrustedDevices::COOKIE]);
        $controller = new class extends PushController {
            public function __construct()
            {
            }

            public function link(int $userId, string $endpoint): void
            {
                $this->linkTrustedDevice($userId, $endpoint);
            }

            public function realApprovals(): PushApprovals
            {
                return $this->approvals();
            }
        };

        // Act
        $controller->link(42, 'https://a.example/1');

        // Assert — nothing to link to, and nothing thrown; the real service is the default
        $this->assertInstanceOf(PushApprovals::class, $controller->realApprovals());
    }

    private function controller(?int $user): object
    {
        return new class ($user) extends PushController {
            public int $status = 0;

            /** @var array<string, mixed> */
            public array $payload = [];

            public object $approvals;

            public function __construct(private ?int $user)
            {
                $this->approvals = new class extends PushApprovals {
                    /** @var list<string> */
                    public array $acknowledged = [];

                    /** @var list<array{0: string, 1: int, 2: string}> */
                    public array $decisions = [];

                    public function acknowledge(string $token): bool
                    {
                        $this->acknowledged[] = $token;

                        return $token === 'good';
                    }

                    public function decide(string $token, int $userId, string $decision, ?int $picked = null): string
                    {
                        $this->decisions[] = [$token, $userId, $decision];

                        return $token === 'good' ? $decision : 'invalid';
                    }
                };
            }

            protected function currentUser(): ?int
            {
                return $this->user;
            }

            protected function approvals(): PushApprovals
            {
                return $this->approvals;
            }

            protected function json(array $data, int $status = 200): mixed
            {
                $this->status  = $status;
                $this->payload = $data;

                return null;
            }
        };
    }
}
