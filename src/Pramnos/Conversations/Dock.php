<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

/**
 * The conversations over every page of the administration: a button with what is unread, and the
 * conversations in a panel when it is pressed — the list or one conversation at a time, and a link
 * to the whole page. A theme puts it in its footer:
 *
 * ```php
 * <?php echo \Pramnos\Conversations\Dock::render(); ?>
 * ```
 *
 * Nothing outside the administration, where the application has no `conversations` feature, for somebody who may not use them
 * (`'conversations' => ['usertype' => 99]` keeps it to root), where the application turned it off
 * (`'conversations' => ['dock' => false]`), and on the conversations page itself.
 */
final class Dock
{
    /** The dock's markup and script, or '' where it does not belong. */
    public static function render(): string
    {
        $app    = \Pramnos\Application\Application::currentInstance();
        $info   = is_object($app) ? $app->applicationInfo : [];
        $config = is_array($info['conversations'] ?? null) ? $info['conversations'] : [];
        $me     = \Pramnos\User\User::current();
        if (!in_array('conversations', (array) ($info['features'] ?? []), true) || ($config['dock'] ?? true) === false || !Access::canUse($me)) {
            return '';
        }
        // The administration's pages only — one footer often serves the site too.
        if (\Pramnos\Http\AdminArea::prefix() !== '' && !\Pramnos\Http\AdminArea::isActive()) {
            return '';
        }
        $document = \Pramnos\Framework\Factory::getDocument();
        if (in_array('pf-conv-page', (array) ($document->bodyclasses ?? []), true)) {
            return '';
        }
        $e     = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $props = (string) json_encode(Client::props($me, 'dock'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // The analyst's picture is in the props, as data: its stylesheet is the page's.
        return \Pramnos\Assist\AvatarSets::stylesheet()
            . '<div class="pf-conv-dock" data-pf-conversations-dock data-props="' . $e($props) . '">'
            . '<button type="button" class="pf-conv-dock-button" aria-expanded="false" aria-label="' . $e(t('Conversations')) . '" title="' . $e(t('Conversations')) . '">'
            . '<span aria-hidden="true">💬</span><span class="pf-conv-dock-count" hidden></span></button>'
            . '<div class="pf-conv-dock-panel" role="dialog" aria-label="' . $e(t('Conversations')) . '" hidden></div>'
            . '</div>'
            . '<script src="' . $e(assetUrl('assets/js/pf-conversations.js')) . '" defer></script>';
    }
}
