<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\MessageChannel;
use Pramnos\Dashboard\MessageReach;
use Pramnos\Dashboard\Widget;
use Pramnos\Dashboard\WidgetRegistry;
use Pramnos\Html\StatTile;

/**
 * Every way the application reaches people, for the period each person sets: a row per channel —
 * email from the send history, then the application's own ({@see MessageChannel}, in
 * `'dashboard' => ['channels' => [...]]`) — with what was sent, what failed, what waits, what it
 * cost, and how many people it reached where the channel can say.
 */
final class Messages extends Widget
{
    use \Pramnos\Dashboard\HasPeriod;

    public function id(): string
    {
        return 'messages';
    }

    public function title(): string
    {
        return 'Messages';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Every channel messages go out by — email, and the application\'s own — with what was sent, failed, waited and cost.';
    }

    /** Whoever may open the email screen, where there is a channel to show. */
    public function allowed(?object $user): bool
    {
        return Figures::opens($user, Figures::ability($this->id(), 'admin.emails')) && WidgetRegistry::channels() !== [];
    }

    /** Not on a new dashboard: the email card is, and with no other channel this says the same. */
    public function byDefault(): bool
    {
        return false;
    }

    public function size(): string
    {
        return self::FULL;
    }

    /** The period, as each person sets it. */
    public function options(): array
    {
        return $this->periodOptions();
    }

    /** The same figures for everybody who may see it: worked out once, kept a while. */
    public function shared(): bool
    {
        return true;
    }

    /** Each channel's figures over the period, by its id; null for one that could not be read. */
    public function data(): array
    {
        [$from, $to] = $this->periodRange();
        $channels = [];
        foreach (WidgetRegistry::channels() as $channel) {
            try {
                $channels[$channel->id()] = [
                    'figures' => $channel->figures($from, $to),
                    'reach'   => $channel instanceof MessageReach ? $channel->reach($from, $to) : null,
                ];
            } catch (\Throwable $e) {
                // One channel that fails is a line saying so, not a card that is gone.
                \Pramnos\Logs\Logger::log('Message channel ' . $channel->id() . ' failed: ' . $e->getMessage(), 'dashboard');
                $channels[$channel->id()] = null;
            }
        }

        return ['channels' => $channels];
    }

    public function present(array $data): string
    {
        $html = '';
        foreach (WidgetRegistry::channels() as $channel) {
            if (!array_key_exists($channel->id(), (array) ($data['channels'] ?? []))) {
                continue;
            }
            $figures = $data['channels'][$channel->id()];
            $html   .= is_array($figures)
                ? $this->row($channel, (array) ($figures['figures'] ?? []), is_array($figures['reach'] ?? null) ? $figures['reach'] : null)
                : '<p class="pf-dashboard-health-message">' . self::e(sprintf(Figures::words('%s could not be read.'), Figures::words($channel->label()))) . '</p>';
        }

        return $html;
    }

    /**
     * One channel's row: its name and its tiles.
     *
     * @param array<string, mixed>      $f     Its figures
     * @param array<string, mixed>|null $reach Who it reached, where it says
     */
    private function row(MessageChannel $channel, array $f, ?array $reach): string
    {
        $total  = (int) ($f['total'] ?? 0);
        $failed = (int) ($f['failed'] ?? 0);
        $rate   = $total > 0 ? $failed / $total : 0.0;
        $tiles  = [
            StatTile::render(Figures::words('Sent'), number_format($total), $this->periodLabel(), $channel->link()),
            StatTile::render(Figures::words('Failed'), number_format($failed), $total > 0 ? number_format($rate * 100, 1) . '%' : '',
                state: $rate >= 0.05 ? StatTile::CRITICAL : ($failed > 0 ? StatTile::WARNING : null)),
        ];
        if (isset($f['queued'])) {
            $tiles[] = StatTile::render(Figures::words('Queued'), number_format((int) $f['queued']));
        }
        if (isset($f['delivered'])) {
            $tiles[] = StatTile::render(Figures::words('Delivered'), number_format((int) $f['delivered']));
        }
        if (isset($f['cost'])) {
            $tiles[] = StatTile::render(Figures::words('Cost'), (string) ($f['currency'] ?? '') . number_format((float) $f['cost'], 2));
        }
        if ($reach !== null) {
            $tiles[] = StatTile::render(Figures::words('Reached'), number_format((int) $reach['recipients']),
                sprintf(Figures::words('%s a day'), number_format((float) $reach['average'], 1))
                . ((int) $reach['missing_days'] > 0 ? ' · ' . sprintf(Figures::words('%d days missing'), (int) $reach['missing_days']) : ''));
        }

        return '<h4 class="pf-dashboard-channel">' . self::e(Figures::words($channel->label())) . '</h4>' . StatTile::grid($tiles);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
