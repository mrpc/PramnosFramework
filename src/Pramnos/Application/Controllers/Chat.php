<?php

declare(strict_types=1);

namespace Pramnos\Application\Controllers;

/**
 * `/admin/Chat/…`, the conversations' first address — the same screen as {@see Conversations}.
 *
 * @deprecated Extend {@see Conversations}: an application that has the AI chat page at `/chat`
 *             has a site controller named `Chat`, which `/admin/Chat` reaches before this one.
 */
class Chat extends Conversations
{
}
