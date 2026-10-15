<?php

declare(strict_types=1);

namespace Pramnos\Html\Form;

/**
 * A form's submission carried no CSRF token this session issued, so its values were not read.
 *
 * Thrown rather than answered with an empty array: a caller that saved what it got back would
 * write "every field empty" over the stored values, which is how a rejected submission blanked a
 * payment provider's settings.
 */
final class TokenRejected extends \RuntimeException
{
}
