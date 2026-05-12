<?php

declare(strict_types=1);

namespace Pramnos\Cache;

use Psr\SimpleCache\InvalidArgumentException;

/**
 * Thrown by SimpleCache when a cache key violates the PSR-16 specification.
 *
 * @package     PramnosFramework
 * @subpackage  Cache
 */
class SimpleCacheInvalidArgumentException extends \InvalidArgumentException implements InvalidArgumentException {}
