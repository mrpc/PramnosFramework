<?php

declare(strict_types=1);

namespace Pramnos\Llm\Analyst\Tools;

use Pramnos\Mcp\McpToolInterface;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Metrics;
use Pramnos\Metrics\Window;

/**
 * The application's metrics ({@see Metrics}) for the analyst and MCP: the catalogue, a series
 * over time — broken down by a dimension, filtered — a period against the one before or a year
 * earlier, and the records behind a number. Only the metrics the person asking may see.
 */
final class MetricsTool implements McpToolInterface
{
    /** The most records `rows` answers. */
    private const ROWS = 50;

    /** The most periods a series answers. */
    private const PERIODS = 120;

    /** @param int $adminId Who is asking: their metrics are those their allowed() lets them see; 0 is nobody signed in */
    public function __construct(private readonly int $adminId = 0)
    {
    }

    public function name(): string
    {
        return 'metrics';
    }

    public function description(): string
    {
        return 'The business\'s metrics, as its reports count them. action=catalogue lists them (kind, '
            . 'sum or distinct, unit, dimensions and their values); series reads one over periods '
            . '(grain day/week/month/quarter/year, from/to YYYY-MM-DD, filters, breakdown by a dimension); '
            . 'compare reads one period against the previous or a year earlier; rows lists the records '
            . 'behind a number. A distinct metric (unique people) is never added across periods or '
            . 'values: ask for the grain and filter you need. null means the period was not computed.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'action'    => ['type' => 'string', 'enum' => ['catalogue', 'series', 'compare', 'rows'], 'description' => 'catalogue by default.'],
            'metric'    => ['type' => 'string', 'description' => 'The metric\'s name, from the catalogue.'],
            'grain'     => ['type' => 'string', 'enum' => Window::GRAINS, 'description' => 'month by default.'],
            'from'      => ['type' => 'string', 'description' => 'series: the first period\'s day, YYYY-MM-DD; a year back by default.'],
            'to'        => ['type' => 'string', 'description' => 'series: the last period\'s day; today by default.'],
            'period'    => ['type' => 'string', 'description' => 'compare, rows: a day in the period, YYYY-MM-DD; today by default.'],
            'against'   => ['type' => 'string', 'enum' => [Metrics::PREVIOUS, Metrics::YEAR_EARLIER], 'description' => 'compare: previous by default.'],
            'filters'   => ['type' => 'object', 'description' => 'Dimension => value (or a list of values, for a sum only).'],
            'breakdown' => ['type' => 'string', 'description' => 'series: a dimension to break it down by.'],
        ]];
    }

    public function execute(array $input): mixed
    {
        $action = (string) ($input['action'] ?? 'catalogue');
        if ($action === 'catalogue' || !isset($input['metric'])) {
            return $this->catalogue();
        }
        $name    = (string) $input['metric'];
        $grain   = (string) ($input['grain'] ?? Window::MONTH);
        $filters = array_map(static fn ($v) => is_array($v) ? array_map('strval', $v) : (string) $v, (array) ($input['filters'] ?? []));
        $metric  = Metrics::all($this->user(), false)[$name] ?? null;
        if ($metric === null) {
            return 'Error: there is no metric "' . $name . '" you may see; action=catalogue lists them.';
        }
        try {
            return match ($action) {
                'compare' => ['metric' => $name, 'unit' => $metric->getUnit()]
                    + Metrics::query($name)->grain($grain)->at((string) ($input['period'] ?? date('Y-m-d')))->where($filters)->compare((string) ($input['against'] ?? Metrics::PREVIOUS))->for($this->user())->compared(),
                'rows'    => $this->rows($metric, $grain, (string) ($input['period'] ?? date('Y-m-d')), $filters),
                default   => $this->series($metric, $grain, $input, $filters),
            };
        } catch (\InvalidArgumentException|\LogicException $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /** @return array<string, mixed> */
    private function catalogue(): array
    {
        $out = [];
        foreach (Metrics::all($this->user(), false) as $metric) {
            $dimensions = [];
            foreach ($metric->getDimensions() as $name) {
                $dimension         = Metrics::dimensionOf($name);
                $dimensions[$name] = ['label' => $dimension->label, 'values' => $dimension->all()];
            }
            $out[] = array_filter([
                'name'        => $metric->name,
                'label'       => $metric->label(),
                'kind'        => $metric->kind,
                'aggregate'   => $metric->kind === Metric::DERIVED ? null : $metric->getAggregate(),
                'unit'        => $metric->getUnit(),
                'currency'    => $metric->getCurrency(),
                'description' => $metric->description(),
                'dimensions'  => $dimensions,
                'breakdowns'  => $metric->kind !== Metric::DERIVED && $metric->getAggregate() === Metric::DISTINCT
                    ? array_map(static fn (array $s): string => $s === [] ? '(total)' : implode('+', $s), $metric->storedSets()) : null,
                'has_rows'    => $metric->hasRows(),
            ], static fn ($v): bool => $v !== null && $v !== '' && $v !== []);
        }

        return ['metrics' => $out];
    }

    /**
     * @param array<string, mixed>               $input
     * @param array<string, string|list<string>> $filters
     * @return array<string, mixed>
     */
    private function series(Metric $metric, string $grain, array $input, array $filters): array
    {
        $to   = (string) ($input['to'] ?? date('Y-m-d'));
        $from = (string) ($input['from'] ?? date('Y-m-d', (int) strtotime($to . ' -1 year')));
        if (count(Window::range($grain, $from, $to)) > self::PERIODS) {
            throw new \InvalidArgumentException('at most ' . self::PERIODS . ' periods at once: a coarser grain, or a shorter span.');
        }
        $breakdown = isset($input['breakdown']) && $input['breakdown'] !== '' ? (string) $input['breakdown'] : null;
        $query     = Metrics::query($metric->name)->grain($grain)->between($from, $to)->where($filters)->by($breakdown)->for($this->user());
        $series    = $query->series();
        $labels    = array_map(static fn (Window $w): string => $w->label(), $series['periods']);
        $values    = [];
        foreach ($series['values'] as $key => $list) {
            $values[$key === '' ? 'total' : (string) $key] = array_combine($labels, $list);
        }

        // The currency the amounts are in, so they are never added to another's.
        return ['metric' => $metric->name, 'unit' => $metric->getUnit(), 'grain' => $grain] + array_filter(['currency' => $query->currency()])
            + ($breakdown !== null ? ['breakdown' => $breakdown] : []) + ['values' => $values];
    }

    /**
     * @param array<string, string|list<string>> $filters
     * @return array<string, mixed>
     */
    private function rows(Metric $metric, string $grain, string $period, array $filters): array
    {
        if (!$metric->hasRows()) {
            return ['metric' => $metric->name, 'says' => 'This metric lists no records behind its numbers.'];
        }
        try {
            $only = Metrics::scoped($metric->name, array_map(static fn ($v): string => is_array($v) ? (string) reset($v) : $v, $filters), $this->user(), null, true);
        } catch (\DomainException $e) {
            return ['metric' => $metric->name, 'says' => $e->getMessage()];
        }
        $rows = Metrics::records($metric->name, $grain, $period, $only);

        // Where a value opens (`metrics.links`): given as a markdown link, for the answer to keep.
        $count  = count($rows);
        $rows   = array_slice($rows, 0, self::ROWS);
        $linked = false;
        foreach ($rows as $i => $row) {
            foreach ($row as $column => $value) {
                $url = is_scalar($value) ? Metrics::link((string) $column, $value, $row) : null;
                if ($url !== null) {
                    $rows[$i][$column] = '[' . str_replace(['[', ']'], ['(', ')'], (string) $value) . '](' . $url . ')';
                    $linked            = true;
                }
            }
        }

        return ['metric' => $metric->name, 'period' => Window::of($grain, $period)->label(), 'count' => $count, 'rows' => $rows]
            + ($linked ? ['links' => 'Values written as markdown links open the record: keep them as links in the answer.'] : []);
    }

    /** Who is asking, or null for nobody. */
    private function user(): ?object
    {
        return $this->adminId > 0 ? new \Pramnos\User\User($this->adminId) : null;
    }
}
