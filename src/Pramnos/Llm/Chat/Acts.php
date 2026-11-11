<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

/**
 * What the assistant does in an answer, and what it wears: picked by the model, played by the
 * avatar. The person's own style is the pose the picture keeps between answers.
 *
 * The avatar set declares them, each with when it fits:
 *
 * ```php
 * 'llm' => ['analyst' => ['avatar_set' => [
 *     …,
 *     'acts' => ['explain' => ['plays' => 'teaching', 'when' => 'explaining or teaching something']],
 *     'wear' => ['party'   => ['pose' => 'party', 'when' => 'celebrating good news']],
 * ]]],
 * ```
 *
 * - **Said by the model, if it wants.** The words and their `when` are in the last part of its
 *   instructions; an answer may end with `[[act:explain]]` and `[[wear:party]]`. Both are taken
 *   out of what streams and what is stored ({@see strip()}), like the mood's tag.
 * - **Kept with the answer.** `extra.act` is `{act, plays}` and `extra.wear` is `{wear, pose}`,
 *   or null when the model chose none or a word the set does not have. The act plays while the
 *   answer is read; the wear lasts until the next message — the application's to show.
 * - **The person's style** ({@see style()}): a pose of the set's, kept with their account, shown
 *   all the time; an answer's wear covers it for that answer.
 * - Only in the `chat` space, and only where the set declares acts or wear. Without them, nothing
 *   here runs.
 */
final class Acts
{
    /** The tag of what the answer does. */
    public const ACT = '/\[\[\s*act\s*:\s*([\p{L}\p{N}_-]+)\s*\]\]/u';

    /** The tag of what the answer wears. */
    public const WEAR = '/\[\[\s*wear\s*:\s*([\p{L}\p{N}_-]+)\s*\]\]/u';

    /** The person's style, kept with their account. Not `x_…` in the fourth place: `User::save()` keeps no such detail. */
    public const STYLE_DETAIL = 'askstyle';

    /**
     * The acts the set declares: word → what it plays and when it fits.
     *
     * @return array<string, array{plays: string, when: string}>
     */
    public static function acts(): array
    {
        return self::read('acts', 'plays');
    }

    /**
     * What the set lets an answer wear: word → the pose and when it fits.
     *
     * @return array<string, array{pose: string, when: string}>
     */
    public static function wear(): array
    {
        return self::read('wear', 'pose');
    }

    /** Whether the set declares acts or wear and, given a scope, it is the conversation page's. */
    public static function on(?Scope $scope = null): bool
    {
        return (self::acts() !== [] || self::wear() !== []) && ($scope === null || $scope->space === Scope::CHAT);
    }

    /** What the model is told it may end an answer with; '' when there is nothing to choose. */
    public static function instruction(): string
    {
        $lines = [];
        if (($acts = self::acts()) !== []) {
            $lines[] = 'If one fits what this answer does, you may end it with a private tag on a line of its own, [[act:WORD]], one of: '
                . implode('; ', array_map(static fn (string $w, array $a): string => $w . ($a['when'] !== '' ? ' (' . $a['when'] . ')' : ''), array_keys($acts), $acts)) . '.';
        }
        if (($wear = self::wear()) !== []) {
            $lines[] = 'Rarely, when the moment calls for it, you may also end it with [[wear:WORD]], one of: '
                . implode('; ', array_map(static fn (string $w, array $a): string => $w . ($a['when'] !== '' ? ' (' . $a['when'] . ')' : ''), array_keys($wear), $wear)) . '.';
        }

        return $lines === [] ? '' : implode("\n", $lines) . ' These tags are taken out before anyone reads the answer. Never mention them.';
    }

    /**
     * The act a reply ends with — its last tag — or null for none or a word the set does not have.
     *
     * @return array{act: string, plays: string}|null
     */
    public static function act(string $reply): ?array
    {
        $word = self::last(self::ACT, $reply);
        $acts = self::acts();

        return $word !== null && isset($acts[$word]) ? ['act' => $word, 'plays' => $acts[$word]['plays']] : null;
    }

    /**
     * What a reply wears — its last tag — or null for none or a word the set does not have.
     *
     * @return array{wear: string, pose: string}|null
     */
    public static function worn(string $reply): ?array
    {
        $word = self::last(self::WEAR, $reply);
        $wear = self::wear();

        return $word !== null && isset($wear[$word]) ? ['wear' => $word, 'pose' => $wear[$word]['pose']] : null;
    }

    /** A reply without its act and wear tags — and, while it streams, without a tag still being written. */
    public static function strip(string $reply): string
    {
        return rtrim((string) preg_replace([self::ACT, self::WEAR, '/\[\[[^\]]*\]?$/u'], '', $reply));
    }

    /** The person's style with a persona (null: the default): a pose of the set in use, or null for none. */
    public static function style(object $user, ?string $persona = null): ?string
    {
        $pose = trim((string) ($user->{self::styleDetail($persona)} ?? ''));

        return $pose !== '' && in_array($pose, \Pramnos\Assist\AvatarSets::poses(), true) ? $pose : null;
    }

    /** Keep the person's style: a pose of the set's, or '' for none. False, keeping nothing, for another. */
    public static function choose(object $user, string $pose, ?string $persona = null): bool
    {
        $pose = trim($pose);
        if ($pose !== '' && !in_array($pose, \Pramnos\Assist\AvatarSets::poses(), true)) {
            return false;
        }
        $user->{self::styleDetail($persona)} = $pose;
        $user->save();

        return true;
    }

    /** The detail a style is kept in: one for each persona, the default's without a suffix. */
    private static function styleDetail(?string $persona): string
    {
        return $persona === null ? self::STYLE_DETAIL : self::STYLE_DETAIL . '-' . $persona;
    }

    /**
     * A list the set declares, each entry with its target and its `when`.
     *
     * @return array<string, array<string, string>>
     */
    private static function read(string $list, string $target): array
    {
        $out = [];
        foreach ((array) (\Pramnos\Assist\AvatarSets::current()[$list] ?? []) as $word => $entry) {
            $entry = is_array($entry) ? $entry : [$target => (string) $entry];
            $word  = mb_strtolower(trim((string) $word));
            if (preg_match('/^[\p{L}\p{N}_-]+$/u', $word) === 1 && trim((string) ($entry[$target] ?? '')) !== '') {
                $out[$word] = [$target => trim((string) $entry[$target]), 'when' => trim((string) ($entry['when'] ?? ''))];
            }
        }

        return $out;
    }

    /** The word of the last tag of a kind in a reply, lowercased. */
    private static function last(string $tag, string $reply): ?string
    {
        if (preg_match_all($tag, $reply, $m) < 1) {
            return null;
        }

        return mb_strtolower((string) end($m[1]));
    }
}
