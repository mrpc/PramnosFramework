<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Assist\Help;
use Pramnos\Dashboard\Widget;

/**
 * What is new, from the administration's help: the pages of the news sections — `News` and
 * `Changelog` unless `'dashboard' => ['help_news' => ['sections' => [...], 'limit' => 5]]` says
 * otherwise — newest first by their `updated` front matter, each linking to its page, and a link
 * to all of them. Not on a new dashboard; offered where the help is on, to whoever may open it.
 */
final class HelpNews extends Widget
{
    /** The sections read unless app.php names others. */
    public const SECTIONS = ['News', 'Changelog'];

    public function id(): string
    {
        return 'help.news';
    }

    public function title(): string
    {
        return 'What\'s new';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'The newest pages of the help\'s news sections, each a click away.';
    }

    /** Where the help is on, for whoever may open it. */
    public function allowed(?object $user): bool
    {
        return Help::enabled() && Figures::opens($user, Figures::ability($this->id(), 'admin.help'));
    }

    public function byDefault(): bool
    {
        return false;
    }

    public function link(): ?string
    {
        return Help::url();
    }

    /**
     * The news pages, newest first: `updated` descending, then title; at most `limit`.
     *
     * @return list<array<string, mixed>>
     */
    public function pages(): array
    {
        $config   = self::config();
        $sections = array_map('mb_strtolower', array_map('strval', (array) ($config['sections'] ?? self::SECTIONS)));
        $pages    = array_values(array_filter(Help::all(), static fn (array $page): bool => in_array(mb_strtolower((string) $page['section']), $sections, true)));
        usort($pages, static fn (array $a, array $b): int => [(string) $b['updated'], (string) $a['title']] <=> [(string) $a['updated'], (string) $b['title']]);

        return array_slice($pages, 0, max(1, (int) ($config['limit'] ?? 5)));
    }

    public function render(): string
    {
        $pages = $this->pages();
        if ($pages === []) {
            return '<p class="pf-dashboard-health-message">' . self::e(Figures::words('Nothing new yet.')) . '</p>';
        }

        $html = '<ul class="pf-dashboard-health">';
        foreach ($pages as $page) {
            $html .= '<li><a href="' . self::e(Help::url((string) $page['slug'])) . '">' . self::e((string) $page['title']) . '</a>'
                . ((string) $page['updated'] !== '' ? '<span class="pf-dashboard-health-message">' . self::e((string) $page['updated']) . '</span>' : '') . '</li>';
        }

        return $html . '</ul><p><a href="' . self::e(Help::url()) . '">' . self::e(Figures::words('All of the help')) . '</a></p>';
    }

    /** `'dashboard' => ['help_news' => [...]]` in app.php. @return array<string, mixed> */
    private static function config(): array
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $config = is_object($app) ? ($app->applicationInfo['dashboard']['help_news'] ?? []) : [];

        return is_array($config) ? $config : [];
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
