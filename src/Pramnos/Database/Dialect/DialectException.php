<?php

declare(strict_types=1);

namespace Pramnos\Database\Dialect;

/**
 * A MySQL statement that cannot be translated without a guess — an upsert whose conflict target
 * the table does not decide — named with what to write instead.
 */
final class DialectException extends \RuntimeException
{
}
