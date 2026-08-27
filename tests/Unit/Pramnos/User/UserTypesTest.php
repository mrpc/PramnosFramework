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
}
