<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Cache\Cache;

/**
 * Saying "no cache here" and being believed.
 *
 * Two switches looked like they turned caching off, and neither did.
 *
 * `'method' => false` fell into `defaultMethod()`, because the test was
 * `$this->method == ''` and `false == ''` is true in PHP. `defaultMethod()`
 * answers with the most capable extension present, so a line saying *false* had
 * been selecting **redis** for as long as it had been written — and the wrong
 * answer is the loudest one available, not the quietest.
 *
 * `'caching' => false` was copied onto the public property by the settings loop
 * and then ignored: `initializeAdapter()` ran unconditionally a few lines later,
 * so the socket opened anyway.
 *
 * Both surfaced during a redis incident, when the first mitigation — turn the
 * cache off — changed nothing, twice, and the connections kept being made.
 */
#[CoversClass(Cache::class)]
class CacheOffSwitchTest extends TestCase
{
    /**
     * @param array<string, mixed> $settings
     */
    private function cache($method = '', array $settings = array()): Cache
    {
        return new Cache('tests', null, $method, $settings);
    }

    /**
     * A cache that is off is off, whichever of the four words was used.
     *
     * `false` and `null` are what a configuration file says; `'none'` and `'off'` are
     * what a `.env` or a settings form can carry, since neither can hold a boolean.
     * All four had to reach the same state, because an installation that picks one and
     * gets caching is not going to try the other three.
     */
    public function testTheSpellingsOfOffAllMeanOff(): void
    {
        foreach (array(false, 'none', 'off', 'false', 'disabled', 'OFF', ' none ') as $spelling) {
            // Act
            $cache = $this->cache($spelling);

            // Assert
            $this->assertSame('none', $cache->method, var_export($spelling, true) . ' selected a store');
            $this->assertFalse($cache->caching, var_export($spelling, true) . ' left caching on');
            $this->assertNull($cache->getAdapter(), var_export($spelling, true) . ' built an adapter');
        }
    }

    /**
     * And it is off through the front door, not only in its properties.
     *
     * The state is what callers meet: `save()` and `load()` answer false, which is the
     * path every caller already has for a miss. Asserted separately because "the flag is
     * set" and "nothing is stored" are different claims, and the second is the one the
     * installation asked for.
     */
    public function testNothingIsStoredOrReturned(): void
    {
        // Arrange
        $cache = $this->cache(false);

        // Act
        $saved = $cache->save('value-under-test', 'probe');
        $read  = $cache->load('probe', 'tests');

        // Assert
        $this->assertFalse($saved);
        $this->assertFalse($read, 'a cache that is off answered with something');
    }

    /**
     * `'method' => false` in the settings, which is where it was actually written.
     *
     * The reported shape. The constructor argument and the settings key reach `$this->method`
     * by different routes — the argument by assignment, the key by the `property_exists()`
     * loop above it — and only the second one was in the incident.
     */
    public function testTheSettingsKeyIsHonouredToo(): void
    {
        // Act
        $cache = $this->cache('', array('method' => false));

        // Assert
        $this->assertSame('none', $cache->method);
        $this->assertFalse($cache->caching);
        $this->assertNull($cache->getAdapter());
    }

    /**
     * `'caching' => false` stops the adapter being built, rather than being noted.
     *
     * The second switch, and the more surprising failure: the method is valid, the
     * settings are complete, and the only thing saying no is a boolean the constructor
     * read and then walked past. Named `'file'` here so that a connection failure cannot
     * be what produces the null adapter — on this path there is nothing to fail.
     */
    public function testTheCachingFlagStopsTheAdapter(): void
    {
        // Act
        $cache = $this->cache('file', array('caching' => false));

        // Assert
        $this->assertNull($cache->getAdapter(), 'the adapter was built for a cache that is off');
        $this->assertFalse($cache->caching);
        $this->assertFalse($cache->save('value-under-test', 'probe'));
    }

    /**
     * `'0'` is not one of the words, and that is deliberate.
     *
     * It is what an unchecked checkbox posts. A settings form saving `'0'` into a method
     * field must not turn an installation's cache off, so the string forms are an
     * enumerated list rather than a falsiness test — which is exactly the kind of test
     * that caused this in the first place.
     */
    public function testAZeroStringIsNotTakenAsOff(): void
    {
        // Act
        $cache = $this->cache('0');

        // Assert
        $this->assertNotSame('none', $cache->method);
        $this->assertTrue($cache->caching);
    }

    /**
     * Nothing said still means the default, which is the behaviour nobody asked to change.
     *
     * `''` and `null` as the constructor argument mean "not specified" — every existing
     * caller passes one of them — so they must keep reaching `defaultMethod()`. The
     * control for the whole class: a fix that read absence as refusal would satisfy every
     * test above and turn caching off for every installation in the framework.
     */
    public function testSayingNothingStillSelectsTheDefault(): void
    {
        // Assert
        foreach (array('', null) as $unspecified) {
            $cache = $this->cache($unspecified);

            $this->assertNotSame('none', $cache->method, 'an unconfigured cache turned itself off');
            $this->assertTrue($cache->caching);
        }
    }
}
