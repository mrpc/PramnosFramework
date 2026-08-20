/**
 * PramnosEcho — Browser-side client for the Pramnos Broadcasting system.
 *
 * Provides a minimal, Pusher-compatible channel subscription API. Works with:
 *   1. The Pusher cloud service (via the Pusher JS SDK)
 *   2. Laravel Reverb or any Pusher-compatible self-hosted server
 *
 * ## Requirements
 *
 * Include the Pusher JS SDK before this file:
 *   <script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>
 *
 * Or via npm: import Pusher from 'pusher-js';
 *
 * ## Setup
 *
 * Include both scripts and configure before use:
 *
 *   <script>
 *     PramnosEcho.configure({
 *       key:     'YOUR_PUSHER_APP_KEY',
 *       cluster: 'eu',
 *       // For Reverb (local dev):
 *       // wsHost: '127.0.0.1',
 *       // wsPort: 8080,
 *       // forceTLS: false,
 *       // enabledTransports: ['ws', 'wss'],
 *     });
 *   </script>
 *
 * ## Subscribe to events
 *
 *   // Public channel
 *   PramnosEcho.channel('orders').listen('order.created', function (data) {
 *     console.log('New order:', data);
 *   });
 *
 *   // Presence channel — who is in the room
 *   PramnosEcho.join('room.lobby')
 *       .here(function (members) { render(members); })
 *       .joining(function (member) { add(member); })
 *       .leaving(function (member) { remove(member); })
 *       .listenForWhisper('typing', function (payload) { showTyping(payload); });
 *
 *   // Browser-to-browser (needs broadcasting.websocket.client_events = true)
 *   PramnosEcho.presence('room.lobby').whisper('typing', { user: 'Ada' });
 *
 *   // Leave this connection out of the broadcast your own write causes
 *   fetch('/messages', { method: 'POST', headers: PramnosEcho.headers(), body: body });
 *
 *   // Private channel (requires auth endpoint — see broadcasting section in docs)
 *   PramnosEcho.private('orders.42').listen('order.paid', function (data) {
 *     console.log('Order paid:', data);
 *   });
 *
 *   // Unsubscribe
 *   PramnosEcho.leave('orders');
 *
 * ## CSRF
 *
 * Auth headers automatically include X-CSRF-Token from <meta name="csrf-token">.
 *
 * @version     1.2.0
 * @package     PramnosFramework
 */
(function (window) {
    'use strict';

    // ─────────────────────────────────────────────────────────────────────────
    // Internal state
    // ─────────────────────────────────────────────────────────────────────────

    var pusher = null;
    var channels = {};

    // ─────────────────────────────────────────────────────────────────────────
    // Channel wrapper
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Wraps a Pusher channel subscription and provides a fluent .listen() API.
     *
     * @param {object} pusherChannel  A Pusher.Channel instance.
     * @constructor
     */
    function EchoChannel(pusherChannel) {
        this._channel = pusherChannel;
    }

    /**
     * Listen for a specific event on this channel.
     *
     * @param  {string}   event     The event name (as returned by the server).
     * @param  {function} callback  Called with the event payload (plain object).
     * @return {EchoChannel}        Returns this for chaining.
     */
    EchoChannel.prototype.listen = function (event, callback) {
        this._channel.bind(event, callback);
        return this;
    };

    /**
     * Stop listening for a specific event.
     *
     * @param  {string}   event
     * @param  {function} [callback]  If omitted, all listeners for this event are removed.
     * @return {EchoChannel}
     */
    EchoChannel.prototype.stopListening = function (event, callback) {
        if (callback) {
            this._channel.unbind(event, callback);
        } else {
            this._channel.unbind(event);
        }
        return this;
    };

    /**
     * Send a client event to the channel's other subscribers.
     *
     * This is the browser-to-browser direction — typing indicators, cursors,
     * transient cues — and the only thing a WebSocket can carry that SSE cannot.
     *
     * Three things to know, because each one is a silent no-op rather than an
     * error:
     *
     *   1. The server must have client events **enabled**. They are off by default
     *      (`broadcasting.websocket.client_events`), because turning them on grants
     *      every connected browser a write path onto a channel.
     *   2. Only `private-` and `presence-` channels relay them. A public channel has
     *      no membership test, so relaying on one would be an open publish endpoint.
     *   3. There is a per-connection rate limit (10/s by default). Events over it are
     *      dropped without a reply — answering each one would hand a browser a cheap
     *      way to make the server talk.
     *
     * The sender never receives its own whisper.
     *
     * @param  {string} event    Name without the 'client-' prefix.
     * @param  {*}      [data]   Payload; anything JSON-encodable.
     * @return {EchoChannel}
     */
    EchoChannel.prototype.whisper = function (event, data) {
        this._channel.trigger('client-' + event, data === undefined ? {} : data);
        return this;
    };

    /**
     * Listen for a client event from another subscriber.
     *
     * @param  {string}   event     Name without the 'client-' prefix.
     * @param  {function} callback  Called with the payload.
     * @return {EchoChannel}
     */
    EchoChannel.prototype.listenForWhisper = function (event, callback) {
        this._channel.bind('client-' + event, callback);
        return this;
    };

    /**
     * Stop listening for a client event.
     *
     * @param  {string}   event
     * @param  {function} [callback]
     * @return {EchoChannel}
     */
    EchoChannel.prototype.stopListeningForWhisper = function (event, callback) {
        return this.stopListening('client-' + event, callback);
    };

    // ─────────────────────────────────────────────────────────────────────────
    // Presence channel
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A channel that knows who is in it.
     *
     * Everything an EchoChannel does, plus the three membership callbacks. The
     * server sends the member list in `pusher_internal:subscription_succeeded` and
     * then announces arrivals and departures — none of which happened before the
     * framework tracked presence membership, so these callbacks could not have
     * fired however they were written.
     *
     * @param {object} pusherChannel
     * @constructor
     * @extends EchoChannel
     */
    function PresenceChannel(pusherChannel) {
        EchoChannel.call(this, pusherChannel);
    }

    PresenceChannel.prototype = Object.create(EchoChannel.prototype);
    PresenceChannel.prototype.constructor = PresenceChannel;

    /**
     * Called once, with everyone already in the channel — the subscriber included.
     *
     * Including yourself is per the protocol and is what you want: a client that had
     * to add itself would show a different room to the person who just joined than
     * to everyone already there.
     *
     * A user with several connections (two tabs, a phone) appears **once**: the
     * server deduplicates by user id, and counts people rather than sockets.
     *
     * @param  {function} callback  Receives an array of members.
     * @return {PresenceChannel}
     */
    PresenceChannel.prototype.here = function (callback) {
        this._channel.bind('pusher:subscription_succeeded', function (members) {
            callback(PresenceChannel._toArray(members));
        });
        return this;
    };

    /**
     * Called when somebody joins — never for your own arrival, and never for a
     * user's second connection.
     *
     * @param  {function} callback  Receives the member.
     * @return {PresenceChannel}
     */
    PresenceChannel.prototype.joining = function (callback) {
        this._channel.bind('pusher:member_added', function (member) {
            callback(PresenceChannel._member(member));
        });
        return this;
    };

    /**
     * Called when somebody leaves — only once their **last** connection goes.
     *
     * A user closing one of two tabs has not left, and reporting it per connection
     * is what makes members flicker out of a list.
     *
     * @param  {function} callback  Receives the member.
     * @return {PresenceChannel}
     */
    PresenceChannel.prototype.leaving = function (callback) {
        this._channel.bind('pusher:member_removed', function (member) {
            callback(PresenceChannel._member(member));
        });
        return this;
    };

    /**
     * Normalise one member into { id, info }.
     *
     * `id` is always a string. The server casts it, and so does this, because a
     * client comparing a numeric id against its own gets 7 !== "7" — which presents
     * as a member who is in the room but is never recognised as anybody, including
     * as yourself.
     *
     * @private
     */
    PresenceChannel._member = function (member) {
        if (!member) {
            return { id: null, info: {} };
        }

        return {
            id:   member.id === undefined || member.id === null ? null : String(member.id),
            info: member.info || {}
        };
    };

    /**
     * Turn Pusher's members object into a plain array.
     *
     * @private
     */
    PresenceChannel._toArray = function (members) {
        var out = [];

        if (!members) {
            return out;
        }

        // pusher-js exposes an each() over its members map; fall back to the raw
        // hash for any client that does not.
        if (typeof members.each === 'function') {
            members.each(function (member) {
                out.push(PresenceChannel._member(member));
            });
            return out;
        }

        Object.keys(members.members || {}).forEach(function (id) {
            out.push({ id: String(id), info: members.members[id] || {} });
        });

        return out;
    };

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    var PramnosEcho = {

        /**
         * Configure and connect to the Pusher backend.
         *
         * Must be called once before subscribing to any channel.
         *
         * @param {object} config
         * @param {string} config.key         Pusher app key (required).
         * @param {string} [config.cluster]   Pusher cluster (default: 'eu').
         * @param {string} [config.wsHost]    Custom host for Reverb/self-hosted.
         * @param {number} [config.wsPort]    Custom port (default: 443 or 80).
         * @param {boolean}[config.forceTLS]  Force WSS (default: true).
         * @param {string} [config.authEndpoint] Auth URL for private channels (default: '/broadcasting/auth').
         */
        configure: function (config) {
            if (!window.Pusher) {
                throw new Error(
                    'PramnosEcho requires the Pusher JS SDK. ' +
                    'Include it before pramnos-echo.js: ' +
                    '<script src="https://js.pusher.com/8.4.0/pusher.min.js"></script>'
                );
            }

            var options = {
                cluster:      config.cluster || 'eu',
                forceTLS:     config.forceTLS !== false,
                authEndpoint: config.authEndpoint || '/broadcasting/auth',
                auth: {
                    headers: {
                        'X-CSRF-Token': PramnosEcho._getCsrfToken()
                    }
                }
            };

            // Reverb / self-hosted overrides
            if (config.wsHost) {
                options.wsHost              = config.wsHost;
                options.wsPort              = config.wsPort || (options.forceTLS ? 443 : 80);
                options.enabledTransports   = config.enabledTransports || ['ws', 'wss'];
                options.disableStats        = true;
            }

            pusher = new window.Pusher(config.key, options);
        },

        /**
         * Subscribe to a public channel.
         *
         * @param  {string}      channelName
         * @return {EchoChannel}
         */
        channel: function (channelName) {
            return this._subscribe(channelName);
        },

        /**
         * Subscribe to a private channel (requires auth endpoint).
         *
         * @param  {string}      channelName  Without the 'private-' prefix.
         * @return {EchoChannel}
         */
        private: function (channelName) {
            return this._subscribe('private-' + channelName);
        },

        /**
         * Subscribe to a presence channel.
         *
         * @param  {string}      channelName  Without the 'presence-' prefix.
         * @return {EchoChannel}
         */
        presence: function (channelName) {
            return this._subscribe('presence-' + channelName, PresenceChannel);
        },

        /**
         * Alias for presence(), matching Laravel Echo's name.
         *
         * @param  {string} channelName  Without the 'presence-' prefix.
         * @return {PresenceChannel}
         */
        join: function (channelName) {
            return this.presence(channelName);
        },

        /**
         * This connection's socket id, or null before the connection is up.
         *
         * Send it with any write that will cause a broadcast, and the server can
         * leave this connection out of it — `toOthers()`. Without it, a client that
         * rendered a change optimistically renders it a second time when the
         * broadcast comes back.
         *
         * @return {string|null}
         */
        socketId: function () {
            return (pusher && pusher.connection && pusher.connection.socket_id) || null;
        },

        /**
         * Headers to merge into a request that will cause a broadcast.
         *
         * ```js
         * fetch('/messages', {
         *     method: 'POST',
         *     headers: Object.assign({'Content-Type': 'application/json'}, PramnosEcho.headers()),
         *     body: JSON.stringify(message)
         * });
         * ```
         *
         * Empty before the connection is established, which is the honest answer:
         * there is no connection to exclude yet.
         *
         * @return {object}
         */
        headers: function () {
            var id = this.socketId();

            return id ? { 'X-Socket-ID': id } : {};
        },

        /**
         * Unsubscribe from a channel and remove all its event listeners.
         *
         * @param {string} channelName  The channel name (without prefix).
         */
        leave: function (channelName) {
            var names = [channelName, 'private-' + channelName, 'presence-' + channelName];
            names.forEach(function (name) {
                if (channels[name]) {
                    pusher.unsubscribe(name);
                    delete channels[name];
                }
            });
        },

        /**
         * Disconnect from Pusher and clear all subscriptions.
         */
        disconnect: function () {
            if (pusher) {
                pusher.disconnect();
                pusher    = null;
                channels  = {};
            }
        },

        // ─────────────────────────────────────────────────────────────────────
        // Internal helpers
        // ─────────────────────────────────────────────────────────────────────

        _subscribe: function (channelName, WrapperType) {
            if (!pusher) {
                throw new Error(
                    'PramnosEcho is not configured. ' +
                    'Call PramnosEcho.configure({ key: "...", cluster: "eu" }) first.'
                );
            }
            if (!channels[channelName]) {
                var Wrapper = WrapperType || EchoChannel;
                channels[channelName] = new Wrapper(pusher.subscribe(channelName));
            }
            return channels[channelName];
        },

        _getCsrfToken: function () {
            var meta = document.querySelector('meta[name="csrf-token"]');
            return meta ? meta.getAttribute('content') : '';
        }
    };

    // ─────────────────────────────────────────────────────────────────────────
    // Export
    // ─────────────────────────────────────────────────────────────────────────

    window.PramnosEcho = PramnosEcho;

    // Exported for instanceof checks and for tests; the API above is the supported
    // surface.
    PramnosEcho.EchoChannel     = EchoChannel;
    PramnosEcho.PresenceChannel = PresenceChannel;

}(window));
