<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Mcp\McpToolInterface;
use Pramnos\Todos\Todos;

/**
 * Add a to-do, or change one, for the assistant — a write, offered where the application lets it
 * act. `assigned_to` 0 is nobody in particular, a `due` of '' clears the day, and an organisation
 * whose plan has no to-dos (`todos.allowed`) is refused.
 */
final class TodosChangeTool implements McpToolInterface
{
    /**
     * @param int         $adminId Who is asking
     * @param object|null $as      The person asking, where the caller has them already
     */
    public function __construct(private readonly int $adminId = 0, private readonly ?object $as = null)
    {
    }

    /** `<the list's tool>_change`. */
    public function name(): string
    {
        return Todos::toolName() . '_change';
    }

    public function description(): string
    {
        return 'Add a to-do, or change one (id), only when the person asked for it or agreed. title: a line, its web address the link. '
            . 'assigned_to: a user id or name, "me", or 0 for nobody in particular. due: YYYY-MM-DD, or "" to clear it. '
            . 'remind_at: YYYY-MM-DD HH:MM on the person\'s clock, or "" to clear it. status: open, in_progress, done, dismissed. '
            . 'private: only its author sees it. repeat: daily, weekdays, weekly, monthly, yearly, or "" to stop — it needs a due day or '
            . 'a reminder, and when it is done the next one opens by itself. A change keeps what it does not name.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'id'          => ['type' => 'integer', 'description' => 'The to-do to change; none to add one.'],
            'title'       => ['type' => 'string'],
            'assigned_to' => ['type' => 'string', 'description' => 'A user id or name, "me", or 0.'],
            'due'         => ['type' => 'string'],
            'remind_at'   => ['type' => 'string'],
            'status'      => ['type' => 'string', 'enum' => Todos::STATUSES],
            'link'        => ['type' => 'string'],
            'private'     => ['type' => 'boolean'],
            'repeat'      => ['type' => 'string', 'enum' => [...Todos::REPEATS, '']],
        ]];
    }

    /** @param array<string, mixed> $input */
    public function execute(array $input): mixed
    {
        $user = $this->as ?? ($this->adminId > 0 ? new \Pramnos\User\User($this->adminId) : null);
        if (!Todos::installed() || !Todos::can($user, 'write')) {
            return 'Error: the to-do list is not yours to change.';
        }
        $me     = (int) $user->userid;
        $tenant = Todos::tenantOf($user);
        if (!Todos::allowed($tenant)) {
            return 'Error: this plan has no to-dos.';
        }
        $zone    = \Pramnos\Metrics\Window::zoneOf($tenant);
        $changes = array_intersect_key($input, array_flip(['title', 'due', 'status', 'link', 'private', 'repeat']));
        if (array_key_exists('assigned_to', $input)) {
            $who = mb_strtolower(trim((string) $input['assigned_to']));
            $to  = in_array($who, ['me', 'εγώ', 'εμένα'], true) ? $me : ($who === '' || $who === '0' ? 0 : TodosTool::person($who, $tenant));
            if (is_string($to)) {
                return 'Error: ' . $to;
            }
            $changes['assigned_to'] = $to;
        }
        if (array_key_exists('remind_at', $input)) {
            $at = trim((string) $input['remind_at']);
            $on = $at === '' ? null : \DateTimeImmutable::createFromFormat('Y-m-d H:i', $at, $zone);
            if ($on === false) {
                return 'Error: remind_at is YYYY-MM-DD HH:MM.';
            }
            $changes['remind_at'] = $on === null ? 0 : $on->getTimestamp();
        }
        try {
            if (isset($input['id'])) {
                $item = Todos::get((int) $input['id']);
                if ($item === null || $item['tenant'] !== $tenant) {
                    return 'Error: there is no to-do ' . (int) $input['id'] . '.';
                }
                Todos::update($item['id'], $changes, $me);
                $id = $item['id'];
            } else {
                $id = Todos::add(['tenant' => $tenant] + $changes, $me);
            }
        } catch (\InvalidArgumentException $e) {
            return 'Error: ' . $e->getMessage();
        }
        $item = Todos::get($id);

        return [isset($input['id']) ? 'changed' : 'added' => TodosTool::item($item, \Pramnos\Conversations\People::names([(int) $item['assigned_to']]), $zone)];
    }
}
