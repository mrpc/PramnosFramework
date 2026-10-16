<?php

declare(strict_types=1);

namespace Pramnos\Push;

/**
 * What the browser half of push (`assets/js/push.js`) says, in the page's language.
 *
 * The script's sentences are English; the page hands it their translations on its tag, so a
 * visitor reads "Notifications are on for this browser." in their own language:
 *
 * ```php
 * <script src="<?php echo assetUrl('assets/js/push.js'); ?>"<?php echo \Pramnos\Push\BrowserScript::attributes(); ?> defer></script>
 * ```
 */
final class BrowserScript
{
    /** Every sentence the script says, as it says it. */
    public const WORDS = [
        'This browser has no service worker, so it cannot receive notifications.',
        'This browser cannot receive push notifications. On iPhone and iPad, add this site to the home screen first.',
        'Notifications need a secure connection (https).',
        "Notifications are blocked for this site. Turn them back on in your browser's site settings.",
        'Asking…',
        'Not turned on.',
        'Notifications are on for this browser.',
        'Sign in first, then turn notifications on.',
        'That did not work. Try again.',
        'Notifications are not set up on this server yet.',
    ];

    /**
     * Each sentence in the page's language, as JSON: `{english: translated}`, only those the
     * language has, so the script says the rest in English.
     */
    public static function words(): string
    {
        $words = [];
        foreach (self::WORDS as $sentence) {
            $said = function_exists('t') ? (string) t($sentence) : $sentence;
            if ($said !== $sentence) {
                $words[$sentence] = $said;
            }
        }

        return $words === [] ? '{}' : (string) json_encode($words, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** ` data-push-words="…"`, escaped, for the script's tag; '' when there is nothing to translate. */
    public static function attributes(): string
    {
        $words = self::words();

        return $words === '{}' ? '' : ' data-push-words="' . htmlspecialchars($words, ENT_QUOTES, 'UTF-8') . '"';
    }
}
