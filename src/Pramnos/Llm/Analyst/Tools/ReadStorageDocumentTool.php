<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\Files;
use Pramnos\Llm\DocumentText;
use Pramnos\Mcp\McpToolInterface;

/**
 * The words of a file sent in a team conversation this person can read — the harness spec's
 * `read_storage_document`, left unbuilt for lack of a generic permission model for stored files.
 * {@see Files::pathFor()} already is one: the same rights as reading the conversation it was sent
 * in, which {@see ConversationSummaryTool} already answers through for a conversation's words.
 */
final class ReadStorageDocumentTool implements McpToolInterface
{
    /**
     * @param int         $adminId Who is asking; 0 is nobody signed in
     * @param object|null $as      The person asking, where the caller has them already
     */
    public function __construct(private readonly int $adminId = 0, private readonly ?object $as = null)
    {
    }

    public function name(): string
    {
        return 'read_storage_document';
    }

    public function description(): string
    {
        return 'The words of a file sent in a team conversation — Word, OpenDocument, CSV/TSV, plain text, Markdown or '
            . 'a saved email. file is its id, from a conversation\'s own file list. The same rights as reading that '
            . 'conversation itself: one this person cannot read answers nothing, named or not.';
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        return ['type' => 'object', 'required' => ['file'], 'properties' => [
            'file' => ['type' => 'integer', 'description' => 'The file\'s id.'],
        ]];
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input): mixed
    {
        $user = $this->as ?? ($this->adminId > 0 ? new \Pramnos\User\User($this->adminId) : null);
        if ($user === null || !Conversations::installed()) {
            return 'Error: the conversations are not open to you.';
        }
        $id   = (int) ($input['file'] ?? 0);
        $path = $id > 0 ? Files::pathFor($id, $user) : null;
        if ($path === null) {
            return 'Error: there is no such file you can read.';
        }
        $file = (array) Files::get($id);
        $text = DocumentText::from((string) $file['name'], (string) file_get_contents($path));

        return $text ?? 'Error: ' . $file['name'] . ' cannot be read as text — too large, empty, or a kind this '
            . 'does not read the words of (a picture, audio, video, a PDF, an archive).';
    }
}
