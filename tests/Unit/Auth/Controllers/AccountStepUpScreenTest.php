<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\EmailSecondFactor;
use Pramnos\Auth\LoginFlow;
use Pramnos\Auth\LoginFlowResult;

/**
 * The screen between the password and the session — `Account::verify()`, 13 statements unexecuted.
 *
 * A unit test, deliberately. Everything this action decides it decides from `$_POST` and the
 * answer of a `LoginFlow`, and the flow is the part that talks to a database — which has its own
 * tests on both backends. Doubling it here leaves the branching, which is the whole of what was
 * uncovered, and adds no connection to a class that would learn nothing from one.
 *
 * Three of the branches are load-bearing:
 *
 *   - **sending a code is a POST.** A GET that sends mail is one a crawler, a link preview or a
 *     back button can fire, and every firing invalidates the code the person is already holding.
 *     A screen that sent on arrival would be unusable in exactly the browsers that prefetch.
 *   - **the factor is named by the form.** Codes are all six digits, and trying each configured
 *     factor in turn would consume one attempt of every *other* factor every time somebody typed
 *     one — locking a person out of their authenticator by mistyping an emailed code.
 *   - **a wrong code keeps the pending login.** The alternative sends somebody back to the
 *     password form for a typo, and the second attempt looks to the lockout like a new sign-in.
 */
#[CoversClass(Account::class)]
class AccountStepUpScreenTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
        $_GET  = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\RequestIdentity::reset();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        $_SESSION = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\RequestIdentity::reset();
    }

    // ── Getting to the screen at all ──────────────────────────────────────────

    /**
     * With no half-finished login, the answer is the sign-in form and not the code form.
     *
     * A code form with nobody behind it would collect six digits and have nothing to check them
     * against, and the person would try again rather than start again. Saying the session expired
     * is the only answer that leads anywhere.
     */
    public function testWithNoPendingLoginTheSignInFormComesBack(): void
    {
        // Arrange
        $probe = $this->probe(pending: null);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame('login', $probe->rendered[0]['view'] ?? null);
        $this->assertSame('session_expired', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame([], $probe->flow->sentFactors, 'a code was sent with nobody signing in');
    }

    /**
     * A GET renders the screen and sends nothing.
     *
     * The property the "send me a code" button is a POST for. Sending on arrival means a
     * prefetch, an unfurled link or the back button each mint a new code and invalidate the one
     * in the person's hand — which presents as codes that never work.
     */
    public function testAGetSendsNothing(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $probe->verify();

        // Assert
        $this->assertSame('stepup', $probe->rendered[0]['view'] ?? null);
        $this->assertSame([], $probe->rendered[0]['ctx']);
        $this->assertSame([], $probe->flow->sentFactors);
        $this->assertSame(0, $probe->flow->authLinksSent);
    }

    /**
     * A GET carrying the send parameters still sends nothing.
     *
     * The half that matters: a URL somebody can be made to visit must not be able to mint a
     * code, and the parameters being present is exactly the case a link would carry.
     */
    public function testAGetCarryingTheSendParametersStillSendsNothing(): void
    {
        // Arrange
        $probe = $this->probe();
        $_GET  = ['send_factor' => EmailSecondFactor::METHOD, 'send_auth_link' => '1'];
        $_POST = ['send_factor' => EmailSecondFactor::METHOD, 'send_auth_link' => '1'];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // Act
        $probe->verify();

        // Assert
        $this->assertSame([], $probe->flow->sentFactors, 'a GET sent a code');
        $this->assertSame(0, $probe->flow->authLinksSent, 'a GET sent a sign-in link');
    }

    /** A POST without the anti-CSRF token does nothing but re-render. */
    public function testAPostWithoutTheTokenDoesNothing(): void
    {
        // Arrange
        $probe = $this->probe(csrf: false);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['send_factor' => EmailSecondFactor::METHOD];

        // Act
        $probe->verify();

        // Assert
        $this->assertSame('invalid_token', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame([], $probe->flow->sentFactors);
    }

    // ── Asking for something to be sent ───────────────────────────────────────

    /** A code is sent when asked for by name, and the screen says so. */
    public function testACodeIsSentWhenAskedForByName(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->post(['send_factor' => EmailSecondFactor::METHOD]);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame([EmailSecondFactor::METHOD], $probe->flow->sentFactors);
        $this->assertSame('email_code_sent', $probe->rendered[0]['ctx']['notice'] ?? null);
    }

    /**
     * The older field name still works, because in-flight pages carry it.
     *
     * A form rendered before the factor-by-name change is sitting in somebody's browser while
     * the deploy happens. Accepting only the new name would refuse their next press with no
     * explanation, and the fix on their side is to reload a page they cannot know to reload.
     */
    public function testTheOlderFieldNameStillAsksForTheEmailedCode(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->post(['send_email_code' => '1']);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame([EmailSecondFactor::METHOD], $probe->flow->sentFactors);
    }

    /**
     * A refused send inside the resend window says how long to wait, and how long.
     *
     * "We could not send it" invites another press; "you can ask again in 40 seconds" does not.
     * The seconds go into the context so the screen can count down rather than repeat itself.
     */
    public function testARefusedSendInsideTheWindowSaysHowLong(): void
    {
        // Arrange
        $probe = $this->probe(sendSucceeds: false, resendIn: 40);
        $this->post(['send_factor' => EmailSecondFactor::METHOD]);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame('email_code_wait', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame(40, $probe->rendered[0]['ctx']['resendIn'] ?? null);
    }

    /** A refusal with no wait left is a failure, and worded as one. */
    public function testARefusedSendWithNoWaitIsAFailure(): void
    {
        // Arrange
        $probe = $this->probe(sendSucceeds: false, resendIn: 0);
        $this->post(['send_factor' => EmailSecondFactor::METHOD]);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame('email_code_failed', $probe->rendered[0]['ctx']['error'] ?? null);
    }

    /** The sign-in link is its own request, and reports its own outcome. */
    public function testTheSignInLinkCanBeAskedForAndReportsBothOutcomes(): void
    {
        // Arrange & Act
        $sent = $this->probe();
        $this->post(['send_auth_link' => '1']);
        $sent->verify();

        $failed = $this->probe(authLinkSucceeds: false);
        $this->post(['send_auth_link' => '1']);
        $failed->verify();

        // Assert
        $this->assertSame(1, $sent->flow->authLinksSent);
        $this->assertSame('auth_link_sent', $sent->rendered[0]['ctx']['notice'] ?? null);
        $this->assertSame('auth_link_failed', $failed->rendered[0]['ctx']['error'] ?? null);
    }

    // ── Answering with a code ─────────────────────────────────────────────────

    /** No code and no send request is a prompt for the code, not an attempt at one. */
    public function testAnEmptySubmissionAsksForTheCode(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->post(['code' => '']);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame('missing_code', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame([], $probe->flow->completions, 'an empty code was checked against a factor');
    }

    /**
     * The code goes to exactly one factor, and the form says which.
     *
     * Every factor's code is six digits. Trying each in turn would spend one attempt of every
     * *other* factor on each press — so mistyping an emailed code three times would lock the
     * authenticator too, and the person would be told their app was wrong.
     */
    public function testTheCodeGoesToExactlyTheNamedFactor(): void
    {
        // Arrange
        $probe = $this->probe();
        $this->post(['code' => '123456', 'method' => 'passkey-ish-adaptor']);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame(
            [['passkey-ish-adaptor', '123456']],
            $probe->flow->completions,
            'the code was tried against more than the factor the form named'
        );
    }

    /**
     * An unnamed factor, and the two names the authenticator answers to, all mean TOTP.
     *
     * `twofactor` is what a step-up list calls it and `totp` is what the registry calls it. A
     * form that used the other name would otherwise be looked up as an application's own factor,
     * not found, and refused — with the code the person read off their phone.
     */
    public function testTheAuthenticatorAnswersToBothOfItsNames(): void
    {
        // Act & Assert
        foreach (['', 'twofactor', 'totp'] as $name) {
            $probe = $this->probe();
            $this->post(['code' => '123456', 'method' => $name]);
            $probe->verify();

            $this->assertSame(
                ['123456'],
                $probe->flow->twoFactorCodes,
                'the authenticator was not asked for method ' . var_export($name, true)
            );
            $this->assertSame([], $probe->flow->completions);
        }
    }

    /** A correct code finishes the sign-in and sends the person where they were going. */
    public function testACorrectCodeFinishesTheSignIn(): void
    {
        // Arrange
        $probe = $this->probe(codeIsCorrect: true);
        $this->post(['code' => '123456']);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame([], $probe->rendered, 'the screen was re-rendered after a correct code');
        $this->assertNotSame([], $probe->redirects);
    }

    /**
     * A wrong code re-renders and leaves the half-login in place.
     *
     * Dropping the pending state on a wrong code would send somebody back to the password form
     * for a typo — and the second password attempt looks to the lockout like a new sign-in, so
     * three typos on the code become three failed logins.
     */
    public function testAWrongCodeKeepsThePendingLogin(): void
    {
        // Arrange
        $probe = $this->probe(codeIsCorrect: false);
        $this->post(['code' => '000000']);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame('invalid_code', $probe->rendered[0]['ctx']['error'] ?? null);
        $this->assertSame(0, $probe->flow->cancellations, 'a wrong code threw the half-login away');
    }

    /** Somebody already signed in is sent on rather than asked for a code. */
    public function testAnAlreadySignedInVisitorIsSentOn(): void
    {
        // Arrange
        $probe = $this->probe(currentUser: 42);

        // Act
        $probe->verify();

        // Assert
        $this->assertSame([], $probe->rendered);
        $this->assertNotSame([], $probe->redirects);
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with the flow doubled and the render recorded.
     *
     * `flow()` is the seam the action reaches everything through, so a double there covers the
     * database, the mailer and the factor registry at once — each of which is somebody else's
     * subject. What is left is the branching, and that is what had never run.
     */
    private function probe(
        ?int $pending = 7,
        bool $csrf = true,
        bool $sendSucceeds = true,
        bool $authLinkSucceeds = true,
        int $resendIn = 0,
        bool $codeIsCorrect = false,
        ?int $currentUser = null
    ): object {
        $flow = new class ($pending, $sendSucceeds, $authLinkSucceeds, $resendIn, $codeIsCorrect)
            extends LoginFlow {
            /** @var list<string> factors a challenge was sent for */
            public array $sentFactors = [];

            /** @var list<array{0: string, 1: string}> named-factor completion attempts */
            public array $completions = [];

            /** @var list<string> codes offered to the authenticator */
            public array $twoFactorCodes = [];

            public int $authLinksSent = 0;

            public int $cancellations = 0;

            public function __construct(
                private ?int $pending,
                private bool $sendSucceeds,
                private bool $authLinkSucceeds,
                private int $resendIn,
                private bool $codeIsCorrect
            ) {
            }

            public function pendingUserId(): ?int
            {
                return $this->pending;
            }

            public function pendingFactors(): array
            {
                return [];
            }

            public function sendFactorChallenge(string $factorName): bool
            {
                $this->sentFactors[] = $factorName;

                return $this->sendSucceeds;
            }

            public function sendAuthLink(string $returnUrl = ''): bool
            {
                $this->authLinksSent++;

                return $this->authLinkSucceeds;
            }

            public function secondsUntilResend(): int
            {
                return $this->resendIn;
            }

            public function completeTwoFactor(string $code): LoginFlowResult
            {
                $this->twoFactorCodes[] = $code;

                return $this->codeIsCorrect
                    ? LoginFlowResult::success((int) $this->pending)
                    : LoginFlowResult::failed();
            }

            public function completeFactor(string $factorName, string $code): LoginFlowResult
            {
                $this->completions[] = [$factorName, $code];

                return $this->codeIsCorrect
                    ? LoginFlowResult::success((int) $this->pending)
                    : LoginFlowResult::failed();
            }

            public function cancel(): void
            {
                $this->cancellations++;
            }
        };

        return new class ($flow, $csrf, $currentUser) extends Account {
            /** @var list<array{view: string, ctx: array}> */
            public array $rendered = [];

            public array $redirects = [];

            public object $flow;

            public function __construct(object $flow, private bool $csrf, private ?int $user)
            {
                $this->flow = $flow;
            }

            protected function flow(): LoginFlow
            {
                return $this->flow;
            }

            protected function currentUserId(): ?int
            {
                return $this->user;
            }

            protected function checkCsrf(): bool
            {
                return $this->csrf;
            }

            protected function renderStepUp(array $ctx): mixed
            {
                $this->rendered[] = ['view' => 'stepup', 'ctx' => $ctx];

                return null;
            }

            protected function renderLogin(array $ctx): mixed
            {
                $this->rendered[] = ['view' => 'login', 'ctx' => $ctx];

                return null;
            }

            protected function returnUrl(): string
            {
                return '';
            }

            protected function postLoginTarget(string $return): string
            {
                return 'somewhere';
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }
        };
    }

    /** @param array<string, string> $fields */
    private function post(array $fields): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $fields;
    }
}
