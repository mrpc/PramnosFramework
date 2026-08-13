<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Pramnos\Auth\AuthorizationException;
use Pramnos\Auth\Gate;

/**
 * A user, as the gate sees one.
 *
 * Deliberately not the framework's `User`: the gate must work with whatever object an
 * application calls a user, and a test that used the real class would hide it if that
 * stopped being true.
 */
class GateTestUser
{
    /**
     * @param int  $userid  Identity, as the permission store spells it
     * @param bool $isAdmin Whether the "admin may do anything" hook should fire
     */
    public function __construct(public int $userid = 1, public bool $isAdmin = false)
    {
    }
}

/**
 * A thing to be authorized against.
 */
class GateTestPost
{
    /**
     * @param int $userid Who owns it
     */
    public function __construct(public int $userid = 1)
    {
    }
}

/**
 * A policy for {@see GateTestPost}.
 */
class GateTestPostPolicy
{
    /**
     * Only the owner may update.
     *
     * @param GateTestUser|null $user The user asking
     * @param GateTestPost      $post The post in question
     * @return bool Whether they own it
     */
    public function update(?GateTestUser $user, GateTestPost $post): bool
    {
        return $user !== null && $user->userid === $post->userid;
    }

    /**
     * Nobody has an opinion about publishing.
     *
     * Returns null on purpose, so the fall-through to the next step is exercised.
     *
     * @param GateTestUser|null $user The user asking
     * @param GateTestPost      $post The post in question
     * @return bool|null Always null
     */
    public function publish(?GateTestUser $user, GateTestPost $post): ?bool
    {
        return null;
    }

    /**
     * Matches the ability `archive-post`, to prove the name folding.
     *
     * @param GateTestUser|null $user The user asking
     * @param GateTestPost      $post The post in question
     * @return bool Always true
     */
    public function archivePost(?GateTestUser $user, GateTestPost $post): bool
    {
        return true;
    }
}

/**
 * A policy with its own hooks, narrower than the global ones.
 */
class GateTestHookedPolicy
{
    /**
     * Decides before any of this policy's methods run.
     *
     * @param GateTestUser|null $user      The user asking
     * @param string            $ability   The ability name
     * @param mixed             ...$args   Whatever the check passed
     * @return bool|null True for an owner, null otherwise
     */
    public function before(?GateTestUser $user, string $ability, mixed ...$args): ?bool
    {
        return $user !== null && $user->userid === 99 ? true : null;
    }

    /**
     * Overrides this policy's results for one user.
     *
     * @param GateTestUser|null $user    The user asking
     * @param string            $ability The ability name
     * @param bool|null         $result  What was decided
     * @param mixed             ...$args Whatever the check passed
     * @return bool|null True for user 77, null otherwise
     */
    public function after(?GateTestUser $user, string $ability, ?bool $result, mixed ...$args): ?bool
    {
        return $user !== null && $user->userid === 77 ? true : null;
    }

    /**
     * Always refuses, so the hooks are the only thing that can allow.
     *
     * @param GateTestUser|null $user The user asking
     * @param GateTestPost      $post The post in question
     * @return bool Always false
     */
    public function update(?GateTestUser $user, GateTestPost $post): bool
    {
        return false;
    }
}

/**
 * `Pramnos\Auth\Gate` — authorization rules that live in code.
 *
 * The gate is the half the framework was missing: the permission store records what an
 * installation has *granted*, and cannot express a rule like "the author, or a moderator".
 * These tests are written around the decision order, because that order is the contract —
 * every "why was this allowed" question is answered by knowing which step decided.
 */
class GateTest extends TestCase
{
    /**
     * Starts every test from an empty registry.
     *
     * `GateIsolation` does this for the suite, but a test class for the gate itself should
     * not depend on the extension it is a sibling of.
     *
     * @return void
     */
    protected function setUp(): void
    {
        Gate::reset();
    }

    /**
     * Leaves nothing behind for the next class.
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
     * @param GateTestUser|null $user Who the gate should answer for
     * @return void
     */
    private function signIn(?GateTestUser $user): void
    {
        Gate::resolveUserUsing(static fn () => $user);
    }

    /**
     * A defined ability decides, and receives the user first.
     *
     * The argument order is the contract every rule is written against: the user, then
     * whatever the check passed.
     */
    public function testADefinedAbilityDecides(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(7));
        Gate::define('update-post', fn ($user, $post) => $user->userid === $post->userid);

        // Act & Assert
        $this->assertTrue(Gate::allows('update-post', new GateTestPost(7)));
        $this->assertFalse(Gate::allows('update-post', new GateTestPost(8)));
        $this->assertTrue(Gate::denies('update-post', new GateTestPost(8)));
    }

    /**
     * An ability nobody defined is refused.
     *
     * The alternative — allowing what nothing claims — would make every typo in an ability
     * name a silent hole. `has()` is what lets a caller tell the two apart deliberately.
     */
    public function testAnUndefinedAbilityIsRefused(): void
    {
        // Arrange
        $this->signIn(new GateTestUser());

        // Act & Assert
        $this->assertFalse(Gate::allows('nobody-defined-this'));
        $this->assertFalse(Gate::has('nobody-defined-this'));
    }

    /**
     * A `before` hook decides immediately and skips everything after it.
     *
     * This is the case the whole hook exists for: "an administrator may do anything",
     * written once instead of at the top of every rule. The ability below would refuse, so
     * a passing test proves the hook ran *instead of* it, not merely before it.
     */
    public function testABeforeHookShortCircuits(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(1, isAdmin: true));
        Gate::define('update-post', fn () => false);
        Gate::before(fn ($user) => $user->isAdmin ? true : null);

        // Act & Assert
        $this->assertTrue(Gate::allows('update-post', new GateTestPost()));
    }

    /**
     * A `before` hook returning null lets the check continue.
     *
     * Without this, one hook would decide everything for everybody — the null is what makes
     * hooks composable.
     */
    public function testANullBeforeHookFallsThrough(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(1, isAdmin: false));
        Gate::before(fn ($user) => $user->isAdmin ? true : null);
        Gate::define('update-post', fn () => true);

        // Act & Assert
        $this->assertTrue(Gate::allows('update-post'));
    }

    /**
     * An `after` hook can override a decision that was already made.
     */
    public function testAnAfterHookCanOverride(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(5));
        Gate::define('update-post', fn () => false);
        Gate::after(fn ($user, $ability, $result) => $user->userid === 5 ? true : null);

        // Act & Assert
        $this->assertTrue(Gate::allows('update-post'));
    }

    /**
     * An `after` hook returning null leaves the result alone.
     */
    public function testAnAfterHookReturningNullChangesNothing(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(5));
        Gate::define('update-post', fn () => true);
        Gate::after(fn () => null);

        // Act & Assert
        $this->assertTrue(Gate::allows('update-post'));
    }

    /**
     * A registered policy answers for its model.
     */
    public function testAPolicyAnswersForItsModel(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(3));
        Gate::policy(GateTestPost::class, GateTestPostPolicy::class);

        // Act & Assert
        $this->assertTrue(Gate::allows('update', new GateTestPost(3)));
        $this->assertFalse(Gate::allows('update', new GateTestPost(4)));
        $this->assertSame(GateTestPostPolicy::class, Gate::getPolicyFor(new GateTestPost()));
    }

    /**
     * A policy can be found from a class name, not only an instance.
     *
     * `create` is the case that needs it: there is no object yet to authorize against.
     */
    public function testAPolicyIsFoundFromAClassName(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(3));
        Gate::policy(GateTestPost::class, GateTestPostPolicy::class);

        // Act & Assert
        $this->assertSame(GateTestPostPolicy::class, Gate::getPolicyFor(GateTestPost::class));
    }

    /**
     * A named ability wins over a policy.
     *
     * The order matters and has to be asserted rather than assumed: an application
     * overriding one model's rule with `define()` needs its definition to be the answer.
     */
    public function testANamedAbilityWinsOverAPolicy(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(3));
        Gate::policy(GateTestPost::class, GateTestPostPolicy::class);
        Gate::define('update', fn () => false);   // the policy would allow this user

        // Act & Assert
        $this->assertFalse(Gate::allows('update', new GateTestPost(3)));
    }

    /**
     * An ability with hyphens finds a camelCase policy method.
     *
     * So `update-post` can read naturally in a route file and still be a method name.
     */
    public function testAbilityNamesAreFoldedToMethodNames(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(3));
        Gate::policy(GateTestPost::class, GateTestPostPolicy::class);

        // Act & Assert
        $this->assertTrue(Gate::allows('archive-post', new GateTestPost()));
        $this->assertTrue(Gate::allows('archive_post', new GateTestPost()));
    }

    /**
     * A policy method returning null is no opinion, and falls through to a refusal.
     *
     * Not to an approval — "nobody said" must never mean "yes" at the end of the chain.
     */
    public function testAPolicyReturningNullFallsThrough(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(3));
        Gate::policy(GateTestPost::class, GateTestPostPolicy::class);

        // Act & Assert
        $this->assertFalse(Gate::allows('publish', new GateTestPost(3)));
    }

    /**
     * A policy with no method for the ability declines rather than fails.
     */
    public function testAPolicyWithoutTheMethodDeclines(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(3));
        Gate::policy(GateTestPost::class, GateTestPostPolicy::class);

        // Act & Assert
        $this->assertFalse(Gate::allows('incinerate', new GateTestPost(3)));
    }

    /**
     * A policy's own hooks run around its own methods.
     *
     * Narrower than the global hooks, which is the point: "the owner may do anything to
     * their own record" belongs to that record's policy, not to every check in the
     * application.
     */
    public function testAPolicyCanCarryItsOwnHooks(): void
    {
        // Arrange
        Gate::policy(GateTestPost::class, GateTestHookedPolicy::class);

        // Act & Assert — before() allows for user 99, though update() refuses everyone
        $this->signIn(new GateTestUser(99));
        $this->assertTrue(Gate::allows('update', new GateTestPost()));

        // Act & Assert — after() overrides for user 77
        $this->signIn(new GateTestUser(77));
        $this->assertTrue(Gate::allows('update', new GateTestPost()));

        // Act & Assert — anybody else gets the method's own answer
        $this->signIn(new GateTestUser(1));
        $this->assertFalse(Gate::allows('update', new GateTestPost()));
    }

    /**
     * An unregistered policy class name is ignored rather than fatal.
     *
     * A typo in a policy registration should not take the request down; the check simply
     * finds nothing to ask.
     */
    public function testAMissingPolicyClassIsIgnored(): void
    {
        // Arrange — no ability defined, so the policy lookup is the only thing that runs
        $this->signIn(new GateTestUser(3));
        Gate::policy(GateTestPost::class, 'App\\Policies\\DoesNotExist');

        // Act & Assert — refused by falling through, not by a "class not found" fatal
        $this->assertFalse(Gate::allows('update', new GateTestPost()));
    }

    /**
     * A user object with no recognisable id cannot be asked about in the store.
     *
     * The fallback declines rather than inventing a subject: asking the permission store
     * about the wrong id is worse than not asking.
     */
    public function testTheFallbackDeclinesWhenTheUserHasNoId(): void
    {
        // Arrange — a user object with no userid/id/userId
        $user = new class {
            /** @var string A name, and nothing the store could use as a subject */
            public string $name = 'anonymous-ish';
        };
        Gate::resolveUserUsing(static fn () => $user);
        Gate::fallbackToPermissions();

        // Act & Assert
        $this->assertFalse(Gate::allows('articles.edit'));
    }

    /**
     * `authorize()` throws, and the exception names what was refused.
     */
    public function testAuthorizeThrowsAndNamesTheAbility(): void
    {
        // Arrange
        $this->signIn(new GateTestUser());
        Gate::define('delete-everything', fn () => false);

        // Act & Assert
        try {
            Gate::authorize('delete-everything');
            $this->fail('authorize() must throw when the check refuses.');
        } catch (AuthorizationException $e) {
            $this->assertSame('delete-everything', $e->getAbility());
            $this->assertSame(403, $e->getCode());
            $this->assertInstanceOf(\Exception::class, $e, 'Existing catch blocks must keep working.');
        }
    }

    /**
     * `authorize()` returns quietly when allowed.
     */
    public function testAuthorizeIsSilentWhenAllowed(): void
    {
        // Arrange
        $this->signIn(new GateTestUser());
        Gate::define('breathe', fn () => true);

        // Act
        Gate::authorize('breathe');

        // Assert — reaching here is the assertion
        $this->assertTrue(true);
    }

    /**
     * A gate can answer for somebody other than the current user.
     *
     * Needed by anything that decides on another user's behalf — an admin screen showing
     * what a given account may do, a job running as somebody.
     */
    public function testItCanAnswerForAnotherUser(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(1));
        Gate::define('update-post', fn ($user, $post) => $user->userid === $post->userid);

        // Act & Assert
        $this->assertFalse(Gate::allows('update-post', new GateTestPost(2)));
        $this->assertTrue(
            Gate::forUser(new GateTestUser(2))->check('update-post', new GateTestPost(2))
        );
    }

    /**
     * A gate with nobody signed in still answers, and rules see a null user.
     *
     * The alternative — throwing when there is no user — would make every public-facing
     * check need a guard around it.
     */
    public function testItAnswersWithNobodySignedIn(): void
    {
        // Arrange
        $this->signIn(null);
        Gate::define('view-public', fn ($user) => $user === null);

        // Act & Assert
        $this->assertTrue(Gate::allows('view-public'));
        $this->assertNull(Gate::forUser(null)->user());
    }

    /**
     * `all()` and `any()` combine abilities.
     */
    public function testAllAndAnyCombineAbilities(): void
    {
        // Arrange
        $this->signIn(new GateTestUser());
        Gate::define('read', fn () => true);
        Gate::define('write', fn () => false);

        $gate = Gate::current();

        // Act & Assert
        $this->assertTrue($gate->all(['read']));
        $this->assertFalse($gate->all(['read', 'write']));
        $this->assertTrue($gate->any(['read', 'write']));
        $this->assertFalse($gate->any(['write']));
    }

    /**
     * `enforce()` is the instance form of `authorize()`.
     */
    public function testEnforceThrowsOnTheInstance(): void
    {
        // Arrange
        Gate::define('shout', fn () => false);

        // Act & Assert
        $this->expectException(AuthorizationException::class);
        Gate::forUser(new GateTestUser())->enforce('shout');
    }

    /**
     * `reset()` forgets everything.
     *
     * This is what `GateIsolation` calls between tests, so a leak here is a leak in every
     * suite that uses the gate.
     */
    public function testResetForgetsEverything(): void
    {
        // Arrange
        $this->signIn(new GateTestUser());
        Gate::define('remembered', fn () => true);
        Gate::policy(GateTestPost::class, GateTestPostPolicy::class);
        $this->assertTrue(Gate::has('remembered'));

        // Act
        Gate::reset();

        // Assert
        $this->assertFalse(Gate::has('remembered'));
        $this->assertNull(Gate::getPolicyFor(new GateTestPost()));
        $this->assertFalse(Gate::allows('remembered'));
    }

    /**
     * The permission-store fallback is off unless it is switched on.
     *
     * A gate that silently consulted a database for names nobody registered would be a gate
     * whose answers cannot be read off the code, so this defaults to off and the default is
     * asserted.
     */
    public function testThePermissionFallbackIsOffByDefault(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(42));

        // Act & Assert — shaped like resource.privilege, and still refused
        $this->assertFalse(Gate::allows('articles.edit'));
    }

    /**
     * With the fallback on, an ability with no dot is still not sent to the store.
     *
     * There would be nothing to tell it: the store wants a resource *and* a privilege, and
     * guessing one would be worse than declining.
     */
    public function testTheFallbackIgnoresAbilitiesWithoutAResource(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(42));
        Gate::fallbackToPermissions();

        // Act & Assert
        $this->assertFalse(Gate::allows('edit'));
    }

    /**
     * With the fallback on and nobody signed in, there is nobody to ask about.
     */
    public function testTheFallbackNeedsAUser(): void
    {
        // Arrange
        $this->signIn(null);
        Gate::fallbackToPermissions();

        // Act & Assert
        $this->assertFalse(Gate::allows('articles.edit'));
    }

    /**
     * With the fallback on, the store is really asked — and "no rule" is no opinion.
     *
     * This goes all the way through `Permissions::isAllowed()` with
     * `$nonExistEqualsFalse = false`, which returns `null` for an ability nobody has granted.
     * The gate must treat that as *nobody said* and fall through to a refusal, rather than
     * reading `null` as a denial the store never made.
     *
     * (An earlier version of this test claimed it exercised an unreadable store. It did not:
     * the store reads fine here and simply holds no rule. The `catch` in the fallback needs a
     * broken database to reach, which a unit suite does not have — it is marked as such in
     * the source rather than covered by a test that says the wrong thing.)
     */
    public function testTheStoreIsAskedAndNoRuleMeansNoOpinion(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(42));
        Gate::fallbackToPermissions();

        // Act
        $result = Gate::allows('articles.edit');

        // Assert — refused by falling through, not by the store having denied
        $this->assertFalse($result);
    }

    /**
     * The fallback can be switched off again.
     */
    public function testTheFallbackCanBeSwitchedOff(): void
    {
        // Arrange
        $this->signIn(new GateTestUser(42));
        Gate::fallbackToPermissions();
        Gate::fallbackToPermissions(null);

        // Act & Assert
        $this->assertFalse(Gate::allows('articles.edit'));
    }

    /**
     * A user object is recognised however it spells its identity.
     *
     * `userid` is the framework's spelling, `id` is everyone else's. The gate has to work
     * with whatever an application calls a user, which is the same reason these tests do not
     * use the framework's own `User` class.
     */
    public function testItFindsTheUserIdHoweverItIsSpelled(): void
    {
        // Arrange — an object using `id` rather than `userid`
        $user = new class {
            /** @var int Identity, spelled the common way */
            public int $id = 11;
        };
        Gate::resolveUserUsing(static fn () => $user);
        Gate::define('check-me', fn ($u) => $u->id === 11);

        // Act & Assert
        $this->assertTrue(Gate::allows('check-me'));
    }
}
