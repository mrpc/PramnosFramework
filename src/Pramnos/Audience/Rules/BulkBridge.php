<?php

declare(strict_types=1);

namespace Pramnos\Audience\Rules;

use Pramnos\Bulk\BulkActions;

/**
 * The rules' actions on a person, offered as bulk actions on a list of people — `user` →
 * `rule.email`, `rule.push`, `rule.group_add`… with the same fields — and run through the rules'
 * engine ({@see Engine::perform()}): a message only within the sending hours, to a person on the
 * consent list chosen, under the frequency caps. The run says how many were skipped and why.
 *
 * On with one setting, `'audience' => ['bulk_actions' => true]`. Who may send a message to many
 * is the application's — `'audience' => ['bulk_messages' => 'admin.newsletters']`, an ability, or
 * a callable `fn (int $userId): bool` — and root alone where it says nothing. The other actions
 * (a group, an attribute, a to-do) are for whoever may manage the audience (`admin.audience`).
 *
 * A message is shown before it goes: how many it reaches, how many are skipped and why, the
 * message one of them gets — and then confirmed ({@see BulkActions::preview()}).
 */
final class BulkBridge
{
    /** The key a rule's action has among the bulk actions, before its own. */
    public const PREFIX = 'rule.';

    /** The rules' actions that are not about the person on the list. */
    public const NOT_ABOUT_THEM = ['notify_admin', 'webhook', 'conversation_post', 'ai'];

    /** Every rule action about a person, as a bulk action on `user`. */
    public static function register(): void
    {
        foreach (Actions::all() as $key => $action) {
            if (in_array($key, self::NOT_ABOUT_THEM, true)) {
                continue;
            }
            $channel = $action['channel'] ?? null;
            $params  = self::params((array) $action['params'], $channel);
            BulkActions::define(
                'user',
                self::PREFIX . $key,
                (string) $action['label'],
                $params,
                static fn (array $ids, array $p, int $by): array => self::run((string) $key, $ids, $p, $by),
                ability: $channel === null ? 'admin.audience' : null,
                allowed: $channel === null ? null : self::mayMessage(...),
                preview: $channel === null ? null : static fn (array $ids, array $p, int $by): array|string => self::preview((string) $key, $ids, $p, $by),
            );
        }
    }

    /** Whether this person may send a message to many: the application's gate, or root. */
    public static function mayMessage(int $userId): bool
    {
        $app  = \Pramnos\Application\Application::currentInstance();
        $gate = is_object($app) ? ($app->applicationInfo['audience']['bulk_messages'] ?? null) : null;
        $user = new \Pramnos\User\User($userId);
        if (is_callable($gate)) {
            return (bool) $gate($userId);
        }
        if (is_string($gate) && $gate !== '') {
            return \Pramnos\Auth\AdminAccess::allows($user, $gate, \Pramnos\Auth\AdminAccess::defaultUsertype());
        }

        return \Pramnos\User\Roles::atLeast($user, \Pramnos\User\Roles::ROOT);
    }

    /**
     * Each person on the list, through the engine; what it did, counted.
     *
     * @param list<int|string>     $ids
     * @param array<string, mixed> $params
     * @return array{done: int, says: string}
     */
    public static function run(string $key, array $ids, array $params, int $by): array
    {
        $consent = (int) ($params['consent'] ?? 0) ?: null;
        $own     = array_diff_key($params, array_flip(['consent', 'confirm']));
        $count   = ['sent' => 0, 'done' => 0, 'skipped' => [], 'error' => 0];
        foreach ($ids as $id) {
            $did = Engine::perform($key, (int) $id, $own, $consent, $by);
            if ($did['status'] === 'skipped') {
                $why = (string) ($did['reason'] ?? 'skipped');
                $count['skipped'][$why] = ($count['skipped'][$why] ?? 0) + 1;
            } elseif ($did['status'] === 'error') {
                $count['error']++;
            } else {
                $count[$did['status']]++;
            }
        }
        $parts = [];
        if ($count['sent'] > 0) {
            $parts[] = sprintf('Sent to %d.', $count['sent']);
        }
        if ($count['done'] > 0) {
            $parts[] = sprintf('Done for %d.', $count['done']);
        }
        foreach ($count['skipped'] as $why => $n) {
            $parts[] = sprintf('%d skipped: %s.', $n, self::why((string) $why));
        }
        if ($count['error'] > 0) {
            $parts[] = sprintf('%d failed.', $count['error']);
        }

        return ['done' => $count['sent'] + $count['done'], 'says' => implode(' ', $parts)];
    }

    /**
     * What a message to these people would do: how many it reaches, how many the hours, the
     * consent list or the caps keep from it, and the message the first it reaches gets.
     *
     * @param list<int|string>     $ids
     * @param array<string, mixed> $params
     * @return array{to: int, skipped: array<string, int>, sample: ?array{subject: string, body: string}}|string
     */
    public static function preview(string $key, array $ids, array $params, int $by): array|string
    {
        $action  = Actions::get($key);
        $channel = $action['channel'] ?? null;
        $consent = (int) ($params['consent'] ?? 0) ?: null;
        // A channel with consent lists is sent to one of them: the person chooses which.
        if ($consent === null && $channel !== null && self::lists($channel) !== []) {
            return 'Choose the consent list it goes by.';
        }
        $listName = $consent === null ? '' : (self::lists((string) $channel)[$consent] ?? '');
        if ($key === 'email' && trim((string) ($params['template'] ?? '')) === '' && trim((string) ($params['body'] ?? '')) === '') {
            return 'Choose a mail template, or write the message.';
        }
        $to      = 0;
        $skipped = [];
        $first   = null;
        foreach ($ids as $id) {
            $why = Engine::blocked((int) $id, $channel, $consent);
            if ($why !== null) {
                // Which list narrowed it, by its name.
                $said = self::why($why) . ($why === 'consent' && $listName !== '' ? ' «' . $listName . '»' : '');
                $skipped[$said] = ($skipped[$said] ?? 0) + 1;
                continue;
            }
            $to++;
            $first ??= (int) $id;
        }

        return ['to' => $to, 'skipped' => $skipped, 'sample' => $first === null ? null : self::sample($key, $first, $params)];
    }

    /**
     * The message one person gets: a template filled for them, or the text written.
     *
     * @param array<string, mixed> $params
     * @return array{subject: string, body: string}|null
     */
    private static function sample(string $key, int $person, array $params): ?array
    {
        if ($key === 'email' && trim((string) ($params['template'] ?? '')) !== '') {
            $user   = Actions::user($person);
            $lookup = $user === null ? null : \Pramnos\Messaging\MailTemplate::lookup((string) $params['template'], (string) ($user->language ?? ''));
            $filled = $lookup === null ? null : \Pramnos\Messaging\MailTemplate::fill($lookup, \Pramnos\Email\TemplateValues::forUser($user));

            return $filled === null ? null : ['subject' => (string) $filled['subject'], 'body' => (string) $filled['body']];
        }
        $subject = (string) ($params['subject'] ?? $params['title'] ?? '');
        $body    = (string) ($params['body'] ?? $params['text'] ?? '');

        return ['subject' => Actions::fill($subject, $person), 'body' => $key === 'email' ? Actions::markdown(Actions::fill($body, $person)) : Actions::fill($body, $person)];
    }

    /**
     * A rule action's fields, with what a message to many needs beside them: the consent list it
     * goes by, and the confirmation.
     *
     * @param array<string, array<string, mixed>> $params
     * @return array<string, array<string, mixed>>
     */
    private static function params(array $params, ?string $channel): array
    {
        foreach ($params as $name => $param) {
            // A rule's own kinds of field — a group, an attribute — are picked as text here.
            if (!in_array($param['type'] ?? 'text', ['text', 'textarea', 'number', 'select'], true)) {
                $params[$name]['type'] = 'text';
            }
        }
        if ($channel === null) {
            return $params;
        }

        return $params + [
            // The audience's consent lists of this channel — not a mail type's unsubscribe list, which is the email's own `list`.
            'consent' => ['label' => t('Consent list it goes by'), 'type' => 'select', 'options' => static fn (): array => self::lists($channel)],
            'confirm' => ['label' => t('Confirm (after the preview)'), 'type' => 'text'],
        ];
    }

    /**
     * A channel's consent lists, id => name; none where they are not installed.
     *
     * @return array<int, string>
     */
    public static function lists(string $channel): array
    {
        try {
            return array_column(\Pramnos\Audience\ConsentLists::all($channel), 'name', 'id');
        } catch (\Throwable) {
            return [];
        }
    }

    /** A reason as the person reads it. */
    private static function why(string $reason): string
    {
        return match ($reason) {
            'consent' => 'not on the consent list',
            'cap'     => 'over the frequency cap',
            'hours'   => 'outside the sending hours',
            default   => $reason,
        };
    }
}
