<?php

declare(strict_types=1);

namespace Pramnos\Database\FromMysql;

/**
 * Tables in the order their rows can be loaded: a table after every table it points at.
 *
 * A foreign key from a table to itself (a parent row) is not an ordering problem and is left out.
 * Tables that point at each other in a ring cannot be ordered; they come last, in name order, and
 * are named, so a load with the keys active knows which rows may be refused.
 */
final class TopologicalSorter
{
    /**
     * @param list<string>                $tables
     * @param array<string, list<string>> $dependsOn Table → the tables its foreign keys point at
     * @return array{order: list<string>, cycles: list<string>}
     */
    public static function sort(array $tables, array $dependsOn): array
    {
        $tables  = array_values(array_unique($tables));
        $known   = array_flip($tables);
        $pending = [];
        foreach ($tables as $table) {
            $pending[$table] = array_values(array_unique(array_filter(
                $dependsOn[$table] ?? [],
                static fn (string $parent): bool => $parent !== $table && isset($known[$parent])
            )));
        }

        $order = [];
        do {
            $ready = array_keys(array_filter($pending, static fn (array $parents): bool => $parents === []));
            sort($ready);
            foreach ($ready as $table) {
                $order[] = $table;
                unset($pending[$table]);
            }
            foreach ($pending as $table => $parents) {
                $pending[$table] = array_values(array_diff($parents, $ready));
            }
        } while ($ready !== []);

        $cycles = array_keys($pending);
        sort($cycles);

        return ['order' => [...$order, ...$cycles], 'cycles' => $cycles];
    }
}
