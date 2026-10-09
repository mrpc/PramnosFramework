<?php

declare(strict_types=1);

namespace Pramnos\Http;

use Pramnos\Cache\Cache;

/**
 * A token bucket: a burst at once, then a steady rate.
 *
 * The bucket holds up to `$capacity` tokens and refills at `$rate` per `$per` seconds; each
 * request takes one, and a request that finds the bucket empty is refused until the next token
 * arrives. So a client that has been quiet may send `$capacity` requests at once, and one that
 * keeps sending is held to the rate — which is what "1000 an hour, bursts of 100" means.
 *
 * On Redis the take is one Lua script, so concurrent requests cannot both spend the last
 * token. On any other cache store it is a read and a write, and two requests racing can both
 * get through.
 * ponytail: non-atomic outside Redis; a store with a compare-and-set would close it.
 */
final class TokenBucket
{
    private const SCRIPT = <<<'LUA'
local capacity = tonumber(ARGV[1])
local rate     = tonumber(ARGV[2])
local now      = tonumber(ARGV[3])
local state    = redis.call('HMGET', KEYS[1], 'tokens', 'at')
local tokens   = tonumber(state[1])
local at       = tonumber(state[2])
if tokens == nil then tokens = capacity; at = now end
tokens = math.min(capacity, tokens + (now - at) * rate)
local allowed = 0
if tokens >= 1 then tokens = tokens - 1; allowed = 1 end
redis.call('HSET', KEYS[1], 'tokens', tostring(tokens), 'at', tostring(now))
redis.call('EXPIRE', KEYS[1], math.ceil(capacity / rate) + 60)
return {allowed, tostring(tokens)}
LUA;

    /**
     * @param Cache|null $cache Where the buckets are kept; the default cache when null
     */
    public function __construct(private ?Cache $cache = null)
    {
    }

    /**
     * Take one token from a bucket.
     *
     * @param string $key      Whose bucket
     * @param int    $capacity The burst: tokens a full bucket holds (at least 1)
     * @param int    $rate     Tokens added per `$per` seconds
     * @param int    $per      The period of `$rate`, in seconds
     * @return array{allowed: bool, remaining: int, retry_after: int} Seconds until a token, when refused
     */
    public function take(string $key, int $capacity, int $rate, int $per): array
    {
        // One Redis may serve several installations, and `app:3` is a different application in each.
        $key      = (defined('sURL') ? md5((string) sURL) . ':' : '') . $key;
        $capacity = max(1, $capacity);
        $perSecond = max(1, $rate) / max(1, $per);
        $now       = microtime(true);
        $cache     = $this->cache ?? \Pramnos\Framework\Factory::getCache();

        $redis = $this->redisOf($cache);
        if ($redis !== null) {
            [$allowed, $tokens] = $redis->eval(self::SCRIPT, ['tokenbucket:' . $key, $capacity, $perSecond, $now], 1);
            $tokens = (float) $tokens;
            $allowed = (int) $allowed === 1;
        } else {
            $id    = 'tokenbucket-' . md5($key);
            $state = json_decode((string) $cache->load($id), true);
            $state = is_array($state) && isset($state['tokens'], $state['at']) ? $state : ['tokens' => $capacity, 'at' => $now];
            $tokens  = min($capacity, (float) $state['tokens'] + ($now - (float) $state['at']) * $perSecond);
            $allowed = $tokens >= 1;
            if ($allowed) {
                $tokens--;
            }
            $cache->save((string) json_encode(['tokens' => $tokens, 'at' => $now]), $id);
        }

        return [
            'allowed'     => $allowed,
            'remaining'   => (int) floor($tokens),
            'retry_after' => $allowed ? 0 : (int) ceil((1 - $tokens) / $perSecond),
        ];
    }

    /** The Redis connection behind a cache, when there is one. */
    private function redisOf(Cache $cache): ?\Redis
    {
        $adapter = $cache->getAdapter();
        if (!$adapter instanceof \Pramnos\Cache\Adapter\RedisAdapter) {
            return null;
        }
        // connect() answers false rather than throwing; a Redis that did not answer leaves no connection.
        $adapter->connect();
        $connection = $adapter->getConnection();

        return $connection instanceof \Redis ? $connection : null;
    }
}
