<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat\Web;

/**
 * The bulk actions a list offers, as forms, already escaped: one folded form per action, its
 * fields, and a button that says how many it will act on.
 */
final class BulkView
{
    /**
     * Approve or reject a proposal that still waits; nothing once it is settled.
     *
     * @param array<string, mixed>  $proposal As Artifacts::shape() answers
     * @param array<string, string> $c        The theme's classes: btn, btnGhost
     */
    public static function decision(array $proposal, string $approve, string $reject, int $returnTo, string $token, array $c = []): string
    {
        if (($proposal['kind'] ?? '') !== 'proposal' || (($proposal['content'] ?? [])['status'] ?? '') !== 'pending') {
            return '';
        }
        $e  = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t  = static fn (string $s): string => $e(\Pramnos\Translator\Language::getInstance()->_($s));
        $id = (int) $proposal['artifact_id'];
        $form = static fn (string $url, string $label, string $class): string => '<form method="post" action="' . $e($url . $id) . '" style="display:inline">' . $token
            . '<input type="hidden" name="conversation_id" value="' . $returnTo . '"><button type="submit" class="' . $e($class) . '">' . $label . '</button></form> ';

        return '<div class="pf-proposal-decision" data-pf-proposal="' . $id . '">' . $form($approve, $t('Approve and do it'), $c['btn'] ?? '')
            . $form($reject, $t('Reject'), $c['btnGhost'] ?? '') . '</div>';
    }

    /**
     * @param array<string, array{label: string, params: array<string, array<string, mixed>>}> $actions As BulkActions::available() answers
     * @param array<string, string>                                                            $c The theme's classes: btn, input, muted
     */
    public static function forms(array $actions, string $action, int $artifactId, int $count, int $returnTo, string $token, array $c = []): string
    {
        if ($actions === []) {
            return '';
        }
        $e    = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $t    = static fn (string $s): string => \Pramnos\Translator\Language::getInstance()->_($s);
        $html = '<div class="pf-bulk" data-pf-bulk="' . $artifactId . '">';
        foreach ($actions as $key => $one) {
            $html .= '<details><summary>' . $e($one['label']) . '</summary>'
                . '<form method="post" action="' . $e($action . $artifactId) . '">' . $token
                . '<input type="hidden" name="action" value="' . $e($key) . '">'
                . '<input type="hidden" name="conversation_id" value="' . $returnTo . '">';
            foreach ($one['params'] as $name => $param) {
                $field = 'params[' . $name . ']';
                $req   = empty($param['required']) ? '' : ' required';
                $html .= '<label>' . $e($param['label'] ?? $name) . ' ';
                $html .= match ($param['type'] ?? 'text') {
                    'select'   => '<select name="' . $e($field) . '" class="' . $e($c['input'] ?? '') . '"' . $req . '><option value=""></option>'
                        . implode('', array_map(static fn ($value, $label): string => '<option value="' . $e($value) . '">' . $e($label) . '</option>',
                            array_keys((array) ($param['options'] ?? [])), (array) ($param['options'] ?? []))) . '</select>',
                    'textarea' => '<textarea name="' . $e($field) . '" class="' . $e($c['input'] ?? '') . '"' . $req . '></textarea>',
                    'number'   => '<input type="number" name="' . $e($field) . '" class="' . $e($c['input'] ?? '') . '"' . $req . '>',
                    default    => '<input type="text" name="' . $e($field) . '" class="' . $e($c['input'] ?? '') . '"' . $req . '>',
                };
                $html .= '</label> ';
            }
            $html .= '<button type="submit" class="' . $e($c['btn'] ?? '') . '">' . $e(sprintf($t('Do it for %d'), $count)) . '</button></form></details>';
        }

        return $html . '</div>';
    }
}
