<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * Mailing lists: subscribers per list by status, and sign-ups, confirmations and
 * unsubscriptions per period. Never an address.
 */
final class MailingListsTool extends AdminTool
{
    private const TABLE = 'pramnos.mailing_list_subscribers';

    public function name(): string
    {
        return 'mailing_lists';
    }

    public function description(): string
    {
        return 'Mailing lists: subscribers per list by status, and sign-ups, confirmations and '
            . 'unsubscriptions per period. Counts only — never an address.';
    }

    protected function answer(array $input, Window $window): array
    {
        if (!$this->has(self::TABLE)) {
            return self::absent('mailing lists');
        }

        $result = $this->table(self::TABLE)
            ->select(['list', 'status', $this->table(self::TABLE)->raw('COUNT(*) AS n')])
            ->groupBy('list', 'status')->orderBy('list')->get();
        $lists  = [];
        while (($row = $result->fetch()) !== null) {
            $lists[(string) $row['list']][(string) $row['status']] = (int) $row['n'];
        }

        $rows = fn () => $this->table(self::TABLE);

        return [
            'window'         => $window->describe(),
            'lists'          => $lists,
            'subscribed'     => $this->series($rows(), 'created_at', $window, true),
            'confirmed'      => $this->series($rows(), 'confirmed_at', $window, true),
            'unsubscribed'   => $this->series($rows(), 'unsubscribed_at', $window, true),
        ];
    }
}
