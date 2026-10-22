<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use Pramnos\Application\Controllers\Conversations as Page;

/** The conversations page, held by a test. */
class HeldPage extends Page
{
    use Held;
}
