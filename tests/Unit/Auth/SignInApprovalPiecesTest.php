<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Auth\ApprovalPushChannel;
use Pramnos\Auth\Notifications\SecurityChangeNotification;
use Pramnos\Auth\Notifications\SignInApprovalNotification;
use Pramnos\Auth\PushApprovals;
use Pramnos\Auth\RegistrationPolicy;
use Pramnos\Auth\SecondFactorRegistry;
use Pramnos\Auth\SecurityChangeNotifier;

/**
 * The small pieces of the phone prompt, each held to the one thing it is for.
 */
#[CoversClass(ApprovalPushChannel::class)]
#[CoversClass(SecurityChangeNotification::class)]
#[CoversClass(SignInApprovalNotification::class)]
#[CoversClass(RegistrationPolicy::class)]
#[CoversClass(SecondFactorRegistry::class)]
class SignInApprovalPiecesTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $savedInstances = null;

    protected function tearDown(): void
    {
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
        }
        SecondFactorRegistry::reset();
        Settings::setSetting(PushApprovals::ENABLED_SETTING, '', false);
    }

    /** @param array<string, mixed> $info */
    private function application(array $info): void
    {
        $stub = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = $info;
        $reflection = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $this->savedInstances ??= $reflection->getValue() ?? [];
        $reflection->setValue(null, ['default' => $stub] + $this->savedInstances);
    }

    /**
     * The approval's channel sends only to the subscriptions it was handed — the trusted
     * ones — and only those of the account it is sending for; urgently, and for two minutes.
     */
    public function testTheApprovalChannelSendsOnlyToTheTrustedDevicesItWasGiven(): void
    {
        // Arrange
        $channel = new class ([['userid' => 7, 'endpoint' => 'a'], ['userid' => 8, 'endpoint' => 'b']]) extends ApprovalPushChannel {
            public function probe(int $userId): array
            {
                return $this->subscriptionsFor($userId);
            }

            public function probeUrgency(): string
            {
                return $this->urgency();
            }
        };

        // Act & Assert
        $this->assertSame([['userid' => 7, 'endpoint' => 'a']], $channel->probe(7));
        $this->assertSame([], $channel->probe(9));
        $this->assertSame('high', $channel->probeUrgency());
        $this->assertSame(PushApprovals::TTL, ApprovalPushChannel::TTL);
    }

    /**
     * The ordinary channel's urgency is `normal`: news, not somebody waiting.
     */
    public function testOrdinaryPushIsNormalUrgency(): void
    {
        // Arrange
        $channel = new class extends \Pramnos\Notification\Channels\PushChannel {
            public function probeUrgency(): string
            {
                return $this->urgency();
            }
        };

        // Act & Assert
        $this->assertSame('normal', $channel->probeUrgency());
    }

    /**
     * The notification goes by push only — a mail would arrive after it can be answered —
     * and carries buttons only when it was given somewhere to send them. An ask that needs the
     * number gets No alone — picking a number takes a page, and a one-tap Yes is what prompt
     * bombing counts on; one that does not (`oneTap`) gets Yes as well.
     */
    public function testTheNotificationCarriesYesOnlyWhenNoNumberIsNeeded(): void
    {
        // Arrange
        $familiar   = new SignInApprovalNotification('Chrome on Windows', 'GR', 1700000000, 'https://x/approve', 'https://x/ack', 'https://x/respond?token=t');
        $unfamiliar = new SignInApprovalNotification('Chrome on Windows', '', 1700000000, 'https://x/approve', 'https://x/ack');

        // Act
        $withButtons = $familiar->toPush(null);
        $withNumber  = $unfamiliar->toPush(null);

        // Assert
        $this->assertSame(['push'], $familiar->via(null));
        $this->assertStringStartsWith('Chrome on Windows · GR · ', $withButtons['body']);
        $this->assertSame('signin-approval', $withButtons['tag']);
        $this->assertSame(['deny'], array_column($withButtons['actions'], 'action'));
        $this->assertSame(['deny'], array_keys($withButtons['data']['actions']));
        $this->assertSame('https://x/respond?token=t&decision=denied', $withButtons['data']['actions']['deny']['post']);
        $oneTap = (new SignInApprovalNotification('Chrome on Windows', 'GR', 1700000000, 'https://x/approve', 'https://x/ack', 'https://x/respond?token=t', true))->toPush(null);
        $this->assertSame(['approve', 'deny'], array_column($oneTap['actions'], 'action'));
        $this->assertSame('https://x/respond?token=t&decision=approved', $oneTap['data']['actions']['approve']['post']);
        $this->assertArrayNotHasKey('actions', $withNumber);
        $this->assertArrayNotHasKey('actions', $withNumber['data']);
        $this->assertStringStartsWith('Chrome on Windows · ', $withNumber['body'], 'no country, no empty separator');
        $this->assertSame('https://x/ack', $withNumber['data']['ack']);
    }

    /**
     * Registration is editable in the administration unless app.php says otherwise; an
     * application that says nothing leaves it editable.
     */
    public function testRegistrationIsEditableUnlessTheApplicationKeepsIt(): void
    {
        // Act & Assert
        $this->application(['auth' => []]);
        $this->assertTrue(RegistrationPolicy::editableInAdmin());
        $this->application(['auth' => ['registration_admin_editable' => false]]);
        $this->assertFalse(RegistrationPolicy::editableInAdmin());
    }

    /**
     * Switched on, the phone prompt is a factor the registry offers — and it brings the
     * mailed code with it, the other way every account can take. Off, neither is added.
     */
    public function testThePhonePromptBringsTheMailedCodeWithIt(): void
    {
        // Arrange
        $this->application(['auth' => ['twofactor_methods' => ['totp']]]);
        $names = static fn (): array => array_map(static fn ($factor) => $factor->name(), SecondFactorRegistry::all());

        // Act
        $off = $names();
        Settings::setSetting(PushApprovals::ENABLED_SETTING, '1', false);
        SecondFactorRegistry::reset();
        $on = $names();

        // Assert
        $this->assertSame(['totp'], $off);
        $this->assertSame(['push', 'totp', 'email'], $on, 'strongest first');
    }

    /**
     * "No, it's not me" mails the owner a warning in its own words — not the "if you made
     * this change" text every other security change carries, because nobody made one.
     */
    public function testARefusedSignInMailsItsOwnWarning(): void
    {
        // Arrange
        $notification = new SecurityChangeNotification(SecurityChangeNotifier::SIGNIN_DENIED, '');

        // Act
        $mail = $notification->toMail(null);

        // Assert
        $this->assertStringContainsString('You refused a sign-in that used your password', $mail['subject']);
        $this->assertStringContainsString('your password is known to them', $mail['body']);
        $this->assertStringNotContainsString('If you made this change', $mail['body']);
    }
}
