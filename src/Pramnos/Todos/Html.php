<?php

declare(strict_types=1);

namespace Pramnos\Todos;

/**
 * The to-dos drawn where they are needed outside their own screen: the header's urgent five
 * ({@see headerButton()}) and "To-dos about this" on a record's page ({@see aboutThis()}).
 *
 * ```php
 * <?php echo \Pramnos\Todos\Html::headerButton('<li class="nav-item">', '</li>', ['class' => 'nav-link']); ?>
 * <?php echo \Pramnos\Todos\Html::aboutThis(adminUrl('Users/edit/42')); ?>
 * ```
 *
 * Each is nothing for somebody who may not read the list, or where there is none.
 */
final class Html
{
    /**
     * The header's urgent five, between `$before` and `$after`: a count, and a panel with the
     * person's items soonest first and the overdue ones nobody has, linked to the list.
     *
     * @param array{class?: string, limit?: int} $options
     */
    public static function headerButton(string $before = '', string $after = '', array $options = []): string
    {
        $user = self::reader();
        if ($user === null) {
            return '';
        }
        $tenant = Todos::tenantOf($user);
        try {
            $urgent = Todos::urgent($tenant, (int) $user->userid, (int) ($options['limit'] ?? 5));
        } catch (\Throwable) {
            return '';
        }
        $now   = time();
        $list  = adminUrl('Todo');
        $label = t('To-dos');
        $items = '';
        foreach ($urgent['items'] as $item) {
            $late   = $item['due_at'] !== null && $item['due_at'] < $now;
            $items .= '<li' . ($late ? ' class="pf-todo-overdue"' : '') . '><a href="' . self::e($item['link'] ?? $list) . '">' . self::e(self::short($item['title'])) . '</a></li>';
        }
        $items .= '<li><a href="' . self::e($list) . '">' . self::e($urgent['items'] === [] ? t('Nothing urgent. See the list') : t('See all')) . '</a></li>';

        return $before . '<details class="pf-todo-urgent">'
            . '<summary class="' . self::e((string) ($options['class'] ?? '')) . '" aria-label="' . self::e($label . ($urgent['count'] > 0 ? ' (' . $urgent['count'] . ')' : '')) . '">☑'
            . ($urgent['count'] > 0 ? '<span class="pf-todo-urgent-count">' . $urgent['count'] . '</span>' : '') . '</summary>'
            . '<ul class="pf-todo-urgent-panel">' . $items . '</ul></details>' . $after;
    }

    /** "To-dos about this": the open items whose link is this page, as a card; nothing for none. */
    public static function aboutThis(string $link): string
    {
        $user = self::reader();
        if ($user === null) {
            return '';
        }
        try {
            $items = Todos::about($link, Todos::tenantOf($user), (int) $user->userid);
        } catch (\Throwable) {
            return '';
        }
        if ($items === []) {
            return '';
        }
        $rows = '';
        foreach ($items as $item) {
            $rows .= '<li class="pf-todo' . ($item['due_at'] !== null && $item['due_at'] < time() ? ' pf-todo-overdue' : '') . '"><span class="pf-todo-title">'
                . self::e(self::short($item['title'])) . '</span></li>';
        }

        return '<section class="pf-card pf-todo-about"><div class="pf-card-body"><h2 class="pf-section-title">' . self::e(t('To-dos about this'))
            . ' <a class="pf-muted" href="' . self::e(adminUrl('Todo')) . '">' . self::e(t('See all')) . '</a></h2><ul class="pf-todo-list">' . $rows . '</ul></div></section>';
    }

    /** The person signed in, where they may read the list and it is installed; else null. */
    private static function reader(): ?object
    {
        $user = \Pramnos\User\User::current();
        if (!is_object($user) || !Todos::offers('menu') || !Todos::can($user, 'read')) {
            return null;
        }

        return $user;
    }

    /** A title cut for a line. */
    private static function short(string $title): string
    {
        $line = trim((string) preg_replace('~\s+~u', ' ', $title));

        return mb_strlen($line) > 80 ? mb_substr($line, 0, 79) . '…' : $line;
    }

    /** Escaped for HTML. */
    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
