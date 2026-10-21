<?php

declare(strict_types=1);

namespace Pramnos\Html;

use Pramnos\Application\Controllers\Audience;
use Pramnos\Audience\Rules\Actions;

/**
 * A rule's editor: when, the steps with their branches, until when — the markup `pf-rules.js`
 * turns into boxes — beside the audience builder for who and for the exit.
 *
 * ```php
 * echo RuleEditor::render($rule['definition'] ?? null);
 * ```
 *
 * The form posts three fields: `definition` (when, entry, steps, goal, consent, hours, caps, as
 * JSON), `who` and `exit` (filters, from {@see AudienceBuilder}); {@see combine()} puts them back
 * together. The catalogue — actions with their parameters, events, segments, groups, consent
 * lists, attributes — is written into the page.
 */
final class RuleEditor
{
    /**
     * The editor for a definition, or an empty one.
     *
     * @param array<string, mixed>|null $definition Checked
     */
    public static function render(?array $definition = null): string
    {
        $e     = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $rest  = $definition ?? ['trigger' => ['type' => 'event', 'event' => ''], 'entry' => 'first', 'start' => null, 'steps' => []];
        $who   = $rest['who'] ?? null;
        $exit  = $rest['exit'] ?? null;
        unset($rest['who'], $rest['exit']);
        $catalog = Audience::describe() + ['actions' => self::actions()];
        $words   = [];
        foreach (self::WORDS as $key => $english) {
            $words[$key] = t($english);
        }

        return '<div class="pf-rule-editor" data-pf-rule-editor'
            . ' data-catalog="' . $e((string) json_encode($catalog, JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-words="' . $e((string) json_encode($words, JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-audience-words="' . $e((string) json_encode(AudienceBuilder::words(), JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-classes="' . $e((string) json_encode(AudienceBuilder::classes(), JSON_UNESCAPED_UNICODE)) . '">'
            . '<input type="hidden" name="definition" value="' . $e((string) json_encode($rest, JSON_UNESCAPED_UNICODE)) . '" data-pf-rule-state>'
            . '<section class="pf-rule-section"><h3>' . $e(t('When')) . '</h3><div data-pf-rule-when></div></section>'
            . '<section class="pf-rule-section"><h3>' . $e(t('Who')) . '</h3>' . AudienceBuilder::filter('who', $who) . '</section>'
            . '<section class="pf-rule-section"><h3>' . $e(t('What')) . '</h3><div data-pf-rule-steps></div></section>'
            . '<section class="pf-rule-section"><h3>' . $e(t('Until')) . '</h3><p>' . $e(t('Leaves the rule when it matches:')) . '</p>'
            . AudienceBuilder::filter('exit', $exit, ['live' => false]) . '<div data-pf-rule-until></div></section>'
            . '<noscript><p>' . $e(t('Editing a rule needs JavaScript.')) . '</p></noscript>'
            . '</div>'
            . '<script src="' . $e(assetUrl('assets/js/pf-rules.js')) . '" defer></script>';
    }

    /**
     * The posted fields as one definition: the editor's JSON with who and exit put back.
     *
     * @param array<string, mixed> $post
     */
    public static function combine(array $post): string
    {
        $definition = json_decode((string) ($post['definition'] ?? ''), true);
        if (!is_array($definition)) {
            return (string) ($post['definition'] ?? '');
        }
        foreach (['who', 'exit'] as $filter) {
            $decoded = json_decode((string) ($post[$filter] ?? ''), true);
            if (is_array($decoded)) {
                $definition[$filter] = $decoded;
            }
        }

        return (string) json_encode($definition, JSON_UNESCAPED_UNICODE);
    }

    /**
     * The actions as the editor offers them: label, channel, parameters.
     *
     * @return list<array{key: string, label: string, channel: ?string, params: list<array<string, mixed>>}>
     */
    private static function actions(): array
    {
        $list = [];
        foreach (Actions::all() as $key => $action) {
            $params = [];
            foreach ($action['params'] as $name => $param) {
                // Choices given as a closure — the application's templates — are read now, once.
                $options  = $param['options'] ?? [];
                $options  = $options instanceof \Closure || (is_callable($options) && !is_array($options)) ? (array) $options() : (array) $options;
                $params[] = ['name' => $name, 'label' => $param['label'], 'type' => $param['type'],
                    'options' => array_map(static fn ($v, $l): array => ['value' => $v, 'label' => $l], array_keys($options), $options)];
            }
            $list[] = ['key' => $key, 'label' => $action['label'], 'channel' => $action['channel'], 'params' => $params];
        }

        return $list;
    }

    /** The words the script answers with. */
    private const WORDS = [
        'event' => 'An event', 'segment_entered' => 'Enters a segment', 'segment_left' => 'Leaves a segment',
        'schedule' => 'On a schedule', 'once' => 'Once, when switched on', 'eventName' => 'Event',
        'segment' => 'Segment', 'cron' => 'Cron expression', 'entry' => 'Takes a person in',
        'first' => 'the first time only', 'every' => 'every time',
        'action' => 'Do something', 'wait' => 'Wait', 'wait_event' => 'Wait for an event', 'branch' => 'If', 'split' => 'A/B split',
        'addStep' => '+ step', 'remove' => 'Remove', 'start' => 'Starts at', 'next' => 'Then', 'end' => 'End',
        'yes' => 'Yes', 'no' => 'No', 'happened' => 'It happened', 'timeout' => 'Time ran out',
        'minutes' => 'minutes', 'hours' => 'hours', 'days' => 'days', 'onFilter' => 'on conditions', 'onMessage' => 'on a message',
        'opened' => 'was opened', 'clicked' => 'was clicked', 'delivered' => 'was delivered',
        'variant' => 'Variant', 'share' => 'Share %', 'addVariant' => '+ variant', 'goal' => 'Goal: an event within days',
        'consent' => 'Consent list', 'none' => '—', 'sendHours' => 'Sending hours', 'from' => 'from', 'to' => 'to',
        'caps' => 'At most', 'addCap' => '+ limit', 'channel' => 'Channel', 'messages' => 'messages in', 'choose' => 'Choose…',
    ];
}
