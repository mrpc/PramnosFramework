<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\User;

use PHPUnit\Framework\TestCase;
use Pramnos\User\UserTypes;

/**
 * What a `usertype` means, and how an application changes it.
 *
 * The column is an integer read as a **threshold** — `>= 90` is an administrator — and
 * that mapping used to live in three places: a console command's constant, an `app.php`
 * key, and a copy of the labels inside each bundled view. "What is 85?" had a different
 * answer depending on which file you asked.
 */
class UserTypesTest extends TestCase
{
    /**
     * A value between two bands belongs to the lower one.
     *
     * That is what makes it a threshold rather than an enum: an application can grant 85
     * and every screen still names the band instead of printing a bare number.
     */
    public function testABandIsAThresholdNotAnEnum(): void
    {
        // Act & Assert
        $this->assertSame('Administrator', UserTypes::label(90));
        $this->assertSame('Administrator', UserTypes::label(95));
        $this->assertSame('Super Administrator', UserTypes::label(98));
        $this->assertSame('Root', UserTypes::label(99));
        $this->assertSame('Root', UserTypes::label(120));
        $this->assertSame('Simple User', UserTypes::label(50));
        $this->assertSame('Simple User', UserTypes::label(0));
    }

    /**
     * The machine account is a type, not a rung.
     *
     * `1` is the identity a Client Credentials grant authenticates as. Under the plain
     * threshold rule every value above 1 would inherit its name — `label(50)` would answer
     * *System User* — so an exact match wins and the threshold search skips it.
     */
    public function testTheSystemAccountIsMatchedExactly(): void
    {
        // Act & Assert
        $this->assertSame('System User (Client Credentials Grant)', UserTypes::label(1));
        $this->assertSame('Simple User', UserTypes::label(2));
        $this->assertSame('Simple User', UserTypes::label(89));
    }

    /**
     * A value below every band still has a name.
     *
     * A negative usertype should not render as an empty cell — a user always has some
     * standing, even if it is the lowest one.
     */
    public function testAValueBelowEveryBandFallsToTheLowest(): void
    {
        // Act & Assert
        $this->assertSame('Simple User', UserTypes::label(-5));
    }

    /**
     * The options a filter offers carry the value they send.
     *
     * The column is matched **equal**, so an operator choosing "Admin" is entitled to see
     * which number that is — and `Html\Select::addOptions()` takes `value => label`,
     * which is the direction this returns.
     */
    public function testTheOptionsAreValueToLabel(): void
    {
        // Act
        $options = UserTypes::options();

        // Assert
        $this->assertSame('Administrator (90)', $options['90'] ?? null);
        $this->assertArrayHasKey('0', $options);
    }

    /**
     * The bands are ordered highest first, whatever order they were declared in.
     *
     * The lookup returns the first band at or below a value, so a configuration listing
     * its bands lowest-first would label an administrator "Guest".
     */
    public function testTheBandsAreOrderedHighestFirst(): void
    {
        // Act
        $floors = array_keys(UserTypes::labels());

        // Assert — strictly descending
        $sorted = $floors;
        rsort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $floors);
    }

    /**
     * The framework's defaults match the numbers it already had opinions about.
     *
     * `90` is `UserCreate::ADMIN_USERTYPE` and `80` is the scaffolded admin area's floor;
     * a default set that disagreed with those would name an administrator "Manager".
     */
    public function testTheDefaultsAgreeWithTheFrameworksOwnNumbers(): void
    {
        // Assert
        $this->assertSame(
            'Administrator',
            UserTypes::label(\Pramnos\Console\Commands\UserCreate::ADMIN_USERTYPE)
        );
        // …and the machine account the Client Credentials grant authenticates as.
        $this->assertArrayHasKey(1, UserTypes::DEFAULTS);
    }

    /**
     * What each type may do is written down, and capabilities accumulate.
     *
     * "What can an Administrator do" was answered by reading twelve controllers: nine
     * declared `requiredUserType = 80`, three declared `90`, the administration area had
     * its own floor in `app.php`, and nothing named what those numbers were for.
     */
    public function testCapabilitiesAccumulateDownwards(): void
    {
        // Act & Assert — an administrator has what a simple user has
        $this->assertTrue(UserTypes::can(90, 'admin.area'));
        $this->assertTrue(UserTypes::can(90, 'account.self'));

        // …and not what only a super administrator has
        $this->assertFalse(UserTypes::can(90, 'admin.settings'));
        $this->assertTrue(UserTypes::can(98, 'admin.settings'));
    }

    /**
     * Root can do everything, including capabilities added after it was written.
     *
     * `*` is the only honest way to write that: a list would go stale the first time a
     * screen adds a capability, and it would go stale *silently*, as a screen root cannot
     * reach.
     */
    public function testRootCanDoAnythingIncludingWhatDoesNotExistYet(): void
    {
        // Act & Assert
        $this->assertTrue(UserTypes::can(99, 'admin.settings'));
        $this->assertTrue(UserTypes::can(99, 'something.invented.tomorrow'));
        $this->assertFalse(UserTypes::can(98, 'something.invented.tomorrow'));
    }

    /**
     * A simple user reaches nothing in the administration area.
     *
     * The one assertion in this file that would matter if it broke.
     */
    public function testASimpleUserReachesNothingAdministrative(): void
    {
        // Act & Assert
        foreach ([0, 2, 50, 89] as $usertype) {
            $this->assertFalse(UserTypes::can($usertype, 'admin.area'), (string) $usertype);
            $this->assertFalse(UserTypes::can($usertype, 'admin.users'), (string) $usertype);
        }
    }

    /**
     * The machine account inherits nothing.
     *
     * It is not a very senior simple user: giving it `account.self` would be inventing a
     * person for an identity that is a Client Credentials grant.
     */
    public function testTheMachineAccountInheritsNothing(): void
    {
        // Act
        $capabilities = UserTypes::capabilities(1);

        // Assert
        $this->assertSame(['api.client_credentials'], $capabilities);
        $this->assertFalse(UserTypes::can(1, 'account.self'));
        $this->assertFalse(UserTypes::can(1, 'admin.area'));
    }

    /**
     * A tone is a meaning, not a colour — and it resolves like a label.
     *
     * Each bundled view carried its own thresholds for how loudly to show a type, so three
     * screens disagreed about which number was alarming. A theme maps a tone to its own
     * classes; it does not decide what is alarming.
     */
    public function testAToneResolvesLikeALabel(): void
    {
        // Act & Assert
        $this->assertSame('danger', UserTypes::tone(99));
        $this->assertSame('danger', UserTypes::tone(98));
        $this->assertSame('warning', UserTypes::tone(90));
        $this->assertSame('warning', UserTypes::tone(95));
        $this->assertSame('neutral', UserTypes::tone(1));
        $this->assertSame('primary', UserTypes::tone(50));

        // …and every tone is one a theme has to be able to render
        foreach (UserTypes::tones() as $tone) {
            $this->assertContains($tone, UserTypes::TONES);
        }
    }

    // ── What an application declares for itself ─────────────────────────────────

    /**
     * Install an `applicationInfo` for the registry to read, and take it away again.
     *
     * A real `Application` with its constructor skipped, as the driver tests do: the
     * registry declares `?Application` and a `stdClass` would TypeError. Nothing here
     * wants a database, a session or a language — this is a configuration read.
     *
     * @param array<string,mixed> $info
     */
    private function withApplicationInfo(array $info): void
    {
        $stub = new class extends \Pramnos\Application\Application {
            public function __construct()
            {
            }
        };
        $stub->applicationInfo = $info;

        $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
        $instances = $ref->getValue() ?? [];
        $this->savedInstances = $instances;
        $instances['default'] = $stub;
        $ref->setValue(null, $instances);
    }

    /** @var array<string,mixed>|null The registry as it was before a test replaced it. */
    private ?array $savedInstances = null;

    protected function tearDown(): void
    {
        if ($this->savedInstances !== null) {
            $ref = new \ReflectionProperty(\Pramnos\Application\Application::class, 'appInstances');
            $ref->setValue(null, $this->savedInstances);
            $this->savedInstances = null;
        }

        parent::tearDown();
    }

    /**
     * An application replaces the bands, and may list them in any order.
     *
     * Sorted by the registry rather than trusted from the configuration, because the
     * lookup reads the first band at or below a value: a config listing its bands
     * lowest-first would otherwise label an administrator with the lowest band's name.
     */
    public function testAnApplicationReplacesTheBandsInAnyOrder(): void
    {
        // Arrange — deliberately lowest first
        $this->withApplicationInfo(['usertypes' => [
            0 => 'Customer', 50 => 'Staff', 100 => 'Owner',
        ]]);

        // Act & Assert
        $this->assertSame('Owner', UserTypes::label(100));
        $this->assertSame('Staff', UserTypes::label(50));
        $this->assertSame('Staff', UserTypes::label(99), 'the band below, not the one above');
        $this->assertSame('Customer', UserTypes::label(0));
        $this->assertSame([100, 50, 0], array_keys(UserTypes::labels()));
    }

    /**
     * An entry that cannot be a band is dropped rather than accepted.
     *
     * `app.php` is written by hand, so a typo is a normal event: a non-numeric floor or
     * an empty label would otherwise become a band that no value can ever match, or one
     * that renders as nothing where a name belongs.
     */
    public function testUnusableEntriesAreDropped(): void
    {
        // Arrange
        $this->withApplicationInfo(['usertypes' => [
            'nine' => 'Nonsense',
            10     => '',
            20     => 'Real',
        ]]);

        // Act & Assert
        $this->assertSame([20 => 'Real'], UserTypes::labels());
    }

    /**
     * Declaring nothing usable leaves the framework's own bands in place.
     *
     * An empty or wholly invalid `usertypes` must not produce an application with *no*
     * bands, where every account would render as the fallback and the administration
     * screens would all read the same.
     */
    public function testAWhollyInvalidDeclarationFallsBackToTheDefaults(): void
    {
        // Arrange
        $this->withApplicationInfo(['usertypes' => ['nope' => 5]]);

        // Act & Assert
        $this->assertSame('Administrator', UserTypes::label(90));
    }

    /**
     * Tones are merged over the framework's, and an unknown tone name is dropped.
     *
     * Merged rather than replaced because an application may want to recolour one band it
     * inherited; dropped because a theme maps the four names to classes and has nothing
     * for a fifth — the band would render unstyled, which looks like a broken page rather
     * than like a bad configuration.
     */
    public function testTonesAreMergedAndValidated(): void
    {
        // Arrange
        $this->withApplicationInfo(['usertype_tones' => [
            90 => 'danger',
            50 => 'chartreuse',
        ]]);

        // Act & Assert
        $this->assertSame('danger', UserTypes::tone(90), 'the application recoloured this one');
        $this->assertSame('primary', UserTypes::tone(50), 'and the invented name was dropped');
        $this->assertSame('danger', UserTypes::tone(99), 'the inherited bands are still there');
    }

    /**
     * Capabilities are **replaced**, not merged.
     *
     * The opposite of tones, deliberately. A tone is decoration and inheriting one is
     * harmless; a capability is permission, and an application that writes its own list
     * must not silently keep the framework's — it would be granting rights it never
     * declared, which is the one direction of surprise that matters here.
     */
    public function testCapabilitiesAreReplacedRatherThanMerged(): void
    {
        // Arrange
        $this->withApplicationInfo(['usertype_capabilities' => [
            90 => ['reports.read'],
            0  => ['account.self'],
        ]]);

        // Act & Assert
        $this->assertTrue(UserTypes::can(90, 'reports.read'));
        $this->assertFalse(
            UserTypes::can(90, 'admin.users'),
            'a framework default the application did not declare must not survive'
        );
        $this->assertSame([90, 0], array_keys(UserTypes::capabilityMap()));
    }

    /**
     * A capability list that is all rubbish leaves the defaults alone.
     *
     * Same reasoning as the bands: an application with an unreadable declaration must not
     * end up with an empty map, where `can()` answers false for everything and every
     * screen refuses everybody.
     */
    public function testAnUnreadableCapabilityDeclarationFallsBackToTheDefaults(): void
    {
        // Arrange
        $this->withApplicationInfo(['usertype_capabilities' => [
            'ninety' => ['nope'],
            90       => 'not a list',
        ]]);

        // Act & Assert
        $this->assertTrue(UserTypes::can(98, 'admin.settings'));
    }
}
