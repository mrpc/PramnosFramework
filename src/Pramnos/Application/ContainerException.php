<?php

declare(strict_types=1);

namespace Pramnos\Application;

use Psr\Container\ContainerExceptionInterface;

/**
 * Thrown by Container when resolution fails for a reason other than
 * a missing binding (e.g., autowiring failure, circular dependency).
 *
 * @package     PramnosFramework
 * @subpackage  Application
 * @see         https://www.php-fig.org/psr/psr-11/
 */
class ContainerException extends \RuntimeException implements ContainerExceptionInterface {}
