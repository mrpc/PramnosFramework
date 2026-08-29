<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\User;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\User\User;

/**
 * The two static user caches, and the memory they used to keep.
 *
 * Neither had a limit. `$usersCache` holds a whole `User` object per account and `$_usercache`
 * holds `(array) $this` — every property, `otherinfo` included — and both live for the process.
 * A web request touches a handful of accounts and never notices; a long-running worker, a
 * console command over every user, or a test suite in one process holds every account it has
 * ever seen.
 *
 * Reported from a consuming project: one test took 235 seconds and was killed by the OOM
 * killer, and 2.06 seconds after this was bounded.
 */
#[CoversClass(User::class)]
class UserCacheBoundsTest extends TestCase
{
    protected function tearDown(): void
    {
        User::clearUserCache();
        parent::tearDown();
    }

    /**
     * A cache past its limit is trimmed, and keeps the recent end.
     *
     * The cache exists because one request loads the same few accounts repeatedly. It was never
     * meant to be a copy of the users table.
     */
    public function testACacheIsTrimmedWhenItGrowsPastTheLimit(): void
    {
        // Arrange
        $max   = (int) (new \ReflectionClass(User::class))->getConstant('USER_CACHE_MAX');
        $cache = [];

        for ($i = 1; $i <= $max * 2; $i++) {
            $cache[$i] = 'account ' . $i;
        }

        // Act
        $this->trim($cache);

        // Assert
        $this->assertLessThanOrEqual($max, count($cache));
        $this->assertArrayHasKey($max * 2, $cache, 'the newest is kept');
        $this->assertArrayNotHasKey(1, $cache, 'the oldest is not');
    }

    /**
     * A cache under the limit is left alone.
     */
    public function testACacheUnderTheLimitIsNotTouched(): void
    {
        // Arrange
        $cache = [1 => 'a', 2 => 'b'];

        // Act
        $this->trim($cache);

        // Assert
        $this->assertSame([1 => 'a', 2 => 'b'], $cache);
    }

    /**
     * The trim drops half, so it does not happen on every insert.
     *
     * Removing one entry per insert past the limit is O(n) per load, which makes the cache
     * slower the longer the process runs — the opposite of what a bound is for.
     */
    public function testTheTrimDropsHalfRatherThanOne(): void
    {
        // Arrange
        $max   = (int) (new \ReflectionClass(User::class))->getConstant('USER_CACHE_MAX');
        $cache = array_fill(1, $max + 1, 'x');

        // Act
        $this->trim($cache);

        // Assert
        $this->assertLessThanOrEqual((int) ($max / 2) + 1, count($cache));
    }

    /**
     * `getUsers()` loads each account once, not twice.
     *
     * `new User($id)` loads — the constructor's whole `else` branch is `return
     * $this->load($userid)`. The explicit `load()` after it read the same two tables a second
     * time, so every list cost twice what it needed to.
     */
    public function testGetUsersDoesNotLoadEachAccountTwice(): void
    {
        // Arrange
        $source = (string) file_get_contents(
            (new \ReflectionClass(User::class))->getFileName()
        );
        $start  = (int) strpos($source, 'static function getUsers(');
        $body   = substr($source, $start, 1600);

        // Assert
        $this->assertStringNotContainsString('$theuser->load(', $body,
            'the constructor already loaded it');
        $this->assertStringContainsString('new User($users->fields[', $body);
    }

    /**
     * And it can be asked for fewer than everything.
     *
     * The default stays "everything", because changing it silently would truncate a caller's
     * list without telling it — but a caller that knows better now has a way to say so.
     */
    public function testGetUsersTakesALimit(): void
    {
        // Act
        $parameters = (new \ReflectionMethod(User::class, 'getUsers'))->getParameters();

        // Assert
        $this->assertCount(2, $parameters);
        $this->assertSame('limit', $parameters[1]->getName());
        $this->assertSame(0, $parameters[1]->getDefaultValue(),
            'nothing is truncated unless a caller asks for it');
    }

    /**
     * Both caches can be emptied — for a worker between jobs, and for a test.
     */
    public function testBothCachesCanBeEmptied(): void
    {
        // Arrange
        $objects = new \ReflectionProperty(User::class, 'usersCache');
        $arrays  = new \ReflectionProperty(User::class, '_usercache');
        $objects->setValue(null, [1 => 'x']);
        $arrays->setValue(null, [1 => ['userid' => 1]]);

        // Act
        User::clearUserCache();

        // Assert
        $this->assertSame([], $objects->getValue());
        $this->assertSame([], $arrays->getValue());
    }

    /** @param array<int|string, mixed> $cache */
    private function trim(array &$cache): void
    {
        $method = new \ReflectionMethod(User::class, 'trimCache');
        $method->invokeArgs(null, [&$cache]);
    }
}
