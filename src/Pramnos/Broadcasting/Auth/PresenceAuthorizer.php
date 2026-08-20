<?php

declare(strict_types=1);

namespace Pramnos\Broadcasting\Auth;

/**
 * A {@see ConnectionAuthorizer} that can also extract a presence channel member's
 * identity from the `channel_data` a client presents.
 *
 * ## Why this is a separate interface
 *
 * `ConnectionAuthorizer` is documented for applications to implement — the Realtime
 * guide says so in as many words. Adding a method to it would break every one of
 * those implementations at the moment they upgraded, for a capability most of them
 * do not need. So presence support is opted into by implementing this instead, and
 * the server asks with `instanceof`.
 *
 * The practical effect is that a deployment with a custom authorizer keeps working
 * unchanged and simply has no presence membership — the same behaviour it has
 * today — until it opts in.
 *
 * ## What member data is for
 *
 * A presence channel is a channel that knows who is in it. The server keeps a
 * member list per channel and sends it to each new subscriber, then announces
 * arrivals and departures. It cannot derive that from the wire: `channel_data` is
 * an opaque string the auth endpoint signed, so whoever validated the signature is
 * the only party that can safely say what is inside it.
 */
interface PresenceAuthorizer extends ConnectionAuthorizer
{
    /**
     * The member this subscription represents, or null when it has no identity.
     *
     * Called only after {@see ConnectionAuthorizer::authorizeChannel()} has already
     * accepted the subscription, so an implementation may trust `$channelData` —
     * its signature has been verified by then. Returning null leaves the client
     * subscribed but unlisted, which is the right answer for a `presence-` channel
     * whose client sent no member data rather than a reason to refuse it.
     *
     * @param string      $channel     Full channel name, `presence-` prefix included.
     * @param string      $socketId    The subscribing connection's socket id.
     * @param string|null $channelData The raw, already-signed member payload.
     * @return array{user_id:string, user_info?:array<string,mixed>}|null
     */
    public function presenceMember(string $channel, string $socketId, ?string $channelData): ?array;
}
