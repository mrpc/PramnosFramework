/**
 * PramnosRealtime — one client, three transports.
 * v1.0.0
 *
 * A thin, dependency-free front end over the framework's realtime transports.
 * Feed it the config produced by \Pramnos\Broadcasting\RealtimeConfig::forClient()
 * (typically serialized into the page or fetched from a settings endpoint) and it
 * connects the right way for the deployment:
 *
 *   - transport 'sse'       → native EventSource (shared hosting; one event stream)
 *   - transport 'websocket' → the built-in server via pramnos-echo.js (Pusher proto)
 *   - transport 'pusher'    → Pusher/Reverb via pramnos-echo.js
 *
 * Two event models exist and are NOT forced into one:
 *   - SSE is a single stream of *named events*: use conn.on(eventName, cb).
 *   - WebSocket/Pusher is *channel + event*: use conn.channel(name).listen(evt, cb).
 * A connection exposes whichever model its transport supports; callers that must
 * support both should branch on conn.transport.
 *
 * Usage:
 *   const rt = PramnosRealtime.connect(window.__realtime);   // config object
 *   if (rt.transport === 'sse') {
 *       rt.on('message', (data) => render(data));
 *       rt.on('users',   (data) => setUsers(data));
 *   } else {
 *       rt.channel('chat').listen('message', (data) => render(data));
 *   }
 *   // later: rt.close();
 */
(function (global) {
    'use strict';

    function safeParse(raw) {
        if (typeof raw !== 'string') { return raw; }
        try { return JSON.parse(raw); } catch (e) { return raw; }
    }

    /**
     * SSE connection — named events over a single EventSource.
     */
    function SseConnection(config) {
        this.transport = 'sse';
        this._url = config.url || '/api/stream';
        this._handlers = {};
        this._es = new EventSource(this._url, { withCredentials: true });
    }
    SseConnection.prototype.on = function (event, cb) {
        var listener = function (e) { cb(safeParse(e.data), e); };
        this._handlers[event] = this._handlers[event] || [];
        this._handlers[event].push(listener);
        this._es.addEventListener(event, listener);
        return this;
    };
    SseConnection.prototype.onOpen = function (cb) { this._es.addEventListener('open', cb); return this; };
    SseConnection.prototype.onError = function (cb) { this._es.addEventListener('error', cb); return this; };
    SseConnection.prototype.close = function () { if (this._es) { this._es.close(); } };

    /**
     * Echo connection — channel/event over pramnos-echo.js (WebSocket or Pusher).
     * Requires pramnos-echo.js (and the Pusher SDK) to be loaded first.
     */
    function EchoConnection(config) {
        this.transport = config.transport; // 'websocket' | 'pusher'
        if (!global.PramnosEcho) {
            throw new Error('PramnosRealtime: pramnos-echo.js (and the Pusher SDK) must be loaded for the "' + config.transport + '" transport.');
        }
        if (config.transport === 'websocket') {
            global.PramnosEcho.configure({
                appKey: config.appKey || 'pramnos-local',
                host: config.host || 'localhost',
                port: config.port || 6001,
                scheme: config.scheme || 'ws'
            });
        } else {
            global.PramnosEcho.configure({
                key: config.key,
                cluster: config.cluster || undefined,
                wsHost: config.wsHost || undefined,
                wsPort: config.wsPort || undefined,
                forceTLS: !!config.forceTLS
            });
        }
    }
    EchoConnection.prototype.channel = function (name) { return global.PramnosEcho.channel(name); };
    EchoConnection.prototype.private = function (name) { return global.PramnosEcho.private(name); };
    EchoConnection.prototype.leave = function (name) { return global.PramnosEcho.leave(name); };
    EchoConnection.prototype.close = function () { if (global.PramnosEcho && global.PramnosEcho.disconnect) { global.PramnosEcho.disconnect(); } };

    var PramnosRealtime = {
        version: '1.0.0',
        /**
         * Connect using the transport named in config.transport.
         * @param {{transport:string}} config from RealtimeConfig::forClient()
         */
        connect: function (config) {
            config = config || { transport: 'sse' };
            if (config.transport === 'websocket' || config.transport === 'pusher') {
                return new EchoConnection(config);
            }
            return new SseConnection(config);
        }
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = PramnosRealtime;
    } else {
        global.PramnosRealtime = PramnosRealtime;
    }
})(typeof window !== 'undefined' ? window : this);
