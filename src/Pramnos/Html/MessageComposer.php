<?php

declare(strict_types=1);

namespace Pramnos\Html;

/**
 * The tools above a message form: saved messages, placeholders, writing with AI, the signature.
 *
 * ```php
 * <div data-pf-composer data-pf-composer-url="<?= adminUrl('Composer') ?>" data-pf-composer-scope="user-message"
 *      data-pf-editor-fields='<?= MessageComposer::fieldsJson() ?>'>
 *     <?= MessageComposer::toolbar(['scope' => 'user-message']) ?>
 *     <input name="subject" data-pf-composer-subject>
 *     <textarea name="message" data-pf-editor="rich" data-pf-composer-body></textarea>
 * </div>
 * ```
 *
 * `pf-utils.js` does the rest, with whichever editor the body has — builtin, rich, TinyMCE or
 * none. A saved message fills the subject and the body; a placeholder (`{firstname}`) goes in at
 * the cursor of whichever of the two had the focus; "Write with AI" asks the `Composer`
 * controller; "With my signature" adds the signature to the body when the form is sent.
 *
 * Placeholders are never filled in the page: the sender fills them for each recipient —
 * {@see fill()} with {@see valuesFor()} for the framework's own forms, an application's own
 * values for its own.
 */
final class MessageComposer
{
    /** The setting that holds an account's signature (HTML), in `usersettings`. */
    public const SIGNATURE_SETTING = 'mail.signature';

    /**
     * The placeholders the framework fills for an account: token => label.
     *
     * @return array<string, string>
     */
    public static function userFields(): array
    {
        return [
            '{firstname}' => 'First name',
            '{lastname}'  => 'Last name',
            '{full_name}' => 'Full name',
            '{username}'  => 'Username',
            '{email}'     => 'Email',
        ];
    }

    /**
     * Placeholders as the composer reads them: `[{token, label}]`, labels through `t()`.
     *
     * @param array<string, string>|null $fields token => label; the account's by default
     */
    public static function fieldsJson(?array $fields = null): string
    {
        $list = [];
        foreach ($fields ?? self::userFields() as $token => $label) {
            $list[] = ['token' => (string) $token, 'label' => self::t((string) $label)];
        }

        return (string) json_encode($list, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /**
     * An account's values for {@see userFields()}.
     *
     * @return array<string, string>
     */
    public static function valuesFor(object $user): array
    {
        $first = trim((string) ($user->firstname ?? ''));
        $last  = trim((string) ($user->lastname ?? ''));

        return [
            '{firstname}' => $first,
            '{lastname}'  => $last,
            '{full_name}' => trim($first . ' ' . $last),
            '{username}'  => (string) ($user->username ?? ''),
            '{email}'     => (string) ($user->email ?? ''),
        ];
    }

    /**
     * A recipient that is only an address — a list's subscriber: the address, and the rest
     * empty rather than left as `{firstname}` in somebody's inbox.
     *
     * @return array<string, string>
     */
    public static function valuesForAddress(string $address): array
    {
        return array_merge(array_fill_keys(array_keys(self::userFields()), ''), ['{email}' => $address]);
    }

    /**
     * The placeholders in `$text` replaced; any others left as written. In HTML the values are
     * escaped.
     *
     * @param array<string, string> $values token => value
     */
    public static function fill(string $text, array $values, bool $html = false): string
    {
        if (!str_contains($text, '{')) {
            return $text;
        }
        if ($html) {
            $values = array_map(static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), $values);
        }

        return strtr($text, $values);
    }

    /** The signed-in account's signature, or an empty string. */
    public static function signature(): string
    {
        $user = \Pramnos\User\User::current();
        if (!$user instanceof \Pramnos\User\User) {
            return '';
        }

        return (string) ($user->getSetting(self::SIGNATURE_SETTING, '') ?? '');
    }

    /** Whether "Write with AI" can be offered: a language model is configured. */
    public static function canWrite(): bool
    {
        try {
            return (new \Pramnos\Llm\Connection())->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The toolbar: saved messages, placeholders, AI, signature. Markup only; `pf-utils.js` wires
     * it inside the `data-pf-composer` container.
     *
     * @param array{saved?: bool, ai?: bool|null, signature?: bool} $options Each part on by
     *        default; `ai` null asks {@see canWrite()}
     */
    public static function toolbar(array $options = []): string
    {
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $parts = [];

        if ($options['saved'] ?? true) {
            $parts[] = '<span class="pf-composer-saved">'
                . '<select data-pf-composer-saved aria-label="' . $e(self::t('Saved messages')) . '">'
                . '<option value="">' . $e(self::t('Saved messages…')) . '</option></select> '
                . '<button type="button" class="pf-composer-button" data-pf-composer-save>' . $e(self::t('Save this message')) . '</button> '
                . '<button type="button" class="pf-composer-button" data-pf-composer-forget hidden>' . $e(self::t('Forget')) . '</button>'
                . '</span>';
        }
        // The placeholders' menu, drawn by pf-utils.js from data-pf-editor-fields.
        $parts[] = '<span class="pf-composer-fields" data-pf-composer-fields aria-label="' . $e(self::t('Placeholders')) . '"></span>';
        if ($options['ai'] ?? self::canWrite()) {
            $parts[] = '<button type="button" class="pf-composer-button" data-pf-composer-write>' . $e(self::t('Write with AI…')) . '</button>';
        }
        if ($options['signature'] ?? true) {
            $parts[] = '<label class="pf-composer-signature"><input type="checkbox" data-pf-composer-signature'
                . ' data-signature="' . $e(self::signature()) . '"> ' . $e(self::t('With my signature')) . '</label> '
                // A tab of its own: saving it there must not take the message being written with it.
                . '<a href="' . $e(adminUrl('Composer/signature')) . '" target="_blank" rel="noopener">'
                . $e(self::t('Edit my signature')) . '</a>';
        }

        // The words pf-utils.js asks and answers with, in the page's language.
        $words = [];
        foreach ([
            'name'        => 'A name for this saved message',
            'share'       => 'Share it with everybody? OK shares it; Cancel keeps it yours.',
            'forget'      => 'Forget this saved message?',
            'shared'      => 'Shared',
            'mine'        => 'Mine',
            'instruction' => 'What should the message say? Leave it empty to improve what is written.',
            'writing'     => 'Writing…',
            'failed'      => 'That did not work.',
        ] as $key => $english) {
            $words[$key] = self::t($english);
        }

        return '<div class="pf-composer-toolbar" role="toolbar" aria-label="' . $e(self::t('Message tools')) . '"'
            . ' data-pf-composer-words="' . $e((string) json_encode($words, JSON_UNESCAPED_UNICODE)) . '">'
            . implode(' ', $parts) . '</div>';
    }

    private static function t(string $words): string
    {
        return function_exists('t') ? (string) t($words) : $words;
    }
}
