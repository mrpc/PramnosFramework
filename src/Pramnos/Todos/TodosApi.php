<?php

declare(strict_types=1);

namespace Pramnos\Todos;

use Pramnos\Llm\Analyst\Tools\TodosChangeTool;
use Pramnos\Llm\Analyst\Tools\TodosTool;
use Pramnos\Routing\Router;

/**
 * The to-do list as JSON, under a path the application chooses, with the API's credentials and
 * as the person asking. The same reads and writes as the assistant's tool, so the two cannot
 * disagree:
 *
 * ```php
 * \Pramnos\Todos\TodosApi::routes($r);              // /todos
 * \Pramnos\Todos\TodosApi::routes($r, '/tasks');    // where a client already asks /tasks
 * ```
 *
 *   - `GET    {path}?who=&about=&closed=`  the items (`who`: me, all, team, an id or a name), with
 *                                          the person's name and whether the plan has to-dos
 *   - `POST   {path}`                      add one: title, assigned_to (0 nobody), due, remind_at, link, private, repeat
 *   - `PUT    {path}/{id}`                 change one: what is not sent stays; `due` '' clears it
 *   - `DELETE {path}/{id}`                 one's own item
 *   - `GET    {path}/calendar?start=&end=` the items with a day in the range, as a calendar shows them
 *   - `POST   {path}/{id}/move`            an item dragged to another day: `start`
 *
 * Answers in the API's shape: `['status' => 200, 'data' => …]` or `['status' => …, 'error' => …]`.
 */
class TodosApi
{
    /** Mount the routes, under `$path`. */
    public static function routes(Router $r, string $path = '/todos'): void
    {
        $path = '/' . trim($path, '/');
        $r->get($path . '/calendar', static fn () => (new static())->calendar())->describeFrom([static::class, 'calendar']);
        $r->post($path . '/{id}/move', static fn ($id) => (new static())->moveDay((int) $id))->describeFrom([static::class, 'moveDay']);
        $r->get($path, static fn () => (new static())->list())->describeFrom([static::class, 'list']);
        $r->post($path, static fn () => (new static())->add())->describeFrom([static::class, 'add']);
        $r->put($path . '/{id}', static fn ($id) => (new static())->change((int) $id))->describeFrom([static::class, 'change']);
        $r->delete($path . '/{id}', static fn ($id) => (new static())->remove((int) $id))->describeFrom([static::class, 'remove']);
    }

    /** The items, the person's name, and whether the plan has to-dos. @return array<string, mixed> */
    public function list(): array
    {
        return $this->answer(function (object $user): array {
            $tenant  = Todos::tenantOf($user);
            $allowed = Todos::allowed($tenant);
            $read    = $allowed ? (new TodosTool((int) $user->userid, $user))->execute(array_intersect_key($_GET, array_flip(['who', 'about', 'closed']))) : ['items' => []];
            if (is_string($read)) {
                throw new \LogicException(substr($read, 7));
            }
            $name = \Pramnos\Conversations\People::names([(int) $user->userid])[(int) $user->userid] ?? (string) ($user->username ?? '');

            return $read + ['me' => (int) $user->userid, 'name' => $name, 'allowed' => $allowed,
                'writes' => Todos::can($user, 'write')];
        });
    }

    /**
     * The items with a day between `start` and `end` (Y-m-d), as a calendar shows them:
     * `[{id, title, start, allDay, url, classNames}]`.
     *
     * @return array<string, mixed>
     */
    public function calendar(): array
    {
        return $this->answer(fn (object $user): array => ['events' => Todos::feed(Todos::tenantOf($user), (int) $user->userid,
            (string) ($_GET['start'] ?? ''), (string) ($_GET['end'] ?? ''))]);
    }

    /**
     * An item dragged to another day: `start`, its new day (Y-m-d).
     *
     * @return array<string, mixed>
     */
    public function moveDay(int $id): array
    {
        return $this->answer(function (object $user) use ($id): array {
            $item = Todos::get($id);
            if ($item === null || $item['tenant'] !== Todos::tenantOf($user)) {
                throw new \DomainException('There is no such to-do.');
            }

            return Todos::move(['id' => $id, 'start' => (string) ((new \Pramnos\Http\Request())->body()['start'] ?? '')], (int) $user->userid);
        }, 200, true);
    }

    /** Add one. @return array<string, mixed> */
    public function add(): array
    {
        return $this->answer(fn (object $user): array => $this->write($user, $this->body()), 201, true);
    }

    /** Change one. @return array<string, mixed> */
    public function change(int $id): array
    {
        return $this->answer(fn (object $user): array => $this->write($user, ['id' => $id] + $this->body()), 200, true);
    }

    /** Delete one's own. @return array<string, mixed> */
    public function remove(int $id): array
    {
        return $this->answer(function (object $user) use ($id): array {
            $item = Todos::get($id);
            if ($item === null || $item['tenant'] !== Todos::tenantOf($user)) {
                throw new \DomainException('There is no such to-do.');
            }
            Todos::delete($id, (int) $user->userid);

            return ['deleted' => $id];
        }, 200, true);
    }

    /**
     * A write through the assistant's tool, its refusals as the API's.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function write(object $user, array $input): array
    {
        $done = (new TodosChangeTool((int) $user->userid, $user))->execute($input);
        if (is_string($done)) {
            $why = substr($done, 7);
            throw str_starts_with($why, 'there is no to-do') ? new \DomainException($why) : new \LogicException($why);
        }

        return (array) ($done['added'] ?? $done['changed']);
    }

    /** The request's JSON body, only the fields an item takes. @return array<string, mixed> */
    private function body(): array
    {
        return array_intersect_key((new \Pramnos\Http\Request())->body(), array_flip(['title', 'assigned_to', 'due', 'remind_at', 'status', 'link', 'private', 'repeat']));
    }

    /** The person asking with the API's credentials; null for nobody. */
    protected function user(): ?object
    {
        $user = \Pramnos\User\User::current();

        return is_object($user) && (int) ($user->userid ?? 0) > 1 ? $user : null;
    }

    /**
     * An action's answer in the API's shape: 401 for nobody, 403 for somebody who may not read the
     * list (or, for a write, change it), 404 for what is not theirs, 422 for what does not make sense.
     *
     * @return array<string, mixed>
     */
    private function answer(\Closure $action, int $status = 200, bool $writes = false): array
    {
        $user = $this->user();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Sign in to read the to-dos.'];
        }
        if (!Todos::installed() || !Todos::can($user, 'read')) {
            return ['status' => 403, 'error' => 'The to-do list is not open to you.'];
        }
        if ($writes && !Todos::can($user, 'write')) {
            return ['status' => 403, 'error' => 'The to-do list is not yours to change.'];
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
