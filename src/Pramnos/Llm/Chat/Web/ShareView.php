<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

/**
 * The sharing markup of the conversation page, already escaped — the same in every theme, which
 * passes its own classes: the form that shares a conversation or an artifact, who the open
 * conversation is shared with, and what others shared with the reader.
 */
final class ShareView
{
    /**
     * "Share", folded: the people to share with, by username or email, comma-separated.
     *
     * @param array<string, string> $c The theme's classes: btn, input, muted
     */
    public static function form(string $action, string $kind, int $targetId, int $returnTo, string $token, array $c = []): string
    {
        $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t = static fn (string $s): string => $e(\Pramnos\Translator\Language::getInstance()->_($s));

        return '<details class="pf-share" data-pf-share="' . $e($kind) . '-' . $targetId . '"><summary>' . $t('Share') . '</summary>'
            . '<form method="post" action="' . $e($action . $targetId) . '">' . $token
            . '<input type="hidden" name="kind" value="' . $e($kind) . '">'
            . '<input type="hidden" name="conversation_id" value="' . $returnTo . '">'
            . '<label>' . $t('With (usernames or emails, comma-separated)')
            . ' <input type="text" name="names" required autocomplete="off" data-pf-people class="' . $e($c['input'] ?? '') . '"></label> '
            . '<button type="submit" class="' . $e($c['btn'] ?? '') . '">' . $t('Share') . '</button>'
            . '<p class="' . $e($c['muted'] ?? '') . '">' . $t('They get a copy of it as it is now, and what it shows was read with your rights.') . '</p>'
            . '</form></details>';
    }

    /**
     * Who the open conversation is shared with, each with a way to stop.
     *
     * @param list<array{share_id: int, name: string}> $shares
     * @param array<string, string>                    $c The theme's classes: btnGhost, muted
     */
    public static function recipients(array $shares, string $unshare, int $returnTo, string $token, array $c = []): string
    {
        if ($shares === []) {
            return '';
        }
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<p class="' . $e($c['muted'] ?? '') . '" data-pf-shared-with>' . $e(\Pramnos\Translator\Language::getInstance()->_('Shared with')) . ': ';
        foreach ($shares as $i => $share) {
            $html .= ($i > 0 ? ', ' : '') . $e($share['name'])
                . '<form method="post" action="' . $e($unshare . (int) $share['share_id']) . '" style="display:inline">' . $token
                . '<input type="hidden" name="conversation_id" value="' . $returnTo . '">'
                . '<button type="submit" class="' . $e($c['btnGhost'] ?? '') . '" aria-label="' . $e(\Pramnos\Translator\Language::getInstance()->_('Stop sharing with') . ' ' . $share['name']) . '">✕</button></form>';
        }

        return $html . '</p>';
    }

    /**
     * What others shared with the reader, each opening as a copy of their own.
     *
     * @param list<array{share_id: int, kind: string, title: string, from: string}> $list
     * @param array<string, string>                                                $c The theme's classes: muted
     */
    public static function withMe(array $list, string $open, array $c = []): string
    {
        if ($list === []) {
            return '';
        }
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<h2 class="' . $e($c['muted'] ?? '') . '" style="margin-top:12px">' . $e(\Pramnos\Translator\Language::getInstance()->_('Shared with you')) . '</h2>'
            . '<ul style="list-style:none;padding:0;margin:4px 0 0" data-pf-shared-with-me>';
        foreach ($list as $one) {
            $html .= '<li><a href="' . $e($open . (int) $one['share_id']) . '">' . ($one['kind'] === 'artifact' ? '▦ ' : '') . $e($one['title']) . '</a>'
                . ' <span class="' . $e($c['muted'] ?? '') . '">' . $e($one['from']) . '</span></li>';
        }

        return $html . '</ul>';
    }
}
