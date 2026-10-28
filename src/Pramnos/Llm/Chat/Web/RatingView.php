<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

/**
 * Good or bad, under an answer, already escaped: two buttons, the one chosen pressed, and a note
 * folded beside the bad one.
 */
final class RatingView
{
    /**
     * @param array<string, mixed>  $message As Conversations::find() answers one
     * @param array<string, string> $c       The theme's classes: btnGhost, input
     */
    public static function buttons(array $message, string $action, int $returnTo, string $token, array $c = []): string
    {
        $e      = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t      = static fn (string $s): string => $e(\Pramnos\Translator\Language::getInstance()->_($s));
        $id     = (int) ($message['message_id'] ?? 0);
        $rating = $message['rating'] ?? null;
        $form   = static function (int $value, string $label, string $aria, string $extra = '') use ($e, $action, $id, $returnTo, $token, $rating, $c): string {
            $pressed = $rating === $value;

            return '<form method="post" action="' . $e($action . $id) . '" style="display:inline">' . $token
                . '<input type="hidden" name="conversation_id" value="' . $returnTo . '">'
                . '<input type="hidden" name="rating" value="' . ($pressed ? 0 : $value) . '">'
                // The note after its button: between 👍 and 👎 it read as belonging to either.
                . '<button type="submit" class="' . $e($c['btnGhost'] ?? '') . '" aria-pressed="' . ($pressed ? 'true' : 'false') . '" aria-label="' . $aria . '">' . $label . '</button>' . $extra . '</form>';
        };

        return '<span class="pf-rating" data-pf-rating="' . $id . '">'
            . $form(1, '👍', $t('A good answer'))
            . $form(-1, '👎', $t('A bad answer'), '<input type="text" name="note" maxlength="500" placeholder="' . $t('What was wrong? (optional)') . '" class="' . $e($c['input'] ?? '') . '" style="max-width:14rem">')
            . '</span>';
    }
}
