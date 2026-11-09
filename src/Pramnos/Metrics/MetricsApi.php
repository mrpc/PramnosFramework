<?php

declare(strict_types=1);

namespace Pramnos\Metrics;

use Pramnos\Routing\Router;

/**
 * The metrics as JSON, outside the administration — a SPA, a mobile application, an
 * organisation's own dashboard — with the API's credentials, through the one query every reader
 * uses, as the person asking: only the metrics they may read, of the organisations they may.
 * Mounted with one line in the application's API routes:
 *
 * ```php
 * \Pramnos\Metrics\MetricsApi::routes($r);
 * ```
 *
 *   - `GET /metrics`                       the catalogue: name, label, unit, dimensions, format…
 *   - `GET /metrics/series?metric=&grain=&from=&to=&by=&f[dim]=&tenant=`   periods and values, and why any is missing
 *   - `GET /metrics/value?metric=&grain=&at=&f[dim]=&compare=&tenant=`     one period, against another
 *   - `GET /metrics/goal?id=`              a goal's progress
 *
 * Answers in the API's shape: `['status' => 200, 'data' => …]` or `['status' => …, 'error' => …]`.
 */
class MetricsApi
{
    /** Mount the routes, under `$path`. */
    public static function routes(Router $r, string $path = '/metrics'): void
    {
        $path = '/' . trim($path, '/');

        $r->get($path . '/series', static fn () => (new static())->series());
        $r->get($path . '/value', static fn () => (new static())->value());
        $r->get($path . '/goal', static fn () => (new static())->goal());
        $r->get($path, static fn () => (new static())->catalogue());
    }

    /** The metrics the person may read. @return array<string, mixed> */
    public function catalogue(): array
    {
        return $this->answer(function (object $user): array {
            $out = [];
            foreach (Metrics::all($user, false) as $metric) {
                $out[] = ['name' => $metric->name, 'label' => $metric->label(), 'unit' => $metric->getUnit(), 'kind' => $metric->kind,
                    'aggregate' => $metric->getAggregate(), 'dimensions' => $metric->getDimensions(), 'format' => $metric->getFormat(), 'currency' => $metric->getCurrency(),
                    'direction' => $metric->getDirection(), 'grains' => $metric->getGrains() ?? Metrics::grains(), 'live' => $metric->isLive(),
                    // Each dimension's words and what it is a part of: a client drills from the one to the other.
                    'hierarchy' => (object) array_combine($metric->getDimensions(), array_map(static function (string $d): array {
                        $dimension = Metrics::dimensionOf($d);

                        return ['label' => $dimension->label, 'parent' => $dimension->getParent(), 'map' => $dimension->hasGeo()];
                    }, $metric->getDimensions()))];
            }

            return $out;
        });
    }

    /** A metric over periods. @return array<string, mixed> */
    public function series(): array
    {
        return $this->answer(function (object $user): array {
            $query  = $this->query($user)->between((string) ($_GET['from'] ?? date('Y-m-d')), (string) ($_GET['to'] ?? date('Y-m-d')));
            $query->by(($_GET['by'] ?? '') !== '' ? (string) $_GET['by'] : null);
            $series = $query->series();

            return [
                'periods' => array_map(static fn (Window $w): string => $w->key(), $series['periods']),
                'labels'  => array_map(static fn (Window $w): string => $w->label(), $series['periods']),
                'values'  => (object) $series['values'],
                'gaps'    => (object) $query->gaps(),
            ] + array_filter(['currency' => $query->currency()]);
        });
    }

    /** One period, and its comparison where asked. @return array<string, mixed> */
    public function value(): array
    {
        return $this->answer(function (object $user): array {
            $query = $this->query($user)->at((string) ($_GET['at'] ?? date('Y-m-d')));
            if (($_GET['compare'] ?? '') !== '') {
                return $query->compare((string) $_GET['compare'])->compared() + array_filter(['currency' => $query->currency()]);
            }

            return ['value' => $query->value()] + array_filter(['currency' => $query->currency()]);
        });
    }

    /** A goal's progress, for a metric the person may read. @return array<string, mixed> */
    public function goal(): array
    {
        return $this->answer(function (object $user): ?array {
            $goal = Goals::get((int) ($_GET['id'] ?? 0));
            if ($goal === null || !isset(Metrics::all($user, false)[$goal['metric']])) {
                throw new \DomainException('There is no such goal.');
            }
            if (!Metrics::withinScope($goal['metric'], (array) $goal['filters'], $user)) {
                throw new \DomainException('There is no such goal.');
            }
            $tenants = Metrics::tenantsOf($user);
            if ($tenants !== null && $goal['tenant'] > 0 && !in_array($goal['tenant'], $tenants, true)) {
                throw new \DomainException('There is no such goal.');
            }

            return ['goal' => $goal, 'progress' => Goals::progress($goal)];
        });
    }

    /** The query the request asks for, read as the person asking. */
    protected function query(object $user): Query
    {
        $name = (string) ($_GET['metric'] ?? '');
        if (!isset(Metrics::all($user, false)[$name])) {
            throw new \DomainException('There is no metric "' . $name . '" you may read.');
        }
        $query = Metrics::query($name)->for($user)->grain((string) ($_GET['grain'] ?? Window::MONTH));
        $filters = array_map(static fn ($v) => is_array($v) ? array_values(array_map('strval', $v)) : (string) $v, array_filter((array) ($_GET['f'] ?? []), static fn ($v): bool => is_scalar($v) || is_array($v)));
        if ($filters !== []) {
            $query->where($filters);
        }
        if (($_GET['tenant'] ?? '') !== '') {
            $query->tenant((int) $_GET['tenant']);
        }

        return $query;
    }

    /** Who asks: the API's signed-in person, or null. */
    protected function user(): ?object
    {
        $user = \Pramnos\User\User::current();

        return is_object($user) && (int) ($user->userid ?? 0) > 1 ? $user : null;
    }

    /**
     * An action's answer in the API's shape: a refusal for nobody, 404 for what is not theirs,
     * 422 for what does not make sense.
     *
     * @return array<string, mixed>
     */
    private function answer(\Closure $action): array
    {
        $user = $this->user();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Sign in to read the metrics.'];
        }
        try {
            return ['status' => 200, 'data' => $action($user)];
        } catch (\DomainException $e) {
            return ['status' => 404, 'error' => $e->getMessage()];
        } catch (\InvalidArgumentException|\LogicException $e) {
            return ['status' => 422, 'error' => $e->getMessage()];
        }
    }
}
