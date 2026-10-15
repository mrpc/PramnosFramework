<?php

declare(strict_types=1);

namespace Pramnos\Dashboard;

/**
 * One card of the administration dashboard: what it is called, who may have it, how wide it starts,
 * whether a new dashboard has it, and what it shows.
 *
 * ```php
 * final class OpenOrders extends \Pramnos\Dashboard\Widget
 * {
 *     public function id(): string    { return 'orders.open'; }
 *     public function title(): string { return 'Open orders'; }
 *     public function render(): string
 *     {
 *         return \Pramnos\Html\StatTile::render('Open', (string) Order::openCount(), link: adminUrl('Orders'));
 *     }
 *     public function allowed(?object $user): bool { return \Pramnos\Auth\AdminAccess::allows($user, 'admin.orders'); }
 * }
 *
 * // app/app.php
 * 'dashboard' => ['widgets' => [\App\Widgets\OpenOrders::class]],
 * ```
 *
 * `allowed()` is the only gate: a widget a person may not have is not offered to them, and is
 * dropped from a layout they saved when it is read — so a layout cannot smuggle one in, and a
 * permission taken away takes the card with it.
 *
 * A card is one of four widths on a grid of four columns — {@see SMALL} one, {@see MEDIUM} two,
 * {@see LARGE} three, {@see FULL} all four; two columns on a tablet, one on a phone. A widget that
 * people tune — a period, a market, a chart's type — declares what may be set in {@see options()},
 * and reads what was chosen with {@see setting()}: each person's choices are kept in their layout,
 * cleaned against the declaration whenever it is read.
 *
 * ```php
 * public function options(): array
 * {
 *     return [
 *         'period' => ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'],   // the first is the default
 *         'title'  => static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? mb_substr(trim($v), 0, 80) : null,
 *     ];
 * }
 *
 * public function render(): string
 * {
 *     return Orders::chart((int) $this->setting('period'));
 * }
 * ```
 *
 * `render()` returns the card's inside, in the theme's classes or the framework's `pf-*` ones
 * (`Html\StatTile` draws in every theme); the dashboard draws the card around it. An empty string
 * is a card with nothing to show today. A widget that throws is drawn as a short note, not as a
 * broken dashboard.
 */
abstract class Widget
{
    /** One column of four. */
    public const SMALL = 's';

    /** Two columns of four — the width a card starts at unless it says. */
    public const MEDIUM = 'm';

    /** Three columns of four. */
    public const LARGE = 'l';

    /** The widths a card may have, narrowest first. */
    public const SIZES = [self::SMALL, self::MEDIUM, self::LARGE, self::FULL];

    /** Half the grid's width: kept for layouts saved before there were four, and read as {@see MEDIUM}. */
    public const HALF = 'half';

    /** What this person chose, cleaned against {@see options()}. */
    protected array $dashboardSettings = [];

    /**
     * The controller drawing the dashboard, for a widget that needs one — a model is built with
     * one: `$this->controller?->getModel('Orders')`. Null outside a dashboard.
     */
    protected ?\Pramnos\Application\Controller $controller = null;

    /** Hand the widget the controller drawing it. */
    public function setController(?\Pramnos\Application\Controller $controller): static
    {
        $this->controller = $controller;

        return $this;
    }

    /** The grid's whole width. */
    public const FULL = 'full';

    /** A name unique among the widgets, kept in the layouts people save: do not rename it. */
    abstract public function id(): string;

    /** The card's heading. */
    abstract public function title(): string;

    /** The card's inside, as HTML. */
    abstract public function render(): string;

    /** Whether this person may have it. The dashboard's role, `manager`, by default. */
    public function allowed(?object $user): bool
    {
        return \Pramnos\User\Roles::atLeast($user, \Pramnos\User\Roles::MANAGER);
    }

    /** The width it starts at: one of {@see SIZES}. */
    public function size(): string
    {
        return self::MEDIUM;
    }

    /**
     * What a person may set on this card, by name: a list of the values allowed — `[value => label]`
     * or a plain list, its first the default — or a function that answers the value as kept, or
     * null to refuse it. Nothing by default.
     *
     * @return array<string, array<int|string, string>|callable(mixed): mixed>
     */
    public function options(): array
    {
        return [];
    }

    /**
     * Take a person's settings: kept only what {@see options()} allows, and each list's first
     * value where nothing was chosen.
     *
     * @param array<string, mixed> $settings
     */
    public function configure(array $settings): static
    {
        $this->dashboardSettings = self::cleanSettings($this, $settings);

        return $this;
    }

    /** One setting as chosen, or its default. */
    public function setting(string $name): mixed
    {
        return $this->dashboardSettings[$name] ?? null;
    }

    /**
     * Settings as they may be kept: each option this widget declares, valid, or its default.
     *
     * @return array<string, mixed>
     */
    public static function cleanSettings(self $widget, mixed $settings): array
    {
        $given = is_array($settings) ? $settings : [];
        $clean = [];
        foreach ($widget->options() as $name => $allowed) {
            $value = $given[$name] ?? null;
            if (is_callable($allowed)) {
                $kept = $value === null ? null : $allowed($value);
                if ($kept !== null) {
                    $clean[$name] = $kept;
                }
                continue;
            }
            $values = array_map('strval', array_is_list($allowed) ? $allowed : array_keys($allowed));
            if ($values === []) {
                continue;
            }
            $clean[$name] = $value !== null && in_array((string) $value, $values, true) ? (string) $value : $values[0];
        }

        return $clean;
    }

    /** A line on what it shows, for the list cards are chosen from. '' for none. */
    public function description(): string
    {
        return '';
    }

    /** A width as it may be kept: one of {@see SIZES}; the older `half` is {@see MEDIUM}. */
    public static function normalSize(mixed $size): ?string
    {
        if ($size === self::HALF) {
            return self::MEDIUM;
        }

        return in_array($size, self::SIZES, true) ? $size : null;
    }

    /** Whether a new dashboard — or one reset to the default — has it. */
    public function byDefault(): bool
    {
        return true;
    }

    /** Where the heading leads — the screen with the detail — or null for none. */
    public function link(): ?string
    {
        return null;
    }
}
