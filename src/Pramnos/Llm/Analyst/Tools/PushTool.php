<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * Web push: sent and failed per period, by kind of notification, and the commonest failures.
 * The status is the push service's answer: a 2xx was delivered to it. Where the installation
 * tracks it (FW-495), also whether anybody opened or dismissed one at all, overall and by kind.
 *
 * Per device or per person is left to an application's own screen; this stays general — one
 * installation's figures, not one person's.
 */
final class PushTool extends AdminTool
{
    private const TABLE = 'pramnos.pushlog';

    public function name(): string
    {
        return 'push';
    }

    public function description(): string
    {
        return 'Web push notifications in a window: sent and failed, per period, by kind of '
            . 'notification, and the commonest failure reasons. Where opened/dismissed are '
            . 'tracked, also the open rate, overall and by kind — counting only pushes sent '
            . "after that tracking began, so an older installation's history is not read as "
            . "\"nobody ever opens these\".";
    }

    protected function answer(array $input, Window $window): array
    {
        if (!$this->has(self::TABLE)) {
            return self::absent('push notifications');
        }

        $rows      = fn () => $window->apply($this->table(self::TABLE), 'sent', false);
        $delivered = $rows()->where('status', '>=', 200)->where('status', '<', 300)->count();
        $total     = $rows()->count();
        $errors    = [];
        $result    = $rows()->select(['error'])->whereNotNull('error')->where('error', '!=', '')->get();
        while (($row = $result->fetch()) !== null) {
            $line          = strtok((string) $row['error'], "\n");
            $errors[$line] = ($errors[$line] ?? 0) + 1;
        }
        arsort($errors);

        $out = [
            'window'       => $window->describe(),
            'sent'         => $total,
            'delivered'    => $delivered,
            'failed'       => $total - $delivered,
            'by_kind'      => $this->countBy($rows(), 'notification'),
            'by_status'    => $this->countBy($rows(), 'status'),
            'per_period'   => $this->series($this->table(self::TABLE), 'sent', $window, false),
            'failures'     => array_slice($errors, 0, 10, true),
        ];

        return $this->tracksOpens() ? $out + $this->openRates($rows) : $out;
    }

    /** Whether `opened_at`/`dismissed_at`/`report_token` exist at all (FW-495's migrations). */
    private function tracksOpens(): bool
    {
        return \Pramnos\Framework\Factory::getDatabase()->schema()->hasColumn(self::TABLE, 'report_token');
    }

    /**
     * Opened, dismissed, and the open rate overall and by kind — **delivered rows with a token
     * only**. A row sent before the token column existed carries none, and counting it would
     * read "cannot say whether this was opened" as "this was never opened", understating every
     * rate by however much history the installation already had when FW-495 shipped.
     *
     * @param callable(): \Pramnos\Database\QueryBuilder $rows A fresh, unconsumed query each call
     * @return array{opened: int, dismissed: int, open_rate: float|null, open_rate_by_kind: array<string, float>}
     */
    private function openRates(callable $rows): array
    {
        $trackable = static fn () => $rows()->where('status', '>=', 200)->where('status', '<', 300)->where('report_token', '!=', '');
        $delivered = $trackable()->count();
        $opened    = $trackable()->whereNotNull('opened_at')->count();
        // Dismissed without ever having been opened: opening and then dismissing counts as opened.
        $dismissed = $trackable()->whereNull('opened_at')->whereNotNull('dismissed_at')->count();

        $byKind = [];
        foreach (array_keys($this->countBy($trackable(), 'notification')) as $kind) {
            $ofKind = $trackable()->where('notification', $kind)->count();
            if ($ofKind < 1) {
                continue;
            }
            $byKind[$kind] = round($trackable()->where('notification', $kind)->whereNotNull('opened_at')->count() / $ofKind * 100, 1);
        }

        return [
            'opened'            => $opened,
            'dismissed'         => $dismissed,
            'open_rate'         => $delivered > 0 ? round($opened / $delivered * 100, 1) : null,
            'open_rate_by_kind' => $byKind,
        ];
    }
}
