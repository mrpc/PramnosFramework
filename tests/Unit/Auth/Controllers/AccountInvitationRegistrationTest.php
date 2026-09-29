<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

require_once __DIR__ . '/AccountControllerTest.php';
require_once __DIR__ . '/AccountRegistrationTest.php';
require_once dirname(__DIR__) . '/LoginFlowTest.php';

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Settings;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\RegistrationPolicy;

/**
 * Registration through an invitation, within a domain list, and with address confirmation.
 *
 * The rules decided in the controller, with the two services it asks faked: whether an
 * invitation opens a closed server, that it is for one address, that the domain list is applied
 * to everybody else, which of the two follows a created account, and what the sign-in form says
 * to somebody who has not confirmed yet.
 */
class AccountInvitationRegistrationTest extends TestCase
{
    private InvitingAccount $c;

    /** @var array<string, mixed> */
    private array $saved = [];

    protected function setUp(): void
    {
        $_POST = [];
        $_GET  = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        foreach ([RegistrationPolicy::DOMAINS_SETTING, RegistrationPolicy::VERIFY_SETTING] as $name) {
            $this->saved[$name] = Settings::getSetting($name, null);
            Settings::setSetting($name, '', false);
        }
        $this->c = new InvitingAccount(null);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            Settings::setSetting($name, $value === null ? '' : (string) $value, false);
        }
        $_POST = [];
        $_GET  = [];
        $_SESSION = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    private function post(string $email): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'username' => 'maria', 'email' => $email,
            'password' => 'sup3rsecret!', 'confirm_password' => 'sup3rsecret!',
        ];
    }

    /**
     * The link opens a closed server for its address, and the form shows that address instead of
     * asking for it.
     */
    public function testALiveInvitationOpensAClosedServer(): void
    {
        // Arrange
        $_GET['invite'] = 'abc123';

        // Act
        $this->c->register();

        // Assert
        $this->assertSame('abc123', $_SESSION[Account::INVITATION_SESSION_KEY] ?? null);
        $this->assertTrue($this->c->view->props['registrationOpen']);
        $this->assertSame('maria@example.com', $this->c->view->props['invitedEmail']);
        $this->assertArrayNotHasKey('error', $this->c->view->props);
    }

    /** A token that is not hex is not kept, and an unknown one opens nothing. */
    public function testAForeignOrDeadTokenOpensNothing(): void
    {
        // Act
        $_GET['invite'] = '<script>';
        $this->c->register();
        $foreign = $_SESSION[Account::INVITATION_SESSION_KEY] ?? null;

        $_GET['invite'] = 'ffff';
        $this->c->register();

        // Assert
        $this->assertNull($foreign);
        $this->assertSame('registration_closed', $this->c->view->props['error']);
    }

    /** An invitation is for one address: registering another with its link is refused. */
    public function testAnotherAddressCannotUseTheInvitation(): void
    {
        // Arrange
        $_SESSION[Account::INVITATION_SESSION_KEY] = 'abc123';
        $this->post('someone.else@example.com');

        // Act
        $this->c->register();

        // Assert
        $this->assertSame('invite_email_mismatch', $this->c->view->props['error']);
        $this->assertSame([], $this->c->created);
    }

    /**
     * The invited address registers, the invitation is used for the new account, and no
     * confirmation is asked for — even under a domain list the address is outside of.
     */
    public function testTheInvitedAddressRegistersAndUsesTheInvitation(): void
    {
        // Arrange
        Settings::setSetting(RegistrationPolicy::DOMAINS_SETTING, 'other.org', false);
        $_SESSION[Account::INVITATION_SESSION_KEY] = 'abc123';
        $this->post('Maria@Example.com');

        // Act
        $this->c->register();

        // Assert
        $this->assertCount(1, $this->c->created);
        $this->assertSame([['abc123', 101]], $this->c->fakeInvitations->accepted);
        $this->assertSame([], $this->c->fakeVerification->begun, 'an invited address is already confirmed');
        $this->assertArrayNotHasKey(Account::INVITATION_SESSION_KEY, $_SESSION);
        $this->assertStringEndsWith('login', (string) $this->c->redirects[0]);
    }

    /**
     * Without an invitation, open registration keeps to the domain list — and an address inside it
     * is held back until it confirms.
     */
    public function testTheDomainListAppliesToOpenRegistration(): void
    {
        // Arrange
        $this->c->settings['auth_allow_registration'] = 'on';
        Settings::setSetting(RegistrationPolicy::DOMAINS_SETTING, 'example.com', false);

        // Act — outside the list
        $this->post('maria@elsewhere.org');
        $this->c->register();
        $outside = $this->c->view->props['error'] ?? null;

        // …and inside it
        $this->post('maria@example.com');
        $this->c->register();

        // Assert
        $this->assertSame('email_domain_not_allowed', $outside);
        $this->assertCount(1, $this->c->created);
        $this->assertSame([101], $this->c->fakeVerification->begun);
    }

    /** With no list and no verification setting, registration is what it was. */
    public function testWithNoPolicyNothingIsHeldBack(): void
    {
        // Arrange
        $this->c->settings['auth_allow_registration'] = 'on';
        $this->post('maria@anywhere.test');

        // Act
        $this->c->register();

        // Assert
        $this->assertCount(1, $this->c->created);
        $this->assertSame([], $this->c->fakeVerification->begun);
    }

    /** The confirmation link says what happened and sends to the sign-in form either way. */
    public function testTheConfirmationLinkReportsTheOutcome(): void
    {
        // Act
        $_GET['token'] = 'good';
        $this->c->confirmemail();
        $_GET['token'] = 'bad';
        $this->c->confirmemail();

        // Assert
        $this->assertSame(['good', 'bad'], $this->c->fakeVerification->confirmed);
        $this->assertCount(2, $this->c->redirects);
        $this->assertStringEndsWith('login', (string) $this->c->redirects[1]);
    }

    /**
     * The right password on an account still waiting to confirm is not a sign-in: no session, a
     * fresh link (when due), and a result the form can word — not "invalid credentials".
     */
    public function testAnUnconfirmedAccountDoesNotSignIn(): void
    {
        // Arrange
        $flow = new class extends \Pramnos\Tests\Unit\Auth\TestableLoginFlow {
            public array $resent = [];

            protected function emailVerification(): \Pramnos\Auth\EmailVerification
            {
                $outer = $this;

                return new class ($outer) extends \Pramnos\Auth\EmailVerification {
                    public function __construct(private object $outer)
                    {
                    }

                    public function isPending(int $userId): bool
                    {
                        return true;
                    }

                    public function resendIfDue(int $userId): bool
                    {
                        $this->outer->resent[] = $userId;

                        return true;
                    }
                };
            }
        };
        $flow->fakeAuth->response = ['status' => true, 'uid' => 7, 'username' => 'alice', 'email' => 'a@b.c', 'auth' => 'hash'];

        // Act
        $result = $flow->attempt('alice', 'secret', false);

        // Assert
        $this->assertTrue($result->isEmailUnverified());
        $this->assertSame(7, $result->userId);
        $this->assertSame([], $flow->fakeAuth->loginArgs, 'no session may be established');
        $this->assertSame([7], $flow->resent);
    }

    /**
     * With the real services and no database behind them, a remembered invitation opens nothing
     * and a malformed confirmation link confirms nothing — neither raises into the page.
     */
    public function testTheRealServicesFailQuietlyWithoutADatabase(): void
    {
        // Arrange
        $account = new class (null) extends RegisteringAccount {
            public function open(): bool
            {
                return Account::registrationIsOpen();
            }

            protected function setting(string $key): string
            {
                return '';
            }

            public function addMessage($message)
            {
            }

            public function addError($error)
            {
            }
        };
        $_SESSION[Account::INVITATION_SESSION_KEY] = 'abc123';

        // Act
        $open = $account->open();
        $_GET['token'] = 'not-hex';
        $account->confirmemail();

        // Assert
        $this->assertFalse($open);
        $this->assertStringEndsWith('login', (string) $account->redirects[0]);
    }

    /** Both mails expose the link they carry, which is what a test or a log line reads. */
    public function testTheMailsExposeTheirLink(): void
    {
        // Act
        $invitation = new \Pramnos\Auth\Notifications\InvitationNotification('https://x/register?invite=a', 7200, 'Anna', 'Hi');
        $verify     = new \Pramnos\Auth\Notifications\VerifyEmailNotification('https://x/register/confirmemail?token=a', 7200, 'maria');

        // Assert
        $this->assertSame('https://x/register?invite=a', $invitation->link());
        $this->assertSame('https://x/register/confirmemail?token=a', $verify->link());
        $this->assertSame(['mail'], $invitation->via(null));
        $this->assertTrue($invitation->queueable());
        $this->assertStringContainsString('Anna', $invitation->toMail(null)['body']);
        $this->assertSame(2, $invitation->storedMailTemplate()['vars']['hours']);
        $this->assertStringContainsString('confirmemail', $verify->toMail(null)['body']);
        $this->assertSame('auth.verify_email', $verify->storedMailTemplate()['category']);
    }

    /** The sign-in form is told why, by a key every theme words. */
    public function testTheFormIsToldTheAddressIsUnconfirmed(): void
    {
        // Arrange
        $account = new class (null) extends TestableAccount {
            public function present(\Pramnos\Auth\LoginFlowResult $r): mixed
            {
                return $this->presentResult($r, 'alice');
            }
        };

        // Act
        $account->present(\Pramnos\Auth\LoginFlowResult::emailUnverified(7));

        // Assert
        $this->assertSame('email_unverified', $account->view->props['error']);
    }
}

/** Registration with the invitation and confirmation services faked. */
class InvitingAccount extends RegisteringAccount
{
    /** @var array<string, string> */
    public array $settings = [];

    public object $fakeInvitations;
    public object $fakeVerification;

    public function __construct(?\Pramnos\Application\Application $application = null)
    {
        parent::__construct($application);
        $this->fakeInvitations = new class extends \Pramnos\Auth\Invitations {
            /** @var list<array{0: string, 1: int}> */
            public array $accepted = [];

            public function find(string $token): ?array
            {
                return $token === 'abc123' ? ['invitation_id' => 1, 'email' => 'maria@example.com'] : null;
            }

            public function accept(string $token, int $userId): ?array
            {
                $this->accepted[] = [$token, $userId];

                return ['invitation_id' => 1];
            }
        };
        $this->fakeVerification = new class extends \Pramnos\Auth\EmailVerification {
            /** @var list<int> */
            public array $begun = [];
            /** @var list<string> */
            public array $confirmed = [];

            public function begin(int $userId): bool
            {
                $this->begun[] = $userId;

                return true;
            }

            public function confirm(string $token): ?int
            {
                $this->confirmed[] = $token;

                return $token === 'good' ? 101 : null;
            }
        };
    }

    // The real decision: the setting, or a live invitation.
    protected function registrationIsOpen(): bool
    {
        return Account::registrationIsOpen();
    }

    protected function setting(string $key): string
    {
        return $this->settings[$key] ?? '';
    }

    protected function invitations(): \Pramnos\Auth\Invitations
    {
        return $this->fakeInvitations;
    }

    protected function emailVerification(): \Pramnos\Auth\EmailVerification
    {
        return $this->fakeVerification;
    }

    public function addMessage($message)
    {
    }

    public function addError($error)
    {
    }
}
