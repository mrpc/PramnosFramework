<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting;

use Pramnos\Broadcasting\Controllers\Broadcasting;
use Pramnos\Routing\Router;

/**
 * The realtime channels' authorization under the application's API, with the API's credentials:
 * a desktop or mobile application signed in with a token joins its private and presence channels
 * — its own `private-users.{id}`, a conversation's — as a page does with its session.
 *
 * ```php
 * \Pramnos\Broadcasting\BroadcastingApi::routes($r);        // POST /broadcasting/auth
 * \Pramnos\Broadcasting\BroadcastingApi::routes($r, '/broadcasting/auth', \App\Controllers\Broadcasting::class);   // the application's own
 * ```
 *
 * The client's Pusher-protocol library points its `authEndpoint` here. The answer is the
 * protocol's own (`{auth}`, and `channel_data` for a presence channel), not the API's envelope,
 * because the library reads it as such. Who may join which channel is decided as for the page
 * ({@see \Pramnos\Broadcasting\Auth\ChannelRegistry}).
 */
final class BroadcastingApi
{
    /**
     * Mount the route, at `$path`, described for the API's document. `$controller` is the
     * application's own subclass where it has one — its `channels()` then decide.
     *
     * @param class-string<Broadcasting> $controller
     */
    public static function routes(Router $r, string $path = '/broadcasting/auth', string $controller = Broadcasting::class): void
    {
        if (!is_a($controller, Broadcasting::class, true)) {
            throw new \InvalidArgumentException($controller . ' is not a ' . Broadcasting::class);
        }
        $r->post('/' . trim($path, '/'), static fn () => (new $controller())->postAuth())->describe('Authorize a private or presence channel',
            'For a Pusher-protocol client signed in with the API\'s credentials: `socket_id` and `channel_name` (form fields, as the'
            . ' libraries send them). Answers the protocol\'s own `{auth}` (and `channel_data` for a presence channel), not the API\'s envelope;'
            . ' 403 for a channel the person may not join, 400 for a malformed request.', [
            'tags'        => ['broadcasting'],
            'requestBody' => ['content' => ['application/x-www-form-urlencoded' => ['schema' => ['type' => 'object', 'required' => ['socket_id', 'channel_name'],
                'properties' => ['socket_id' => ['type' => 'string'], 'channel_name' => ['type' => 'string']]]]]],
        ]);
    }
}
