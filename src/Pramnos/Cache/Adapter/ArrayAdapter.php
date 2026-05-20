<?php

namespace Pramnos\Cache\Adapter;

/**
 * In-memory cache adapter backed by a PHP array.
 *
 * Intended for unit tests and environments where no external cache daemon is
 * available. Data is stored in an instance-level array and is never persisted
 * to disk or shared across processes. TTL is honoured: entries silently expire
 * when their lifetime has elapsed.
 *
 * @author      Yannis - Pastis Glaros <mrpc@pramnoshosting.gr>
 * @copyright   (C) 2024 Yannis - Pastis Glaros
 */
class ArrayAdapter extends AbstractAdapter
{
    /**
     * Stored entries.
     * Shape: [ $key => ['data' => mixed, 'expires' => int] ]
     * expires = 0 means never-expires.
     *
     * @var array<string, array{data: mixed, expires: int}>
     */
    private array $store = [];

    /**
     * Category-hash registry (used by categoryHash()).
     *
     * @var array<string, string>
     */
    private array $hashes = [];

    /**
     * @param string $prefix Prefix for all cache keys.
     */
    public function __construct(string $prefix = '')
    {
        parent::__construct($prefix);
    }

    /**
     * No external connection required — always succeeds.
     */
    public function connect(): bool
    {
        return true;
    }

    /**
     * Load a cached item.
     *
     * Returns false when the key is absent or the entry has expired. Expired
     * entries are pruned on access (lazy expiry).
     *
     * @param string $key     Cache key (already prefixed by Cache class).
     * @param int    $timeout Not used for lookup; included to satisfy interface.
     * @return mixed|false
     */
    public function load($key, $timeout = 3600): mixed
    {
        $fullKey = $this->prefix . $key;

        if (!array_key_exists($fullKey, $this->store)) {
            return false;
        }

        $entry = $this->store[$fullKey];

        if ($entry['expires'] !== 0 && time() > $entry['expires']) {
            unset($this->store[$fullKey]);
            return false;
        }

        return $entry['data'];
    }

    /**
     * Save a value to the in-memory store.
     *
     * @param string $key     Cache key.
     * @param mixed  $data    Value to cache.
     * @param int    $timeout TTL in seconds. 0 = never expires.
     */
    public function save($key, $data, $timeout = 3600): bool
    {
        $fullKey = $this->prefix . $key;
        $this->store[$fullKey] = [
            'data'    => $data,
            'expires' => $timeout > 0 ? time() + $timeout : 0,
        ];
        return true;
    }

    /**
     * Delete a single entry.
     */
    public function delete($key): bool
    {
        $fullKey = $this->prefix . $key;
        $existed = array_key_exists($fullKey, $this->store);
        unset($this->store[$fullKey]);
        return $existed;
    }

    /**
     * Clear the entire store or only entries whose key contains $category.
     */
    public function clear($category = ''): bool
    {
        if ($category === '') {
            $this->store = [];
            return true;
        }

        foreach (array_keys($this->store) as $key) {
            if (str_contains($key, $category)) {
                unset($this->store[$key]);
            }
        }
        return true;
    }

    /**
     * Returns the list of category substrings present in stored keys.
     */
    public function getCategories($prefix = ''): array
    {
        $categories = [];
        foreach (array_keys($this->store) as $key) {
            if ($prefix === '' || str_starts_with($key, $prefix)) {
                $parts = explode('_', $key, 2);
                if (isset($parts[0]) && $parts[0] !== '') {
                    $categories[$parts[0]] = true;
                }
            }
        }
        return array_keys($categories);
    }

    /**
     * Returns basic statistics about the in-memory store.
     */
    public function getStats(): array
    {
        $this->pruneExpired();
        return [
            'adapter' => 'array',
            'items'   => count($this->store),
            'bytes'   => strlen(serialize($this->store)),
        ];
    }

    /**
     * Always available.
     */
    public function test(): bool
    {
        return true;
    }

    /**
     * Get or set a category hash. Resets to a new random hash when $reset = true.
     */
    public function categoryHash($category, $prefix = '', $reset = false): string
    {
        $hashKey = $prefix . $category;
        if ($reset || !isset($this->hashes[$hashKey])) {
            $this->hashes[$hashKey] = substr(md5($hashKey . uniqid('', true)), 0, 8);
        }
        return $this->hashes[$hashKey];
    }

    /**
     * Return all non-expired cache items with metadata.
     */
    public function getAllItems($category = '', $limit = 100): array
    {
        $this->pruneExpired();
        $items = [];
        $count = 0;

        foreach ($this->store as $key => $entry) {
            if ($category !== '' && !str_contains($key, $category)) {
                continue;
            }
            $items[] = [
                'key'          => $key,
                'size'         => strlen(serialize($entry['data'])),
                'created_time' => $entry['expires'] > 0 ? $entry['expires'] - 3600 : 0,
                'expires'      => $entry['expires'],
            ];
            if (++$count >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * Remove all expired entries from the store.
     */
    private function pruneExpired(): void
    {
        $now = time();
        foreach ($this->store as $key => $entry) {
            if ($entry['expires'] !== 0 && $now > $entry['expires']) {
                unset($this->store[$key]);
            }
        }
    }
}
