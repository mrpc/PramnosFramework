<?php

declare(strict_types=1);

namespace Pramnos\Audience;

use Pramnos\Database\Database;

/**
 * The consent a send or a rule asks for, as lists an administrator names — never as a field a
 * rule names, so where consent is kept can change without touching a single rule.
 *
 * The application declares **sources** — where an agreement is read from — and the lists screen
 * makes **lists** over them:
 *
 * ```php
 * // code: a source is a filter that means "agreed", and the users column a new account's default goes to
 * ConsentLists::source('smsoffers', t('Offers by SMS (profile setting)'),
 *     ['conditions' => [['attribute' => 'smsoffers', 'op' => 'true']]], column: 'smsoffers');
 *
 * // the screen, or a seeder
 * $sms = ConsentLists::create('SMS offers', 'sms', 'smsoffers', description: t('Offers and discounts by SMS'), defaultOn: false);
 *
 * // a send
 * $final   = ConsentLists::narrow($audience, $sms);      // the audience that agreed
 * $removed = ConsentLists::removes($audience, $sms);     // how many consent takes out
 * ```
 *
 * An email list also leaves out every address that unsubscribed from everything (`all` in
 * `emailoptouts`), and from its own opt-out list when it names one — the footer link stays the
 * last word whatever a profile checkbox says. The framework declares one source, `everyone`:
 * no stored preference, so on an email list only the opt-outs decide.
 */
final class ConsentLists
{
    /** The channels a list can be for. */
    public const CHANNELS = ['email', 'sms', 'push', 'phone', 'site'];

    /** @var array<string, array{label: string, filter: array<string, mixed>, column: ?string}> */
    private static array $sources = [];

    /** @var array<string, callable(int): list<string>> */
    private static array $usage = [];

    /**
     * Declare where an agreement is read from. The filter is checked when a list over it is used,
     * so a source may be declared before the attributes it reads.
     *
     * @param array<string, mixed> $filter Who agreed, as a {@see Filter}
     * @param ?string              $column A users column holding it, written with a new account's default
     */
    public static function source(string $key, string $label, array $filter, ?string $column = null): void
    {
        self::$sources[$key] = ['label' => $label, 'filter' => $filter, 'column' => $column];
    }

    /**
     * Every source, the framework's `everyone` first.
     *
     * @return array<string, array{label: string, filter: array<string, mixed>, column: ?string}>
     */
    public static function sources(): array
    {
        return ['everyone' => ['label' => t('Everybody (only opt-outs count)'), 'filter' => [], 'column' => null]] + self::$sources;
    }

    /**
     * Make a list.
     *
     * @throws \InvalidArgumentException An unknown channel or source, or no name
     */
    public static function create(string $name, string $channel, string $source, string $description = '', bool $defaultOn = false, ?string $optOutList = null): int
    {
        $now = time();
        self::db()->queryBuilder()->table('#PREFIX#consent_lists')->insert(self::checked([
            'name' => $name, 'channel' => $channel, 'source' => $source, 'description' => $description,
            'defaulton' => $defaultOn, 'optoutlist' => $optOutList,
        ]) + ['createdat' => $now, 'updatedat' => $now]);

        return (int) self::db()->getInsertId();
    }

    /**
     * Change a list: any of name, channel, source, description, defaultOn, optOutList.
     *
     * @param array<string, mixed> $changes
     */
    public static function update(int $id, array $changes): void
    {
        $list = self::get($id) ?? throw new \InvalidArgumentException("No consent list {$id}.");
        $row  = self::checked([
            'name'        => $changes['name'] ?? $list['name'],
            'channel'     => $changes['channel'] ?? $list['channel'],
            'source'      => $changes['source'] ?? $list['source'],
            'description' => $changes['description'] ?? $list['description'],
            'defaulton'   => $changes['defaultOn'] ?? $list['defaultOn'],
            'optoutlist'  => array_key_exists('optOutList', $changes) ? $changes['optOutList'] : $list['optOutList'],
        ]);
        self::db()->queryBuilder()->table('#PREFIX#consent_lists')->where('listid', $id)->update($row + ['updatedat' => time()]);
    }

    /**
     * Delete a list — refused while a send or a rule uses it.
     *
     * @throws \RuntimeException Naming what uses it
     */
    public static function delete(int $id): void
    {
        $uses = [];
        foreach (self::$usage as $finder) {
            array_push($uses, ...array_map('strval', $finder($id)));
        }
        if ($uses !== []) {
            throw new \RuntimeException(t('This consent list is in use: %s', implode(', ', $uses)));
        }
        self::db()->queryBuilder()->table('#PREFIX#consent_lists')->where('listid', $id)->delete();
    }

    /**
     * Tell the lists where they are used, so one in use is not deleted.
     *
     * @param callable(int): list<string> $finder
     */
    public static function usage(string $source, callable $finder): void
    {
        self::$usage[$source] = $finder;
    }

    /**
     * One list, or null.
     *
     * @return array{id: int, name: string, channel: string, description: string, source: string, optOutList: ?string, defaultOn: bool}|null
     */
    public static function get(int $id): ?array
    {
        $rows = self::db()->queryBuilder()->table('#PREFIX#consent_lists')->where('listid', $id)->limit(1)->getAllOrFail();

        return $rows === [] ? null : self::row($rows[0]);
    }

    /**
     * Every list, or a channel's, by name — what a send's picker offers.
     *
     * @return list<array{id: int, name: string, channel: string, description: string, source: string, optOutList: ?string, defaultOn: bool}>
     */
    public static function all(?string $channel = null): array
    {
        $query = self::db()->queryBuilder()->table('#PREFIX#consent_lists')->orderBy('name');
        if ($channel !== null) {
            $query->where('channel', $channel);
        }

        return array_map(self::row(...), $query->getAllOrFail());
    }

    /**
     * Who is in a list, as a filter: the source's agreement, and for email, not unsubscribed.
     *
     * @return array{match: string, conditions: list<array<string, mixed>>}
     */
    public static function filter(int $id): array
    {
        $list   = self::get($id) ?? throw new \InvalidArgumentException("No consent list {$id}.");
        $source = self::sources()[$list['source']] ?? throw new \InvalidArgumentException(
            "Consent list {$list['name']} reads source {$list['source']}, which the application no longer declares."
        );
        $conditions = $source['filter'] === [] ? [] : [Filter::fromArray($source['filter'], new Database())->toArray()];
        if ($list['channel'] === 'email') {
            $left         = array_values(array_unique(array_filter([$list['optOutList'], \Pramnos\Email\Unsubscribe::LIST_ALL])));
            $conditions[] = ['attribute' => 'email_optouts', 'op' => 'none_of', 'value' => $left];
        }

        return ['match' => 'all', 'conditions' => $conditions];
    }

    /**
     * An audience narrowed to the people in a list.
     *
     * @param Filter|array<string, mixed> $audience
     */
    public static function narrow(Filter|array $audience, int $id): Filter
    {
        $tree = $audience instanceof Filter ? $audience->toArray() : Filter::fromArray($audience, self::db())->toArray();

        return Filter::fromArray(['match' => 'all', 'conditions' => [$tree, self::filter($id)]], self::db());
    }

    /**
     * How many of an audience a list takes out — the picker's "consent removes N".
     *
     * @param Filter|array<string, mixed> $audience
     */
    public static function removes(Filter|array $audience, int $id): int
    {
        $audience = $audience instanceof Filter ? $audience : Filter::fromArray($audience, self::db());

        return $audience->count() - self::narrow($audience, $id)->count();
    }

    /** Whether one person is in a list now — what a send to one person asks. */
    public static function allows(int $id, int $userid): bool
    {
        return self::narrow([], $id)->matches($userid);
    }

    /**
     * Give a new account each list's default, where the list's source keeps the agreement in a
     * users column. Lists over the same column: the last one made wins, as on the screen.
     *
     * @return array<string, int> The columns written
     */
    public static function applyDefaults(int $userid): array
    {
        $columns = [];
        $lists   = array_map(self::row(...), self::db()->queryBuilder()->table('#PREFIX#consent_lists')
            ->orderBy('listid')->getAllOrFail());
        foreach ($lists as $list) {
            $column = self::sources()[$list['source']]['column'] ?? null;
            if ($column !== null) {
                $columns[$column] = $list['defaultOn'] ? 1 : 0;
            }
        }
        if ($columns !== []) {
            self::db()->queryBuilder()->table('#PREFIX#users')->where('userid', $userid)->update($columns);
        }

        return $columns;
    }

    /** Forget the declared sources and usage: tests. */
    public static function reset(): void
    {
        self::$sources = [];
        self::$usage   = [];
    }

    /**
     * A row checked and shaped for the table.
     *
     * @param  array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function checked(array $row): array
    {
        $name = mb_substr(trim(strip_tags((string) $row['name'])), 0, 150);
        if ($name === '') {
            throw new \InvalidArgumentException(t('A consent list needs a name.'));
        }
        if (!in_array($row['channel'], self::CHANNELS, true)) {
            throw new \InvalidArgumentException(t('A consent list is for one of: %s.', implode(', ', self::CHANNELS)));
        }
        if (!isset(self::sources()[$row['source']])) {
            throw new \InvalidArgumentException(t('No consent source %s is declared.', (string) $row['source']));
        }
        $optOut = trim((string) ($row['optoutlist'] ?? ''));

        return [
            'name'        => $name,
            'channel'     => $row['channel'],
            'source'      => $row['source'],
            'description' => (string) $row['description'],
            'defaulton'   => $row['defaulton'] ? 1 : 0,
            'optoutlist'  => $optOut === '' ? null : mb_substr($optOut, 0, 64),
        ];
    }

    /**
     * @param  array<string, mixed> $row
     * @return array{id: int, name: string, channel: string, description: string, source: string, optOutList: ?string, defaultOn: bool}
     */
    private static function row(array $row): array
    {
        return [
            'id'          => (int) $row['listid'],
            'name'        => (string) $row['name'],
            'channel'     => (string) $row['channel'],
            'description' => (string) ($row['description'] ?? ''),
            'source'      => (string) $row['source'],
            'optOutList'  => $row['optoutlist'] === null ? null : (string) $row['optoutlist'],
            'defaultOn'   => (bool) (int) $row['defaulton'],
        ];
    }

    private static function db(): Database
    {
        return Database::getInstance();
    }
}
