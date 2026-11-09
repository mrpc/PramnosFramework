<?php

declare(strict_types=1);

namespace Pramnos\Todos;

use Pramnos\Dashboard\Widget;

/**
 * The dashboard's to-do card: how many of the reader's items are open and overdue, and the first
 * of the urgent five ({@see Todos::urgent()}). Offered where the list is installed, to whoever may
 * read it; not on a new dashboard unless chosen.
 */
final class TodosWidget extends Widget
{
    public function id(): string
    {
        return 'todos';
    }

    public function title(): string
    {
        return 'To-dos';
    }

    /** What it shows, for the list cards are chosen from. */
    public function description(): string
    {
        return 'Your open and overdue to-dos, the most urgent first.';
    }

    /** Whoever may read the list. */
    public function allowed(?object $user): bool
    {
        return Todos::can($user, 'read');
    }

    /** Chosen, not given. */
    public function byDefault(): bool
    {
        return false;
    }

    /** The heading leads to the list. */
    public function link(): ?string
    {
        return adminUrl('Todo');
    }

    /** The reader's counts and their urgent items. */
    public function render(): string
    {
        $user = \Pramnos\User\User::current();
        if (!is_object($user)) {
            return '';
        }
        $urgent = Todos::urgent(Todos::tenantOf($user), (int) $user->userid, 5);
        $now    = time();
        $late   = count(array_filter($urgent['items'], static fn (array $i): bool => $i['due_at'] !== null && $i['due_at'] < $now));
        $rows   = '';
        foreach ($urgent['items'] as $item) {
            $rows .= '<li class="pf-todo' . ($item['due_at'] !== null && $item['due_at'] < $now ? ' pf-todo-overdue' : '') . '"><span class="pf-todo-title">'
                . htmlspecialchars(mb_strimwidth(trim((string) preg_replace('~\s+~u', ' ', $item['title'])), 0, 90, '…'), ENT_QUOTES, 'UTF-8') . '</span></li>';
        }

        return \Pramnos\Html\StatTile::render(t('Open'), (string) $urgent['count'], $late > 0 ? sprintf(t('%d overdue'), $late) : '')
            . ($rows === '' ? '<p class="pf-muted">' . htmlspecialchars(t('Nothing to do.'), ENT_QUOTES, 'UTF-8') . '</p>' : '<ul class="pf-todo-list">' . $rows . '</ul>');
    }
}
