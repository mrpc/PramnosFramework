<?php

declare(strict_types=1);

namespace Pramnos\Tests\Support;

use Pramnos\Cache\Cache;

/**
 * A cache that keeps strings in memory and cannot count atomically: the fallback's store.
 */
class InMemoryBucketCache extends Cache
{
    /** @var array<string, string> */
    public array $values = [];

    /** Without the parent's adapter machinery. */
    public function __construct()
    {
    }

    /** No adapter: the bucket takes its read-and-write path. */
    public function getAdapter()
    {
        return null;
    }

    /** @return string|false */
    public function load($id, $category = null, $timeout = null)
    {
        return $this->values[$id] ?? false;
    }

    /** @return bool */
    public function save($data = '', $id = null)
    {
        $this->values[(string) $id] = (string) $data;

        return true;
    }
}
