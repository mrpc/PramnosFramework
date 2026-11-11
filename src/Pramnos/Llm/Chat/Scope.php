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
 *
 * And, in `chat`, with one **persona** ({@see Personas}): a character other than the default
 * keeps its own conversations, memories, mood and tasks, stored under `chat:<persona>`
 * ({@see stored()}), while `space` stays `chat` — everything that asks which space it is in
 * still says `chat`. What is the person's rather than a character's — their profile, calendar and
 * journal — is read and written through {@see person()}, the same for every persona. The default
 * persona writes plain `chat`, so conversations from before personas stay its own.
 *
 * And with one **profile** ({@see Profiles}): somebody of the account's family — a child, the
 * other parent — whose conversations, memories, diary, photos, plan and reminders are their own,
 * stored under `chat@<profile>` or `chat:<persona>@<profile>`. The account holder has none, and
 * writes as before.
 */
final class Scope
{
    /** A person's own conversations. */
    public const CHAT = 'chat';

    /** An administrator's analyst, over the whole installation. */
    public const ADMIN = 'admin';

    /** The space: `chat` or `admin`. */
    public readonly string $space;

    /** The persona other than the default, or null. */
    public readonly ?string $persona;

    /** The account's profile speaking, or null for the account holder. */
    public readonly ?int $profile;

    /**
     * @param string      $space   A space, or a stored one with its persona and profile — `chat:tesla@12` — as a row or a job carries it
     * @param string|null $persona A persona other than the default; null for the default
     * @param int|null    $profile A profile of the account other than its holder; null for the holder
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $organizationId = null,
        string $space = self::CHAT,
        ?string $persona = null,
        ?int $profile = null,
    ) {
        if (str_contains($space, '@')) {
            [$space, $stored] = explode('@', $space, 2);
            $profile ??= ctype_digit($stored) ? (int) $stored : null;
        }
        if (str_contains($space, ':')) {
            [$space, $stored] = explode(':', $space, 2);
            $persona ??= $stored;
        }
        $this->space   = $space;
        $this->persona = $persona === null || $persona === '' ? null : $persona;
        $this->profile = $profile === null || $profile <= 0 ? null : $profile;
    }

    /** What the rows of this scope carry in their `space` column: `chat`, `admin`, `chat:<persona>`, `chat@<profile>`… */
    public function stored(): string
    {
        return ($this->persona === null ? $this->space : $this->space . ':' . $this->persona) . ($this->profile === null ? '' : '@' . $this->profile);
    }

    /** The same person, with no persona: what is theirs whichever character they talk to — their profile kept. */
    public function person(): self
    {
        return $this->persona === null ? $this : new self($this->userId, $this->organizationId, $this->space, null, $this->profile);
    }

    /** The same person, with a persona — null for the default — their profile kept. */
    public function withPersona(?string $persona): self
    {
        return $persona === $this->persona ? $this : new self($this->userId, $this->organizationId, $this->space, $persona, $this->profile);
    }

    /** The same person in another space — their profile kept, no persona. */
    public function inSpace(string $space): self
    {
        return new self($this->userId, $this->organizationId, $space, null, $this->profile);
    }

    /** The same account as one of its profiles — null for its holder — the persona kept. */
    public function withProfile(?int $profile): self
    {
        return new self($this->userId, $this->organizationId, $this->space, $this->persona, $profile);
    }

    /** What a call in this space is for, as the router and the call log read it. */
    public function purpose(): string
    {
        // A persona with a feature of its own is asked of that feature's model (Personas).
        return $this->space === self::ADMIN ? 'analyst' : (Personas::feature($this) ?? $this->space);
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
        $query->where('space', $this->stored());

        return $byUser ? $query->where('userid', $this->userId) : $query;
    }

    /**
     * The columns a row written in this scope carries.
     *
     * @return array{organization_id: int|null, userid: int, space: string}
     */
    public function columns(): array
    {
        return ['organization_id' => $this->organizationId, 'userid' => $this->userId, 'space' => $this->stored()];
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
