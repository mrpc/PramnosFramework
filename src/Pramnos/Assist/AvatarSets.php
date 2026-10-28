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
 *     'markup' => APP_PATH . '/themes/admin/assets/naya/naya.svg',  // a file, a string, or a callable
 *     'css'    => '/themes/admin/assets/naya/naya.css',
 *     'states' => ['success' => 1800, 'greeting' => 2400],          // ms, for the states that end by themselves
 * ]]],
 *
 * AvatarSets::register('naya', $markup, '/assets/naya.css', ['greeting' => 2400]);
 * 'llm' => ['analyst' => ['avatar_set' => 'naya']],
 * ```
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

    /** @var array<string, array{markup: string|callable, css: ?string, states: array<string, int>}> */
    private static array $sets = [];

    /** Whether a set's stylesheet has been written into this page already, by URL. */
    private static array $linked = [];

    /**
     * Make a set known by name.
     *
     * @param string|callable(): string $markup Markup, a file holding it, or what returns it
     * @param array<string, int>        $states Milliseconds for the states that end by themselves
     */
    public static function register(string $name, string|callable $markup, ?string $css = null, array $states = []): void
    {
        self::$sets[$name] = ['markup' => $markup, 'css' => $css, 'states' => $states];
    }

    /** Forget the registered sets and the stylesheets written: tests. */
    public static function reset(): void
    {
        self::$sets   = [];
        self::$linked = [];
    }

    /**
     * The set in use: `llm.analyst.avatar_set` — a registered name or the set itself — or the
     * framework's; null where the application gives a plain picture and no set.
     *
     * @return array{markup: string|callable, css: ?string, states: array<string, int>}|null
     */
    public static function current(): ?array
    {
        $config = \Pramnos\Llm\Llm::config('analyst', []);
        $set    = is_array($config) ? ($config['avatar_set'] ?? null) : null;
        if (is_string($set) && isset(self::$sets[$set])) {
            return self::$sets[$set];
        }
        if (is_array($set) && isset($set['markup']) && (is_string($set['markup']) || is_callable($set['markup']))) {
            return ['markup' => $set['markup'], 'css' => isset($set['css']) ? (string) $set['css'] : null, 'states' => (array) ($set['states'] ?? [])];
        }
        if (\Pramnos\Llm\Analyst\Analyst::avatar() !== null) {
            return null;
        }

        return ['markup' => self::DEFAULT_MARKUP, 'css' => null, 'states' => []];
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
        $state = in_array($state, self::STATES, true) ? $state : 'idle';
        $set   = self::current();
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $name  = \Pramnos\Llm\Analyst\Analyst::name();
        if ($set === null) {
            $inner     = '<img src="' . $e((string) \Pramnos\Llm\Analyst\Analyst::avatar()) . '" alt="" width="' . $size . '" height="' . $size . '">';
            $durations = self::DURATIONS;
            $css       = null;
        } else {
            $inner     = self::markup($set['markup']);
            $durations = array_map('intval', $set['states'] + self::DURATIONS);
            $css       = $set['css'];
        }

        return ($link ? self::link($css) : '') . '<span class="pf-assistant-avatar pf-assistant--' . $state . ($class !== '' ? ' ' . $e($class) : '') . '" data-state="' . $state . '"'
            . ' data-durations="' . $e((string) json_encode(array_intersect_key($durations, array_flip(self::STATES)))) . '"'
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
