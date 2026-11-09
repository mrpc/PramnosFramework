<?php

declare(strict_types=1);

namespace Pramnos\User;

/**
 * The `urls` registry kept sound: each address once, found by a hash that does not collide, and
 * none kept that nothing refers to.
 *
 * ```php
 * UrlRegistry::referencedBy('visitactions', 'urlid');   // an application's own table that keeps url ids
 * UrlRegistry::prune();                                 // `urls:prune` — the addresses nothing refers to go
 * UrlRegistry::rehash();                                // `urls:rehash` — sha filled, doubles merged, unique index
 * ```
 *
 * {@see Token::urlId()} finds an address by `sha` (the SHA-1 of the whole of it) where the column
 * is there. Before `urls:rehash` has filled it for the old rows, an old row is found by its crc32
 * **and its own text** — never by the crc32 alone, which is what filed a new address under an old
 * one whose crc32 it shared — and its `sha` is filled then.
 *
 * Which tables keep url ids is declared, not guessed: `tokenactions.urlid` is the framework's, and `page_hits`' `urlid`, `target` and `referrer` where the site's traffic is installed;
 * an application adds its own. A prune deletes only what none of them refers to, and never an
 * address first seen less than a day ago, which may still be waiting in a spool.
 *
 * On a large table run `urls:prune` first: the unique index `urls:rehash` makes is then built
 * over what is left.
 */
final class UrlRegistry
{
    /** The unique index on `sha`. */
    public const INDEX = 'uq_urls_sha';

    /** The framework's own columns that keep url ids where their feature is installed: the site's traffic. */
    public const WHERE_INSTALLED = [['pramnos.page_hits', 'urlid'], ['pramnos.page_hits', 'target'], ['pramnos.page_hits', 'referrer']];

    /** @var list<array{0: string, 1: string}> The columns that keep url ids: [table, column] */
    private static array $references = [['#PREFIX#tokenactions', 'urlid']];

    /**
     * Declare a column that keeps url ids: a prune keeps what it refers to. A table with several
     * — a page, a link out, a referrer — declares each.
     */
    public static function referencedBy(string $table, string $column): void
    {
        if (!in_array([$table, $column], self::$references, true)) {
            self::$references[] = [$table, $column];
        }
    }

    /** @return array<string, string> The tables that keep url ids, each with its first column; {@see columns()} has every one */
    public static function references(): array
    {
        $tables = [];
        foreach (self::$references as [$table, $column]) {
            $tables[$table] ??= $column;
        }

        return $tables;
    }

    /** @return list<array{0: string, 1: string}> Every column that keeps url ids, as [table, column] */
    public static function columns(): array
    {
        return self::$references;
    }

    /** Back to the framework's own reference: for a test. */
    public static function reset(): void
    {
        self::$references = [['#PREFIX#tokenactions', 'urlid']];
    }

    /**
     * Delete the addresses nothing refers to, a range of ids at a time. Those first seen in the
     * last day stay; one with no `createdat` is from before it was kept, so old.
     *
     * @param callable(int, int): void|null $progress Told the ids done so far and how many went
     * @return int How many went
     */
    public static function prune(int $batch = 10000, ?int $now = null, ?callable $progress = null, bool $dryRun = false): int
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $schema = $db->schema();
        $cutoff = ($now ?? time()) - 86400;
        $refs   = self::present($schema);
        $max    = (int) ($db->queryBuilder()->table('#PREFIX#urls')->max('urlid') ?? 0);
        $aged   = $schema->hasColumn('urls', 'createdat');
        $gone   = 0;
        for ($from = 0; $from <= $max; $from += $batch) {
            $to   = $from + $batch;
            $kept = [];
            foreach ($refs as [$table, $column]) {
                foreach ($db->queryBuilder()->table($table)->select([$column])->distinct()->where($column, '>', $from)->where($column, '<=', $to)->getAllOrFail() as $row) {
                    $kept[(int) $row[$column]] = true;
                }
            }
            $query = $db->queryBuilder()->table('#PREFIX#urls')->where('urlid', '>', $from)->where('urlid', '<=', $to);
            if ($aged) {
                $query->where(static fn ($q) => $q->whereNull('createdat')->orWhere('createdat', '<', $cutoff));
            }
            foreach (array_chunk(array_keys($kept), 1000) as $chunk) {
                $query->whereNotIn('urlid', $chunk);
            }
            $count = (clone $query)->count();
            if ($count > 0 && !$dryRun) {
                $query->delete();
            }
            $gone += $count;
            if ($progress !== null) {
                $progress(min($to, $max), $gone);
            }
        }

        return $gone;
    }

    /**
     * The declared references, each checked to be there. A table that is not there stops the work
     * rather than being passed over: an address only it referred to would be deleted, and the
     * foreign key from `tokenactions` would take that table's rows with it.
     *
     * @return list<array{0: string, 1: string}>
     * @throws \RuntimeException For a declared table that is not there
     */
    private static function present(\Pramnos\Database\SchemaBuilder $schema): array
    {
        $optional = $schema->hasTable('pramnos.page_hits') ? self::WHERE_INSTALLED : [];
        foreach (self::$references as [$table, $column]) {
            $name = str_replace('#PREFIX#', '', $table);
            if (!$schema->hasTable($name) || !$schema->hasColumn($name, $column)) {
                throw new \RuntimeException('urls: the reference ' . $name . '.' . $column . ' is not there; nothing was pruned or merged. Declare only tables that exist.');
            }
        }

        return array_merge(self::$references, array_values(array_filter($optional, static fn (array $r): bool => !in_array($r, self::$references, true))));
    }

    /**
     * Make `sha` whole: fill it for every row, merge an address kept twice into its first (the
     * references moved to it), then make the unique index.
     *
     * @return array{filled: int, merged: int, indexed: bool}
     */
    public static function rehash(int $batch = 5000, ?callable $progress = null): array
    {
        $db     = \Pramnos\Framework\Factory::getDatabase();
        $schema = $db->schema();
        if (!$schema->hasColumn('urls', 'sha')) {
            throw new \RuntimeException('urls has no sha column: run the migrations first (add_sha_to_urls).');
        }
        $table  = static fn () => $db->queryBuilder()->table('#PREFIX#urls');
        $filled = 0;
        while (true) {
            $rows = $table()->select(['urlid', 'url'])->whereNull('sha')->orderBy('urlid')->limit($batch)->getAllOrFail();
            if ($rows === []) {
                break;
            }
            foreach ($rows as $row) {
                $table()->where('urlid', (int) $row['urlid'])->update(['sha' => sha1((string) $row['url'])]);
            }
            $filled += count($rows);
            if ($progress !== null) {
                $progress('filled', $filled);
            }
        }
        $merged = 0;
        $refs   = self::present($schema);
        foreach ($table()->select(['sha'])->groupBy(['sha'])->havingRaw('COUNT(*) > 1')->getAllOrFail() as $double) {
            $ids  = array_map('intval', array_column($table()->select(['urlid'])->where('sha', $double['sha'])->orderBy('urlid')->getAllOrFail(), 'urlid'));
            $keep = array_shift($ids);
            foreach ($refs as [$refTable, $column]) {
                $db->queryBuilder()->table($refTable)->whereIn($column, $ids)->update([$column => $keep]);
            }
            $table()->whereIn('urlid', $ids)->delete();
            $merged += count($ids);
        }
        $indexed = false;
        if (!$schema->hasIndex('urls', self::INDEX)) {
            // The schema builder adds the installation's prefix itself.
            $registry = 'urls';
            $schema->table($registry, static function ($t): void {
                $t->unique(['sha'], self::INDEX);
            });
            $indexed = true;
        }

        return ['filled' => $filled, 'merged' => $merged, 'indexed' => $indexed];
    }
}
