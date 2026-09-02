<?php

declare(strict_types=1);

namespace Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Adapter\AbstractAdapter;

/**
 * `swap()` — write a value and report what was there before.
 *
 * The base implementation is a `load()` then a `save()`, which is **not atomic**, and the docblock
 * says so: an adapter that can do a real swap overrides it. Four statements, never executed.
 *
 * What the tests pin is the return contract, because that is what a caller branches on: **`null`
 * means the key was unset**, and it has to be distinguishable from a stored empty string. A caller
 * using `swap()` to claim something — "I am the one who set this" — reads a `''` as "somebody got
 * here first" if the two collapse.
 */
#[CoversClass(AbstractAdapter::class)]
class AdapterSwapTest extends TestCase
{
    /** An in-memory adapter: the smallest thing that satisfies load/save. */
    private function adapter(): AbstractAdapter
    {
        return new class extends AbstractAdapter {
            /** @var array<string, mixed> */
            public array $store = [];

            public function load($key, $timeout = 3600)
            {
                return array_key_exists($key, $this->store) ? $this->store[$key] : false;
            }

            public function save($key, $value, $ttl = 3600)
            {
                $this->store[$key] = $value;

                return true;
            }

            public function delete($key)
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear($category = '')
            {
                $this->store = [];

                return true;
            }

            public function connect()
            {
                return true;
            }
        };
    }

    /**
     * The previous value comes back, and the new one is stored.
     */
    public function testThePreviousValueComesBackAndTheNewOneIsStored(): void
    {
        // Arrange
        $adapter = $this->adapter();
        $adapter->save('token', 'old');

        // Act
        $previous = $adapter->swap('token', 'new');

        // Assert
        $this->assertSame('old', $previous);
        $this->assertSame('new', $adapter->load('token'));
    }

    /**
     * An unset key swaps to `null`, not to an empty string.
     *
     * The contract a claim depends on: `null` is "nobody had this", and a caller that treats a
     * stored `''` the same way would decide somebody else had claimed it.
     */
    public function testAnUnsetKeySwapsToNull(): void
    {
        // Arrange
        $adapter = $this->adapter();

        // Act
        $previous = $adapter->swap('fresh', 'mine');

        // Assert
        $this->assertNull($previous);
        $this->assertSame('mine', $adapter->load('fresh'));
    }

    /**
     * A stored empty string comes back as an empty string, not as `null`.
     *
     * The other side of the same coin, and the reason the guard is `=== false || === null` rather
     * than `empty()` or a falsy test: `''` is a value somebody wrote, and collapsing it to `null`
     * would tell the next caller the key was free. `0` and `'0'` are the same case.
     *
     * I expected this to be the base implementation's blind spot and wrote the test asserting it
     * was. It is not — the identity comparison is exactly what keeps the two apart.
     */
    public function testAStoredEmptyStringIsNotNull(): void
    {
        // Arrange
        $adapter = $this->adapter();
        $adapter->save('flag', '');
        $adapter->save('zero', '0');

        // Act + Assert
        $this->assertSame('', $adapter->swap('flag', 'set'), 'an empty string was reported as absent');
        $this->assertSame('0', $adapter->swap('zero', 'set'), "'0' was reported as absent");
    }

    /**
     * The previous value is returned as a string.
     *
     * Whatever went in. The signature says `string|null`, and a caller comparing the result
     * against a token it wrote needs the types to match rather than to be `==`-lucky.
     */
    public function testThePreviousValueIsAString(): void
    {
        // Arrange
        $adapter = $this->adapter();
        $adapter->save('count', 42);

        // Act
        $previous = $adapter->swap('count', '43');

        // Assert
        $this->assertSame('42', $previous);
    }
}
