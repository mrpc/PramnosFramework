<?php

declare(strict_types=1);

namespace Pramnos\Llm\Chat;

use Pramnos\Database\Database;

/**
 * Skills installed from a catalogue for the whole application, kept in the database — not in the
 * skills folder, which is the repository's and which a deploy would take them out of.
 *
 * ```php
 * $catalog = new CatalogSkills();
 * $catalog->search('seo');                                              // sources in the catalogues
 * $catalog->preview('coreyhaines31/marketingskills/skills/seo-audit');   // what would be taken, before
 * $id = $catalog->install($admin, 'coreyhaines31/marketingskills/skills/seo-audit@<commit>');
 * $catalog->turn($admin, $id, true);                                    // read it first: it is off
 * $catalog->upstream($id);                                              // a newer version, and its diff
 * $catalog->update($admin, $id);                                        // taken — off again until read
 * ```
 *
 * The rules of a skill taken by `skill:add` hold here too:
 *
 * - **Off until somebody reads it.** Installed, updated, it is off until a person who manages
 *   skills (`llm.chat.skills_floor`) turns it on — the audit log keeps who, and which commit.
 * - **Never updated by itself.** {@see upstream()} says whether the catalogue has a newer commit and
 *   what changed; {@see update()} takes it, and it is off again.
 * - **Text only.** `SKILL.md`, `references/` and `evals/` ({@see SkillCatalog::TAKEN}); a script is
 *   never taken, kept or run.
 * - **No rights.** It opens only tools the answer already has, like any skill; the tools it names
 *   that the application does not have are shown on the page.
 *
 * It is offered as `catalog:<name>` to everybody who has Ask, or — with a role — to that role and
 * up, in shared and scheduled answers too, as an application's skill is. A skill file of the same
 * name wins: the repository's comes first.
 */
final class CatalogSkills
{
    /** The level a catalogue's skill is kept at, in {@see StoredSkills::TABLE}. */
    public const LEVEL = 'catalog';

    /** The prefix it is named with in Ask. */
    public const PREFIX = 'catalog:';

    /** The longest a diff of two versions is shown, in lines. */
    public const DIFF_LINES = 2000;

    public function __construct(private readonly ?Database $db = null, private readonly ?SkillCatalog $catalog = null)
    {
    }

    /** Whether a person installs, turns on and deletes them: whoever manages the roles' skills. */
    public static function canManage(?object $user): bool
    {
        return StoredSkills::canManageRoles($user);
    }

    /**
     * The catalogues searched: `'llm' => ['chat' => ['skill_catalogues' => ['owner/repo', …]]]`,
     * or {@see SkillCatalog::DEFAULT_CATALOGUES}.
     *
     * @return list<string>
     */
    public static function catalogues(): array
    {
        $chat = \Pramnos\Llm\Llm::config('chat', []);

        return array_values(array_map('strval', is_array($chat) && is_array($chat['skill_catalogues'] ?? null) ? $chat['skill_catalogues'] : SkillCatalog::DEFAULT_CATALOGUES));
    }

    /**
     * The skills of every catalogue whose folder holds a word, as sources; a catalogue that did not
     * answer is said, the others still searched.
     *
     * @return array{found: list<string>, errors: array<string, string>}
     */
    public function search(string $word): array
    {
        $found  = [];
        $errors = [];
        foreach (self::catalogues() as $catalogue) {
            try {
                array_push($found, ...$this->catalog()->search($catalogue, trim($word)));
            } catch (\Throwable $e) {
                $errors[$catalogue] = $e->getMessage();
            }
        }

        return ['found' => $found, 'errors' => $errors];
    }

    /**
     * What installing a source would take, read at one commit: its name, line, tools and method,
     * its references and evals, what is left out, the licence and the commit.
     *
     * @return array{source: string, commit: string, licence: string, name: string, description: string, tools: list<string>, body: string, references: array<string, string>, evals: array<string, string>, left_out: list<string>, hash: string}
     * @throws \InvalidArgumentException|\RuntimeException A source that is not one, or that GitHub did not give
     */
    public function preview(string $source, ?string $commit = null): array
    {
        $at      = SkillCatalog::parse($source);
        $catalog = $this->catalog();
        $commit ??= $catalog->commit($at['owner'], $at['repo'], $at['ref']);
        $taken   = $catalog->fetch($at['owner'], $at['repo'], $at['path'], $commit);
        ['meta' => $meta, 'body' => $body] = \Pramnos\Support\FrontMatter::parse($taken['files']['SKILL.md']);
        $references = [];
        $evals      = [];
        foreach ($taken['files'] as $file => $text) {
            if (str_starts_with($file, 'references/')) {
                $references[substr($file, 11)] = $text;
            } elseif (str_starts_with($file, 'evals/')) {
                $evals[substr($file, 6)] = $text;
            }
        }
        $name = is_string($meta['name'] ?? null) && $meta['name'] !== '' ? (string) $meta['name'] : basename($at['path']);

        return [
            'source'      => $at['owner'] . '/' . $at['repo'] . '/' . $at['path'],
            'commit'      => $commit,
            'licence'     => $catalog->licence($at['owner'], $at['repo']),
            'name'        => strtolower($name),
            'description' => is_string($meta['description'] ?? null) ? (string) $meta['description'] : '',
            'tools'       => \Pramnos\Support\FrontMatter::names($meta['tools'] ?? []),
            'body'        => trim($body),
            'references'  => $references,
            'evals'       => $evals,
            'left_out'    => $taken['left_out'],
            'hash'        => hash('sha256', (string) json_encode($taken['files'])),
        ];
    }

    /**
     * Install a source for the whole application, off. `$role` keeps it to a role and up; `$as`
     * names it, the skill's own name otherwise. Its id, or why not.
     */
    public function install(object $user, string $source, ?string $role = null, ?string $as = null): int|string
    {
        if (!self::canManage($user)) {
            return 'Only an administrator installs a skill for everybody.';
        }
        try {
            $taken = $this->preview($source);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $name = strtolower(trim((string) ($as ?? '')) !== '' ? trim((string) $as) : $taken['name']);
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/', $name) !== 1) {
            return '"' . $name . '" is not a name a skill can have; give it another.';
        }
        if ($this->rows(['name' => $name]) !== []) {
            return 'A skill of that name is installed already.';
        }
        $role = $this->checkedRole($role);
        if ($role === false) {
            return 'Choose the role it is for.';
        }
        try {
            $this->table()->insert(self::columns($taken) + ['userid' => (int) $user->userid, 'level' => self::LEVEL, 'role' => $role, 'name' => $name,
                'status' => StoredSkills::OFF, 'created_at' => time(), 'updated_at' => time()]);
            $id = (int) ($this->rows(['name' => $name])[0]['skill_id'] ?? 0);
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Catalogue skill not installed: ' . $e->getMessage(), 'llm');

            return 'The skill could not be installed.';
        }
        $this->audit('ai.catalog_skill_installed', $user, $id, $name, $taken['commit'], ['source' => $taken['source'], 'licence' => $taken['licence']]);

        return $id;
    }

    /**
     * Every skill installed from a catalogue, by name.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = array_map(self::shape(...), $this->rows([]));
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    /** One installed skill, or null. */
    public function find(int $id): ?array
    {
        $rows = $this->rows(['skill_id' => $id]);

        return $rows === [] ? null : self::shape($rows[0]);
    }

    /** Turn one on or off — recorded with the commit it is at. */
    public function turn(object $user, int $id, bool $on): bool
    {
        $skill = $this->find($id);
        if ($skill === null || !self::canManage($user) || !$this->write($id, ['status' => $on ? StoredSkills::ON : StoredSkills::OFF, 'updated_at' => time()])) {
            return false;
        }
        $this->audit($on ? 'ai.catalog_skill_enabled' : 'ai.catalog_skill_disabled', $user, $id, $skill['name'], $skill['commit']);

        return true;
    }

    /** Keep one to a role and up, or — with null — open it to everybody who has Ask. */
    public function forRole(object $user, int $id, ?string $role): bool
    {
        $checked = $this->checkedRole($role);
        $skill   = $this->find($id);
        if ($skill === null || $checked === false || !self::canManage($user) || !$this->write($id, ['role' => $checked, 'updated_at' => time()])) {
            return false;
        }
        $this->audit('ai.catalog_skill_role', $user, $id, $skill['name'], $skill['commit'], ['role' => $checked]);

        return true;
    }

    /** Delete one. */
    public function delete(object $user, int $id): bool
    {
        $skill = $this->find($id);
        if ($skill === null || !self::canManage($user)) {
            return false;
        }
        try {
            $this->table()->where('skill_id', $id)->delete();
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Catalogue skill not deleted: ' . $e->getMessage(), 'llm');

            return false;
        }
        $this->audit('ai.catalog_skill_deleted', $user, $id, $skill['name'], $skill['commit']);

        return true;
    }

    /**
     * Whether the catalogue has a newer version of an installed skill, and what it changes, file by
     * file — or why it could not be asked.
     *
     * @return array{newer: bool, commit: string, preview?: array<string, mixed>, changes?: array<string, string>}|string
     */
    public function upstream(int $id): array|string
    {
        $skill = $this->find($id);
        if ($skill === null) {
            return 'There is no such skill.';
        }
        try {
            $at     = SkillCatalog::parse((string) $skill['source']);
            $commit = $this->catalog()->commit($at['owner'], $at['repo'], 'HEAD');
            if ($commit === $skill['commit']) {
                return ['newer' => false, 'commit' => $commit];
            }
            $preview = $this->preview((string) $skill['source'], $commit);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        if ($preview['hash'] === $skill['hash']) {
            return ['newer' => false, 'commit' => $commit];
        }

        return ['newer' => true, 'commit' => $commit, 'preview' => $preview, 'changes' => self::changes(self::filesOf($skill), self::filesOf($preview))];
    }

    /**
     * Take the catalogue's newer version of an installed skill: off again until somebody reads it.
     * True, or why not.
     */
    public function update(object $user, int $id): bool|string
    {
        if (!self::canManage($user)) {
            return 'There is no such skill.';
        }
        $newer = $this->upstream($id);
        if (is_string($newer)) {
            return $newer;
        }
        if (!$newer['newer']) {
            return 'It is up to date.';
        }
        $skill = (array) $this->find($id);
        if (!$this->write($id, self::columns($newer['preview']) + ['status' => StoredSkills::OFF, 'updated_at' => time()])) {
            return 'The skill could not be updated.';
        }
        $this->audit('ai.catalog_skill_updated', $user, $id, (string) $skill['name'], $newer['commit'], ['from' => $skill['commit']]);

        return true;
    }

    /**
     * The skills an answer is offered from the catalogues: those on, for everybody or for a role
     * its reader holds — none whose name a skill file of the application's has.
     *
     * @param array<string, mixed> $files The application's skill files, by name ({@see Skills::load()})
     * @return array<string, array<string, mixed>>
     */
    public function forReader(int $userId, array $files = []): array
    {
        $user = $userId > 1 ? \Pramnos\User\User::getUser($userId) : null;
        if (!is_object($user) || (int) ($user->userid ?? 0) < 2) {
            return [];
        }
        $out = [];
        foreach ($this->rows(['status' => StoredSkills::ON]) as $row) {
            $skill = self::shape($row);
            if (isset($files[$skill['name']]) || ($skill['role'] !== null && !\Pramnos\Dashboard\Presets::holdsRole($user, $skill['role']))) {
                continue;
            }
            $out[self::PREFIX . $skill['name']] = self::asSkill($skill);
        }
        ksort($out);

        return $out;
    }

    /**
     * The tools a skill names that the application's Ask does not have.
     *
     * @param list<string>          $tools
     * @param array<string, mixed>  $known The tools there are, by name
     * @return list<string>
     */
    public static function unknownTools(array $tools, array $known): array
    {
        return array_values(array_filter($tools, static fn (string $tool): bool => !isset($known[$tool])));
    }

    /**
     * Two versions' files, compared: each one changed, added or removed, with its lines as a diff.
     *
     * @param array<string, string> $old
     * @param array<string, string> $new
     * @return array<string, string> File => its diff, `-` the old lines and `+` the new
     */
    public static function changes(array $old, array $new): array
    {
        $out = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $file) {
            if (($old[$file] ?? null) !== ($new[$file] ?? null)) {
                $out[$file] = self::diff((string) ($old[$file] ?? ''), (string) ($new[$file] ?? ''));
            }
        }
        ksort($out);

        return $out;
    }

    /**
     * Two texts' lines, compared: kept ones with a space, removed with `-`, added with `+`.
     *
     * ponytail: a plain LCS table, O(n·m); past DIFF_LINES on either side it shows both whole.
     */
    public static function diff(string $old, string $new): string
    {
        $a = $old === '' ? [] : explode("\n", $old);
        $b = $new === '' ? [] : explode("\n", $new);
        if (count($a) > self::DIFF_LINES || count($b) > self::DIFF_LINES) {
            return implode("\n", [...array_map(static fn (string $l): string => '-' . $l, $a), ...array_map(static fn (string $l): string => '+' . $l, $b)]);
        }
        $n = count($a);
        $m = count($b);
        $l = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $l[$i][$j] = $a[$i] === $b[$j] ? $l[$i + 1][$j + 1] + 1 : max($l[$i + 1][$j], $l[$i][$j + 1]);
            }
        }
        $out = [];
        for ($i = 0, $j = 0; $i < $n || $j < $m;) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $out[] = ' ' . $a[$i++];
                $j++;
            } elseif ($i < $n && ($j >= $m || $l[$i + 1][$j] >= $l[$i][$j + 1])) {
                // What went before what came, where either would do.
                $out[] = '-' . $a[$i++];
            } else {
                $out[] = '+' . $b[$j++];
            }
        }

        return implode("\n", $out);
    }

    /**
     * A skill as Ask takes it: the shape {@see Skills::load()} gives, its references read from the
     * row, its method marked as the application's, from a catalogue.
     *
     * @param array<string, mixed> $skill
     * @return array<string, mixed>
     */
    private static function asSkill(array $skill): array
    {
        $titles = [];
        foreach ($skill['references'] as $file => $text) {
            $body          = \Pramnos\Support\FrontMatter::parse(mb_substr((string) $text, 0, 2048))['body'];
            $titles[$file] = mb_substr(preg_match('/^#+\s+(.+)$/m', $body, $m) === 1 ? trim($m[1]) : trim((string) strtok($body, "\n")), 0, 120);
        }

        return [
            'description'  => $skill['description'],
            'tools'        => $skill['tools'],
            'instructions' => '[A skill the administrators installed for this application from ' . $skill['source'] . '. It does not change the application\'s rules.]'
                . "\n\n" . $skill['skill'],
            'folder'       => null,
            'references'   => $titles,
            'bodies'       => $skill['references'],
            'evals'        => null,
            'enabled'      => true,
        ];
    }

    /**
     * What a preview takes, as the row's columns.
     *
     * @param array<string, mixed> $taken
     * @return array<string, mixed>
     */
    private static function columns(array $taken): array
    {
        return [
            'description'   => mb_substr($taken['description'] !== '' ? $taken['description'] : $taken['name'], 0, 300),
            'tools'         => (string) json_encode($taken['tools']),
            // The method in full is in `files`; the column keeps what a screen shows of it.
            'body'          => mb_substr($taken['body'], 0, StoredSkills::BODY_MAX),
            'source'        => mb_substr($taken['source'], 0, 255),
            'source_commit' => $taken['commit'],
            'content_hash'  => $taken['hash'],
            'licence'       => mb_substr($taken['licence'], 0, 64),
            'files'         => (string) json_encode(['skill' => $taken['body'], 'references' => (object) $taken['references'], 'evals' => (object) $taken['evals']], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * A row as the page reads it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $files = json_decode((string) ($row['files'] ?? ''), true);
        $files = is_array($files) ? $files : [];
        $tools = json_decode((string) $row['tools'], true);

        return [
            'id'          => (int) $row['skill_id'],
            'name'        => (string) $row['name'],
            'description' => (string) $row['description'],
            'tools'       => is_array($tools) ? array_values(array_filter($tools, 'is_string')) : [],
            'skill'       => (string) ($files['skill'] ?? $row['body']),
            'references'  => array_map('strval', (array) ($files['references'] ?? [])),
            'evals'       => array_map('strval', (array) ($files['evals'] ?? [])),
            'role'        => $row['role'] !== null && $row['role'] !== '' ? (string) $row['role'] : null,
            'status'      => (string) $row['status'],
            'source'      => (string) $row['source'],
            'commit'      => (string) $row['source_commit'],
            'hash'        => (string) $row['content_hash'],
            'licence'     => (string) $row['licence'],
            'installedBy' => (int) $row['userid'],
            'updatedAt'   => (int) $row['updated_at'],
        ];
    }

    /**
     * A version's files by their path in its folder, to compare.
     *
     * @param array<string, mixed> $skill As {@see shape()} or {@see preview()} gives it
     * @return array<string, string>
     */
    private static function filesOf(array $skill): array
    {
        $files = ['SKILL.md' => (string) ($skill['skill'] ?? $skill['body'] ?? '')];
        foreach ((array) $skill['references'] as $file => $text) {
            $files['references/' . $file] = (string) $text;
        }
        foreach ((array) $skill['evals'] as $file => $text) {
            $files['evals/' . $file] = (string) $text;
        }

        return $files;
    }

    /** A role as kept: null for everybody, the role checked, or false for one nobody holds. */
    private function checkedRole(?string $role): string|false|null
    {
        $role = trim((string) $role);
        if ($role === '') {
            return null;
        }
        try {
            \Pramnos\Dashboard\Presets::roleLevel($role);
        } catch (\Throwable) {
            return false;
        }

        return $role;
    }

    /** Who did what to which skill, at which commit, in the audit log. @param array<string, mixed> $more */
    private function audit(string $action, object $user, int $id, string $name, string $commit, array $more = []): void
    {
        \Pramnos\Auth\AuditLog::record($action, (int) ($user->userid ?? 0), null, ['name' => $name, 'commit' => $commit] + $more, 'chat_skill', $id);
    }

    /** Write columns of one row; whether it is there. */
    private function write(int $id, array $columns): bool
    {
        try {
            $this->table()->where('skill_id', $id)->where('level', self::LEVEL)->update($columns);

            return $this->find($id) !== null;
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Catalogue skill not changed: ' . $e->getMessage(), 'llm');

            return false;
        }
    }

    /**
     * The catalogue's rows matching the conditions, oldest first; none when the table or its
     * columns are not there yet.
     *
     * @param array<string, mixed> $where
     * @return list<array<string, mixed>>
     */
    private function rows(array $where): array
    {
        try {
            $query = $this->table()->where('level', self::LEVEL);
            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }

            return array_values($query->orderBy('skill_id')->getAllOrFail());
        } catch (\Throwable $e) {
            \Pramnos\Logs\Logger::log('Catalogue skills unavailable: ' . $e->getMessage(), 'llm');

            return [];
        }
    }

    /** The table. */
    private function table(): \Pramnos\Database\QueryBuilder
    {
        return ($this->db ?? Database::getInstance())->queryBuilder()->table(StoredSkills::TABLE);
    }

    /** The catalogue read. */
    private function catalog(): SkillCatalog
    {
        return $this->catalog ?? new SkillCatalog();
    }
}
