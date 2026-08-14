<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\Gate;
use Pramnos\Debug\Collectors\GateCollector;

/**
 * A user, as the gate sees one.
 */
class GateCollectorUser
{
    /**
     * @param int  $userid  Identity
     * @param bool $isAdmin Whether the "admin may do anything" hook fires
     */
    public function __construct(public int $userid = 1, public bool $isAdmin = false)
    {
    }
}

/**
 * A thing to be authorized against.
 */
class GateCollectorPost
{
    /**
     * @param int $userid Who owns it
     */
    public function __construct(public int $userid = 1)
    {
    }
}

/**
 * A policy, so the panel has a rule to name.
 */
class GateCollectorPostPolicy
{
    /**
     * Only the owner may update.
     *
     * @param GateCollectorUser|null $user The user asking
     * @param GateCollectorPost      $post The post
     * @return bool Whether they own it
     */
    public function update(?GateCollectorUser $user, GateCollectorPost $post): bool
    {
        return $user !== null && $user->userid === $post->userid;
    }
}

/**
 * The Gate panel — **which** rule decided, for every check a request made.
 *
 * The `Auth` tab answers who the request is and what convinced the server of it. Nothing
 * answered the next question, and that was not an oversight in the toolbar but a property of the
 * feature: a gate's rule is a closure in a bootstrap file, so it appears in no stack trace; a
 * `before` hook that returns `true` skips everything and leaves no mark; the SQL panel cannot
 * help because a decision may touch no database at all; and a 403 says something refused, not
 * which of six steps did.
 *
 * The step these tests care about most is `default`. `fallbackToPermissions()` is off by default,
 * so an ability nobody defined is silently refused — which makes a **typo in an ability name
 * indistinguishable from a deliberate deny**, because both produce `false`. The step is the only
 * thing that tells them apart.
 */
class GateCollectorTest extends TestCase
{
    /**
     * Every test starts from an empty gate with recording on.
     *
     * @return void
     */
    protected function setUp(): void
    {
        Gate::reset();
        Gate::enableDecisionLog();
    }

    /**
     * Leaves nothing behind — the registry and the log are both process-wide.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Gate::reset();
    }

    /**
     * Points the gate at a known user.
     *
     * @param GateCollectorUser|null $user Who the gate answers for
     * @return void
     */
    private function signIn(?GateCollectorUser $user): void
    {
        Gate::resolveUserUsing(static fn () => $user);
    }

    /**
     * Nothing is recorded until recording is switched on.
     *
     * This is the cost guarantee, and it is the reason the recorder is opt-in at all: an
     * application that never opens the toolbar pays one boolean check per decision, not an
     * array that grows for nobody. Same shape as `Database::enableQueryLog()`.
     */
    public function testNothingIsRecordedWhenTheLogIsOff(): void
    {
        // Arrange — reset() turns recording off as well as clearing
        Gate::reset();
        $this->signIn(new GateCollectorUser());
        Gate::define('read', fn () => true);

        // Act
        Gate::allows('read');

        // Assert
        $this->assertSame([], Gate::decisionLog());
    }

    /**
     * A named ability records its result and that it decided.
     */
    public function testANamedAbilityIsRecordedWithItsStep(): void
    {
        // Arrange
        $this->signIn(new GateCollectorUser(7));
        Gate::define('update-post', fn ($user, $post) => $user->userid === $post->userid);

        // Act
        Gate::allows('update-post', new GateCollectorPost(7));

        // Assert
        $log = Gate::decisionLog();
        $this->assertCount(1, $log);
        $this->assertSame('update-post', $log[0]['ability']);
        $this->assertTrue($log[0]['allowed']);
        $this->assertSame('ability', $log[0]['step']);
        $this->assertSame(GateCollectorPost::class, $log[0]['subject']);
        $this->assertSame(7, $log[0]['user']);
    }

    /**
     * A `before` hook is recorded as the thing that decided.
     *
     * The ability below would refuse, so recording `before` proves the panel reports the step
     * that *actually* answered rather than the one that would have.
     */
    public function testABeforeHookIsRecordedAsTheDecider(): void
    {
        // Arrange
        $this->signIn(new GateCollectorUser(1, isAdmin: true));
        Gate::define('update-post', fn () => false);
        Gate::before(fn ($user) => $user->isAdmin ? true : null);

        // Act
        Gate::allows('update-post');

        // Assert
        $log = Gate::decisionLog();
        $this->assertTrue($log[0]['allowed']);
        $this->assertSame('before', $log[0]['step']);
    }

    /**
     * A policy is recorded, and named.
     *
     * `PostPolicy::update` answers "which rule decided" in one line, which is the whole question
     * the decision order exists to make answerable.
     */
    public function testAPolicyIsRecordedAndNamed(): void
    {
        // Arrange
        $this->signIn(new GateCollectorUser(3));
        Gate::policy(GateCollectorPost::class, GateCollectorPostPolicy::class);

        // Act
        Gate::allows('update', new GateCollectorPost(3));

        // Assert
        $log = Gate::decisionLog();
        $this->assertSame('policy', $log[0]['step']);
        $this->assertSame('GateCollectorPostPolicy::update', $log[0]['detail']);
    }

    /**
     * An `after` hook that overrides is recorded as the decider.
     */
    public function testAnAfterHookThatOverridesIsRecorded(): void
    {
        // Arrange
        $this->signIn(new GateCollectorUser(5));
        Gate::define('update-post', fn () => false);
        Gate::after(fn ($user, $ability, $result) => $user->userid === 5 ? true : null);

        // Act
        Gate::allows('update-post');

        // Assert
        $log = Gate::decisionLog();
        $this->assertTrue($log[0]['allowed']);
        $this->assertSame('after', $log[0]['step']);
    }

    /**
     * An ability nobody defined is recorded as `default`.
     *
     * **This is the row the panel earns its place with.** A mistyped ability name and a
     * deliberate deny both produce `false`; only the step distinguishes them, and the collector
     * counts these separately so the tab can say so without being opened.
     */
    public function testAnUndefinedAbilityIsRecordedAsDefault(): void
    {
        // Arrange
        $this->signIn(new GateCollectorUser());

        // Act — as a typo would look
        Gate::allows('updatePost', new GateCollectorPost());

        // Assert
        $log = Gate::decisionLog();
        $this->assertFalse($log[0]['allowed']);
        $this->assertSame('default', $log[0]['step']);

        $collected = (new GateCollector())->collect();
        $this->assertSame(1, $collected['undefined']);
        $this->assertSame(1, $collected['refused']);
    }

    /**
     * Identical checks collapse into a count.
     *
     * Rendering a permission-gated menu can check the same ability for every one of forty
     * items. That should read as `×40`, not fill the panel and push the interesting row off it.
     */
    public function testIdenticalChecksCollapse(): void
    {
        // Arrange
        $this->signIn(new GateCollectorUser());
        Gate::define('see-menu', fn () => true);

        // Act
        for ($i = 0; $i < 40; $i++) {
            Gate::allows('see-menu');
        }

        // Assert
        $log = Gate::decisionLog();
        $this->assertCount(1, $log, 'Forty identical checks must be one row.');
        $this->assertSame(40, $log[0]['times']);

        $collected = (new GateCollector())->collect();
        $this->assertSame(40, $collected['checks'], 'The count is of checks, not of rows.');
        $this->assertSame(40, $collected['allowed']);
    }

    /**
     * The log does not grow without bound.
     *
     * A page checking hundreds of *distinct* abilities has a different problem than this log is
     * for, and filling memory to describe it would be the wrong trade.
     */
    public function testTheLogIsCapped(): void
    {
        // Arrange
        $this->signIn(new GateCollectorUser());

        // Act — well past the cap, each distinct
        for ($i = 0; $i < 260; $i++) {
            Gate::allows('ability-' . $i);
        }

        // Assert
        $this->assertLessThanOrEqual(200, count(Gate::decisionLog()));
    }

    /**
     * Arguments never reach the payload; only the subject's class name does.
     *
     * A policy check receives whole models, and this payload is attached to the response — it
     * sits in a browser's network log. So a row's value must be a class name and nothing that
     * came out of a database, the same rule `AuthCollector` applies to the credential.
     */
    public function testArgumentsAreNotRecorded(): void
    {
        // Arrange — a model carrying something that must not travel
        $this->signIn(new GateCollectorUser(3));
        $post = new GateCollectorPost(3);
        Gate::define('update', fn () => true);

        // Act
        Gate::allows('update', $post, 'a-secret-argument');

        // Assert — nothing the check was given, in the form the payload actually travels in
        $encoded = (string) json_encode(Gate::decisionLog());
        $this->assertStringNotContainsString('a-secret-argument', $encoded);

        // The subject is there, as a class name and nothing more. Asserted on the decoded log
        // rather than the JSON, because json_encode escapes namespace separators and a
        // substring check against the raw name would fail for the wrong reason.
        $this->assertSame(GateCollectorPost::class, Gate::decisionLog()[0]['subject']);
    }

    /**
     * The collector's summary is readable without opening the tab.
     */
    public function testTheCollectorSummarisesTheRequest(): void
    {
        // Arrange
        $this->signIn(new GateCollectorUser());
        Gate::define('read', fn () => true);
        Gate::define('write', fn () => false);

        // Act
        Gate::allows('read');
        Gate::allows('write');
        Gate::allows('nobody-defined-this');

        // Assert
        $collected = (new GateCollector())->collect();
        $this->assertSame('gate', (new GateCollector())->name());
        $this->assertSame(3, $collected['checks']);
        $this->assertSame(1, $collected['allowed']);
        $this->assertSame(2, $collected['refused']);
        $this->assertSame(1, $collected['undefined']);
        $this->assertCount(3, $collected['decisions']);
    }

    /**
     * `clearDecisionLog()` empties the log without switching recording off.
     *
     * Needed by a long-running process that reports per request rather than per lifetime.
     */
    public function testTheLogCanBeClearedWithoutDisablingIt(): void
    {
        // Arrange
        $this->signIn(new GateCollectorUser());
        Gate::define('read', fn () => true);
        Gate::allows('read');

        // Act
        Gate::clearDecisionLog();
        Gate::allows('read');

        // Assert — still recording, and only the newer check is there
        $this->assertCount(1, Gate::decisionLog());
        $this->assertSame(1, Gate::decisionLog()[0]['times']);
    }
}
