<?php

declare(strict_types=1);

namespace Pramnos\Bulk;

/**
 * Actions a person can take on a list of records at once — "send these people an email", "turn
 * these devices off" — declared once per kind of record and offered wherever such a list
 * appears: an Ask artifact, or a list screen of the application's own.
 *
 * ```php
 * BulkActions::define('device', 'disable', t('Turn off'), [
 *     'reason' => ['label' => t('Why'), 'type' => 'text', 'required' => true],
 * ], function (array $ids, array $params, int $by): array {
 *     $n = Devices::disable($ids, $params['reason'], $by);
 *     return ['done' => $n];
 * }, ability: 'admin.devices');
 *
 * BulkActions::available('device', $userId);             // what this person may do with devices
 * BulkActions::run('device', 'disable', $ids, ['reason' => 'Audit'], $userId);
 * ```
 *
 * Who may run an action is asked again when it runs, not only when it is offered: an
 * `ability` (checked by `AdminAccess`, the administration's own rule) and/or an `allowed`
 * callable. A `filter` callable narrows the ids to those this person may act on. Every run is
 * written to the audit log. A list longer than {@see INLINE} goes to the queue in chunks of
 * {@see CHUNK}, so a request is not held while thousands of records are handled — which means an
 * action must be defined in the worker too: declare it where the application boots, not in a
 * controller.
 *
 * The framework declares one: `user` → `group_add`, put the people in a user group (an existing
 * one, or a new one by name).
 */
final class BulkActions
{
    /** Records handled in the request; above this, in the queue. */
    public const INLINE = 200;

    /** Records in each queued task. */
    public const CHUNK = 500;

    /** Records one run may name. */
    public const MAX = 10000;

    /** @var array<string, array<string, array{label: string, params: array<string, array<string, mixed>>, run: callable, ability: ?string, allowed: ?callable, filter: ?callable}>> */
    private static array $actions = [];

    /** Whether the framework's own actions are declared yet. */
    private static bool $booted = false;

    /** The queue long lists go to, when not the application's. For a test. */
    private static ?\Pramnos\Queue\QueueManager $queue = null;

    /**
     * Declare an action on a kind of record. Declaring the same key again replaces it.
     *
     * @param array<string, array<string, mixed>> $params name => label, type (text, textarea, number,
     *        select), options (for a select: value => label, or a callable answering them), required
     * @param callable(list<int|string>, array<string, mixed>, int): (array{done?: int, says?: string}|int) $run
     * @param ?callable(int): bool                                  $allowed Whether this person may
     * @param ?callable(list<int|string>, int): list<int|string>   $filter  The ids this person may act on
     */
    public static function define(
        string $entity,
        string $key,
        string $label,
        array $params,
        callable $run,
        ?string $ability = null,
        ?callable $allowed = null,
        ?callable $filter = null
    ): void {
        self::boot();
        self::$actions[$entity][$key] = ['label' => $label, 'params' => $params, 'run' => $run, 'ability' => $ability, 'allowed' => $allowed, 'filter' => $filter];
    }

    /** Remove an action — the framework's own too, for an application that offers its own instead. */
    public static function forget(string $entity, string $key): void
    {
        self::boot();
        unset(self::$actions[$entity][$key]);
    }

    /**
     * What this person may do with records of this kind: key => label and params, the options of a
     * select resolved.
     *
     * @return array<string, array{label: string, params: array<string, array<string, mixed>>}>
     */
    public static function available(string $entity, int $userId): array
    {
        self::boot();
        $out = [];
        foreach (self::$actions[$entity] ?? [] as $key => $action) {
            if (!self::allows($action, $userId)) {
                continue;
            }
            $params = [];
            foreach ($action['params'] as $name => $param) {
                if (isset($param['options']) && is_callable($param['options'])) {
                    $param['options'] = (array) ($param['options'])($userId);
                }
                $param['label'] = \Pramnos\Translator\Language::getInstance()->_((string) ($param['label'] ?? $name));
                $params[$name]  = $param;
            }
            $out[$key] = ['label' => \Pramnos\Translator\Language::getInstance()->_($action['label']), 'params' => $params];
        }

        return $out;
    }

    /**
     * Run an action on these records, as this person. In the request for a short list, in the
     * queue for a long one. Answers what happened, or why it did not.
     *
     * @param list<int|string>     $ids
     * @param array<string, mixed> $params
     * @return array{done: int, queued: int, says: string}|string
     */
    public static function run(string $entity, string $key, array $ids, array $params, int $by): array|string
    {
        self::boot();
        $action = self::$actions[$entity][$key] ?? null;
        if ($action === null) {
            return 'There is no such action.';
        }
        if (!self::allows($action, $by)) {
            return 'You may not do that.';
        }
        foreach ($action['params'] as $name => $param) {
            if (!empty($param['required']) && trim((string) ($params[$name] ?? '')) === '') {
                return sprintf('%s is required.', (string) ($param['label'] ?? $name));
            }
        }
        $ids = array_values(array_unique(array_filter($ids, static fn ($id): bool => is_int($id) || (is_string($id) && $id !== '')), SORT_REGULAR));
        if ($action['filter'] !== null) {
            $ids = array_values(($action['filter'])($ids, $by));
        }
        if ($ids === []) {
            return 'There is nothing here to do that to.';
        }
        if (count($ids) > self::MAX) {
            return sprintf('Up to %d at a time.', self::MAX);
        }

        if (count($ids) <= self::INLINE) {
            $result = self::execute($entity, $key, $ids, $params, $by);

            return ['done' => $result['done'], 'queued' => 0, 'says' => $result['says']];
        }

        $queue  = self::$queue ?? new \Pramnos\Queue\QueueManager(
            \Pramnos\Queue\QueueManager::controllerOrPlain(\Pramnos\Application\Application::currentInstance(), 'queue')
        );
        $chunks = array_chunk($ids, self::CHUNK);
        foreach ($chunks as $chunk) {
            $queue->addTask(BulkActionTask::TASK, ['entity' => $entity, 'key' => $key, 'ids' => $chunk, 'params' => $params, 'by' => $by]);
        }

        return ['done' => 0, 'queued' => count($ids), 'says' => ''];
    }

    /**
     * Do it now — the request's short list, or one queued chunk. Asks again whether this person
     * may, since a queued chunk runs later.
     *
     * @param list<int|string>     $ids
     * @param array<string, mixed> $params
     * @return array{done: int, says: string}
     */
    public static function execute(string $entity, string $key, array $ids, array $params, int $by): array
    {
        self::boot();
        $action = self::$actions[$entity][$key] ?? null;
        if ($action === null || !self::allows($action, $by)) {
            return ['done' => 0, 'says' => 'Not allowed, or no longer defined.'];
        }
        $answer = ($action['run'])($ids, $params, $by);
        $done   = is_int($answer) ? $answer : (int) ($answer['done'] ?? 0);
        $says   = is_array($answer) ? (string) ($answer['says'] ?? '') : '';

        \Pramnos\Auth\AuditLog::record('bulk.' . $entity . '.' . $key, $by, null, [
            'count' => count($ids), 'done' => $done, 'params' => $params,
        ], 'bulk', $entity . '.' . $key);

        return ['done' => $done, 'says' => $says];
    }

    /** Back to the framework's own actions only, and the application's queue. For a test. */
    public static function reset(): void
    {
        self::$actions = [];
        self::$booted  = false;
        self::$queue   = null;
    }

    /** Send long lists to this queue instead of the application's. For a test. */
    public static function useQueue(?\Pramnos\Queue\QueueManager $queue): void
    {
        self::$queue = $queue;
    }

    /** @param array{ability: ?string, allowed: ?callable} $action */
    private static function allows(array $action, int $userId): bool
    {
        if ($userId < 2) {
            return false;
        }
        if ($action['ability'] !== null) {
            $user = new \Pramnos\User\User($userId);
            if (!\Pramnos\Auth\AdminAccess::allows($user, $action['ability'], \Pramnos\Auth\AdminAccess::defaultUsertype())) {
                return false;
            }
        }

        return $action['allowed'] === null || (bool) ($action['allowed'])($userId);
    }

    /** The framework's own actions, declared on first use. */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$actions['user']['group_add'] = [
            'label'   => 'Add to a group',
            'params'  => [
                'group'     => ['label' => 'Group', 'type' => 'select', 'options' => [GroupAdd::class, 'groups']],
                'new_group' => ['label' => 'Or a new group, by name', 'type' => 'text'],
            ],
            'run'     => [GroupAdd::class, 'run'],
            'ability' => 'admin.groups',
            'allowed' => null,
            'filter'  => null,
        ];
    }
}
