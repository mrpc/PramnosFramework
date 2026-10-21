<?php

declare(strict_types=1);

namespace Pramnos\Html;

use Pramnos\Application\Controllers\Audience;

/**
 * The audience builder and picker: the markup `pf-audience.js` turns into conditions with a live
 * count beside them.
 *
 * ```php
 * // a segment's form: conditions over attributes, the count, a breakdown and a sample
 * echo AudienceBuilder::filter('filter', $segment['filter']);
 *
 * // a send's form: in these segments and groups, except these, a filter, a consent list
 * echo AudienceBuilder::picker('audience', $send['audience'], ['channel' => 'email']);
 * ```
 *
 * The field posts the state as JSON: a {@see \Pramnos\Audience\Filter} for `filter()`, a
 * {@see \Pramnos\Audience\Picker} state for `picker()`. The catalogue — attributes this account
 * may see, segments, groups, consent lists — is written into the page, so the builder draws with
 * no request; the numbers come from `/admin/Audience/preview`. Without JavaScript the field keeps
 * the value it was given, and the page says the builder needs it.
 */
final class AudienceBuilder
{
    /**
     * Conditions over people's attributes, with the live panel.
     *
     * @param array<string, mixed>|string|null                       $value   The filter, or its JSON
     * @param array{breakdown?: bool, sample?: int, live?: bool, api?: string} $options
     */
    public static function filter(string $name, array|string|null $value = null, array $options = []): string
    {
        $state = is_string($value) ? $value : (string) json_encode($value ?? ['match' => 'all', 'conditions' => []], JSON_UNESCAPED_UNICODE);

        return self::render('filter', $name, $state, Audience::describe(), $options);
    }

    /**
     * A send's audience: segments and groups in and out, a filter for this send, a consent list.
     *
     * @param array<string, mixed>|string|null                     $value   The picker state, or its JSON
     * @param array{channel?: string, sample?: int, live?: bool, api?: string} $options
     */
    public static function picker(string $name, array|string|null $value = null, array $options = []): string
    {
        $state   = is_string($value) ? $value : (string) json_encode($value ?? [], JSON_UNESCAPED_UNICODE);
        $catalog = Audience::describe();
        if (isset($options['channel'])) {
            $catalog['consent'] = array_values(array_filter($catalog['consent'], static fn (array $l): bool => $l['channel'] === $options['channel']));
        }

        return self::render('picker', $name, $state, $catalog, $options);
    }

    /**
     * Where a person's card is: `audience.person_url` in app.php with `{id}`, or the framework's
     * Users screen.
     */
    public static function personUrl(int $id): string
    {
        $app      = \Pramnos\Application\Application::currentInstance();
        $template = is_object($app) ? (string) ($app->applicationInfo['audience']['person_url'] ?? '') : '';

        return $template !== '' ? str_replace('{id}', (string) $id, $template) : adminUrl('Users/view/') . $id;
    }

    /**
     * @param array<string, mixed> $catalog
     * @param array<string, mixed> $options
     */
    private static function render(string $mode, string $name, string $state, array $catalog, array $options): string
    {
        $e    = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $live = $options['live'] ?? true;

        return '<div class="pf-audience pf-audience-' . $mode . '" data-pf-audience="' . $mode . '"'
            . ' data-api="' . $e((string) ($options['api'] ?? adminUrl('Audience'))) . '"'
            . ' data-sample="' . (int) ($options['sample'] ?? 5) . '"'
            . (($options['breakdown'] ?? true) && $mode === 'filter' ? ' data-breakdown' : '')
            . ' data-catalog="' . $e((string) json_encode($catalog, JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-words="' . $e((string) json_encode(self::words(), JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-classes="' . $e((string) json_encode(self::classes(), JSON_UNESCAPED_UNICODE)) . '">'
            . '<input type="hidden" name="' . $e($name) . '" value="' . $e($state) . '" data-pf-audience-state>'
            . '<div class="pf-audience-area" data-pf-audience-area></div>'
            . ($live ? '<aside class="pf-audience-live" data-pf-audience-live aria-live="polite"></aside>' : '')
            . '<noscript><p class="pf-audience-noscript">' . $e(t('Choosing an audience needs JavaScript.')) . '</p></noscript>'
            . '</div>'
            . '<script src="' . $e(assetUrl('assets/js/pf-audience.js')) . '" defer></script>';
    }

    /**
     * The words the script answers with, in the page's language.
     *
     * @return array<string, string>
     */
    public static function words(): array
    {
        $words = [];
        foreach ([
            'matchBefore'    => 'People who match',
            'matchAfter'     => 'of these',
            'matchLabel'     => 'All or any',
            'all'            => 'all',
            'any'            => 'any',
            'and'            => 'and',
            'or'             => 'or',
            'addCondition'   => '+ condition',
            'addGroup'       => '+ group',
            'remove'         => 'Remove',
            'choose'         => 'Choose…',
            'attribute'      => 'What',
            'operator'       => 'How',
            'segment'        => 'Segment',
            'segments'       => 'Segments',
            'groups'         => 'Groups',
            'days'           => 'days',
            'people'         => 'people match now',
            'reaches'        => 'will receive it',
            'sample'         => 'For example',
            'breakdownBy'    => 'Break down by…',
            'failed'         => 'That did not work.',
            'include'        => 'To people in any of',
            'exclude'        => 'Except people in',
            'addPick'        => '+ segment or group',
            'filterOnly'     => '+ a filter for this send only',
            'consentList'    => 'Consent list',
            'noConsent'      => 'No consent list',
            'picked'         => '%s picked',
            'excluded'       => '−%s excepted',
            'withoutConsent' => '−%s without consent',
            'events'         => 'What they did',
            'times'          => 'times',
            'inLast'         => 'in the last',
            'daysOrEver'     => 'days (empty: ever)',
            'day'            => 'Day',
        ] as $key => $english) {
            $words[$key] = t($english);
        }

        return $words;
    }

    /**
     * The project's theme's classes for the controls.
     *
     * @return array{button: string, primary: string, input: string, select: string}
     */
    private static function classes(): array
    {
        return match (Form\FieldStyles::configured()) {
            'tailwind'  => ['button' => 'btn btn-sm btn-ghost', 'primary' => 'btn btn-sm btn-primary', 'input' => 'input input-sm input-bordered', 'select' => 'select select-sm select-bordered'],
            'bootstrap' => ['button' => 'btn btn-sm btn-outline-secondary', 'primary' => 'btn btn-sm btn-primary', 'input' => 'form-control form-control-sm', 'select' => 'form-select form-select-sm'],
            default     => ['button' => 'btn btn-secondary btn-sm', 'primary' => 'btn btn-primary btn-sm', 'input' => 'form-control', 'select' => 'form-control'],
        };
    }
}
