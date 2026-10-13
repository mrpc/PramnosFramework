<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * Whose conversations, memories, questions and schedules these are: one person, and the
 * organisation they are acting in when the application has organisations.
 *
 * Taken from the request — the router's context — or from the row a queued or scheduled run
 * belongs to. Never from anything a model sent.
 *
 * Inside an organisation only that organisation's rows are seen, and outside one only the rows
 * that have none: a row written without an organisation never shows inside one, nor one
 * organisation's in another.
 *
 * And within one **space**: `chat`, a person's own Ask AI, or `admin`, an administrator's
 * analyst. The two share the tables and never each other's rows.
 */
final class Scope
{
    /** A person's own conversations. */
    public const CHAT = 'chat';

    /** An administrator's analyst, over the whole installation. */
    public const ADMIN = 'admin';

    public function __construct(
        public readonly int $userId,
        public readonly ?int $organizationId = null,
        public readonly string $space = self::CHAT,
    ) {
    }

    /** What a call in this space is for, as the router and the call log read it. */
    public function purpose(): string
    {
        return $this->space === self::ADMIN ? 'analyst' : $this->space;
    }

    /**
     * The scope of the current request, from the router's context; null without a person.
     */
    public static function current(): ?self
    {
        $context = \Pramnos\Llm\Llm::router()->context([]);
        $userId  = (int) ($context['userid'] ?? 0);
        $org     = $context['organization_id'] ?? null;

        return $userId > 0 ? new self($userId, $org === null || (int) $org <= 0 ? null : (int) $org) : null;
    }

    /**
     * Narrow a query to this scope's rows.
     *
     * @param bool $byUser Also to this person's — false for what an organisation shares
     */
    public function apply(\Pramnos\Database\QueryBuilder $query, bool $byUser = true): \Pramnos\Database\QueryBuilder
    {
        $this->organizationId === null
            ? $query->whereNull('organization_id')
            : $query->where('organization_id', $this->organizationId);
        $query->where('space', $this->space);

        return $byUser ? $query->where('userid', $this->userId) : $query;
    }

    /**
     * The columns a row written in this scope carries.
     *
     * @return array{organization_id: int|null, userid: int, space: string}
     */
    public function columns(): array
    {
        return ['organization_id' => $this->organizationId, 'userid' => $this->userId, 'space' => $this->space];
    }

    /**
     * The context a model call is made in.
     *
     * @return array{userid: int, organization_id: int|null}
     */
    public function context(): array
    {
        return ['userid' => $this->userId, 'organization_id' => $this->organizationId];
    }
}
