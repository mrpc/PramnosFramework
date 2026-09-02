<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\Controllers\Account;

/**
 * What happens to the sign-in form when the proof-of-work service breaks.
 *
 * Two arms, and they disagree on purpose — which is the whole reason to pin them:
 *
 * - **minting a challenge fails → the page still renders**, without one. The comment says a check
 *   that cannot be minted must not take the form down with it.
 * - **verifying a submission fails → the submission is refused.** Fails closed, deliberately: a
 *   check that let a submission through when verification broke would be bypassable by breaking
 *   verification, and an absent challenge is treated as a failure for the same reason — otherwise
 *   advertising an old user agent would be enough to skip it.
 *
 * Neither arm had ever run. Both are reached through `humanCheck()`, which exists so that they can:
 * the two `new \Pramnos\Security\HumanCheck()` expressions they replaced were inside the very
 * `try` blocks under test.
 *
 * Note what the two arms add up to, which is asserted here rather than argued: with the service
 * down, the form renders with no challenge and then refuses every submission that arrives without
 * one. The page is up and nobody can sign in.
 */
#[CoversClass(Account::class)]
class HumanCheckFailureModesTest extends TestCase
{
    private mixed $savedAuth = null;

    private bool $hadAuth = false;

    protected function setUp(): void
    {
        parent::setUp();

        $app = Application::getInstance();
        $this->hadAuth   = isset($app->applicationInfo['auth']);
        $this->savedAuth = $app->applicationInfo['auth'] ?? null;

        // The policy reads this, and every arm below is behind `humanChecks($form)`.
        $app->applicationInfo['auth'] = ['security' => ['human_check' => true]];
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();
        if ($this->hadAuth) {
            $app->applicationInfo['auth'] = $this->savedAuth;
        } else {
            unset($app->applicationInfo['auth']);
        }

        parent::tearDown();
    }

    /**
     * A controller whose proof-of-work service does what the test needs.
     *
     * @param array<string, string> $posted What `post()` should answer
     */
    private function controller(?\Throwable $failure, array $posted = []): object
    {
        return new class ($failure, $posted) extends Account {
            public function __construct(
                private readonly ?\Throwable $failure,
                private readonly array $posted
            ) {
                parent::__construct();
            }

            protected function humanCheck(): \Pramnos\Security\HumanCheck
            {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                return new \Pramnos\Security\HumanCheck();
            }

            protected function post(string $key, bool $trim = true): string
            {
                return $this->posted[$key] ?? '';
            }

            public function mintChallenge(string $form): ?array
            {
                return $this->humanCheckChallenge($form);
            }

            public function checkPasses(string $form): bool
            {
                return $this->humanCheckPasses($form);
            }
        };
    }

    /**
     * A challenge that cannot be minted gives `null`, not an exception.
     *
     * The form renders either way; what an exception here would do is take the sign-in page down
     * entirely, which is a worse outage than a check nobody can solve.
     */
    public function testAChallengeThatCannotBeMintedIsNull(): void
    {
        // Arrange
        $controller = $this->controller(new \RuntimeException('no entropy source'));

        // Act
        $challenge = $controller->mintChallenge('login');

        // Assert
        $this->assertNull($challenge);
    }

    /**
     * A verification that raises refuses the submission.
     *
     * The asymmetry with the test above, and the right way round: a check whose verification is
     * broken must not accept, or breaking verification becomes the bypass.
     */
    public function testAVerificationThatRaisesRefusesTheSubmission(): void
    {
        // Arrange — a submission that carries both fields, so it reaches the verify call
        $controller = $this->controller(
            new \RuntimeException('verifier unavailable'),
            ['human_challenge' => 'some-challenge', 'human_solution' => 'some-solution']
        );

        // Act
        $passes = $controller->checkPasses('login');

        // Assert
        $this->assertFalse($passes, 'a broken verifier accepted a submission');
    }

    /**
     * A submission with nothing in it is refused without asking the service.
     *
     * Which is what makes the check unskippable: a client that simply omits the fields — the first
     * thing anything automating a form does — is refused here, before any verification runs. The
     * service is not even constructed, so this holds when it is down too.
     */
    public function testASubmissionWithNoChallengeIsRefusedWithoutAskingTheService(): void
    {
        // Arrange — the service would raise if it were reached
        $controller = $this->controller(new \RuntimeException('should not be reached'), []);

        // Act
        $passes = $controller->checkPasses('login');

        // Assert
        $this->assertFalse($passes);
    }

    /**
     * Half a submission is no submission.
     *
     * Each field on its own, because a check on only one of them would let the other be dropped.
     */
    public function testHalfASubmissionIsAlsoRefused(): void
    {
        // Arrange
        $onlyChallenge = $this->controller(null, ['human_challenge' => 'c', 'human_solution' => '']);
        $onlySolution  = $this->controller(null, ['human_challenge' => '', 'human_solution' => 's']);

        // Act + Assert
        $this->assertFalse($onlyChallenge->checkPasses('login'));
        $this->assertFalse($onlySolution->checkPasses('login'));
    }

    /**
     * A form the policy does not gate needs no check, and never asks for one.
     *
     * The early return that lets the call sites read as a single condition — and it has to come
     * before the service is touched, or turning the feature off would still break when the service
     * does.
     */
    public function testAFormWithoutAHumanCheckPassesAndMintsNothing(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['auth'] = [
            'security' => ['human_check' => ['login' => false, 'register' => true]],
        ];
        $controller = $this->controller(new \RuntimeException('should not be reached'));

        // Act + Assert
        $this->assertTrue($controller->checkPasses('login'), 'an ungated form should pass');
        $this->assertNull($controller->mintChallenge('login'), 'an ungated form has no challenge');
    }

    /**
     * With the service working, a minted challenge is a real one.
     *
     * The control for every test above: without this they would all pass against a `humanCheck()`
     * that simply never worked.
     */
    public function testAWorkingServiceMintsAChallenge(): void
    {
        // Arrange
        $controller = $this->controller(null);

        // Act
        $challenge = $controller->mintChallenge('login');

        // Assert
        $this->assertIsArray($challenge);
        $this->assertNotEmpty($challenge, 'the challenge is empty, so the failure arms prove nothing');
    }
}
