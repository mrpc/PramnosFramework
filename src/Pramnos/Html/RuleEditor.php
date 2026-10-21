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

        $icons = [];
        foreach (['plus', 'close', 'send', 'action', 'clock', 'rule', 'branch', 'split'] as $name) {
            $icons[$name] = Icon::svg($name, '');
        }
        $card = static fn (int $n, string $key, string $icon, string $title, string $body): string => '<details class="pf-rule-card" open data-pf-rule-card="' . $key . '">'
            . '<summary><span class="pf-rule-num">' . $n . '</span><span class="pf-rule-icon pf-rule-tone-' . $key . '">' . Icon::svg($icon, '') . '</span>'
            . '<span><span class="pf-rule-card-title">' . $e($title) . '</span><span class="pf-rule-card-summary" data-pf-rule-summary="' . $key . '"></span></span>'
            . '<span class="pf-rule-chevron">' . Icon::svg('chevron', '') . '</span></summary>'
            . '<div class="pf-rule-card-body">' . $body . '</div></details>';

        return '<div class="pf-rule-editor" data-pf-rule-editor'
            . ' data-catalog="' . $e((string) json_encode($catalog, JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-words="' . $e((string) json_encode($words, JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-icons="' . $e((string) json_encode($icons, JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-audience-words="' . $e((string) json_encode(AudienceBuilder::words(), JSON_UNESCAPED_UNICODE)) . '"'
            . ' data-classes="' . $e((string) json_encode(AudienceBuilder::classes(), JSON_UNESCAPED_UNICODE)) . '">'
            . '<input type="hidden" name="definition" value="' . $e((string) json_encode($rest, JSON_UNESCAPED_UNICODE)) . '" data-pf-rule-state>'
            . $card(1, 'when', 'rule', t('When'), '<div class="pf-rule-rows" data-pf-rule-when></div>')
            . $card(2, 'who', 'members', t('Who'), AudienceBuilder::filter('who', $who, ['sample' => 3]))
            . $card(3, 'what', 'action', t('What'), '<div data-pf-rule-steps></div>')
            . $card(4, 'until', 'goal', t('Until'), '<p class="pf-label">' . $e(t('Leaves the rule when it matches:')) . '</p>'
                . AudienceBuilder::filter('exit', $exit, ['live' => false]) . '<div class="pf-rule-rows" data-pf-rule-until></div>')
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
        'event' => 'An event happens', 'segment_entered' => 'Enters a segment', 'segment_left' => 'Leaves a segment',
        'schedule' => 'On a schedule', 'once' => 'Once, when switched on', 'eventName' => 'Event', 'chooseEvent' => 'Choose an event…',
        'otherEvents' => 'Other', 'search' => 'Search…', 'nothingFound' => 'Nothing found', 'whenWord' => 'When',
        'segment' => 'Segment', 'cron' => 'Cron expression', 'entry' => 'Takes a person in',
        'first' => 'the first time only', 'every' => 'every time',
        'message' => 'Message', 'messageHint' => 'An email, a notification, a message on the site',
        'action' => 'Action', 'actionHint' => 'Change a group or an attribute, or what the application adds',
        'wait' => 'Wait', 'waitHint' => 'Hold for a while', 'wait_event' => 'Wait for an event', 'waitEventHint' => 'Until they do something, or time runs out',
        'branch' => 'If', 'branchHint' => 'Two ways, on conditions or on a message', 'split' => 'A/B split', 'splitHint' => 'Share people between variants',
        'addStep' => 'Add a step', 'remove' => 'Remove', 'start' => 'Starts at', 'next' => 'Then', 'end' => 'End',
        'send' => 'Send', 'doThis' => 'Do', 'waitFor' => 'Wait for', 'waitUntil' => 'Wait until', 'forUpTo' => 'for up to',
        'theMessageOf' => 'the message of',
        'yes' => 'Yes', 'no' => 'No', 'happened' => 'It happened', 'timeout' => 'Time ran out',
        'minutes' => 'minutes', 'hours' => 'hours', 'days' => 'days', 'onFilter' => 'on conditions', 'onMessage' => 'on a message',
        'opened' => 'was opened', 'clicked' => 'was clicked', 'delivered' => 'was delivered',
        'variant' => 'Variant of the split', 'share' => 'Share %', 'addVariant' => 'Add a variant', 'goal' => 'Goal of the rule', 'within' => 'within',
        'consent' => 'Consent list', 'none' => '—', 'sendHours' => 'Sending hours', 'from' => 'from', 'to' => 'to',
        'limit' => 'Frequency limit', 'caps' => 'At most', 'per' => 'per', 'addCap' => 'Add a frequency limit', 'channel' => 'Channel', 'choose' => 'Choose…',
        'whenEvent' => 'When: %s', 'noEvent' => 'No event chosen yet', 'everybody' => 'Everybody', 'conditionsCount' => '%s conditions',
        'noSteps' => 'No steps yet', 'stepsCount' => '%s steps', 'oneStep' => '%s step', 'oneCondition' => '%s condition', 'oneLimit' => '%s limit', 'goalSummary' => 'Goal: %s within %s days', 'limitsCount' => '%s limits',
        'noLimits' => 'No goal, no limits',
    ];
}
