<?php

declare(strict_types=1);

namespace Pramnos\General;

use Pramnos\Cache\Cache;

/**
 * `matomo/device-detector`'s cache, kept in the application's store — Redis where it is
 * configured — so its rules are read from YAML once, not once a request.
 *
 * Parsing the rules is nearly all of a parse's cost: `mobiles.yml` alone is about 230 ms, and
 * every phone, Mac or unrecognised agent opens it. Kept here, an agent never seen before costs
 * the matching and nothing else. The package puts its own version in every key, so an update
 * reads its new rules rather than the old ones.
 *
 * Each entry is also held in the process: the rules are read many times in one parse.
 */
final class DeviceDetectorCache implements \DeviceDetector\Cache\CacheInterface
{
    /** How long an entry is kept, in seconds. A version change is a new key anyway. */
    public const TTL = 604800;

    /** @var array<string, mixed> What this process has fetched or saved */
    private static array $held = [];

    /**
     * @param string     $category The store's category
     * @param Cache|null $store    The store; the application's, unless given
     */
    public function __construct(private string $category = 'device-detector', private ?Cache $store = null)
    {
    }

    /** The entry, or false when there is none. */
    public function fetch(string $id)
    {
        if (array_key_exists($id, self::$held)) {
            return self::$held[$id];
        }
        $value = $this->attempt(fn (Cache $store) => $store->load($id, null, self::TTL));
        if ($value !== false) {
            self::$held[$id] = $value;
        }

        return $value;
    }

    /** Whether there is an entry. */
    public function contains(string $id): bool
    {
        return $this->fetch($id) !== false;
    }

    /** Keep an entry, here and in the store. */
    public function save(string $id, $data, int $lifeTime = 0): bool
    {
        self::$held[$id] = $data;

        return (bool) $this->attempt(static function (Cache $store) use ($id, $data, $lifeTime) {
            $store->timeout = $lifeTime > 0 ? $lifeTime : self::TTL;

            return $store->save($data, $id);
        });
    }

    /** Forget an entry. */
    public function delete(string $id): bool
    {
        unset(self::$held[$id]);

        return (bool) $this->attempt(static fn (Cache $store) => $store->delete($id));
    }

    /** Forget every entry. */
    public function flushAll(): bool
    {
        self::$held = [];

        return (bool) $this->attempt(fn (Cache $store) => $store->clear($this->category));
    }

    /**
     * Ask the store; false when it cannot answer. A store that is down means the rules are read
     * from YAML, a slower page and not a broken one.
     */
    private function attempt(callable $ask): mixed
    {
        try {
            return $ask($this->store ??= Cache::getInstance($this->category));
        } catch (\Throwable) {
            return false;
        }
    }
}
