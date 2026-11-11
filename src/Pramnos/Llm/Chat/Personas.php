<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * The characters a person can talk to on the conversation page — one by default, the others by
 * their choice.
 *
 * ```php
 * 'llm' => ['chat' => ['personas' => [
 *     'naya'  => ['name' => 'Naya', 'avatar_set' => [...], 'voice' => 'el-GR-…', 'feature' => 'persona_naya', 'default' => true],
 *     'tesla' => ['character' => 'tesla'],     // a character the framework ships (Assist\Characters)
 * ]]],
 * ```
 *
 * - **Each keeps its own** conversations, memories, word about itself, mood and tasks: the
 *   scope's persona ({@see Scope::$persona}), stored as `chat:<persona>`. The default persona
 *   writes plain `chat`, so what there was before personas stays the default's.
 * - **Shared**: what is the person's — their profile, calendar and journal ({@see Scope::person()}).
 * - **Who it is**: a persona other than the default is told so after the assistant's own
 *   instructions ({@see identity()}); `assistant` gives it an assistant class of its own instead.
 *   `feature` is the purpose its calls are made for, so it can have a model of its own.
 * - **The person's choice** is kept with their account (`askpersona`); `GET {chat}/personas` lists
 *   them, `PUT {chat}/persona` chooses.
 *
 * Only the conversation page's space: the administration's analyst has no personas.
 */
final class Personas
{
    /** The person's choice, kept with their account. Not `x_…` in the fourth place: `User::save()` keeps no such detail. */
    public const USER_DETAIL = 'askpersona';

    /**
     * Every persona, by key: what app.php says, a character's filled in.
     *
     * @return array<string, array{key: string, name: string, gender: ?string, prompt: string, avatar_set: mixed, voice: ?string, feature: ?string, assistant: ?string, size: ?int, default: bool}>
     */
    public static function all(): array
    {
        $chat = (array) \Pramnos\Llm\Llm::config('chat', []);
        $out  = [];
        foreach ((array) ($chat['personas'] ?? []) as $key => $given) {
            $key = strtolower(trim((string) $key));
            if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $key) !== 1 || !is_array($given)) {
                continue;
            }
            $character = is_string($given['character'] ?? null) ? (\Pramnos\Assist\Characters::get($given['character']) ?? []) : [];
            $out[$key] = [
                'key'        => $key,
                'name'       => (string) ($given['name'] ?? $character['name'] ?? ucfirst($key)),
                'gender'     => isset($given['gender']) ? (string) $given['gender'] : ($character['gender'] ?? null),
                'prompt'     => (string) ($given['prompt'] ?? $character['prompt'] ?? ''),
                'avatar_set' => $given['avatar_set'] ?? (isset($character['avatar_set']) ? (string) $given['character'] : null),
                'voice'      => isset($given['voice']) ? (string) $given['voice'] : ($character['voice'] ?? null),
                'feature'    => isset($given['feature']) ? (string) $given['feature'] : null,
                'assistant'  => isset($given['assistant']) ? (string) $given['assistant'] : null,
                'size'       => isset($given['size']) ? (int) $given['size'] : ($character['size'] ?? null),
                'default'    => !empty($given['default']),
            ];
        }

        return $out;
    }

    /** Whether the application has personas to choose between. */
    public static function on(): bool
    {
        return self::all() !== [];
    }

    /**
     * One persona, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** The default persona's key: the one marked `default`, else the first; null without personas. */
    public static function default(): ?string
    {
        $all = self::all();
        foreach ($all as $key => $persona) {
            if ($persona['default']) {
                return $key;
            }
        }

        return array_key_first($all);
    }

    /** The persona a person chose, if it is still offered; else the default. Null without personas. */
    public static function chosen(object $user): ?string
    {
        $key = trim((string) ($user->{self::USER_DETAIL} ?? ''));

        return $key !== '' && isset(self::all()[$key]) ? $key : self::default();
    }

    /** Keep a person's choice. False, keeping nothing, for a persona that is not offered. */
    public static function choose(object $user, string $key): bool
    {
        if (!isset(self::all()[$key])) {
            return false;
        }
        $user->{self::USER_DETAIL} = $key === self::default() ? '' : $key;
        $user->save();

        return true;
    }

    /**
     * A request's scope with the persona its person chose — in the conversation page's space,
     * where the application has personas; the default persona is no persona (plain `chat`).
     */
    public static function scopeFor(Scope $scope, ?object $user = null): Scope
    {
        if ($scope->space !== Scope::CHAT || $scope->persona !== null || !self::on()) {
            return $scope;
        }
        $chosen = self::chosen($user ?? new \Pramnos\User\User($scope->userId));

        return $scope->withPersona($chosen === self::default() ? null : $chosen);
    }

    /** The key of a scope's persona: its own, or the default for none. Null without personas. */
    public static function of(Scope $scope): ?string
    {
        return $scope->space !== Scope::CHAT ? null : ($scope->persona ?? self::default());
    }

    /**
     * Who the assistant is, for a persona other than the default — after the assistant's own
     * instructions, which describe the default. '' otherwise.
     */
    public static function identity(Scope $scope): string
    {
        if ($scope->persona === null || ($persona = self::get($scope->persona)) === null || $persona['assistant'] !== null) {
            return '';
        }

        return 'In this conversation you are ' . $persona['name'] . ', not anybody another instruction names. '
            . ($persona['prompt'] !== '' ? $persona['prompt'] . ' ' : '')
            . 'Keep everything else above: what you can do, and how.';
    }

    /**
     * The avatar set of a scope's persona — the default's too, where it names one — or null for the
     * analyst's own (app.php's `analyst.avatar_set`).
     *
     * @return string|array<string, mixed>|null
     */
    public static function avatarSet(Scope $scope): string|array|null
    {
        $key = self::of($scope);
        $set = $key === null ? null : (self::get($key)['avatar_set'] ?? null);

        return is_string($set) || is_array($set) ? $set : null;
    }

    /** The purpose a scope's calls are made for: its persona's `feature`, where it names one. */
    public static function feature(Scope $scope): ?string
    {
        $key = self::of($scope);

        return $key === null ? null : (self::get($key)['feature'] ?? null);
    }
}
