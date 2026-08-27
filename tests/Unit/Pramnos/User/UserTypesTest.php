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
        $this->assertSame('Admin', UserTypes::label(90));
        $this->assertSame('Admin', UserTypes::label(120));
        $this->assertSame('Manager', UserTypes::label(85));
        $this->assertSame('Editor', UserTypes::label(50));
        $this->assertSame('Member', UserTypes::label(10));
        $this->assertSame('Guest', UserTypes::label(0));
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
        $this->assertSame('Guest', UserTypes::label(-5));
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
        $this->assertSame('Admin (90)', $options['90'] ?? null);
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
            'Admin',
            UserTypes::label(\Pramnos\Console\Commands\UserCreate::ADMIN_USERTYPE)
        );
        $this->assertArrayHasKey(80, UserTypes::DEFAULTS);
    }
}
