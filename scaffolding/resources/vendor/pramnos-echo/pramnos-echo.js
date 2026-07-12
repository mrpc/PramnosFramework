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
            return this._subscribe('presence-' + channelName);
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

        _subscribe: function (channelName) {
            if (!pusher) {
                throw new Error(
                    'PramnosEcho is not configured. ' +
                    'Call PramnosEcho.configure({ key: "...", cluster: "eu" }) first.'
                );
            }
            if (!channels[channelName]) {
                channels[channelName] = new EchoChannel(pusher.subscribe(channelName));
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

}(window));
