<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * An artifact the assistant made — a report, a list, a table, a text — handed to another
 * administrator to look into, as a to-do of theirs.
 *
 * {@see assign()} shares the artifact with them ({@see Shares}: it reaches them as a share they
 * open) and opens a to-do in their list that leads to it, with the note and a due day. When they
 * close it, or add a note to it, whoever handed it over is told ({@see \Pramnos\Todos\Todos}).
 *
 * The to-do list is the application's where it keeps its own:
 *
 * ```php
 * 'llm' => ['chat' => ['assign' => fn (array $item): int => MyTodos::add($item)]],
 * // $item: title, note, link, assigned_to, assigned_by, due (Y-m-d or null), artifact_id
 * ```
 *
 * and the framework's `Pramnos\Todos` otherwise.
 */
final class Assignments
{
    /** The kind a to-do handed over this way has in the framework's list. */
    public const KIND = 'assigned';

    public function __construct(private readonly Scope $scope)
    {
    }

    /**
     * Hand an artifact to somebody (a username or an email) to look into: shared with them, and a
     * to-do in their list. Answers the to-do and where it leads, or why not.
     *
     * @return array{todo: int, to: string, link: string}|string
     */
    public function assign(int $artifactId, string $to, string $note = '', ?string $due = null): array|string
    {
        $artifact = (new Artifacts($this->scope))->find($artifactId);
        if ($artifact === null) {
            return 'There is no such artifact.';
        }
        $due = $due === null || trim($due) === '' ? null : trim($due);
        if ($due !== null && \DateTimeImmutable::createFromFormat('!Y-m-d', $due) === false) {
            return 'A due day is Y-m-d.';
        }
        $recipients = SharePolicy::current()->recipients($this->scope, [trim($to)]);
        if ($recipients === []) {
            return 'There is nobody called ' . trim($to) . ' to hand it to.';
        }
        $recipient = (int) reset($recipients);
        $shared    = (new Shares())->share($this->scope, Shares::ARTIFACT, $artifactId, [trim($to)]);
        if (is_string($shared)) {
            return $shared;
        }
        $share = array_values(array_filter((new Shares())->sharesOf($this->scope, Shares::ARTIFACT, $artifactId), static fn (array $s): bool => $s['recipient'] === $recipient))[0] ?? null;
        $link  = $share === null ? '' : SharePolicy::current()->link(['space' => $this->scope->space, 'share_id' => $share['share_id']]);
        // A to-do's link is an address or a path from /: a relative one is made one.
        if ($link !== '' && preg_match('~^(/|https?://)~', $link) !== 1) {
            $link = '/' . $link;
        }
        $note  = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $note)), 0, 300);
        $item  = [
            'title'       => mb_substr(t('Look into') . ': ' . $artifact['title'] . ($note !== '' ? ' — ' . $note : ''), 0, 250),
            'note'        => $note,
            'link'        => $link,
            'assigned_to' => $recipient,
            'assigned_by' => $this->scope->userId,
            'due'         => $due,
            'artifact_id' => $artifactId,
        ];
        $todo = $this->todo($item);

        return is_string($todo) ? $todo : ['todo' => $todo, 'to' => Shares::nameOf($recipient), 'link' => $link];
    }

    /**
     * `assign_artifact`, for the administration's Ask.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        return ['assign_artifact' => [
            'description' => 'Hand one of the artifacts to another administrator to look into: it is shared with them and becomes a '
                . 'to-do in their list, with a note and a due day. Only after the person confirmed who, and by when — repeat it back '
                . 'and ask first.',
            'parameters'  => ['type' => 'object', 'properties' => [
                'id'   => ['type' => 'integer', 'description' => 'The artifact\'s id.'],
                'to'   => ['type' => 'string', 'description' => 'Their username or email.'],
                'note' => ['type' => 'string', 'description' => 'What to look into, briefly.'],
                'due'  => ['type' => 'string', 'description' => 'Y-m-d, or nothing.'],
            ], 'required' => ['id', 'to']],
            'run'         => function (array $a): string {
                $done = $this->assign((int) ($a['id'] ?? 0), (string) ($a['to'] ?? ''), (string) ($a['note'] ?? ''), isset($a['due']) ? (string) $a['due'] : null);

                return is_string($done) ? 'Error: ' . $done : 'Handed to ' . $done['to'] . ', a to-do in their list.';
            },
            'writes'      => true,
        ]];
    }

    /**
     * The to-do: the application's list where it has one, the framework's otherwise.
     *
     * @param array<string, mixed> $item
     */
    private function todo(array $item): int|string
    {
        $hook = ((array) \Pramnos\Llm\Llm::config('chat', []))['assign'] ?? null;
        if (is_callable($hook)) {
            $made = $hook($item);

            return is_int($made) || (is_string($made) && ctype_digit($made)) ? (int) $made : (is_string($made) ? $made : 'The to-do could not be made.');
        }
        if (!\Pramnos\Todos\Todos::installed()) {
            return 'There is no to-do list here.';
        }
        try {
            return \Pramnos\Todos\Todos::add(['title' => $item['title'], 'link' => $item['link'] !== '' ? $item['link'] : null, 'assigned_to' => $item['assigned_to'],
                'kind' => self::KIND, 'source' => 'artifact:' . $item['artifact_id'] . ':' . $item['assigned_to'], 'due' => $item['due']], (int) $item['assigned_by']);
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }
    }
}
