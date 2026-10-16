<?php

declare(strict_types=1);

namespace Pramnos\Dashboard\Widgets;

use Pramnos\Dashboard\Widget;

/**
 * Who is signed in now: each session seen in the last fifteen minutes, newest first — the person,
 * linked to their account, the address, when, the page and the browser — with a button that signs
 * that session out on its next request; and how many are signed in and how many are visitors.
 * Not on a new dashboard; for whoever may open the users.
 *
 * Not final: an application adds a column of its own by extending it and answering
 * {@see rowExtras()}, and registers it under the same id.
 */
class SessionsOnline extends Widget
{
    /** Seconds a session counts as online after its last request — as the analyst counts it. */
    public const ONLINE = 900;

    /** The most sessions listed. */
    public const LIMIT = 20;

    public function id(): string
    {
        return 'sessions.online';
    }

    public function title(): string
    {
        return 'Online now';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Who is signed in now, and where they are; a session can be signed out from here.';
    }

    /** Whoever may open the users. */
    public function allowed(?object $user): bool
    {
        return Figures::opens($user, Figures::ability($this->id(), 'admin.users'));
    }

    public function byDefault(): bool
    {
        return false;
    }

    public function size(): string
    {
        return self::FULL;
    }

    public function link(): ?string
    {
        return Figures::link($this->id(), 'Users/sessions');
    }

    /**
     * Markup of an application's own for a session's row, at its end — escaped by it. Nothing by
     * default.
     *
     * @param array<string, mixed> $session The sessions row
     */
    protected function rowExtras(array $session): string
    {
        return '';
    }

    public function render(): string
    {
        $since = time() - self::ONLINE;
        $table = fn () => \Pramnos\Framework\Factory::getDatabase()->queryBuilder()->table('#PREFIX#sessions')
            ->where('time', '>=', $since)->where('logout', 0);
        $signedIn = $table()->where('guest', 0)->count();
        $visitors = $table()->where('guest', 1)->count();
        $result   = $table()->where('guest', 0)->orderBy('time', 'desc')->limit(self::LIMIT)->get();
        $own      = md5((string) session_id());
        $token    = \Pramnos\Http\Session::getInstance()->getTokenField();
        $back     = (string) adminUrl('Dashboard');

        $html = '<p class="pf-dashboard-health-message">' . self::e(sprintf(Figures::words('%s signed in · %s visitors'), number_format($signedIn), number_format($visitors))) . '</p>';
        $rows = '';
        while (($row = $result->fetch()) !== null) {
            $userId = (int) ($row['userid'] ?? 0);
            $name   = self::e((string) $row['uname']);
            $rows  .= '<tr><td>' . ($userId > 1 ? '<a href="' . self::e(adminUrl('users/view/') . $userId) . '">' . $name . '</a>' : $name) . '</td>'
                . '<td>' . self::e((string) $row['host_addr']) . '</td>'
                . '<td>' . self::e(date('H:i', (int) $row['time'])) . '</td>'
                . '<td>' . self::e(mb_strimwidth((string) $row['url'], 0, 60, '…')) . '</td>'
                . '<td title="' . self::e((string) $row['agent']) . '">' . self::e(mb_strimwidth((string) $row['agent'], 0, 40, '…')) . '</td>'
                . '<td>' . ((string) $row['sid'] === $own
                    ? self::e(Figures::words('This session'))
                    : '<form method="post" action="' . self::e(adminUrl('Users/endsession')) . '" style="display:inline;margin:0">' . $token
                        . '<input type="hidden" name="sid" value="' . self::e((string) $row['sid']) . '">'
                        . '<input type="hidden" name="return" value="' . self::e($back) . '">'
                        . '<button type="submit" class="btn btn-sm" data-confirm="' . self::e(Figures::words('Sign this session out?')) . '">' . self::e(Figures::words('Sign out')) . '</button></form>')
                . '</td>' . $this->rowExtras($row) . '</tr>';
        }

        return $rows === '' ? $html : $html . '<table class="pf-dashboard-vitals"><thead><tr><th>' . self::e(Figures::words('Who')) . '</th><th>IP</th><th>'
            . self::e(Figures::words('Last')) . '</th><th>' . self::e(Figures::words('Page')) . '</th><th>' . self::e(Figures::words('Browser')) . '</th><th></th></tr></thead><tbody>'
            . $rows . '</tbody></table>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
