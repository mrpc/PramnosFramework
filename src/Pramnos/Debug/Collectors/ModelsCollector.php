<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Collects Model load/save/delete operations during the current request.
 *
 * Model::_load() and Model::_save() call record() via the static bridge
 * DebugBar::recordModel(). Distinct model classes are counted separately
 * so the tab badge shows unique model types, not total DB round-trips.
 *
 * @package PramnosFramework
 */
class ModelsCollector implements CollectorInterface
{
    /** @var list<array{class: string, table: string, op: string, key: mixed}> */
    private array $operations = [];

    /** @var array<string, true> unique class names seen */
    private array $seen = [];

    public function name(): string
    {
        return 'models';
    }

    public function record(string $class, string $table, string $operation, mixed $key = null): void
    {
        $short = class_exists($class) ? (new \ReflectionClass($class))->getShortName() : $class;
        $this->operations[] = [
            'class' => $short,
            'table' => $table,
            'op'    => $operation,
            'key'   => $key,
        ];
        $this->seen[$class] = true;
    }

    public function collect(): array
    {
        return [
            'count'      => count($this->seen),
            'ops'        => count($this->operations),
            'operations' => $this->operations,
        ];
    }
}
