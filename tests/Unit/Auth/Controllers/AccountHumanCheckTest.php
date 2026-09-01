<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Controllers\Account;
use Pramnos\Auth\SecurityPolicy;

/**
 * The proof-of-work check on the three public forms — 18 statements, never executed.
 *
 * Sign-in, registration and forgot-password are the three forms anybody on the internet may
 * submit, and each is abused differently: sign-in for credential stuffing, registration for junk
 * accounts, forgot-password to make the site deliver mail to an address somebody else typed. The
 * check is one policy switch per form, off by default, and everything below is about what happens
 * at the two moments that switch is consulted.
 *
 * **It fails closed.** A form that requires a check and submits nothing is refused — including from
 * a browser with no Web Worker, which is the interesting case: letting that through would make the
 * check bypassable by advertising an old user agent, and that is the first thing anybody automating
 * a form tries. So the absent-solution branch and the "not required" branch have to be asserted
 * separately, because a single mistake collapses them into each other and the check silently stops
 * existing.
 *
 * **It degrades open at mint time, and that is not a contradiction.** A challenge that cannot be
 * minted must not take the form down: the page renders without one, and verification then treats
 * the absent challenge the same way it treats any other missing one. The asymmetry is deliberate —
 * failing to *offer* a check locks legitimate people out of their own account, failing to *demand*
 * one lets a script through, and only the second is recoverable by turning the switch off.
 *
 * No database: the policy is settings, the challenge is arithmetic, and the seams here are the two
 * `post()` values.
 */
#[CoversClass(Account::class)]
class AccountHumanCheckTest extends TestCase
{
    private array $originalInfo = [];

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }

        $application = \Pramnos\Application\Application::getInstance();
        $this->originalInfo = (array) $application->applicationInfo;
    }

    protected function tearDown(): void
    {
        $application = \Pramnos\Application\Application::currentInstance();

        if (is_object($application)) {
            $application->applicationInfo = $this->originalInfo;
        }
    }

    /**
     * Turn the check on for the named forms and off for the rest.
     *
     * Written where the policy actually reads it — `applicationInfo['auth']['security']`, which is
     * `app.php`, not a row in the settings table. That is deliberate in the framework and worth
     * knowing here: a check on a public form is part of how a deployment was built, not something a
     * screen can switch off. My first version of this file configured a *setting* and every
     * assertion passed vacuously, because the policy answered its default and the check was never
     * required at all.
     *
     * Configured through the real value rather than by stubbing `SecurityPolicy`, because which
     * forms carry a check is exactly what an installation writes, and the shape of that value has
     * three spellings — `true` means all three, an array means per form, anything else means none.
     */
    private function requireChecksOn(array $forms): void
    {
        $this->writePolicy([
            'login'    => in_array('login', $forms, true),
            'register' => in_array('register', $forms, true),
            'forgot'   => in_array('forgot', $forms, true),
        ]);
    }

    private function writePolicy(mixed $humanCheck): void
    {
        $application = \Pramnos\Application\Application::currentInstance();
        $info = (array) $application->applicationInfo;
        $auth = is_array($info['auth'] ?? null) ? $info['auth'] : [];
        $security = is_array($auth['security'] ?? null) ? $auth['security'] : [];

        $security['human_check'] = $humanCheck;
        $auth['security'] = $security;
        $info['auth'] = $auth;

        $application->applicationInfo = $info;
    }

    /** A controller whose two `post()` values this test supplies. */
    private function probe(string $challenge = '', string $solution = ''): object
    {
        return new class ($challenge, $solution) extends Account {
            public function __construct(private string $c, private string $s)
            {
            }

            public function callPasses(string $form): bool
            {
                return $this->humanCheckPasses($form);
            }

            public function callChallenge(string $form): ?array
            {
                return $this->humanCheckChallenge($form);
            }

            protected function post(string $key, bool $trim = true): string
            {
                return match ($key) {
                    'human_challenge' => $this->c,
                    'human_solution'  => $this->s,
                    default           => '',
                };
            }
        };
    }

    // ── When the form does not carry a check ──────────────────────────────────

    /**
     * With the check off, an empty submission passes and no challenge is offered.
     *
     * The default for every installation, and the reason `humanCheckPasses()` answers true rather
     * than "not applicable": the call sites read as a single condition, so a method that returned
     * something else would need every one of them to special-case it — and one that forgot would
     * refuse every submission on a site that never turned the feature on.
     */
    public function testWithTheCheckOffNothingIsRequiredAndNothingIsOffered(): void
    {
        // Arrange
        $this->requireChecksOn([]);
        $probe = $this->probe();

        // Act & Assert
        foreach (['login', 'register', 'forgot'] as $form) {
            $this->assertTrue($probe->callPasses($form), $form . ' was refused with the check off');
            $this->assertNull($probe->callChallenge($form), $form . ' was offered a challenge');
        }
    }

    /**
     * The switch is per form.
     *
     * The three forms are abused differently and cost differently to protect — a check on sign-in
     * is paid by everybody who signs in, and one on forgot-password by the few people who forget.
     * An installation that turned it on everywhere because it could not turn it on for one form
     * would be paying for the expensive case to get the cheap one.
     */
    public function testTheSwitchIsPerForm(): void
    {
        // Arrange
        $this->requireChecksOn(['forgot']);
        $probe = $this->probe();

        // Act & Assert
        $this->assertTrue($probe->callPasses('login'), 'login inherited the forgot form\'s check');
        $this->assertTrue($probe->callPasses('register'));
        $this->assertFalse($probe->callPasses('forgot'), 'the form that was switched on was not checked');

        $this->assertNull($probe->callChallenge('login'));
        $this->assertIsArray($probe->callChallenge('forgot'), 'the checked form got no challenge');
    }

    /**
     * `human_check: true` means all three.
     *
     * The shorthand an installation actually writes, and it has to reach the same place as the
     * array form — a value read as "not an array, therefore none" would switch the whole feature
     * off for everybody who used the short spelling, silently.
     */
    public function testTheShorthandTurnsOnAllThree(): void
    {
        // Arrange
        $this->writePolicy(true);
        $probe = $this->probe();

        // Act & Assert
        foreach (['login', 'register', 'forgot'] as $form) {
            $this->assertFalse($probe->callPasses($form), $form . ' was not covered by the shorthand');
        }
    }

    // ── When it does ──────────────────────────────────────────────────────────

    /**
     * A challenge is minted, and it carries what a client needs to solve it.
     *
     * A challenge with nothing in it renders a form nobody can submit — the page looks fine and
     * every submission is refused, which is the worst way for this feature to fail because it looks
     * like the credentials are wrong.
     */
    public function testAChallengeIsMintedForACheckedForm(): void
    {
        // Arrange
        $this->requireChecksOn(['login']);

        // Act
        $challenge = $this->probe()->callChallenge('login');

        // Assert
        $this->assertIsArray($challenge);
        $this->assertNotSame([], $challenge, 'an empty challenge is a form nobody can submit');
    }

    /**
     * Submitting nothing is refused — the fails-closed property.
     *
     * A browser with no Web Worker submits exactly this: both fields empty. Letting it through
     * would make the check bypassable by advertising an old user agent, and that is the first thing
     * anybody automating a form does. All three shapes of "nothing" are asserted, because a check
     * written as `if (!$challenge || !$solution)` and one written against `''` differ on `'0'`.
     */
    public function testSubmittingNothingIsRefused(): void
    {
        // Arrange
        $this->requireChecksOn(['login']);

        // Act & Assert
        $this->assertFalse($this->probe('', '')->callPasses('login'), 'both fields empty passed');
        $this->assertFalse(
            $this->probe('a-challenge', '')->callPasses('login'),
            'a challenge with no solution passed'
        );
        $this->assertFalse(
            $this->probe('', 'a-solution')->callPasses('login'),
            'a solution with no challenge passed'
        );
    }

    /**
     * A solution that does not belong to the challenge is refused.
     *
     * Which is the check doing its job: the pair is verified rather than merely present, so
     * submitting two arbitrary strings — what a script does once it notices the fields exist — does
     * not get through.
     */
    public function testAWrongSolutionIsRefused(): void
    {
        // Arrange
        $this->requireChecksOn(['login']);

        // Act & Assert
        $this->assertFalse(
            $this->probe('not-a-real-challenge', '12345')->callPasses('login'),
            'an invented pair was accepted'
        );
    }

    /**
     * A challenge that cannot be verified is refused rather than raised.
     *
     * A malformed challenge reaches `verify()` from a form somebody has been editing, or from a
     * page that sat open across a deploy. The answer is a refused submission with the form
     * redisplayed — an exception out of the sign-in POST is a 500 on the login page, which is
     * indistinguishable from the site being down.
     */
    public function testAnUnverifiableChallengeIsRefusedNotRaised(): void
    {
        // Arrange
        $this->requireChecksOn(['forgot']);

        // Act & Assert — deliberately not a challenge shape at all
        $this->assertFalse(
            $this->probe(str_repeat('%', 200), 'x')->callPasses('forgot'),
            'a malformed challenge raised out of the form instead of being refused'
        );
    }

    /**
     * A real challenge solved correctly passes.
     *
     * The one that makes all the refusals above meaningful: without it they would be satisfied by a
     * check that refuses everything, which is a site nobody can sign in to.
     */
    public function testASolvedChallengePasses(): void
    {
        // Arrange
        $this->requireChecksOn(['login']);
        $check     = new \Pramnos\Security\HumanCheck();
        $challenge = $check->challenge();

        $token    = (string) ($challenge['challenge'] ?? '');
        $solution = $this->solve($check, $challenge);

        $this->assertNotSame('', $token, 'the challenge carries no token for a client to return');
        $this->assertNotNull($solution, 'the challenge could not be solved within the search bound');

        // Act & Assert
        $this->assertTrue(
            $this->probe($token, $solution)->callPasses('login'),
            'a correctly solved challenge was refused, so the form cannot be submitted at all'
        );
    }

    /**
     * Solve the challenge the way the browser's worker does.
     *
     * The work is checked with `HumanCheck::meetsDifficulty()`, which is public, rather than
     * reimplementing the leading-zero-bit count here: a solver with its own copy of that rule can
     * disagree with the one being tested, and then this test asserts the two agree with each other
     * rather than that a real solution is accepted.
     *
     * Bounded, and answering null rather than a guess when it runs out — a test that "solved" it
     * wrongly would assert the opposite of what it claims.
     */
    private function solve(\Pramnos\Security\HumanCheck $check, array $challenge): ?string
    {
        $token = (string) ($challenge['challenge'] ?? '');
        $parts = explode('.', $token);

        if (count($parts) !== 4) {
            return null;
        }

        $payload = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
        $bits    = (int) ($challenge['difficulty'] ?? $parts[1]);

        if ($bits < 1) {
            return null;
        }

        for ($i = 0; $i < 5000000; $i++) {
            if ($check->meetsDifficulty($payload, (string) $i, $bits)) {
                return (string) $i;
            }
        }

        return null;
    }
}
