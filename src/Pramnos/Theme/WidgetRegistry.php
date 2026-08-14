<?php

declare(strict_types=1);

namespace Pramnos\Theme;

/**
 * Maps a stored widget's `type` to something that can render it.
 *
 * A theme's widgets are stored as plain records — a type, an area, a title, some settings —
 * because they are edited from an admin screen and have to survive a deploy. The registry is
 * what turns `'type' => 'latest-posts'` back into an object.
 *
 * ```php
 * $theme->widgets()->register('latest-posts', LatestPosts::class);
 * $theme->widgets()->register('html', fn (array $record) => new RawHtml($record['html'] ?? ''));
 * ```
 *
 * A class name is constructed with no arguments; a callable receives the stored record and
 * returns the widget. The callable form is there because most widgets need their settings at
 * construction, and threading them through a constructor signature the registry has to know
 * about would make every widget's shape the registry's business.
 *
 * **Nothing here costs anything until it is used.** An application that registers no widgets
 * has an empty array. `Theme::renderWidgetArea()` returns before touching the registry when
 * the area has no stored widgets, which is every area in a project that does not use them.
 *
 * ### An unknown type is skipped, not fatal
 *
 * Widget records outlive the code that renders them: a plugin is removed, a type is renamed,
 * and the record is still in the settings. Rendering the rest of the area is the only sensible
 * behaviour — a sidebar should not take the page down because one entry in it is stale.
 *
 * Unknown types are collected in {@see unresolved()} so the situation is visible to whoever
 * looks rather than merely survivable.
 */
class WidgetRegistry
{
    /**
     * Type => class name or factory.
     *
     * @var array<string, string|callable>
     */
    private array $factories = [];

    /**
     * Types that were asked for and are not registered.
     *
     * @var array<string, int> Type => how many records wanted it
     */
    private array $unresolved = [];

    /**
     * Registers a widget type.
     *
     * @param string          $type    The `type` stored on a widget record
     * @param string|callable $factory A class name, or a callable receiving the record
     * @return static This registry, for chaining
     */
    public function register(string $type, string|callable $factory): static
    {
        $this->factories[$type] = $factory;

        return $this;
    }

    /**
     * Whether a type has been registered.
     *
     * @param string $type The type to look for
     * @return bool True when `register()` has been called for it
     */
    public function has(string $type): bool
    {
        return isset($this->factories[$type]);
    }

    /**
     * Every registered type.
     *
     * @return string[] The type names
     */
    public function types(): array
    {
        return array_keys($this->factories);
    }

    /**
     * Builds the widget for a stored record, or null when its type is unknown.
     *
     * A factory that returns something which is not a {@see WidgetInterface} is treated as
     * unknown rather than trusted: rendering would fail later, further from the cause.
     *
     * @param array<string, mixed> $record The stored widget record; `type` selects the factory
     * @return WidgetInterface|null The widget, or null when nothing can render it
     */
    public function resolve(array $record): ?WidgetInterface
    {
        $type = (string) ($record['type'] ?? '');

        if ($type === '' || !isset($this->factories[$type])) {
            if ($type !== '') {
                $this->unresolved[$type] = ($this->unresolved[$type] ?? 0) + 1;
            }

            return null;
        }

        $factory = $this->factories[$type];
        $widget  = is_string($factory)
            ? (class_exists($factory) ? new $factory() : null)
            : $factory($record);

        if (!$widget instanceof WidgetInterface) {
            $this->unresolved[$type] = ($this->unresolved[$type] ?? 0) + 1;

            return null;
        }

        return $widget;
    }

    /**
     * Types that were asked for and could not be resolved.
     *
     * A stale widget record is not an error worth stopping for, but it is worth being able to
     * find — a sidebar quietly missing one of its four widgets is otherwise a puzzle.
     *
     * @return array<string, int> Type => how many records asked for it
     */
    public function unresolved(): array
    {
        return $this->unresolved;
    }

    /**
     * Forgets every registration.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->factories = [];
        $this->unresolved = [];
    }
}
