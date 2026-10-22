<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use Pramnos\Application\Controllers\ConversationFiles;

/** The files screen, held by a test. */
class HeldFiles extends ConversationFiles
{
    use Held;
}
