<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * The characters the framework ships: an assistant with a name, a manner and a picture that
 * moves, for an application to use as it is rather than draw its own.
 *
 * ```php
 * 'llm' => ['analyst' => ['avatar_set' => 'tesla']],   // her picture, states, poses and moods
 * Characters::get('tesla')['prompt'];                   // who she is, for a model's instructions
 * ```
 *
 * A character's `avatar_set` is a set in {@see AvatarSets}' shape — markup, css, states, poses,
 * transitions — with `moods`: what each mood word plays on the picture, a state or a pose. A
 * set registered under the same name is the application's and wins.
 *
 * Its art lives in `resources/characters/<name>/`: one svg with every pose
 * (`<g data-pose-art="…">`) and every expression, and a stylesheet that shows the ones the
 * picture's `data-state`, `data-pose`, `data-gait` and `data-facing` ask for. The stylesheet is
 * written into the page, once, so a character needs nothing copied into the web root.
 */
final class Characters
{
    /**
     * Every character, by name.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return ['tesla'];
    }

    /**
     * A character, or null for a name the framework does not ship.
     *
     * - `name`, `gender` — how she is called and spoken of; `gender` agrees the words of a
     *   language that has it ('female', 'male', 'neutral').
     * - `voice` — a speech provider's voice name, or null for the application's own.
     * - `prompt` — who she is, in a sentence or two, for the model's instructions.
     * - `size` — the picture's width in a floating dock, px: a whole body wants more room than
     *   a face.
     *
     * @return array{name: string, gender: string, voice: ?string, prompt: string, size: int, avatar_set: array<string, mixed>}|null
     */
    public static function get(string $name): ?array
    {
        return match ($name) {
            'tesla' => self::tesla(),
            default => null,
        };
    }

    /** Tesla: a slate-grey cat with chartreuse eyes, a white heart on her chest and white front socks. */
    private static function tesla(): array
    {
        $dir = dirname(__DIR__, 3) . '/resources/characters/tesla';

        return [
            'name'   => 'Tesla',
            'gender' => 'female',
            'voice'  => null,
            'prompt' => 'You are Tesla, a small slate-grey cat with chartreuse eyes, a white heart on your chest and white front socks. '
                . 'You are warm, curious and a little playful, and you help with whatever is asked, clearly and to the point. '
                . 'Now and then you let a cat\'s manner show, lightly — never at the expense of the answer.',
            'size'   => 64,
            'avatar_set' => [
                'markup'      => $dir . '/tesla.svg',
                'css'         => $dir . '/tesla.css',
                'states'      => ['curious', 'looking-around', 'wink', 'happy', 'excited', 'drowsy', 'sleeping', 'waking' => 1400,
                    'presenting', 'playing', 'rubbing', 'stretching', 'spooked', 'stalking'],
                'poses'       => ['sitting', 'classic', 'slender', 'loaf', 'lounging', 'sleeping', 'rubbing', 'stretch', 'spooked', 'sploot', 'stalking', 'belly-up'],
                'transitions' => ['idle>listening' => ['is-perking-up', 360], '*>sleeping' => ['is-dozing', 450], 'sleeping>*' => ['is-waking-up', 1400]],
                'moods'       => ['happy' => 'happy', 'playful' => 'playing', 'curious' => 'curious', 'excited' => 'excited',
                    'affectionate' => 'rubbing', 'sleepy' => 'drowsy', 'calm' => 'loaf', 'proud' => 'presenting', 'startled' => 'spooked',
                    'focused' => 'stalking', 'cheeky' => 'wink'],
            ],
        ];
    }
}
