<?php

declare(strict_types=1);

namespace Pramnos\Assist;

/**
 * The analyst's living picture: a set of markup and CSS that moves with what it is doing.
 *
 * The contract is one element and one attribute. The framework draws
 *
 * ```html
 * <span class="pf-assistant-avatar pf-assistant--idle" data-state="idle" style="--pf-assistant-size:24px">…the set's markup…</span>
 * ```
 *
 * and changes only `data-state` (and the `pf-assistant--{state}` class with it), to one of
 * {@see STATES}; every movement is the set's CSS, reacting to `[data-state="…"]`. A state that
 * ends by itself — `success`, `greeting` — goes back to `idle` after the set's duration.
 *
 * An application names its own set in app.php, or registers it and names it:
 *
 * ```php
 * 'llm' => ['analyst' => ['avatar_set' => [
 *     'markup' => APP_PATH . '/themes/admin/assets/aria/aria.svg',  // a file, a string, or a callable
 *     'css'    => '/themes/admin/assets/aria/aria.css',
 *     'states' => ['success' => 1800, 'greeting' => 2400],          // ms, for the states that end by themselves
 * ]]],
 *
 * AvatarSets::register('aria', $markup, '/assets/aria.css', ['greeting' => 2400]);
 * 'llm' => ['analyst' => ['avatar_set' => 'aria']],
 * ```
 *
 * A set may have more than the framework's states, poses, and transitions between states:
 *
 * ```php
 * 'states'      => ['curious', 'drowsy', 'sleeping', 'success' => 1800],  // a name alone is a state of its own; name => ms ends by itself
 * 'poses'       => ['sitting', 'loaf', 'sploot'],                          // set as data-pose, independent of the state
 * 'transitions' => ['idle>listening' => ['is-perking-up', 360], 'sleeping>*' => 'is-waking-up'],
 * ```
 *
 * The framework still sets only {@see STATES} by itself; the others are the application's to ask
 * for (`pfAssistant.state('curious')`, a mood's `plays`). A transition's class is on the element
 * for its milliseconds (400 when not given) when the state changes from → to; `*` is any.
 *
 * A name that is not registered is one of the framework's characters ({@see Characters}):
 * `'avatar_set' => 'tesla'`. `{uid}` in a set's markup becomes a different value in each picture
 * drawn, for the ids a gradient or a mask needs; `css` that is a file on disk rather than an
 * address is written into the page, once, as a `<style>`.
 *
 * Without one it is the framework's own, `pf`: a round drop with two eyes, in the theme's accent,
 * still where the reader asks for less motion. A plain picture (`llm.analyst.avatar`) with no set
 * is drawn as that picture, in the same element, and does not move.
 */
final class AvatarSets
{
    /** The states, a closed list: the framework sets only these. */
    public const STATES = ['idle', 'listening', 'thinking', 'talking', 'success', 'error', 'offline', 'greeting', 'notify'];

    /** How long a state that ends by itself lasts before `idle`, unless the set says otherwise (ms). */
    public const DURATIONS = ['success' => 1800, 'greeting' => 2400, 'notify' => 4000];

    /** @var array<string, array{markup: string|callable, css: ?string, states: array<int|string, int|string>, poses: list<string>, transitions: array<string, string|array>}> */
    private static array $sets = [];

    /** How long a transition's class stays when the set does not say (ms). */
    public const TRANSITION_MS = 400;

    /** Whether a set's stylesheet has been written into this page already, by URL. */
    private static array $linked = [];

    /** Pictures drawn in this process, for `{uid}`. */
    private static int $drawn = 0;

    /** A set in use for a while instead of app.php's ({@see using()}): a persona's. */
    private static mixed $using = null;

    /** The name its picture is called by meanwhile — the persona's or the character's. */
    private static ?string $usingName = null;

    /**
     * Run with another set in use — a persona's — as if app.php named it: its moods, acts and
     * picture. The set before is back afterwards, whatever `$run` does.
     *
     * @template T
     * @param string|array<string, mixed>|null $set A registered name, a character, a set — or null for app.php's, under another name
     * @param callable(): T                $run
     * @param string|null                  $name What its picture is called (`aria-label`): a character's own name by default
     * @return T
     */
    public static function using(string|array|null $set, callable $run, ?string $name = null): mixed
    {
        [$before, $beforeName] = [self::$using, self::$usingName];
        self::$using     = $set;
        self::$usingName = $name ?? (is_string($set) && !isset(self::$sets[$set]) ? (Characters::get($set)['name'] ?? null) : null);
        try {
            return $run();
        } finally {
            [self::$using, self::$usingName] = [$before, $beforeName];
        }
    }

    /**
     * What the picture does when touched — `tickle`, `pet`, `push`, `poke` → `{plays, buzz?, repeat?,
     * sound?}` — for the page that notices the touch to play. Never told to a model: a touch is the
     * picture's, not the conversation's.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function reactions(): array
    {
        $out = [];
        foreach ((array) (self::current()['reactions'] ?? []) as $touch => $reaction) {
            $reaction = is_array($reaction) ? $reaction : ['plays' => (string) $reaction];
            if (is_string($touch) && trim((string) ($reaction['plays'] ?? '')) !== '') {
                $out[$touch] = $reaction;
            }
        }

        return $out;
    }

    /**
     * The gaits the set in use draws — `walk`, `trot`, `sprint`, `zoomies` as `data-gait` — for
     * a page to offer; [] for a set that does not move.
     *
     * @return list<string>
     */
    public static function gaits(): array
    {
        return array_values(array_map('strval', (array) (self::current()['gaits'] ?? [])));
    }

    /**
     * Make a set known by name.
     *
     * @param string|callable(): string $markup Markup, a file holding it, or what returns it
     * @param array<int|string, int|string> $states      Milliseconds for the states that end by themselves; a name alone, a state of the set's own
     * @param list<string>                  $poses       The set's poses
     * @param array<string, string|array>   $transitions 'from>to' => class, or [class, ms]
     */
    public static function register(string $name, string|callable $markup, ?string $css = null, array $states = [], array $poses = [], array $transitions = []): void
    {
        self::$sets[$name] = ['markup' => $markup, 'css' => $css, 'states' => $states, 'poses' => $poses, 'transitions' => $transitions];
    }

    /** Forget the registered sets and the stylesheets written: tests. */
    public static function reset(): void
    {
        self::$sets   = [];
        self::$linked = [];
        self::$drawn  = 0;
    }

    /**
     * The set in use: `llm.analyst.avatar_set` — a registered name or the set itself — or the
     * framework's; null where the application gives a plain picture and no set.
     *
     * @return array{markup: string|callable, css: ?string, states: array<int|string, int|string>, poses: list<string>, transitions: array<string, string|array>}|null
     */
    public static function current(): ?array
    {
        $config = \Pramnos\Llm\Llm::config('analyst', []);
        $set    = self::$using ?? (is_array($config) ? ($config['avatar_set'] ?? null) : null);
        if (is_string($set) && isset(self::$sets[$set])) {
            return self::$sets[$set];
        }
        if (is_string($set) && ($character = Characters::get($set)) !== null) {
            $set = $character['avatar_set'];
        }
        if (is_array($set) && isset($set['markup']) && (is_string($set['markup']) || is_callable($set['markup']))) {
            return ['markup' => $set['markup'], 'css' => isset($set['css']) ? (string) $set['css'] : null, 'states' => (array) ($set['states'] ?? []),
                'poses' => array_values(array_map('strval', (array) ($set['poses'] ?? []))), 'transitions' => (array) ($set['transitions'] ?? []),
                'moods' => array_map('strval', (array) ($set['moods'] ?? [])), 'acts' => (array) ($set['acts'] ?? []), 'wear' => (array) ($set['wear'] ?? []),
                'gaits' => (array) ($set['gaits'] ?? []), 'visemes' => !empty($set['visemes']), 'reactions' => (array) ($set['reactions'] ?? [])];
        }
        if (\Pramnos\Llm\Analyst\Analyst::avatar() !== null) {
            return null;
        }

        return ['markup' => self::DEFAULT_MARKUP, 'css' => null, 'states' => [], 'poses' => [], 'transitions' => []];
    }

    /**
     * Every state the set in use plays: the framework's, then its own.
     *
     * @return list<string>
     */
    public static function states(): array
    {
        $own = [];
        foreach ((array) (self::current()['states'] ?? []) as $key => $value) {
            $own[] = is_int($key) ? (string) $value : (string) $key;
        }

        return array_values(array_unique([...self::STATES, ...array_filter($own, static fn (string $s): bool => preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $s) === 1)]));
    }

    /**
     * The set's poses.
     *
     * @return list<string>
     */
    public static function poses(): array
    {
        return (array) (self::current()['poses'] ?? []);
    }

    /**
     * What each mood word plays on the picture, a state or a pose: the moods of a set given in
     * app.php, else `analyst.avatar_moods`, else those of the set in use (a character's).
     *
     * @return array<string, string>
     */
    public static function moods(): array
    {
        $analyst = (array) \Pramnos\Llm\Llm::config('analyst', []);
        $set     = self::$using ?? ($analyst['avatar_set'] ?? null);
        $given   = is_array($set) && is_array($set['moods'] ?? null) ? $set['moods'] : [];
        // A persona's set is its own: app.php's avatar_moods are the analyst's set's.
        $moods   = $given !== [] ? $given : ((self::$using === null ? (array) ($analyst['avatar_moods'] ?? []) : []) ?: (array) (self::current()['moods'] ?? []));

        return array_map('strval', $moods);
    }

    /**
     * The set's transitions, each as a class and its milliseconds.
     *
     * @return array<string, array{class: string, ms: int}>
     */
    public static function transitions(): array
    {
        $out = [];
        foreach ((array) (self::current()['transitions'] ?? []) as $when => $how) {
            $class = is_array($how) ? (string) ($how[0] ?? $how['class'] ?? '') : (string) $how;
            if (str_contains((string) $when, '>') && $class !== '') {
                $out[(string) $when] = ['class' => $class, 'ms' => (int) (is_array($how) ? ($how[1] ?? $how['ms'] ?? self::TRANSITION_MS) : self::TRANSITION_MS)];
            }
        }

        return $out;
    }

    /**
     * The analyst's picture, in a state, at a size: the contract's element around the set's
     * markup — or around the plain picture, where that is what the application gives — with the
     * set's stylesheet the first time a page draws it.
     *
     * `$link = false` for a picture that is data rather than the page — in JSON, a `<template>`, a
     * script's props: no `<link>`, and the stylesheet is not counted as written, so the page still
     * gets it from {@see stylesheet()} or the next picture it draws.
     */
    public static function render(string $state = 'idle', int $size = 24, string $class = '', bool $link = true): string
    {
        $set    = self::current();
        $states = $set === null ? self::STATES : self::states();
        $state  = in_array($state, $states, true) ? $state : 'idle';
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $name  = self::$usingName ?? \Pramnos\Llm\Analyst\Analyst::name();
        if ($set === null) {
            $inner     = '<img src="' . $e((string) \Pramnos\Llm\Analyst\Analyst::avatar()) . '" alt="" width="' . $size . '" height="' . $size . '">';
            $durations = self::DURATIONS;
            $css       = null;
        } else {
            $inner     = str_replace('{uid}', 'pfa' . ++self::$drawn, self::markup($set['markup']));
            $durations = array_map('intval', array_filter($set['states'], static fn ($v, $k): bool => is_string($k) && is_numeric($v), ARRAY_FILTER_USE_BOTH) + self::DURATIONS);
            $css       = $set['css'];
        }

        return ($link ? self::link($css) : '') . '<span class="pf-assistant-avatar pf-assistant--' . $state . ($class !== '' ? ' ' . $e($class) : '') . '" data-state="' . $state . '"'
            . ' data-durations="' . $e((string) json_encode(array_intersect_key($durations, array_flip($states)))) . '"'
            // The set's own states, poses and transitions, for pfAssistant and the debug panel.
            . (count($states) > count(self::STATES) ? ' data-states="' . $e(implode(',', array_slice($states, count(self::STATES)))) . '"' : '')
            . ($set !== null && $set['poses'] !== [] ? ' data-poses="' . $e(implode(',', $set['poses'])) . '"' : '')
            . ($set !== null && self::transitions() !== [] ? ' data-transitions="' . $e((string) json_encode(self::transitions())) . '"' : '')
            // A mouth for each sound: pf-voice sets data-viseme while it speaks (A E I O U M F L S rest).
            . ($set !== null && !empty($set['visemes']) ? ' data-visemes' : '')
            // Offline is the server's word: the page's own states do not wake it.
            . ($state === 'offline' ? ' data-offline' : '')
            // Its size inline, so a page without the theme's components.css does not draw it the
            // width of the page; through the variable, so a theme can still size it where it sits.
            . ' style="' . ($size !== 24 ? '--pf-assistant-size:' . max(8, $size) . 'px;' : '')
            . 'display:inline-block;vertical-align:middle;width:var(--pf-assistant-size,' . max(8, $size) . 'px);height:var(--pf-assistant-size,' . max(8, $size) . 'px)"'
            . ' role="img" aria-label="' . $e($name) . '">' . $inner . '</span>';
    }

    /**
     * The current set's stylesheet, once a page — for a caller that draws the picture inside an
     * element where a `<link>` does not belong (a button, a link), to put it before. '' when there
     * is none or it is in the page already.
     */
    public static function stylesheet(): string
    {
        return self::link(self::current()['css'] ?? null);
    }

    /** A `<link>` to a stylesheet the first time a page asks for it; '' after, or for none. */
    private static function link(?string $css): string
    {
        if ($css === null || $css === '' || isset(self::$linked[$css])) {
            return '';
        }
        self::$linked[$css] = true;
        if (str_ends_with($css, '.css') && is_file($css)) {
            return '<style>' . (string) file_get_contents($css) . '</style>';
        }

        return '<link rel="stylesheet" href="' . htmlspecialchars(self::versioned($css), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * A stylesheet on this server with `?v=` its file's time, so a cache or a CDN does not keep an
     * old one; one elsewhere, or that says its own version, as given.
     */
    private static function versioned(string $css): string
    {
        if (str_contains($css, '?') || preg_match('#^([a-z]+:)?//#i', $css)) {
            return $css;
        }
        $roots = [rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/'), defined('ROOT') ? \Pramnos\Application\FrameworkScripts::webRoot((string) ROOT) : ''];
        foreach (array_filter($roots) as $root) {
            if (($time = @filemtime($root . '/' . ltrim($css, '/'))) !== false) {
                return $css . '?v=' . $time;
            }
        }

        return $css;
    }

    /** A set's markup: given, read from its file, or returned by its callable. */
    private static function markup(string|callable $markup): string
    {
        if (!is_string($markup)) {
            return (string) $markup();
        }

        return !str_contains($markup, '<') && is_file($markup) ? (string) file_get_contents($markup) : $markup;
    }

    /**
     * The framework's set: a drop with two eyes and a mouth, coloured by the theme's accent
     * (`currentColor` under `.pf-assistant-avatar`). Its movement is in every theme's
     * components.css. Its colours are attributes too, which the stylesheet overrides: without it,
     * the drop is the text's colour with white eyes, not a black shape.
     */
    private const DEFAULT_MARKUP = '<svg class="pf-assistant-pf" viewBox="0 0 32 32" width="100%" height="100%" aria-hidden="true" focusable="false">'
        . '<path class="pf-assistant-body" fill="currentColor" d="M16 2C9 9 5 14 5 19a11 11 0 0 0 22 0c0-5-4-10-11-17z"/>'
        . '<g class="pf-assistant-eyes" fill="#fff"><circle cx="12" cy="19" r="2"/><circle cx="20" cy="19" r="2"/></g>'
        . '<path class="pf-assistant-mouth" fill="none" stroke="#fff" stroke-width="1.4" stroke-linecap="round" d="M13 24q3 2 6 0"/>'
        . '<g class="pf-assistant-dots" fill="#fff" opacity="0"><circle cx="11" cy="12" r="1.3"/><circle cx="16" cy="12" r="1.3"/><circle cx="21" cy="12" r="1.3"/></g>'
        . '</svg>';
}
