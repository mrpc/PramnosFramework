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
 *
 * A card whose figures are slow to work out, and the same for everybody who may see it, is
 * **shared**: it splits `render()` into {@see data()} — the figures, without words, links or
 * HTML — and {@see present()}, which draws them for the person looking. The dashboard keeps the
 * data in the cache ({@see WidgetData}) under {@see dataKey()}, and `dashboard:precompute` can
 * keep it filled from a cron line, so nobody waits for it.
 *
 * ```php
 * public function shared(): bool { return true; }
 * public function data(): array { return ['open' => Order::openCount()]; }   // slow, the same for all
 * public function present(array $data): string
 * {
 *     return \Pramnos\Html\StatTile::render(t('Open'), (string) $data['open'], link: adminUrl('Orders'));
 * }
 * ```
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

    /**
     * The card's inside, as HTML: what {@see present()} draws of {@see data()}, unless the card
     * draws itself.
     */
    public function render(): string
    {
        return $this->present($this->data());
    }

    /**
     * Whether the card's data is the same for everybody who may see it with the same settings,
     * so the dashboard may keep it and hand it to the next — {@see data()} and {@see present()}.
     */
    public function shared(): bool
    {
        return false;
    }

    /**
     * The card's figures, for {@see present()}: plain values only — no words in a language, no
     * links, no HTML, nothing of the person looking — as they are kept as JSON and handed to
     * whoever opens the card next.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return [];
    }

    /**
     * The card's inside, drawn from {@see data()} for the person looking: their language, the
     * links they may follow.
     *
     * @param array<string, mixed> $data
     */
    public function present(array $data): string
    {
        return '';
    }

    /**
     * The settings `dashboard:precompute` works this card out with, for each it finds — its
     * defaults and every saved layout's: those alone, unless the card's data has variants nobody
     * saves. A card whose market is "as the header shows it", read from a cookie, spreads one
     * setting into one per market looked at lately, each with the market named, so that each
     * entry is ready before somebody asks for it.
     *
     * There is no person and no request while precomputing: a variant must name everything its
     * data depends on.
     *
     * @param array<string, mixed> $settings As cleaned against {@see options()}
     * @return list<array<string, mixed>>
     */
    public function precomputeSettings(array $settings): array
    {
        return [$settings];
    }

    /**
     * What the data depends on: the card and its settings, without those that only change how it
     * is drawn (`title`, `chart`). Two people's cards with the same key share one entry. A card
     * whose data depends on something else — a market the site is showing — adds it.
     */
    public function dataKey(): string
    {
        $settings = array_diff_key($this->dashboardSettings, ['title' => true, 'chart' => true]);
        ksort($settings);

        return $this->id() . ':' . md5((string) json_encode($settings));
    }

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
     * What a person may set on this card, by name. Each is one of:
     *
     * - a list of the values allowed — `[value => label]` or a plain list, its first the default;
     * - a function that answers the value as kept, or null to refuse it (a text field);
     * - a field: `['label' => 'From', 'input' => 'date', 'clean' => fn, 'when' => ['period' => 'custom']]`
     *   — `input` text, date or number, with `clean` as above; or `values` in place of `clean`, a
     *   list as above; `label` the field's words, translated; `when` the other settings it is
     *   shown for.
     *
     * Nothing by default.
     *
     * @return array<string, array<int|string, mixed>|callable(mixed): mixed>
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
            $field = self::field($allowed);
            if ($field['clean'] !== null) {
                $kept = $value === null || $value === '' ? null : ($field['clean'])($value);
                if ($kept !== null) {
                    $clean[$name] = $kept;
                }
                continue;
            }
            $values = array_map('strval', array_keys($field['values']));
            if ($values === []) {
                continue;
            }
            $clean[$name] = $value !== null && in_array((string) $value, $values, true) ? (string) $value : $values[0];
        }

        return $clean;
    }

    /**
     * One option as a field: its `input` (select, text, date, number), `label` or null, `values`
     * (`[value => label]`) for a select, `clean` for the others, and `when`.
     *
     * @param array<int|string, mixed>|callable $option As {@see options()} declares it
     * @return array{input: string, label: string|null, values: array<string, string>, clean: callable|null, when: array<string, string>}
     */
    public static function field(mixed $option): array
    {
        $spec = is_array($option) && (isset($option['clean']) || isset($option['values'])) && !array_is_list($option) ? $option : null;
        $clean = $spec !== null ? ($spec['clean'] ?? null) : (is_callable($option) ? $option : null);
        $list  = $spec !== null ? ($spec['values'] ?? []) : (is_array($option) && !is_callable($option) ? $option : []);
        $values = [];
        if (is_array($list) && $clean === null) {
            foreach ($list as $key => $label) {
                $values[array_is_list($list) ? (string) $label : (string) $key] = (string) $label;
            }
        }

        return [
            'input'  => $clean === null ? 'select' : (in_array($spec['input'] ?? '', ['date', 'number'], true) ? (string) $spec['input'] : 'text'),
            'label'  => isset($spec['label']) ? (string) $spec['label'] : null,
            'values' => $values,
            'clean'  => is_callable($clean) ? $clean : null,
            'when'   => array_map('strval', is_array($spec['when'] ?? null) ? $spec['when'] : []),
        ];
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
