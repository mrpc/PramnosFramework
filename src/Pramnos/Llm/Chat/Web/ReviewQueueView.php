<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

/**
 * The review queue's page body, already escaped — the same in every theme, which passes its own
 * classes: how answers were rated lately, then each bad answer waiting, with its question, the
 * person's note, and a button that marks it reviewed.
 */
final class ReviewQueueView
{
    /**
     * @param list<array<string, mixed>> $queue   As Ratings::queue() answers
     * @param array{good: int, bad: int, waiting: int} $summary As Ratings::summary() answers
     * @param array<string, string>      $urls    reviewed (`reviewed/<id>`), open (an admin-space conversation, `?c=<id>`), back
     * @param array<string, string>      $c       The theme's classes: card, muted, btn, btnGhost
     */
    public static function html(array $queue, array $summary, array $urls, string $token, array $c = []): string
    {
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t    = static fn (string $s): string => \Pramnos\Translator\Language::getInstance()->_($s);
        $html = '<div class="px-4 py-6" data-pf-review-queue>'
            . (($urls['back'] ?? '') !== '' ? '<a href="' . $e($urls['back']) . '" class="' . $e($c['btnGhost'] ?? '') . '">‹ ' . $e($t('Ask')) . '</a>' : '')
            . '<h1>' . $e($t('Answers to review')) . '</h1>'
            . '<p class="' . $e($c['muted'] ?? '') . '" data-pf-review-summary>' . $e(sprintf($t('Last 30 days: %d good, %d bad; %d waiting to be reviewed.'), $summary['good'], $summary['bad'], $summary['waiting'])) . '</p>';
        if ($queue === []) {
            return $html . '<p>' . $e($t('Nothing waiting: no bad answer has been left unreviewed.')) . '</p></div>';
        }
        foreach ($queue as $one) {
            $html .= '<section class="' . $e($c['card'] ?? '') . '" style="padding:12px;margin:12px 0" data-pf-review="' . (int) $one['message_id'] . '">'
                . '<header><strong>' . $e($one['title']) . '</strong> <span class="' . $e($c['muted'] ?? '') . '">' . $e($one['owner_name'] . ' · ' . $one['rated_at'] . ' · ' . $one['space']) . '</span></header>'
                . '<p><strong>' . $e($t('Asked')) . ':</strong> ' . nl2br($e($one['question'])) . '</p>'
                . '<div><strong>' . $e($t('Answered')) . ':</strong>' . \Pramnos\Llm\Chat\Markdown::toHtml((string) $one['answer']) . '</div>'
                . ($one['note'] !== '' ? '<p><strong>' . $e($t('What was wrong')) . ':</strong> ' . $e($one['note']) . '</p>' : '')
                . '<form method="post" action="' . $e(($urls['reviewed'] ?? '') . (int) $one['message_id']) . '" style="display:inline">' . $token
                . '<button type="submit" class="' . $e($c['btn'] ?? '') . '">' . $e($t('Mark reviewed')) . '</button></form>'
                . ($one['space'] === \Pramnos\Llm\Chat\Scope::ADMIN && ($urls['open'] ?? '') !== ''
                    ? ' <a href="' . $e($urls['open'] . (int) $one['conversation_id']) . '" class="' . $e($c['btnGhost'] ?? '') . '">' . $e($t('Open the conversation')) . '</a>' : '')
                . '</section>';
        }

        return $html . '</div>';
    }
}
