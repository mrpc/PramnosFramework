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
 * `render()` returns the card's inside, in the theme's classes or the framework's `pf-*` ones
 * (`Html\StatTile` draws in every theme); the dashboard draws the card around it. An empty string
 * is a card with nothing to show today. A widget that throws is drawn as a short note, not as a
 * broken dashboard.
 */
abstract class Widget
{
    /** Half the grid's width. */
    public const HALF = 'half';

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

    /** Whether this person may have it. Administrators of the dashboard's level, by default. */
    public function allowed(?object $user): bool
    {
        return $user !== null && (int) ($user->usertype ?? 0) >= 80;
    }

    /** The width it starts at: {@see HALF} or {@see FULL}. */
    public function size(): string
    {
        return self::HALF;
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
