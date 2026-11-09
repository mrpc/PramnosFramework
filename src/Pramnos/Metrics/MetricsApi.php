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

        $r->get($path . '/series', static fn () => (new static())->series())->describeFrom([static::class, 'series']);
        $r->get($path . '/value', static fn () => (new static())->value())->describeFrom([static::class, 'value']);
        $r->get($path . '/goal', static fn () => (new static())->goal())->describeFrom([static::class, 'goal']);
        $r->get($path, static fn () => (new static())->catalogue())->describeFrom([static::class, 'catalogue']);
        static::goalRoutes($r, $path . '/goals');
    }

    /**
     * The goals' routes, under `$path` — mounted with the metrics' as `/metrics/goals`, or on their
     * own where an application's client already asks `/goals`:
     *
     *   - `GET    {path}`               the goals, each judged, with `kinds`, `repeats`, `metrics` and `overall`
     *   - `POST   {path}`               add one ({@see Goals::create()}'s keys)
     *   - `PUT    {path}/{id}`          change one: what is not sent stays
     *   - `DELETE {path}/{id}`          take one away (its achievements stay)
     *   - `POST   {path}/preview`       a goal judged without keeping it: its sentence, and a target to try
 *   - `GET    {path}/achievements`  what was reached, newest first (`?limit=`)
     */
    public static function goalRoutes(Router $r, string $path = '/goals'): void
    {
        $path = '/' . trim($path, '/');
        $r->post($path . '/preview', static fn () => (new static())->previewGoal())->describeFrom([static::class, 'previewGoal']);
        $r->get($path . '/achievements', static fn () => (new static())->achievements())->describeFrom([static::class, 'achievements']);
        $r->get($path, static fn () => (new static())->goals())->describeFrom([static::class, 'goals']);
        $r->post($path, static fn () => (new static())->addGoal())->describeFrom([static::class, 'addGoal']);
        $r->put($path . '/{id}', static fn ($id) => (new static())->changeGoal((int) $id))->describeFrom([static::class, 'changeGoal']);
        $r->delete($path . '/{id}', static fn ($id) => (new static())->deleteGoal((int) $id))->describeFrom([static::class, 'deleteGoal']);
    }

    /** The goals the person may read, each judged, and what a form needs to add one. @return array<string, mixed> */
    public function goals(): array
    {
        return $this->answer(function (object $user): array {
            $tool    = new \Pramnos\Llm\Analyst\Tools\GoalsTool((int) $user->userid, $user);
            $metrics = [];
            foreach (Metrics::all($user, false) as $metric) {
                $across     = $metric->getAcross();
                $overall    = null;
                if ($across !== null) {
                    try {
                        $overall = Metrics::query($metric->name)->for($user)->grain(Window::DAY)->at(date('Y-m-d'))->series()['values'][''][0] ?? null;
                    } catch (\Throwable) {
                        $overall = null;
                    }
                }
                $metrics[] = ['metric' => $metric->name, 'label' => $metric->label(), 'kinds' => $metric->kind === Metric::LEVEL ? GoalJudge::KINDS_LEVEL : GoalJudge::KINDS_TOTAL,
                    'higher_is_better' => $metric->getDirection() !== Metric::LOWER_IS_BETTER, 'overall' => $across !== null, 'now' => $overall];
            }

            return ['goals' => $tool->execute(['achievements' => false])['goals'], 'kinds' => array_values(array_unique([...GoalJudge::KINDS_TOTAL, ...GoalJudge::KINDS_LEVEL])),
                'repeats' => GoalJudge::RECURRING, 'metrics' => $metrics,
                'overall' => (object) array_column(array_filter($metrics, static fn (array $m): bool => $m['overall']), 'now', 'metric')];
        });
    }

    /**
     * A goal judged without keeping it — the form's live sentence: its state and sentence, the
     * last whole period's figure, and a target to try. The same values as adding one.
     *
     * @return array<string, mixed>
     */
    public function previewGoal(): array
    {
        return $this->answer(function (object $user): array {
            $body = (new \Pramnos\Http\Request())->body();
            if (!isset(Metrics::all($user, false)[(string) ($body['metric'] ?? '')])) {
                throw new \DomainException('There is no metric "' . ($body['metric'] ?? '') . '" you may set a goal on.');
            }
            $tenants = Metrics::tenantsOf($user);
            $tenant  = (int) ($body['tenant'] ?? ($tenants !== null && count($tenants) === 1 ? $tenants[0] : 0));
            $goal    = array_intersect_key($body, array_flip(['metric', 'kind', 'target', 'growth', 'reference', 'repeats', 'starts', 'due', 'filters']));
            if (!isset($goal['target']) || $goal['target'] === '') {
                // No target yet: only the last period's figure, and what to try.
                return ['says' => ''] + Goals::preview(['target' => 1] + $goal + ['tenant' => $tenant]);
            }

            return Goals::preview($goal + ['tenant' => $tenant]);
        });
    }

    /** Add a goal. @return array<string, mixed> */
    public function addGoal(): array
    {
        return $this->answer(function (object $user): array {
            $body = (new \Pramnos\Http\Request())->body();
            if (!isset(Metrics::all($user, false)[(string) ($body['metric'] ?? '')])) {
                throw new \DomainException('There is no metric "' . ($body['metric'] ?? '') . '" you may set a goal on.');
            }
            $tenants = Metrics::tenantsOf($user);
            $tenant  = (int) ($body['tenant'] ?? ($tenants !== null && count($tenants) === 1 ? $tenants[0] : 0));
            if ($tenants !== null && !in_array($tenant, $tenants, true)) {
                throw new \DomainException('That organisation\'s goals are not yours to set.');
            }
            $goal = array_intersect_key($body, array_flip(['metric', 'kind', 'target', 'growth', 'reference', 'repeats', 'starts', 'due', 'filters', 'owner', 'note', 'notify']));
            if (!Metrics::withinScope((string) $goal['metric'], (array) ($goal['filters'] ?? []), $user)) {
                throw new \DomainException('Those figures are not yours to set a goal on.');
            }
            $id = Goals::create($goal + ['tenant' => $tenant, 'by' => (int) $user->userid]);

            return ['id' => $id] + (Goals::judge($id) ?? []);
        }, 201);
    }

    /** Change a goal the person may read: its metric, organisation and dimension values stay. @return array<string, mixed> */
    public function changeGoal(int $id): array
    {
        return $this->answer(function (object $user) use ($id): array {
            $this->readableGoal($user, $id);
            $body = array_intersect_key((new \Pramnos\Http\Request())->body(), array_flip(['kind', 'target', 'growth', 'reference', 'repeats', 'starts', 'due', 'owner', 'note', 'notify']));
            Goals::update($id, $body);

            return ['id' => $id] + (Goals::judge($id) ?? []);
        });
    }

    /** Take a goal away; what it reached stays. @return array<string, mixed> */
    public function deleteGoal(int $id): array
    {
        return $this->answer(function (object $user) use ($id): array {
            $this->readableGoal($user, $id);
            Goals::delete($id);

            return ['deleted' => $id];
        });
    }

    /** What was reached, newest first. @return array<string, mixed> */
    public function achievements(): array
    {
        return $this->answer(function (object $user): array {
            $tenants = Metrics::tenantsOf($user);
            $limit   = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

            return ['achievements' => Achievements::list($tenants !== null && count($tenants) === 1 ? $tenants[0] : null, $limit)];
        });
    }

    /** @throws \DomainException For a goal that is not there, or not the person's to read */
    private function readableGoal(object $user, int $id): void
    {
        if (!in_array($id, array_column(\Pramnos\Llm\Analyst\Tools\GoalsTool::readable($user), 'id'), true)) {
            throw new \DomainException('There is no such goal.');
        }
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
    private function answer(\Closure $action, int $status = 200): array
    {
        $user = $this->user();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Sign in to read the metrics.'];
        }
        try {
            return ['status' => $status, 'data' => $action($user)];
        } catch (\DomainException $e) {
            return ['status' => 404, 'error' => $e->getMessage()];
        } catch (\InvalidArgumentException|\LogicException $e) {
            return ['status' => 422, 'error' => $e->getMessage()];
        }
    }
}
