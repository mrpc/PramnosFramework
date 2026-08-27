<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Auth\SecurityPolicy;

/**
 * Which public auth forms carry the proof-of-work check, and how it fails.
 *
 * `HumanCheck` existed, was documented, had a client script — and nothing used it. These
 * pin the wiring: which forms it applies to, and the two failure directions.
 *
 * The direction that matters is **closed**. A form that requires a check and submits no
 * solution must be refused, including from a browser with no Web Worker — letting that
 * through would make the check bypassable by advertising an old user agent, which is the
 * first thing anybody automating a form does. The opposite mistake, failing open on a
 * missing challenge, is the one that makes a control look present and do nothing.
 */
#[CoversClass(SecurityPolicy::class)]
class HumanCheckOnAuthFormsTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private ?array $savedInstances = null;

    protected function tearDown(): void
    {
        if ($this->savedInstances !== null) {
            (new \ReflectionProperty(Application::class, 'appInstances'))
                ->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }

        $_POST = [];
        parent::tearDown();
    }

    private function withHumanCheck(mixed $configured): void
    {
        $stub = new class extends Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = ['auth' => ['security' => ['human_check' => $configured]]];

        $reflection = new \ReflectionProperty(Application::class, 'appInstances');
        $this->savedInstances = $reflection->getValue() ?? [];
        $instances = $this->savedInstances;
        $instances['default'] = $stub;
        $reflection->setValue(null, $instances);
    }

    /**
     * Off by default: none of the forms carry one.
     *
     * It burns a little battery on every visitor's device, and an application with no spam
     * problem should not be spending that.
     */
    public function testNoFormCarriesACheckByDefault(): void
    {
        // Arrange
        $this->withHumanCheck(false);

        // Act & Assert
        $this->assertSame(
            ['login' => false, 'register' => false, 'forgot' => false],
            SecurityPolicy::humanCheckForms()
        );
    }

    /**
     * `true` means all three.
     */
    public function testTrueMeansEveryForm(): void
    {
        // Arrange
        $this->withHumanCheck(true);

        // Act & Assert
        foreach (['login', 'register', 'forgot'] as $form) {
            $this->assertTrue(SecurityPolicy::humanChecks($form), $form . ' must carry a check');
        }
    }

    /**
     * A form can be named on its own.
     *
     * The likely configuration: registration and password-reset are public writes that
     * cost the site mail, and an application may not want the cost on every sign-in.
     */
    public function testFormsCanBeChosenIndividually(): void
    {
        // Arrange
        $this->withHumanCheck(['register' => true, 'forgot' => true]);

        // Act & Assert
        $this->assertTrue(SecurityPolicy::humanChecks('register'));
        $this->assertTrue(SecurityPolicy::humanChecks('forgot'));
        $this->assertFalse(SecurityPolicy::humanChecks('login'));
    }

    /**
     * A submission with no solution is refused when the form requires one.
     *
     * The fails-closed test, exercised through the controller's own helper.
     */
    public function testAMissingSolutionIsRefused(): void
    {
        // Arrange
        $this->withHumanCheck(['login' => true]);
        $account = new HumanCheckProbe();
        $_POST = [];

        // Act & Assert
        $this->assertFalse($account->passes('login'));

        // …and a challenge with no solution is refused too
        $_POST = ['human_challenge' => 'something', 'human_solution' => ''];
        $this->assertFalse($account->passes('login'));
    }

    /**
     * A form without a check passes without one, so call sites read as one condition.
     */
    public function testAFormWithoutACheckPasses(): void
    {
        // Arrange
        $this->withHumanCheck(['register' => true]);
        $account = new HumanCheckProbe();

        // Act & Assert
        $this->assertTrue($account->passes('login'));
        $this->assertFalse($account->passes('register'));
    }

    /**
     * A wrong solution is refused, and a right one is accepted.
     *
     * The round trip through the real `HumanCheck`: mint a challenge, solve it the way the
     * worker does, and hand both back. Difficulty is left at the default, which is a few
     * hundred milliseconds of work.
     */
    public function testASolvedChallengeIsAccepted(): void
    {
        // Arrange
        $this->withHumanCheck(['login' => true]);
        $account = new HumanCheckProbe();

        // A signing key both instances agree on. Without it each `new HumanCheck()` invents
        // its own, and the controller refuses a challenge this test minted correctly.
        \Pramnos\Application\Settings::setSetting('securitySalt', 'test-human-check-salt', false);

        $check     = new \Pramnos\Security\HumanCheck();
        $challenge = $check->challenge();

        $_POST = [
            'human_challenge' => $challenge['challenge'],
            'human_solution'  => 'definitely-not-a-solution',
        ];
        $this->assertFalse($account->passes('login'), 'a wrong nonce must be refused');

        // Act — search for a nonce the way the client's worker does.
        //
        // The payload is the challenge *without* its signature: the client strips the last
        // dot-separated field (see pf-humancheck.js), and hashing the whole token instead
        // produces a solution that satisfies `meetsDifficulty` for the wrong string and is
        // then refused by `verify()`. Which is exactly what this test did on the first
        // attempt. `BaseTestCase::solvedHumanCheckFields()` is the same search, for the
        // application tests that need it against a real request.
        $payload  = implode('.', array_slice(explode('.', (string) $challenge['challenge']), 0, 3));
        $bits     = (int) $challenge['difficulty'];
        $solution = null;
        for ($nonce = 0; $nonce < 5000000; $nonce++) {
            $candidate = base_convert((string) $nonce, 10, 36);
            if ($check->meetsDifficulty($payload, $candidate, $bits)) {
                $solution = $candidate;
                break;
            }
        }

        // Assert
        $this->assertNotNull($solution, 'the challenge must be solvable');
        $_POST['human_solution'] = $solution;
        $this->assertTrue($account->passes('login'));

        // …and it is single-use: the same pair cannot be replayed
        $this->assertFalse($account->passes('login'), 'a solved challenge is spent');
    }
}

/**
 * Exposes the controller's protected check so it can be asserted without a request.
 */
class HumanCheckProbe extends \Pramnos\Auth\Controllers\Account
{
    public function __construct()
    {
        // Deliberately not parent::__construct(): that registers actions against an
        // application this test does not have.
    }

    public function passes(string $form): bool
    {
        return $this->humanCheckPasses($form);
    }
}
