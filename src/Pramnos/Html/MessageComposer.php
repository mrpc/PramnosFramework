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
     * The fields as the composer reads them: `[{token, label}]`, labels through `t()`, and a
     * `value` where `$values` has one — a message to one person, where the menu inserts "Cathy"
     * rather than `{firstname}`.
     *
     * @param array<string, string>|null $fields token => label; the account's by default
     * @param array<string, string>      $values token => value, e.g. {@see valuesFor()}
     * @return list<array{token: string, label: string, value?: string}>
     */
    public static function fields(?array $fields = null, array $values = []): array
    {
        $list = [];
        foreach ($fields ?? self::userFields() as $token => $label) {
            $entry = ['token' => (string) $token, 'label' => self::t((string) $label)];
            if (array_key_exists($token, $values)) {
                $entry['value'] = (string) $values[$token];
            }
            $list[] = $entry;
        }

        return $list;
    }

    /**
     * {@see fields()} as JSON, safe in a single-quoted `data-pf-editor-fields` attribute.
     *
     * @param array<string, string>|null $fields token => label; the account's by default
     * @param array<string, string>      $values token => value
     */
    public static function fieldsJson(?array $fields = null, array $values = []): string
    {
        return (string) json_encode(self::fields($fields, $values), JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    /**
     * An account's values for {@see userFields()} — a `User`, or its row as an array.
     *
     * @param object|array<string, mixed> $user
     * @return array<string, string>
     */
    public static function valuesFor(object|array $user): array
    {
        $user  = (object) $user;
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
     * The toolbar: saved messages, the fields, AI, signature — with the inline panels for saving
     * and for writing. Markup only; `pf-utils.js` wires it inside the `data-pf-composer`
     * container. Each part carries a `pf-composer-*` hook and the project's theme's button and
     * field classes.
     *
     * @param array{saved?: bool, ai?: bool|null, signature?: bool, fields?: string} $options Each
     *        part on by default; `ai` null asks {@see canWrite()}; `fields` `menu` (one "Fields"
     *        dropdown) or `buttons` (a row, one per field)
     */
    public static function toolbar(array $options = []): string
    {
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $c     = self::classes();
        $btn   = static fn (string $attr, string $label, string $kind = 'button', bool $hidden = false): string =>
            '<button type="button" class="pf-composer-button ' . $c[$kind] . '" ' . $attr . ($hidden ? ' hidden' : '') . '>' . $e($label) . '</button>';
        $parts = [];
        $panels = [];

        if ($options['saved'] ?? true) {
            // The list stays hidden until pf-utils.js finds a saved message to put in it.
            $parts[] = '<span class="pf-composer-saved">'
                . '<select class="pf-composer-select ' . $c['select'] . '" data-pf-composer-saved hidden aria-label="' . $e(self::t('Saved messages')) . '">'
                . '<option value="">' . $e(self::t('Saved messages…')) . '</option></select> '
                . $btn('data-pf-composer-save', self::t('Save this message')) . ' '
                . $btn('data-pf-composer-forget', self::t('Forget'), 'button', true)
                . '</span>';
            $panels[] = '<div class="pf-composer-panel" data-pf-composer-save-panel hidden>'
                . '<label class="pf-composer-label">' . $e(self::t('Name')) . ' '
                . '<input type="text" maxlength="150" class="pf-composer-input ' . $c['input'] . '" data-pf-composer-name></label> '
                . '<label class="pf-composer-check" data-pf-composer-shared-label hidden><input type="checkbox" data-pf-composer-shared> '
                . $e(self::t('For everybody')) . '</label> '
                . $btn('data-pf-composer-save-go', self::t('Save'), 'primary') . ' '
                . $btn('data-pf-composer-cancel', self::t('Cancel'))
                . '</div>';
        }
        // The fields, drawn by pf-utils.js from data-pf-editor-fields: one dropdown, or a row.
        if (($options['fields'] ?? 'menu') === 'buttons') {
            $parts[] = '<span class="pf-composer-fields" data-pf-composer-fields aria-label="' . $e(self::t('Fields')) . '"></span>';
        } else {
            // <details>: opens and closes with no script, and Escape and the keyboard work.
            $parts[] = '<details class="pf-composer-menu">'
                . '<summary class="pf-composer-button ' . $c['button'] . '">' . $e(self::t('Fields')) . ' ▾</summary>'
                . '<span class="pf-composer-fields pf-composer-menu-list" data-pf-composer-fields role="menu"></span>'
                . '</details>';
        }
        if ($options['ai'] ?? self::canWrite()) {
            $parts[] = $btn('data-pf-composer-write', self::t('Write with AI…'));
            $panels[] = '<div class="pf-composer-panel" data-pf-composer-write-panel hidden>'
                . '<label class="pf-composer-label">' . $e(self::t('What should it say?'))
                . '<textarea rows="2" class="pf-composer-input ' . $c['area'] . '" data-pf-composer-instruction'
                . ' placeholder="' . $e(self::t('Leave it empty to improve what is written.')) . '"></textarea></label> '
                . $btn('data-pf-composer-write-go', self::t('Improve'), 'primary') . ' '
                . $btn('data-pf-composer-undo', self::t('Undo'), 'button', true) . ' '
                . $btn('data-pf-composer-cancel', self::t('Close'))
                . '</div>';
        }
        if ($options['signature'] ?? true) {
            $parts[] = '<label class="pf-composer-signature"><input type="checkbox" data-pf-composer-signature'
                . ' data-signature="' . $e(self::signature()) . '"> ' . $e(self::t('With my signature')) . '</label> '
                // A tab of its own: saving it there must not take the message being written with it.
                . '<a href="' . $e(adminUrl('Composer/signature')) . '" target="_blank" rel="noopener">'
                . $e(self::t('Edit my signature')) . '</a>';
        }
        $parts[] = '<span class="pf-composer-status" data-pf-composer-status role="status" aria-live="polite"></span>';

        // The words pf-utils.js answers with, in the page's language.
        $words = [];
        foreach ([
            'write'   => 'Write',
            'improve' => 'Improve',
            'writing' => 'Writing…',
            'failed'  => 'That did not work.',
            'saved'   => 'Saved.',
            'shared'  => 'Shared',
            'forget'  => 'Click again to forget it',
            'name'    => 'A saved message needs a name.',
        ] as $key => $english) {
            $words[$key] = self::t($english);
        }

        return '<div class="pf-composer-toolbar" role="toolbar" aria-label="' . $e(self::t('Message tools')) . '"'
            . ' data-pf-composer-words="' . $e((string) json_encode($words, JSON_UNESCAPED_UNICODE)) . '">'
            . implode(' ', $parts) . implode('', $panels) . '</div>';
    }

    /**
     * The project's theme's classes for the toolbar's controls — `scaffold_theme`, as the forms
     * read it ({@see Form\FieldStyles::configured()}).
     *
     * @return array{button: string, primary: string, input: string, area: string, select: string}
     */
    private static function classes(): array
    {
        return match (Form\FieldStyles::configured()) {
            'tailwind'  => ['button' => 'btn btn-sm', 'primary' => 'btn btn-sm btn-primary', 'input' => 'input input-sm input-bordered',
                'area' => 'textarea textarea-bordered w-full', 'select' => 'select select-sm select-bordered'],
            'bootstrap' => ['button' => 'btn btn-sm btn-outline-secondary', 'primary' => 'btn btn-sm btn-primary', 'input' => 'form-control form-control-sm',
                'area' => 'form-control', 'select' => 'form-select form-select-sm'],
            default     => ['button' => 'btn btn-secondary btn-sm', 'primary' => 'btn btn-primary btn-sm', 'input' => 'form-control',
                'area' => 'form-control', 'select' => 'form-control'],
        };
    }

    /** `t()` where the application has loaded it; the English otherwise. */
    private static function t(string $words): string
    {
        return function_exists('t') ? (string) t($words) : $words;
    }
}
