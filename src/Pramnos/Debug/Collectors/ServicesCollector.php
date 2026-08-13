<?php

declare(strict_types=1);

namespace Pramnos\Debug\Collectors;

/**
 * Collects the services that took part in the current request.
 *
 * Fed by {@see \Pramnos\Application\Service}: its constructor notes that the
 * service ran, and `measure()` notes one named operation with its duration. That
 * is the whole seam — a service is recorded because it extends the base, not
 * because its author remembered to instrument it.
 *
 * Two things are counted separately, because they answer different questions.
 * `count` is how many distinct service classes the request touched, which is the
 * number worth putting on the tab; `ops` is how many measured calls were made,
 * which is where a request that used one service forty times becomes visible.
 *
 */
class ServicesCollector implements CollectorInterface
{
    /** @var list<array{class: string, op: string, ms: float}> */
    private array $operations = [];

    /**
     * Per class: how many operations, and how long they took in total.
     *
     * Keyed by the short name, which is what the toolbar shows. Two services
     * with the same short name in different namespaces would share a row — an
     * acceptable trade for a panel that has to stay readable, and the operations
     * table below still lists every call.
     *
     * @var array<string, array{class: string, ops: int, ms: float}>
     */
    private array $services = [];

    public function name(): string
    {
        return 'services';
    }

    /**
     * Record a service, and optionally one of its operations.
     *
     * @param  string      $class     Fully-qualified class name
     * @param  string|null $operation Method or operation name, when timing one
     * @param  float|null  $ms        How long that operation took
     * @return void
     */
    public function record(string $class, ?string $operation = null, ?float $ms = null): void
    {
        $short = class_exists($class) ? (new \ReflectionClass($class))->getShortName() : $class;

        if (!isset($this->services[$short])) {
            $this->services[$short] = ['class' => $short, 'ops' => 0, 'ms' => 0.0];
        }

        if ($operation === null) {
            return;
        }

        $duration = round((float) $ms, 2);
        $this->services[$short]['ops']++;
        $this->services[$short]['ms'] = round($this->services[$short]['ms'] + $duration, 2);

        $this->operations[] = [
            'class' => $short,
            'op'    => $operation,
            'ms'    => $duration,
        ];
    }

    /**
     * @return array{count: int, ops: int, services: list<array{class: string, ops: int, ms: float}>, operations: list<array{class: string, op: string, ms: float}>}
     */
    public function collect(): array
    {
        return [
            'count'      => count($this->services),
            'ops'        => count($this->operations),
            'services'   => array_values($this->services),
            'operations' => $this->operations,
        ];
    }
}
