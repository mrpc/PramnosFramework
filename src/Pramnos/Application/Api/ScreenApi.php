<?php

declare(strict_types=1);

namespace Pramnos\Application\Api;

use Pramnos\Routing\Router;

/**
 * An administration screen under the application's API — every action of it, with the screen's own
 * rights, checks and code — for a client that is not the screen's page: a SPA of one part of the
 * administration, a desktop application, a script.
 *
 * ```php
 * ScreenApi::mount($r, '/admin/rules', \Pramnos\Application\Controllers\Rules::class);
 * ```
 *
 *   - `GET  {path}` — the screen's list (`display`);
 *   - `GET  {path}/{action}/{id?}` — a read: `view/12`, `edit/12`;
 *   - `POST {path}/{action}/{id?}` — a write, one of the screen's write actions: `save`, `status/12`.
 *
 * The action runs through the screen's `exec()`, answering the API
 * ({@see \Pramnos\Application\Controller::answerTheApi()}): the screen's floor and ability, its
 * `auth()` and its own checks hold as on the page. Where the page would be drawn, the answer is
 * the data the view was given; where the browser would be sent on, it is the screen's messages
 * and the address. `id` is what the page's address carries after the action (`Rules/view/12`),
 * values go in the query (GET) or a JSON body (POST) under the names the page's form uses.
 *
 * Answers:
 *   - `200 {data: {…the view's variables}}` — a read;
 *   - `200 {data: {messages, redirect}}` — a write that went through;
 *   - `422 {error, errors, messages, redirect}` — the screen said no, in its own words;
 *   - `401` / `403` — nobody signed in, or below the screen's floor;
 *   - an action that answers JSON already answers it as it is.
 */
class ScreenApi
{
    /**
     * Mount a screen's actions under `$path`, each described for the API's document by the
     * action's own docblock.
     *
     * @param class-string<\Pramnos\Application\Controller> $screen
     * @param list<string>                                  $leaveOut Actions not offered
     */
    public static function mount(Router $r, string $path, string $screen, array $leaveOut = []): void
    {
        $path = '/' . trim($path, '/');
        $name = (new \ReflectionClass($screen))->getShortName();
        foreach (static::actions($screen, $leaveOut) as $action => $method) {
            $call = static fn ($id = null) => static::call($screen, $method, $action, $id);
            $uri  = $action === 'display' ? $path : $path . '/' . $action . '/{id?}';
            $route = $method === 'POST' ? $r->post($uri, $call) : $r->get($uri, $call);
            [$summary, $description] = \Pramnos\Routing\Route::fromDocblock((string) (new \ReflectionMethod($screen, $action))->getDocComment());
            $route->describe($summary !== '' ? $summary : ucfirst($name) . ': ' . $action,
                trim($description . "\n\nThe administration's `" . $name . '/' . $action . '`, answering the API: a read answers the data its page'
                    . ' draws, a write its messages and where the page goes next.'),
                ['tags' => ['admin']]);
        }
    }

    /**
     * The screen's actions and the method each is called with: a write by POST, the rest by GET.
     *
     * @param  class-string<\Pramnos\Application\Controller> $screen
     * @param  list<string>                                  $leaveOut
     * @return array<string, string>
     */
    public static function actions(string $screen, array $leaveOut = []): array
    {
        $controller = new $screen(\Pramnos\Application\Application::getInstance());
        $writes     = (fn (): array => array_map('strtolower', $this->writeActions))->call($controller);
        $leaveOut   = array_map('strtolower', $leaveOut);
        $actions    = [];
        foreach (array_merge($controller->actions, $controller->actions_auth) as $action) {
            $action = strtolower((string) $action);
            if (!in_array($action, $leaveOut, true) && method_exists($controller, $action)) {
                $actions[$action] = in_array($action, $writes, true) ? 'POST' : 'GET';
            }
        }

        return $actions;
    }

    /**
     * One action of the screen, answering the API.
     *
     * @param  class-string<\Pramnos\Application\Controller> $screen
     * @return array<string, mixed>
     */
    public static function call(string $screen, string $method, string $action, mixed $id = null): array
    {
        $user = \Pramnos\Http\RequestIdentity::user();
        if ((int) ($user->userid ?? 0) <= 1) {
            return ['status' => 401, 'error' => 'Sign in first.'];
        }
        if ($id !== null && $id !== '') {
            $_GET['_option'] = (string) $id;
        }
        $controller = (new $screen(\Pramnos\Application\Application::getInstance()))->answerTheApi();
        try {
            $answer = $controller->exec($action, []);
        } catch (ScreenAnswer $said) {
            return self::said($said, $controller);
        } catch (\Pramnos\Http\RedirectException) {
            return ['status' => 401, 'error' => 'Sign in first.'];
        } catch (\Pramnos\Application\ApplicationClosedException $e) {
            throw $e;
        } catch (\Exception $e) {
            if (in_array($e->getCode(), [403, 404], true)) {
                return ['status' => $e->getCode(), 'error' => $e->getMessage()];
            }
            throw $e;
        } finally {
            self::forgetFlash();
        }
        if ($answer instanceof \Pramnos\Http\Response) {
            $status = $answer->getStatusCode();
            $body   = json_decode((string) $answer->getBody(), true);

            return $status < 400 ? ['status' => $status, 'data' => $body]
                : ['status' => $status, 'error' => is_array($body) ? (string) ($body['error'] ?? '') : ''];
        }
        if (is_string($answer) && trim($answer) !== '') {
            return ['status' => 406, 'error' => 'That action draws a page the API cannot answer with.'];
        }

        return self::flash($controller, null);
    }

    /** What the screen said, as the API answers it. */
    private static function said(ScreenAnswer $said, \Pramnos\Application\Controller $controller): array
    {
        return match ($said->kind) {
            ScreenAnswer::DATA     => ['status' => 200, 'data' => $said->payload],
            ScreenAnswer::REDIRECT => self::flash($controller, (string) $said->payload),
            default                => ['status' => $said->getCode(), 'error' => (string) $said->payload],
        };
    }

    /** A write's answer: its messages and where it went on to — or its errors, as a 422. */
    private static function flash(\Pramnos\Application\Controller $controller, ?string $redirect): array
    {
        $messages = array_values(array_map('strval', $controller->_messages));
        $errors   = array_values(array_map('strval', $controller->_errors));
        if ($errors !== []) {
            return ['status' => 422, 'error' => $errors[0], 'errors' => $errors, 'messages' => $messages, 'redirect' => $redirect];
        }

        return ['status' => 200, 'data' => ['messages' => $messages, 'redirect' => $redirect]];
    }

    /** The screen's messages were answered: not shown again on the person's next page. */
    private static function forgetFlash(): void
    {
        if (isset($_SESSION)) {
            unset($_SESSION['_messages'], $_SESSION['_errors']);
        }
    }
}
