<?php

declare(strict_types=1);

namespace Pramnos\Llm\Companion;

use Pramnos\Llm\Chat\Scope;

/**
 * The personal-companion layer of a person's own conversations, on with
 * `'llm' => ['chat' => ['companion' => true]]`: what they confirmed about themselves and the
 * people in their life ({@see Dossier}), in every prompt, and the tools to propose and confirm it;
 * their appointments and tasks ({@see Calendar}).
 *
 * Off by default: an assistant for work has no business keeping who somebody's brother is. Never
 * in an answer others read too, nor in the administrators' analyst.
 */
final class Companion
{
    private ?Dossier $dossier = null;

    private ?Calendar $calendar = null;

    /** @param string $timezone The person's clock, for their calendar */
    public function __construct(private readonly Scope $scope, private readonly string $timezone = 'UTC')
    {
    }

    /** Whether the application turned it on, for this scope's space. */
    public static function on(Scope $scope): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return $scope->space === Scope::CHAT && is_array($chat) && !empty($chat['companion']);
    }

    /** The person's profile. */
    public function dossier(): Dossier
    {
        return $this->dossier ??= new Dossier($this->scope);
    }

    /** The person's appointments and tasks. */
    public function calendar(): Calendar
    {
        return $this->calendar ??= new Calendar($this->scope, $this->timezone);
    }

    /**
     * The companion's tools, one set per answer: a proposal made in it is known to it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(): array
    {
        return $this->dossier()->tools() + $this->calendar()->tools();
    }

    /** What the companion adds to the prompt's second zone. Empty with nothing. */
    public function context(): string
    {
        return Dossier::available() ? $this->dossier()->context() : '';
    }
}
