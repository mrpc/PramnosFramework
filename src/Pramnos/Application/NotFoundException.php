<?php

declare(strict_types=1);

namespace Pramnos\Application;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Thrown by Container::get() when no entry is found for the given identifier.
 *
 * @package     PramnosFramework
 * @subpackage  Application
 * @see         https://www.php-fig.org/psr/psr-11/
 */
class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface {}
