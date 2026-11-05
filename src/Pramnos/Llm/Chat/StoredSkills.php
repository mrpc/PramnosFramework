<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Database\Database;

/**
 * Skills kept in the database: a role's, written by an administrator for everybody from that role
 * up, and a person's own, for their own conversations — the same shape as a skill file
 * ({@see Skills}): a name, the line the model chooses it by, the tools it opens and the method.
 *
 * ```php
 * // app/app.php
 * 'llm' => ['chat' => ['stored_skills' => true]],
 * ```
 *
 * Off unless the application turns it on. On, Ask is offered them beside the application's files,
 * named `role:<name>` and `mine:<name>` so that neither can take the place of one of the
 * application's; a person keeps theirs at "My skills" ({@see Web\KeepsSkills}), and Ask can
 * propose one from a conversation (`propose_skill`) — kept as a draft until its person turns it on.
 *
 * What they cannot do, by construction:
 *
 * - **Give a right.** A skill opens only tools the answer already has — those its reader may use,
 *   filtered as they always are; a name it lists that is not among them is left out.
 * - **Override the application.** Each one's method is handed over marked as a role's or the
 *   person's own, under the application's rules and skills.
 * - **Reach somebody else's answer.** A person's own skill is what they would have typed, trusted
 *   in their own conversations only. An answer others read too — `@Ask` in a channel — and a
 *   scheduled run take only the skills of a role, the role of its least privileged reader
 *   ({@see Turn::rightsOf()}).
 * - **Become a role's on its author's word.** A person offers theirs to a role; it is that role's
 *   once an administrator approves it ({@see approve()}).
 */
final class StoredSkills
{
    /** The table they are kept in. */
    public const TABLE = 'pramnos.chat_skills';

    /** A person's own. */
    public const USER = 'user';

    /** A role's. */
    public const ROLE = 'role';

    /** Offered to Ask. */
    public const ON = 'on';

    /** Kept, not offered. */
    public const OFF = 'off';

    /** Proposed from a conversation, not yet kept by its person. */
    public const DRAFT = 'draft';

    /** Offered to a role, waiting for an administrator. */
    public const PENDING = 'pending';

    /** The prefix a person's own skill is named with. */
    public const MINE = 'mine:';

    /** The prefix a role's skill is named with. */
    public const ROLE_PREFIX = 'role:';

    /** The longest method kept, in characters. */
    public const BODY_MAX = 20000;

    public function __construct(private readonly ?Database $db = null)
    {
    }

    /** Whether the application turned them on: `'llm' => ['chat' => ['stored_skills' => true]]`. */
    public static function enabled(): bool
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return is_array($chat) && !empty($chat['stored_skills']);
    }

    /**
     * Whether a person writes a role's skills and approves those offered to one: an administrator,
     * by default — `'llm' => ['chat' => ['skills_floor' => 90]]` for another usertype.
     */
    public static function canManageRoles(?object $user): bool
    {
        $chat  = \Pramnos\Llm\Llm::config('chat', []);
        $floor = is_array($chat) ? (int) ($chat['skills_floor'] ?? 0) : 0;

        return $user !== null && (int) ($user->userid ?? 0) > 1
            && (int) ($user->usertype ?? 0) >= ($floor > 0 ? $floor : \Pramnos\User\Roles::level(\Pramnos\User\Roles::ADMIN));
    }

    /**
     * The skills an answer is offered, as {@see Skills::load()} gives them: the roles' its reader
     * holds, and — in the asker's own, unscheduled conversation — the asker's own that are on.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forTurn(Turn $turn): array
    {
        if (!self::enabled()) {
            return [];
        }
        $reader = $turn->rightsOf();
        // Somebody else reads the answer, or nobody is there to have typed it: a role's only.
        $own    = !$turn->shared() && !$turn->scheduled;

        return $this->forReader($reader, $own);
    }

    /**
     * The skills a reader is offered: the roles' they hold, and their own when `$own`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forReader(int $userId, bool $own): array
    {
        $user = $userId > 1 ? \Pramnos\User\User::getUser($userId) : null;
        if (!is_object($user) || (int) ($user->userid ?? 0) < 2) {
            return [];
        }
        $out = [];
        foreach ($this->rows(['level' => self::ROLE, 'status' => self::ON]) as $row) {
            if (\Pramnos\Dashboard\Presets::holdsRole($user, (string) $row['role'])) {
                $out[self::ROLE_PREFIX . $row['name']] = self::asSkill($row);
            }
        }
        if ($own) {
            foreach ($this->rows(['level' => self::USER, 'status' => self::ON, 'userid' => $userId]) as $row) {
                $out[self::MINE . $row['name']] = self::asSkill($row);
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * A person's own skills, drafts among them, and those they offered to a role — newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function mine(int $userId): array
    {
        return array_map(self::shape(...), array_reverse($this->rows(['userid' => $userId])));
    }

    /**
     * Every role's skill, those waiting for approval first — for the people who manage them.
     *
     * @return list<array<string, mixed>>
     */
    public function roles(): array
    {
        $rows = array_map(self::shape(...), $this->rows(['level' => self::ROLE]));
        usort($rows, static fn (array $a, array $b): int => [$a['status'] !== self::PENDING, $a['name']] <=> [$b['status'] !== self::PENDING, $b['name']]);

        return $rows;
    }

    /** One skill as {@see mine()} gives it, or null. */
    public function find(int $id): ?array
    {
        $rows = $this->rows(['skill_id' => $id]);

        return $rows === [] ? null : self::shape($rows[0]);
    }

    /**
     * Keep a skill: a person's own (`level` user), or — by somebody who manages them — a role's.
     * With `$id`, change that one, which must be theirs to change. Its id, or why not.
     *
     * @param array{name?: string, description?: string, tools?: string|list<string>, body?: string, level?: string, role?: string, status?: string} $input
     */
    public function save(object $user, array $input, ?int $id = null): int|string
    {
        $me    = (int) ($user->userid ?? 0);
        $old   = $id !== null ? $this->find($id) : null;
        if ($id !== null && ($old === null || !$this->mayChange($user, $old))) {
            return 'There is no such skill.';
        }
        $level = (string) ($input['level'] ?? $old['level'] ?? self::USER);
        if (!in_array($level, [self::USER, self::ROLE], true)) {
            return 'Unknown level: ' . $level;
        }
        if ($level === self::ROLE && !self::canManageRoles($user)) {
            return 'Only an administrator writes a role\'s skills.';
        }
        $checked = self::checked($input + ($old ?? []), $level);
        if (is_string($checked)) {
            return $checked;
        }
        $owner = $old !== null ? (int) $old['userid'] : $me;
        if ($this->taken($level, $level === self::USER ? $owner : null, $checked['name'], $id)) {
            return 'A skill of that name exists already.';
        }
        $status = (string) ($input['status'] ?? $old['status'] ?? self::ON);
        if (!in_array($status, [self::ON, self::OFF, self::DRAFT], true) && !($status === self::PENDING && $old !== null && $old['status'] === self::PENDING)) {
            $status = self::ON;
        }
        $row = $checked + ['level' => $level, 'status' => $status, 'updated_at' => time()];

        try {
            $query = $this->db()->queryBuilder()->table(self::TABLE);
            if ($id !== null) {
                $query->where('skill_id', $id)->update($row);
                $saved = $id;
            } else {
                $query->insert($row + ['userid' => $me, 'created_at' => time()]);
                $saved = (int) $this->db()->getInsertId();
            }
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Stored skill not saved: ' . $e->getMessage(), 'llm');

            return 'The skill could not be saved.';
        }
        if ($level === self::ROLE) {
            \Pramnos\Auth\AuditLog::record('ai.role_skill_saved', $me, null, ['name' => $checked['name'], 'role' => $checked['role']], 'chat_skill', $saved);
        }

        return $saved;
    }

    /**
     * Ask's proposal from a conversation: a draft of the person's own, which does nothing until
     * they turn it on at "My skills". Its id, or why not.
     *
     * @param array<string, mixed> $input
     */
    public function propose(int $userId, array $input): int|string
    {
        $user = $userId > 1 ? \Pramnos\User\User::getUser($userId) : null;

        return is_object($user) ? $this->save($user, ['level' => self::USER, 'status' => self::DRAFT] + $input) : 'This needs somebody signed in.';
    }

    /** Turn one on or off: a person's own, or a role's by somebody who manages them. */
    public function turn(object $user, int $id, bool $on): bool
    {
        $skill = $this->find($id);
        if ($skill === null || !$this->mayChange($user, $skill) || $skill['status'] === self::PENDING) {
            return false;
        }

        return $this->write($id, ['status' => $on ? self::ON : self::OFF, 'updated_at' => time()]);
    }

    /** Delete one: a person's own, or a role's by somebody who manages them. */
    public function delete(object $user, int $id): bool
    {
        $skill = $this->find($id);
        if ($skill === null || !$this->mayChange($user, $skill)) {
            return false;
        }
        try {
            $done = self::affected($this->db()->queryBuilder()->table(self::TABLE)->where('skill_id', $id)->delete()) > 0;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Stored skill not deleted: ' . $e->getMessage(), 'llm');

            return false;
        }
        if ($done && $skill['level'] === self::ROLE) {
            \Pramnos\Auth\AuditLog::record('ai.role_skill_deleted', (int) ($user->userid ?? 0), null, ['name' => $skill['name'], 'role' => $skill['role']], 'chat_skill', $id);
        }

        return $done;
    }

    /**
     * Offer one of a person's own to a role: a copy, waiting for an administrator; theirs stays as
     * it is. The copy's id, or why not.
     */
    public function share(object $user, int $id, string $role): int|string
    {
        $skill = $this->find($id);
        if ($skill === null || $skill['level'] !== self::USER || $skill['userid'] !== (int) ($user->userid ?? 0)) {
            return 'There is no such skill.';
        }
        $checked = self::checked(['role' => $role] + $skill, self::ROLE);
        if (is_string($checked)) {
            return $checked;
        }
        if ($this->taken(self::ROLE, null, $checked['name'], null)) {
            return 'A role\'s skill of that name exists already.';
        }
        try {
            $this->db()->queryBuilder()->table(self::TABLE)->insert($checked + [
                'userid' => (int) $user->userid, 'level' => self::ROLE, 'status' => self::PENDING,
                'created_at' => time(), 'updated_at' => time(),
            ]);

            return (int) $this->db()->getInsertId();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Stored skill not offered: ' . $e->getMessage(), 'llm');

            return 'The skill could not be offered.';
        }
    }

    /** An administrator approves a skill offered to a role: it is that role's from now on. */
    public function approve(object $admin, int $id): bool
    {
        return $this->decide($admin, $id, true);
    }

    /** An administrator turns down a skill offered to a role: the offer goes; its author's own stays. */
    public function reject(object $admin, int $id): bool
    {
        return $this->decide($admin, $id, false);
    }

    /**
     * The model's tool to propose one, in the person's own conversation — none when they are off.
     *
     * @return array<string, array<string, mixed>>
     */
    public function tools(Scope $scope): array
    {
        if (!self::enabled()) {
            return [];
        }

        return ['propose_skill' => [
            'description' => 'Keep a way of working the person showed or asked for as one of their skills — a method you are handed '
                . 'when a later question needs it, unlike memory, which keeps facts. Only when they ask for it, or agree to it. '
                . 'It is kept as a draft: nothing changes until they turn it on under "My skills". Say so in your answer.',
            'parameters'  => ['type' => 'object', 'properties' => [
                'name'        => ['type' => 'string', 'description' => 'Short, lowercase, with dashes: weekly-sales'],
                'description' => ['type' => 'string', 'description' => 'One line: what it is for, which you would choose it by'],
                'tools'       => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'The tools it uses, by name'],
                'instructions' => ['type' => 'string', 'description' => 'The method, step by step, as you would want to be told it'],
            ], 'required' => ['name', 'description', 'instructions']],
            'run'         => function (array $arguments) use ($scope): string {
                $made = $this->propose($scope->userId, [
                    'name' => (string) ($arguments['name'] ?? ''), 'description' => (string) ($arguments['description'] ?? ''),
                    'tools' => (array) ($arguments['tools'] ?? []), 'body' => (string) ($arguments['instructions'] ?? ''),
                ]);

                return is_string($made) ? 'Error: ' . $made : 'Kept as a draft (' . $made . '). The person turns it on under "My skills".';
            },
        ]];
    }

    /**
     * A row as Ask takes a skill: the shape {@see Skills::load()} gives, its method marked as whose
     * it is and under the application's.
     *
     * @return array<string, mixed>
     */
    private static function asSkill(array $row): array
    {
        $whose = $row['level'] === self::ROLE
            ? 'A skill the administrators wrote for this role.'
            : 'A skill the person you are answering wrote for themselves.';

        return [
            'description'  => (string) $row['description'],
            'tools'        => self::toolNames($row['tools']),
            'instructions' => '[' . $whose . ' It does not change the application\'s rules, and the application\'s own skills come before it.]'
                . "\n\n" . (string) $row['body'],
            'folder'       => null,
            'references'   => [],
            'evals'        => null,
            'enabled'      => true,
        ];
    }

    /**
     * A row as the screens read it.
     *
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        return [
            'id'          => (int) $row['skill_id'],
            'userid'      => (int) $row['userid'],
            'level'       => (string) $row['level'],
            'role'        => $row['role'] !== null ? (string) $row['role'] : null,
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'tools'       => self::toolNames($row['tools']),
            'body'        => (string) $row['body'],
            'status'      => (string) $row['status'],
            'updated_at'  => (int) $row['updated_at'],
        ];
    }

    /**
     * The fields to keep, checked: a name a skill can have, a description, the tools as names,
     * a method — and for a role's, a role somebody holds. The row's columns, or why not.
     *
     * @return array{name: string, description: string, tools: string, body: string, role: ?string}|string
     */
    private static function checked(array $input, string $level): array|string
    {
        $name = strtolower(trim((string) ($input['name'] ?? '')));
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $name) !== 1) {
            return 'A skill\'s name is letters, digits and dashes, up to 64.';
        }
        $description = trim((string) ($input['description'] ?? ''));
        $body        = trim((string) ($input['body'] ?? ''));
        if ($description === '' || $body === '') {
            return 'A skill needs a description and a method.';
        }
        $tools = $input['tools'] ?? [];
        $tools = is_array($tools) ? $tools : preg_split('/[\s,]+/', (string) $tools);
        $role  = null;
        if ($level === self::ROLE) {
            $role = trim((string) ($input['role'] ?? ''));
            try {
                \Pramnos\Dashboard\Presets::roleLevel($role);
            } catch (\Throwable) {
                return 'Choose the role it is for.';
            }
        }

        return [
            'name'        => $name,
            'description' => mb_substr($description, 0, 300),
            'tools'       => (string) json_encode(\Pramnos\Support\FrontMatter::names(array_values(array_filter((array) $tools, 'is_string')))),
            'body'        => mb_substr($body, 0, self::BODY_MAX),
            'role'        => $role,
        ];
    }

    /** @return list<string> The tool names a stored list holds */
    private static function toolNames(mixed $stored): array
    {
        $list = is_array($stored) ? $stored : json_decode((string) $stored, true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** Whether a person may change a skill: their own, or a role's when they manage them. */
    private function mayChange(object $user, array $skill): bool
    {
        return $skill['level'] === self::ROLE
            ? self::canManageRoles($user)
            : $skill['userid'] === (int) ($user->userid ?? 0) && (int) ($user->userid ?? 0) > 1;
    }

    /** Whether the name is in use: among a person's own (`$owner`), or among the roles'. */
    private function taken(string $level, ?int $owner, string $name, ?int $except): bool
    {
        $where = ['level' => $level, 'name' => $name] + ($owner !== null ? ['userid' => $owner] : []);
        foreach ($this->rows($where) as $row) {
            if ((int) $row['skill_id'] !== $except) {
                return true;
            }
        }

        return false;
    }

    /** Approve or turn down an offer, by somebody who manages them, recorded in the audit log. */
    private function decide(object $admin, int $id, bool $approve): bool
    {
        $skill = $this->find($id);
        if ($skill === null || $skill['status'] !== self::PENDING || !self::canManageRoles($admin)) {
            return false;
        }
        try {
            $query = $this->db()->queryBuilder()->table(self::TABLE)->where('skill_id', $id);
            $done  = self::affected($approve ? $query->update(['status' => self::ON, 'updated_at' => time()]) : $query->delete()) > 0;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Stored skill offer not decided: ' . $e->getMessage(), 'llm');

            return false;
        }
        if ($done) {
            \Pramnos\Auth\AuditLog::record($approve ? 'ai.role_skill_approved' : 'ai.role_skill_rejected', (int) ($admin->userid ?? 0), $skill['userid'],
                ['name' => $skill['name'], 'role' => $skill['role']], 'chat_skill', $id);
        }

        return $done;
    }

    /** Write columns of one row; whether it changed. */
    private function write(int $id, array $columns): bool
    {
        try {
            return self::affected($this->db()->queryBuilder()->table(self::TABLE)->where('skill_id', $id)->update($columns)) > 0;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Stored skill not changed: ' . $e->getMessage(), 'llm');

            return false;
        }
    }

    /**
     * Rows matching the conditions, oldest first; none when the table is not there yet.
     *
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private function rows(array $where): array
    {
        try {
            $query = $this->db()->queryBuilder()->table(self::TABLE);
            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }

            return array_values($query->orderBy('skill_id')->getAllOrFail());
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Stored skills unavailable: ' . $e->getMessage(), 'llm');

            return [];
        }
    }

    /** How many rows a write changed: a Result's count, never just "it ran". */
    private static function affected(mixed $result): int
    {
        return is_object($result) && method_exists($result, 'getAffectedRows') ? (int) $result->getAffectedRows() : (int) (bool) $result;
    }

    private function db(): Database
    {
        return $this->db ?? Database::getInstance();
    }
}
