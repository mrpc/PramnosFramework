<?php

declare(strict_types=1);

namespace Pramnos\Email;

use Pramnos\Messaging\Mail;

/**
 * What the send history (`#PREFIX#mails`, one row per message whatever became of it) says about
 * a period: how many, by status, by module, and where the failures were — for the dashboard's
 * cards and the analyst alike, so the two say the same.
 *
 * Counted with GROUP BY over the `date` index, however long the history: one range scan per
 * question.
 */
final class SendHistory
{
    /** How many modules a breakdown lists. */
    private const TOP = 10;

    public function __construct(private readonly ?\Pramnos\Database\Database $db = null)
    {
    }

    /** Whether there is a send history to read. */
    public function available(): bool
    {
        return $this->db()->schema()->hasTable('mails');
    }

    /**
     * The period's figures: `total`, `sent`, `failed`, `queued`, `failure_rate` (of sent and
     * failed, null with neither), `by_status` (`sent`, `failed`, `queued`, `status N` for one of an
     * application's own), `by_module` and `failed_by_module` (an empty module is `other`).
     *
     * @return array{total: int, sent: int, failed: int, queued: int, failure_rate: float|null, by_status: array<string, int>, by_module: array<string, int>, failed_by_module: array<string, int>}
     */
    public function figures(int $from, int $to): array
    {
        $byStatus = [];
        $result   = $this->range($from, $to)->select(['status', $this->raw('COUNT(*) AS n')])->groupBy('status')->get();
        while (($row = $result->fetch()) !== null) {
            $name = match ((int) $row['status']) {
                Mail::STATUS_SENT   => 'sent',
                Mail::STATUS_FAILED => 'failed',
                Mail::STATUS_QUEUED => 'queued',
                default             => 'status ' . (int) $row['status'],
            };
            $byStatus[$name] = (int) $row['n'];
        }
        $sent   = $byStatus['sent'] ?? 0;
        $failed = $byStatus['failed'] ?? 0;

        return [
            'total'            => array_sum($byStatus),
            'sent'             => $sent,
            'failed'           => $failed,
            'queued'           => $byStatus['queued'] ?? 0,
            'failure_rate'     => $sent + $failed > 0 ? round($failed / ($sent + $failed), 4) : null,
            'by_status'        => $byStatus,
            'by_module'        => $this->byModule($this->range($from, $to)),
            'failed_by_module' => $failed > 0 ? $this->byModule($this->range($from, $to)->where('status', Mail::STATUS_FAILED)) : [],
        ];
    }

    /** @return array<string, int> Rows by module, largest first; an empty module is `other` */
    private function byModule(\Pramnos\Database\QueryBuilder $query): array
    {
        $counts = [];
        $result = $query->select(['module', $this->raw('COUNT(*) AS n')])->groupBy('module')->get();
        while (($row = $result->fetch()) !== null) {
            $module          = trim((string) $row['module']) !== '' ? (string) $row['module'] : 'other';
            $counts[$module] = ($counts[$module] ?? 0) + (int) $row['n'];
        }
        arsort($counts);

        return array_slice($counts, 0, self::TOP, true);
    }

    private function range(int $from, int $to): \Pramnos\Database\QueryBuilder
    {
        return $this->db()->queryBuilder()->table('#PREFIX#mails')->where('date', '>=', $from)->where('date', '<=', $to);
    }

    private function raw(string $sql): \Pramnos\Database\Expression
    {
        return $this->db()->queryBuilder()->raw($sql);
    }

    private function db(): \Pramnos\Database\Database
    {
        return $this->db ?? \Pramnos\Framework\Factory::getDatabase();
    }
}
