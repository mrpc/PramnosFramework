<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Auth;

use Pramnos\Broadcasting\Apps\BroadcastApp;

/**
 * Produces the channel-authorization token a Pusher-protocol client presents when
 * it subscribes to a private or presence channel.
 *
 * {@see PusherAuthorizer} was the other half of this and shipped first: the
 * framework could **verify** a signature but never **produce** one, so every
 * application wrote the signing endpoint itself. The two are now one pair, over
 * one string-to-sign definition, which is the only way they cannot drift.
 *
 * The string signed is:
 *
 * ```
 * private  channel: "<socketId>:<channel>"
 * presence channel: "<socketId>:<channel>:<channelData>"
 * ```
 *
 * and the token is `"<appKey>:" . hash_hmac('sha256', <string>, <appSecret>)`.
 *
 * **`channelData` is signed as the exact JSON string that is sent**, not as the
 * array it came from. Re-encoding it on the other side would change key order or
 * escaping and invalidate a token nobody tampered with — the classic
 * canonicalisation bug, here avoided by never canonicalising twice.
 */
final class PusherAuthSigner
{
    public function __construct(private readonly BroadcastApp $app)
    {
    }

    /**
     * Sign a private channel subscription.
     *
     * @return array{auth:string} The body a Pusher client expects.
     * @throws \RuntimeException When the app has no secret to sign with.
     */
    public function signPrivate(string $socketId, string $channel): array
    {
        return ['auth' => $this->sign($socketId . ':' . $channel)];
    }

    /**
     * Sign a presence channel subscription.
     *
     * @param array<string,mixed> $memberData Must contain `user_id`; `user_info`
     *                                        is conventional and optional.
     * @return array{auth:string, channel_data:string}
     * @throws \RuntimeException When the app has no secret, or member data has no
     *         user_id — a presence member without an identity cannot be tracked,
     *         and the server would have nothing to put in its member list.
     */
    public function signPresence(string $socketId, string $channel, array $memberData): array
    {
        if (!isset($memberData['user_id']) || (string) $memberData['user_id'] === '') {
            throw new \RuntimeException(
                'Presence channel member data must include a non-empty user_id.'
            );
        }

        // user_id is always a string on the wire: a client comparing it against
        // its own id gets 7 !== "7" otherwise, which shows up as a member who is
        // present but never recognised as "me".
        $memberData['user_id'] = (string) $memberData['user_id'];

        $channelData = (string) json_encode($memberData);

        return [
            'auth'         => $this->sign($socketId . ':' . $channel . ':' . $channelData),
            'channel_data' => $channelData,
        ];
    }

    /**
     * Sign whichever kind of channel $channel is, given the authorization result
     * from {@see ChannelRegistry::authorize()}.
     *
     * @param array<string,mixed>|true $authorization Member data for presence, or
     *                                               true for a private channel.
     * @return array{auth:string, channel_data?:string}
     */
    public function signFor(string $socketId, string $channel, array|bool $authorization): array
    {
        if (ChannelRegistry::isPresence($channel)) {
            if (!is_array($authorization)) {
                throw new \RuntimeException(
                    'Channel "' . $channel . '" is a presence channel, so its authorization '
                    . 'callback must return member data (an array), not a boolean.'
                );
            }

            return $this->signPresence($socketId, $channel, $authorization);
        }

        return $this->signPrivate($socketId, $channel);
    }

    private function sign(string $payload): string
    {
        if (!$this->app->canSign()) {
            throw new \RuntimeException(
                'Application "' . $this->app->key . '" has no broadcasting secret, so channel '
                . 'authorization cannot be signed. Set broadcasting.pusher.app_secret, or give '
                . 'the application row a broadcast_secret.'
            );
        }

        return $this->app->key . ':' . hash_hmac('sha256', $payload, $this->app->secret);
    }
}
