<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

/**
 * Tracked email: sent, opened — by a person, or only by a mail provider's proxy — and clicked,
 * per period and by list; the subjects most sent — shared ones only — and the links most
 * clicked. Never a recipient.
 */
final class EmailTool extends AdminTool
{
    /**
     * A subject is listed only when this many were sent with it: a personalised one —
     * "Welcome Maria" — is one person's, and no masking can tell a name from a word.
     */
    public const SUBJECT_MIN = 3;

    private const SENT   = 'pramnos.emailtracking';
    private const CLICKS = 'pramnos.emailtrackingclicks';

    public function name(): string
    {
        return 'email';
    }

    public function description(): string
    {
        return 'Email in a window. history: every message sent, by status, by module and the failures by module. '
            . 'And tracked email: sent, opened (by a person; proxy-only opens counted '
            . 'apart), clicked, open and click rates, per period and by list; the most sent '
            . 'subjects (those sent to at least three) and the most clicked links. Never a recipient.';
    }

    protected function answer(array $input, Window $window): array
    {
        // The send history — every message, tracked or not — as the dashboard counts it.
        $history = new \Pramnos\Email\SendHistory();
        $sends   = $history->available() ? ['history' => $history->figures($window->from, $window->to)] : [];
        if (!$this->has(self::SENT)) {
            return $sends === [] ? self::absent('email tracking') : ['window' => $window->describe()] + $sends;
        }

        $sent    = fn () => $window->apply($this->table(self::SENT), 'sent_at', true);
        $total   = $sent()->count();
        $opened  = $sent()->where('opens', '>', 0)->count();
        $clicked = $sent()->where('clicks', '>', 0)->count();

        return [
            'window'      => $window->describe(),
            'sent'        => $total,
            'opened'      => $opened,
            'proxy_only'  => $sent()->where('opens', 0)->where('proxy_opens', '>', 0)->count(),
            'clicked'     => $clicked,
            'open_rate'   => $total > 0 ? round($opened / $total, 3) : null,
            'click_rate'  => $total > 0 ? round($clicked / $total, 3) : null,
            'by_list'     => $this->countBy($sent(), 'list'),
            'subjects'    => array_filter($this->countBy($sent(), 'subject', 10), static fn (int $n): bool => $n >= self::SUBJECT_MIN),
            'per_period'  => $this->series($this->table(self::SENT), 'sent_at', $window, true),
            'links'       => $this->links($window),
        ] + $sends;
    }

    /** @return array<string, int> The links most clicked, without their query strings */
    private function links(Window $window): array
    {
        if (!$this->has(self::CLICKS)) {
            return [];
        }

        $result = $window->apply($this->table(self::CLICKS), 'clicked_at', true)->select(['url'])->get();
        $links  = [];
        while (($row = $result->fetch()) !== null) {
            // A query string carries tokens and addresses; the page is what was clicked.
            $url         = strtok((string) $row['url'], '?#');
            $links[$url] = ($links[$url] ?? 0) + 1;
        }
        arsort($links);

        return array_slice($links, 0, 10, true);
    }
}
