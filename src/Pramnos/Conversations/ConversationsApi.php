<?php

declare(strict_types=1);

namespace Pramnos\Conversations;

use Pramnos\Routing\Router;

/**
 * The conversations under the application's API, with the API's credentials — a bearer token,
 * or the page's session — for a client that is not the administration's page: a desktop or a
 * mobile application, a SPA.
 *
 * ```php
 * \Pramnos\Conversations\ConversationsApi::routes($r);                  // /conversations
 * ```
 *
 *   - `GET  {path}/{action}` and `GET {path}/{action}/{id}` — a read: `conversations`,
 *     `messages/{id}`, `members/{id}`, `links/{id}`, `tabs/{id}`, `canvas/{canvasId}`, …
 *   - `POST {path}/{action}` and `POST {path}/{action}/{id}` — a write: `post/{id}`,
 *     `describe/{id}`, `savecanvas/{canvasId}`, `addtodo`, …
 *
 * Every action is the one the administration's screen answers
 * (`Application\Controllers\Conversations`), with the same rights, values and answers — one
 * implementation behind both, so the two cannot disagree. The answer is in the API's shape:
 * `['status' => 200, 'data' => …]`, or `['status' => …, 'error' => …]`. A write is a POST and a
 * read a GET; the browser's page (`display`) is not offered. The Conversations guide lists the
 * actions.
 */
class ConversationsApi
{
    /**
     * Mount the routes, under `$path`: one for each action, a read by GET and a write by POST, each
     * described for the API's document (`api:docs`) by the action's own docblock.
     */
    public static function routes(Router $r, string $path = '/conversations'): void
    {
        $path = '/' . trim($path, '/');
        foreach (static::actions() as $action => [$method, $summary]) {
            $uri   = $path . '/' . $action . '/{id?}';
            $call  = static fn ($id = null) => (new static())->call($method, $action, (int) $id);
            $route = $method === 'POST' ? $r->post($uri, $call) : $r->get($uri, $call);
            $route->describe($summary !== '' ? $summary : ucfirst($action),
                'The conversations\' `' . $action . '` action, as `/admin/Conversations/' . $action . '` answers it. `id` is the conversation, message,'
                . ' canvas or person it acts on, and is left out where it takes none. Values go in the query (GET) or a JSON body (POST).',
                ['tags' => ['conversations']]);
        }
    }

    /**
     * The actions offered, each with its method and the first line of its docblock.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function actions(): array
    {
        static $actions = null;
        if ($actions !== null) {
            return $actions;
        }
        $screen = (new static())->screen((object) ['userid' => 0], 0);
        $writes = $screen->writes();
        $actions = [];
        foreach (array_unique(array_map('strtolower', $screen->actions_auth)) as $action) {
            if ($action === 'display' || !method_exists(\Pramnos\Application\Controllers\Conversations::class, $action)) {
                continue;
            }
            $summary = \Pramnos\Routing\Route::fromDocblock((string) (new \ReflectionMethod(\Pramnos\Application\Controllers\Conversations::class, $action))->getDocComment())[0];
            $actions[$action] = [in_array($action, $writes, true) ? 'POST' : 'GET', $summary];
        }

        return $actions;
    }

    /**
     * One action, as the person asking: a read by GET, a write by POST.
     *
     * @return array<string, mixed>
     */
    public function call(string $method, string $action, int $id): array
    {
        $user = $this->user();
        if ($user === null) {
            return ['status' => 401, 'error' => 'Sign in to use the conversations.'];
        }
        $action = strtolower($action);
        $screen = $this->screen($user, $id);
        $writes = $screen->writes();
        $reads  = array_values(array_diff(array_map('strtolower', $screen->actions_auth), $writes, ['display']));
        $known  = $method === 'POST' ? $writes : $reads;
        if (!in_array($action, $known, true) || !method_exists($screen, $action)) {
            $other = $method === 'POST' ? $reads : $writes;

            return in_array($action, $other, true)
                ? ['status' => 405, 'error' => $method === 'POST' ? 'That is read with GET.' : 'That is written with POST.']
                : ['status' => 404, 'error' => 'There is no such action.'];
        }
        $answer = $screen->{$action}();
        if (!$answer instanceof \Pramnos\Http\Response) {
            // A file or a picture: sent already.
            return ['status' => 200, 'data' => null];
        }
        $status = $answer->getStatusCode();
        $body   = json_decode((string) $answer->getBody(), true);

        return $status < 400 ? ['status' => $status, 'data' => $body]
            : ['status' => $status, 'error' => is_array($body) ? (string) ($body['error'] ?? '') : ''];
    }

    /** The person asking with the API's credentials; null for nobody. */
    protected function user(): ?object
    {
        $user = \Pramnos\User\User::current();

        return is_object($user) && (int) ($user->userid ?? 0) > 1 ? $user : null;
    }

    /** The administration's screen, answering for this person and this id. */
    protected function screen(object $user, int $id): \Pramnos\Application\Controllers\Conversations
    {
        $screen = new class (\Pramnos\Application\Application::getInstance()) extends \Pramnos\Application\Controllers\Conversations {
            /** Who asks: the API's person. */
            public ?object $asking = null;

            /** The id from the route. */
            public int $routeId = 0;

            /** The API's person, not the page's session. */
            protected function me(): ?object
            {
                return $this->asking;
            }

            /** The id from the route, not the page's address. */
            protected function id(): int
            {
                return $this->routeId;
            }

            /** @return list<string> The actions that write */
            public function writes(): array
            {
                return array_map('strtolower', $this->writeActions);
            }
        };
        $screen->asking  = $user;
        $screen->routeId = $id;

        return $screen;
    }
}
