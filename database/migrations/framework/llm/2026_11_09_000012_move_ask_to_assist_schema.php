<?php

namespace Pramnos\Framework\Migrations\Llm;

require_once dirname(__DIR__) . '/conversations/2026_11_09_000008_move_conversations_and_ask_to_schemas.php';

use Pramnos\Framework\Migrations\Conversations\MoveConversationsAndAskToSchemas;

/**
 * Moves the assistant's (Ask) tables into `assist.*`, for an installation with language models but
 * without the conversations: the move that does both lives with the conversations, whose
 * migrations such an installation never runs, while the code reads `assist.chat_*` either way.
 *
 * The same move, the assistant's tables only; a table already moved is left alone, so whichever of
 * the two runs first does it and the other finds nothing.
 */
class MoveAskToAssistSchema extends MoveConversationsAndAskToSchemas
{
    public string $feature     = 'llm';
    public $description = 'Moves the assistant into assist.*';

    /** The assistant's tables only. */
    public const MOVES = ['assist' => parent::MOVES['assist']];
}
