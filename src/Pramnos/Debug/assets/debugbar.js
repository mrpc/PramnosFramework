/**
 * The Pramnos debug toolbar — one renderer, two ways in.
 *
 * There used to be two: ~970 lines of PHP building HTML, CSS and inline JS for
 * server-rendered pages, and a separate ~560-line JavaScript panel for SPA
 * projects, drawing the same tables from the same collector data. They drifted,
 * and then the same bug had to be fixed twice — the `✕` that hid nothing was one
 * mistake written in two languages.
 *
 * So this file draws the toolbar, and nothing else does. It is delivered two ways:
 *
 *   - **Server-rendered pages.** `DebugBar::render()` emits this script inline,
 *     preceded by `<div id="pramnos-debug-data" hidden>` holding the request's
 *     collector data as JSON. A `<div>` rather than a `<script type=…>` because a
 *     data island in a script element is a grey area under a strict CSP, and this
 *     one has to work on every install.
 *   - **SPA projects.** The same source is scaffolded as `lib/debug.js` with an
 *     ESM `export` appended, and `lib/api.js` calls `record()` for every
 *     response. There is no data island: the first entry arrives with the first
 *     API call.
 *
 * The model is the same either way: a list of **entries**, each one request with
 * the collector payload it produced. Selecting an entry shows its tabs. That is
 * what makes a SPA get every tab a server-rendered page has — the payload
 * `ApiDebugPayload::build()` attaches already carries every collector; only the
 * drawing was missing.
 *
 * Three rules, because this runs inside somebody else's application:
 *   - the original `fetch`/`XMLHttpRequest` is always called and its result
 *     returned unchanged;
 *   - response bodies are only read through `clone()`;
 *   - every entry point is wrapped in try/catch. A toolbar that breaks the page
 *     it measures is worse than no toolbar.
 */
(function () {
    'use strict';

    // Delivered twice on one page (a partial that re-emits it, a SPA shell that
    // also server-renders) must not mean two toolbars.
    if (typeof window === 'undefined' || window.__pramnosDebugBar) {
        return;
    }

    /** How many requests are kept. Enough to see a pattern, not to hoard. */
    var HISTORY = 50;

    /**
     * How much of a request body is kept, in characters.
     *
     * A datatables request is a couple of kilobytes of column metadata and fits
     * comfortably; a file upload never will, and is described rather than kept
     * anyway. What is dropped is said out loud in the panel rather than quietly
     * cut, because a body that looks complete and is not is worse than one that
     * admits it stops.
     */
    var BODY_LIMIT = 8192;

    /**
     * Above this, a body is shown as it was sent rather than laid out.
     *
     * Pretty-printing means parsing, re-serialising and walking every key to
     * mask it. That is nothing for two kilobytes and real work for eight, and it
     * would run on the toolbar's own render path — the one that must never be
     * the reason a page feels slow. The browser's own network panel draws the
     * same line somewhere: past a point it stops formatting and hands you the
     * text.
     */
    var BODY_FORMAT_LIMIT = 2048;

    /** Where the "I hid the bar" choice lives, shared by both deliveries. */
    var HIDDEN_KEY = 'pramnos.debugbar.hidden';

    /** Where the panel's height is remembered, the way the hidden flag is. */
    var HEIGHT_KEY = 'pramnos.debugbar.height';
    /**
     * Which tab was open, so a page change does not close the panel.
     *
     * The bar remembered whether it was hidden and how tall it was, and forgot the one piece
     * of state a developer is actually in the middle of using. Every navigation collapsed the
     * panel, so following a bug across three pages meant reopening the same tab three times —
     * and the tab most often reopened is the one that explains why the page you just landed on
     * is not the page you asked for.
     */
    var TAB_KEY = 'pramnos.debugbar.tab';

    /** The panel will not be dragged smaller or larger than this, in pixels. */
    var MIN_HEIGHT = 80;
    var MAX_HEIGHT = 900;

    /** Keys whose values are replaced before they can be read or screenshotted. */
    var SECRET = /pass|secret|token|apikey|api_key|authorization|cookie|csrf/i;

    /**
     * The CSP nonce this script was allowed to run with, if there is one.
     *
     * The stylesheet is created here rather than emitted as markup, and a strict
     * `style-src` blocks an injected `<style>` just as it would an inline one —
     * an unstyled toolbar is a column of unreadable text at the bottom of the
     * page. Taken from the script element itself rather than from the data
     * island: the server already had to nonce this tag for it to be running at
     * all, so there is nothing extra to pass and nothing extra to leak.
     */
    var NONCE = (function () {
        try {
            var self = document.currentScript;
            return self ? (self.nonce || self.getAttribute('nonce') || '') : '';
        } catch (e) {
            return '';
        }
    }());

    /** Segment colours for the timeline (Catppuccin). */
    var PALETTE = ['#89b4fa', '#cba6f7', '#a6e3a1', '#f9e2af', '#fab387', '#f38ba8', '#94e2d5'];

    /**
     * Tab order, and which payload key feeds each.
     *
     * Fixed rather than derived from the payload's key order: a collector added
     * by an application would otherwise land wherever PHP happened to put it,
     * and the tab a reader wants must not move between requests.
     */
    var TABS = [
        { key: 'queries',    label: 'SQL',        title: 'SQL Database queries executed' },
        { key: 'timers',     label: 'Time',       title: 'Execution time profiling & breakdown' },
        { key: 'route',      label: 'Route',      title: 'Matched HTTP route & controller action' },
        { key: 'auth',       label: 'Auth',       title: 'Authenticated user identity & JWT state' },
        { key: 'gate',       label: 'Gate',       title: 'Authorization policy & permission checks' },
        { key: 'session',    label: 'Session',    title: 'Session data & CSRF tokens' },
        { key: 'logs',       label: 'Logs',       title: 'Application & PSR-3 server logs' },
        { key: 'views',      label: 'Views',      title: 'Rendered HTML templates & view variables' },
        { key: 'models',     label: 'Domain',     title: 'Domain model entities loaded' },
        { key: 'exceptions', label: 'Exceptions', title: 'Server-side PHP exceptions caught' },
        { key: 'errors',     label: 'Errors',     title: 'Browser-side JavaScript errors captured' },
        { key: 'client',     label: 'Client',     title: 'Browser environment & device details' },
        { key: 'api',        label: 'API',        title: 'Interactive API testing playground' },
        { key: 'migrations', label: 'Migrations', title: 'Database schema migrations' }
    ];

    /*
     * A `CLIENT_TABS = { errors: true, client: true, api: true }` lookup stood here and
     * nothing read it. The three tabs it named are genuinely special — they are drawn from
     * what this script observed rather than from a response payload, because there is no
     * payload key for an error raised in the browser three seconds after the response
     * arrived — but that knowledge is encoded as explicit `tab.key === …` checks in three
     * places below, not as a table lookup. Reported by ESLint the first time it ran here.
     *
     * Left as a note rather than wired up: making the constant the single source would mean
     * editing three behavioural branches in a 3744-line asset, which is a refactor and not
     * the addition of a linter.
     */

    /** @type {Array<Object>} One per request, oldest first. */
    var entries = [];

    /**
     * Errors raised in the browser, oldest first, deduplicated.
     *
     * @type {Array<{kind: string, message: string, stack: string, at: Date,
     *               times: number, during: ?Object}>}
     */
    var clientErrors = [];

    /**
     * How many distinct browser errors are kept.
     *
     * Small, because identical ones collapse into a count: a render loop that
     * throws does so thousands of times, and fifty *different* failures is
     * already far past the point where anybody is reading them one by one.
     */
    var ERROR_HISTORY = 50;

    /** Stack traces are truncated to this many characters before display. */
    var STACK_LIMIT = 4096;

    /**
     * Where the client-side router thinks it is, as it last said.
     *
     * Reported by the application's router rather than worked out here: the route
     * table is the application's, and guessing at it would produce a panel that
     * is confidently wrong about the one thing this tab exists to settle.
     *
     * @type {?{name: string, base: ?string, params: ?Object, at: Date}}
     */
    var clientRoute = null;

    /** A storage value longer than this is shown truncated. */
    var VALUE_LIMIT = 200;

    /**
     * The API playground's state.
     *
     * Kept here rather than in the DOM because the panel is re-rendered whenever
     * a request is recorded — including the request the playground itself just
     * made. State in the markup would be wiped by the very call the reader was
     * making.
     */
    var pg = {
        doc: null,        // the parsed OpenAPI document
        error: null,      // why it could not be loaded
        loading: false,
        ops: [],          // [{method, path, summary, body}]
        selected: -1,
        path: '',         // the path, with its parameters substituted in
        params: {},       // parameter name => the value the reader typed
        body: '',
        sendToken: true,
        sending: false,   // a call is in flight, and the panel says so
        result: null      // {status, statusText, ms, text, url}
    };

    /**
     * The framework route that hands back one request's log lines.
     *
     * Known here rather than announced by the server. It is the same path in
     * every installation, so putting it in every debug payload would be sending
     * the client something it could already work out — and a response should
     * carry what only it knows, not a constant.
     *
     * Whether the route *answers* is a different question, and one the answer
     * itself settles: an application with the DevPanel switched off replies 404,
     * the toolbar says so once and stops offering. Feature detection by use,
     * rather than by advertisement.
     */
    var LOGS_PATH = 'devpanel/logs';

    /** Set once the endpoint has refused, so it is not asked again. */
    var logsUnavailable = false;

    /**
     * Did this page come from the framework's MVC pipeline?
     *
     * True when a data island was found, which happens only for a page the
     * middleware rendered. A SPA shell never goes through it, and the parts of
     * the toolbar that link to server-rendered pages have nothing to link to.
     */
    var hasMvcPage = false;

    /** @type {Object<string, Array>} Server-side log lines, by request id. */
    var serverLogs = {};

    /** @type {Object<string, boolean>} Requests whose log fetch is in flight. */
    var fetching = {};

    /** The unwrapped fetch, kept so the toolbar's own calls are not recorded. */
    var rawFetch = null;

    var selected = -1;          // entry whose tabs are shown; -1 = none yet
    var userPicked = false;     // has the reader chosen a request themselves?
    var activeTab = null;       // 'requests' or a payload key; null = panel closed
    var tabRestored = false;    // whether the previous page's tab has been reopened yet
    var openCategory = null;     // category key of open dropdown menu, or null
    var devPanelEnabled = false;
    var devPanelCustomUrl = null;
    var adminerUrl = null;
    var root = null;
    var tabsEl = null;
    var panelEl = null;
    var infoEl = null;
    var handleEl = null;
    var gripEl = null;
    var styleEl = null;

    // ── Escaping ────────────────────────────────────────────────────────────

    /** Escape for use as text. */
    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /** Escape for use inside a double-quoted attribute. */
    function escAttr(value) {
        return esc(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // ── Payload readers ─────────────────────────────────────────────────────

    /**
     * Server time for an entry.
     *
     * `payload.request.time` is the reliable copy: the top-level `time` and
     * `memory` keys are overwritten by any collector registered under the same
     * name, and one is — MemoryCollector, whose data is an object. Reading the
     * top-level key printed "[object Object]MB".
     */
    function serverMs(entry) {
        var p = entry.payload || {};
        if (p.request && typeof p.request.time === 'number') {
            return p.request.time;
        }
        return typeof p.time === 'number' ? p.time : null;
    }

    /** Peak memory in MB, from the copy no collector can overwrite. */
    function memoryMb(entry) {
        var p = entry.payload || {};
        return p.request && typeof p.request.memory === 'number' ? p.request.memory : null;
    }

    /** The statements an entry carries, however the collector labelled them. */
    function queriesOf(entry) {
        var q = (entry.payload || {}).queries;
        if (!q) {
            return [];
        }
        return q.queries || q.statements || [];
    }

    /** How many statements ran. */
    function queryCount(entry) {
        var q = (entry.payload || {}).queries;
        if (!q) {
            return null;
        }
        return typeof q.count === 'number' ? q.count : queriesOf(entry).length;
    }

    /**
     * Whatever the caller passed as a request body, as text.
     *
     * Read synchronously and without consuming anything: a string is already
     * text, URLSearchParams and FormData can be walked, and everything else is
     * described rather than decoded — reading a Blob is asynchronous, and the
     * object belongs to the application.
     *
     * The body never leaves the browser. It is what this page just sent, shown
     * back to the person who sent it; nothing is added to the request and
     * nothing is transmitted anywhere.
     */
    function captureBody(body) {
        try {
            if (body === null || body === undefined) {
                return null;
            }
            if (typeof body === 'string') {
                return body.length > BODY_LIMIT
                    ? body.slice(0, BODY_LIMIT) + '\n… truncated'
                    : body;
            }
            if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
                return body.toString();
            }
            if (typeof FormData !== 'undefined' && body instanceof FormData) {
                var parts = [];
                body.forEach(function (value, key) {
                    parts.push(key + '=' + (typeof value === 'string' ? value : '[file]'));
                });
                return parts.join('&');
            }
            if (typeof Blob !== 'undefined' && body instanceof Blob) {
                return '[Blob, ' + body.size + ' bytes]';
            }
            if (body.byteLength !== undefined && body.byteLength !== null) {
                return '[binary, ' + body.byteLength + ' bytes]';
            }
            return null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Turn a form-urlencoded body into the structure it encodes.
     *
     * `columns%5B0%5D%5Bdata%5D=0` is a nested value written flat and then
     * percent-escaped. A datatables request is fifty of those, and as raw text
     * it is unreadable — which is the same as not being shown.
     */
    function decodeForm(text) {
        var out = {};

        String(text).split('&').forEach(function (pair) {
            if (pair === '') {
                return;
            }

            var eq = pair.indexOf('=');
            var rawKey = eq < 0 ? pair : pair.slice(0, eq);
            var rawVal = eq < 0 ? '' : pair.slice(eq + 1);
            var key;
            var value;

            try {
                key = decodeURIComponent(rawKey.replace(/\+/g, ' '));
            } catch (e) {
                key = rawKey;
            }
            try {
                value = decodeURIComponent(rawVal.replace(/\+/g, ' '));
            } catch (e) {
                value = rawVal;
            }

            // name[a][b] → ['name', 'a', 'b']
            var path = [];
            var head = /^([^[]*)/.exec(key);
            if (head) {
                path.push(head[1]);
            }
            var re = /\[([^\]]*)\]/g;
            var part;
            while ((part = re.exec(key)) !== null) {
                path.push(part[1]);
            }

            var node = out;
            for (var i = 0; i < path.length; i++) {
                var seg = path[i] === '' ? String(Object.keys(node).length) : path[i];
                if (i === path.length - 1) {
                    node[seg] = value;
                } else {
                    if (typeof node[seg] !== 'object' || node[seg] === null) {
                        node[seg] = {};
                    }
                    node = node[seg];
                }
            }
        });

        return out;
    }

    /** Pretty-print JSON and form bodies; leave anything else as it is. */
    function formatBody(text) {
        if (String(text).length > BODY_FORMAT_LIMIT) {
            // Too big to lay out on a render path. Masked, because that is not
            // optional, and shown as it was sent.
            return maskFlat(text);
        }

        try {
            var trimmed = String(text).trim();
            if (trimmed.charAt(0) === '{' || trimmed.charAt(0) === '[') {
                return JSON.stringify(mask(JSON.parse(trimmed)), null, 2);
            }
            // A form body: has pairs, and does not start like JSON.
            if (trimmed.indexOf('=') > -1) {
                return JSON.stringify(mask(decodeForm(trimmed)), null, 2);
            }
        } catch (e) {
            /* a body that will not parse is not a body that can be laid out */
        }

        return maskFlat(text);
    }

    /**
     * Mask secret-looking values in text that could not be parsed.
     *
     * The structured path goes through {@see mask}, which works on keys. This is
     * the fallback for a raw string, and it is deliberately simple: a JSON
     * string value, and a query-string value. A masker nobody can read is a
     * masker nobody trusts.
     */
    function maskFlat(text) {
        try {
            return String(text)
                .replace(/("[^"]*(?:pass|secret|token|apikey|api_key|authorization|cvv)[^"]*"\s*:\s*)"[^"]*"/gi, '$1"***"')
                .replace(/([?&][^=&]*(?:pass|secret|token|apikey|api_key|authorization|cvv)[^=&]*=)[^&]*/gi, '$1***');
        } catch (e) {
            return text;
        }
    }

    /** A human size for the collapsed summary line. */
    function sizeOf(text) {
        var n = String(text).length;
        return n < 1024 ? (n + ' B') : ((n / 1024).toFixed(1) + ' KB');
    }

    /** Local wall clock to the millisecond, to line up against a server log. */
    function clockTime(date) {
        function pad(n, w) {
            return String(n).padStart(w, '0');
        }
        return pad(date.getHours(), 2) + ':' + pad(date.getMinutes(), 2) + ':'
            + pad(date.getSeconds(), 2) + '.' + pad(date.getMilliseconds(), 3);
    }

    /** Replace secret-looking values, at any depth. */
    function mask(value) {
        if (Array.isArray(value)) {
            return value.map(mask);
        }
        if (value && typeof value === 'object') {
            var out = {};
            Object.keys(value).forEach(function (key) {
                out[key] = SECRET.test(key) ? '***' : mask(value[key]);
            });
            return out;
        }
        return value;
    }

    // ── Recording ───────────────────────────────────────────────────────────

    /**
     * Note one request.
     *
     * A payload is what proves the toolbar is meant to exist: the server only
     * attaches one in development. Once one has arrived, later requests are
     * recorded whether or not they carry their own — a 204 from a save has no
     * body to put a payload in, and it is exactly the call somebody wants to
     * see. In production none ever arrives, so nothing is recorded, the DOM is
     * never touched, and no toolbar appears.
     *
     * @param {string}      method
     * @param {string}      path
     * @param {number}      status
     * @param {Object|null} payload  The `_debug` data, or null
     * @param {Object}      [extra]  { ms, body, kind }
     */
    function record(method, path, status, payload, extra) {
        try {
            extra = extra || {};
            if (!payload && entries.length === 0) {
                return;
            }

            entries.push({
                kind: extra.kind || 'xhr',
                method: method,
                path: path,
                status: status,
                payload: payload || null,
                at: new Date(),
                ms: typeof extra.ms === 'number' ? extra.ms : null,
                body: extra.body === undefined ? null : extra.body
            });
            if (entries.length > HISTORY) {
                entries.shift();
                if (selected > 0) {
                    selected--;
                }
            }

            if (!userPicked) {
                selected = defaultSelection();
            }

            ensureBar();

            // Reopen whatever was open before this page. Once per page load: after that the
            // developer's clicks own the state, and re-applying it on every recorded XHR
            // would fight them.
            if (!tabRestored) {
                tabRestored = true;
                activeTab = restoreTab(entries[entries.length - 1]);
            }

            render();
        } catch (e) {
            /* instrumentation never breaks the page */
        }
    }

    /**
     * Note something the browser threw.
     *
     * Public, because the interesting failures are the ones somebody caught. An
     * `ApiError` a screen handles properly never reaches `unhandledrejection`,
     * and a component failure caught by `<svelte:boundary>` never reaches
     * `window.onerror` — both are exactly what a reader is looking for when a
     * screen shows an error message and the network tab looks fine. So the
     * automatic handlers below catch what nobody caught, and this is how
     * application code hands over what it did catch.
     *
     * Collected even when there is no toolbar on screen yet: in a SPA the bar
     * appears with the first response that carries debug data, and an error
     * thrown before that is often the reason there wasn't one. Nothing is drawn
     * until the bar exists, so in production this stays a short array in memory
     * and never touches the DOM.
     *
     * @param {Error|string} error     The failure, or a message
     * @param {Object}       [context] { kind, request } — `kind` labels the row,
     *                                 `request` overrides the correlated request
     */
    function reportError(error, context) {
        try {
            context = context || {};

            var message = error && error.message ? String(error.message) : String(error);
            var stack = error && error.stack ? String(error.stack).slice(0, STACK_LIMIT) : '';
            var kind = context.kind || (error && error.name ? String(error.name) : 'error');
            var status = error && typeof error.status === 'number' ? error.status : null;

            // The same failure twice is one finding with a counter, not two rows.
            // Identity is the label plus the message plus where it came from: a
            // stack is often absent (a cross-origin script reports none at all),
            // so it cannot be part of the key.
            var key = kind + '\n' + message;
            var seen = null;
            clientErrors.forEach(function (item) {
                if (item.key === key) {
                    seen = item;
                }
            });

            if (seen) {
                seen.times++;
                seen.at = new Date();
            } else {
                clientErrors.push({
                    key: key,
                    kind: kind,
                    message: message,
                    stack: stack,
                    status: status,
                    at: new Date(),
                    times: 1,
                    during: context.request || duringRequest()
                });
                if (clientErrors.length > ERROR_HISTORY) {
                    clientErrors.shift();
                }
            }

            // Only if there is already a bar. Creating one because the page threw
            // would put a toolbar on a production site.
            if (root) {
                render();
            }
        } catch (e) {
            /* the error reporter is the last thing that may throw */
        }
    }

    /**
     * The request an error most likely belongs to: the most recent one.
     *
     * A heuristic, and labelled as one in the panel ("after"). Correlating
     * properly would mean the browser knowing which fetch a stack frame came
     * from, which it does not — but "the call that had just come back" is right
     * nearly every time, because that is what the code that threw was reacting
     * to. An explicit `request` in the context beats it whenever the caller
     * knows better, which an API client does.
     */
    function duringRequest() {
        var entry = entries[entries.length - 1];
        if (!entry) {
            return null;
        }
        return {
            method: entry.method,
            path: entry.path,
            status: entry.status,
            id: requestIdOf(entry)
        };
    }

    /**
     * Listen for what nobody caught.
     *
     * Both listeners are passive — they never call `preventDefault()`, so the
     * browser still logs to the console and any other handler still runs. A
     * debug panel that swallowed errors would be worse than one that missed them.
     */
    function watchForErrors() {
        if (typeof window.addEventListener !== 'function') {
            return;
        }

        window.addEventListener('error', function (event) {
            // A failed <img> or <script> fires this too, with no `error` object.
            // Worth showing — a 404 on a bundle is a real finding — but it has to
            // be described rather than read off an Error that is not there.
            if (event && event.error) {
                reportError(event.error, { kind: 'uncaught' });
                return;
            }
            if (event && event.message) {
                reportError(event.message, { kind: 'uncaught' });
            }
        });

        window.addEventListener('unhandledrejection', function (event) {
            var reason = event && event.reason !== undefined ? event.reason : 'unknown';
            reportError(reason, { kind: 'unhandled rejection' });
        });
    }

    /**
     * Note where the client-side router has arrived.
     *
     * Called by the application's router on every navigation. Optional: with no
     * router reporting, the Client tab still shows the URL and the configuration,
     * which is most of the answer — the route *name* is the part only the
     * application knows.
     *
     * @param {string} name      The route the application resolved to
     * @param {Object} [detail]  { base, params }
     */
    function reportRoute(name, detail) {
        try {
            detail = detail || {};
            clientRoute = {
                name: String(name),
                base: detail.base === undefined ? null : detail.base,
                params: detail.params || null,
                at: new Date()
            };
            if (root && activeTab === 'client') {
                render();
            }
        } catch (e) {
            /* a router that cannot be observed still has to route */
        }
    }

    /**
     * The two tabs that read as a stream over the page, not as one request's state.
     *
     * A log line and an exception happen *at a moment*; which request produced
     * one is a detail of it, not its identity. Route, Session, SQL and the rest
     * describe a request — adding those up across three calls would produce a
     * table that is true of nothing.
     *
     * Key → the array in that collector's payload.
     */
    var STREAMS = { logs: 'entries', exceptions: 'items' };

    /**
     * Tabs that describe *now* rather than one request.
     *
     * Auth is the odd one: it is not a stream, but it is not a property of a
     * request either. Who you are is a state, and it changes — that is the whole
     * point of a login. Reported from the request that happened to be selected,
     * the tab said "anonymous" for as long as the selection stayed on the call
     * made before signing in, and only a page refresh appeared to fix it. That
     * is the bug this list exists to prevent.
     */
    var STATE_TABS = { auth: true };

    /**
     * Show the streams across every request?
     *
     * Only until the reader picks a request. From that point every tab, these
     * two included, describes the request they chose — asking for one request
     * and being shown the page's total is the confusion this whole selection
     * model exists to avoid.
     */
    function aggregating() {
        if (entries.length < 2) {
            return false;
        }
        // Also when the request in view brought nothing back. A call that died
        // carries no payload — and it is the one somebody clicked *because* it
        // went wrong. Emptying the bar down to a single tab at that moment is
        // the opposite of what it is for; the page's other requests are the only
        // place the reason can still be.
        return !userPicked || !(entries[selected] && entries[selected].payload);
    }

    /**
     * Every item of one stream, across all requests, each tagged with its source.
     *
     * @returns {Array<{item: Object, from: Object}>}
     */
    function streamAcross(key) {
        var field = STREAMS[key];
        var out = [];
        entries.forEach(function (e) {
            var data = e.payload ? e.payload[key] : null;
            if (!data || data.error) {
                return;
            }

            var items = data[field] || [];
            items.forEach(function (item) {
                out.push({ item: item, from: e });
            });

            // A count with no items is a request that only got its summary
            // across — an error page cannot carry a `_debug` key, and a header
            // never carries messages. Counting it as nothing would hide the one
            // request that failed behind a tab reading "Exceptions 0", which is
            // the report this fixes.
            var missing = (typeof data.count === 'number' ? data.count : 0) - items.length;
            if (missing <= 0) {
                return;
            }

            // Once the server's own log has been fetched for this request, its
            // error lines *are* the detail that was missing — so they take the
            // placeholder's place, on the row belonging to the request that
            // raised them. Reading "1 raised, details elsewhere" while the
            // details sit in a table further down is a puzzle nobody needs.
            var fromServer = serverLogs[requestIdOf(e)];
            if (fromServer) {
                var errors = fromServer.filter(function (line) {
                    return ['error', 'critical', 'alert', 'emergency'].indexOf(line.level) > -1;
                });
                if (errors.length) {
                    errors.forEach(function (line) {
                        out.push({
                            from: e,
                            item: {
                                type: 'server',
                                class: '',
                                // Logged messages carry their stack trace after
                                // the first line; the row shows the sentence and
                                // the full text stays in the server-log table.
                                message: String(line.message || '').split('\\n')[0],
                                file: line.file || '',
                                line: 0
                            }
                        });
                    });
                    return;
                }
            }

            out.push({
                from: e,
                item: {
                    type: 'summary',
                    class: '',
                    message: missing + ' raised — this response could not carry '
                        + 'the details; the application error log has them',
                    file: '',
                    line: 0
                }
            });
        });
        return out;
    }

    /** One stream, drawn with the request each item came from. */
    function renderStream(key, entry) {
        var all = streamAcross(key);
        var rows = '';
        all.forEach(function (row) {
            rows += key === 'logs' ? logRow(row.item, row.from) : exceptionRow(row.item, row.from);
        });

        var lead = '<p class="pdb-muted">Everything this page has logged, across '
            + entries.length + ' requests. Pick a request to see only its own.</p>';

        // The offer to fetch the server's own log belongs here too. It was only
        // drawn for a picked request, which is the state somebody reaches after
        // they already know what they are looking for — and the default view is
        // where they start.
        return lead
            + (key === 'logs' ? logTable(rows, true) : exceptionTable(rows, true))
            + serverLogSection(entry);
    }

    /**
     * The request to show when the reader has not chosen one.
     *
     * The page's own request, if this page has one. Everything else on the page
     * is a consequence of it, and it is what somebody has in mind when they look
     * at the bar — a datatable that fetches its rows the moment it renders used
     * to move the tabs onto that JSON call, so a page that rendered a template
     * reported `Views 0` and the number was true of a request nobody had asked
     * about.
     *
     * With no page entry — a SPA, whose shell never went through the middleware
     * — the newest request is the answer, and following it is the whole point.
     */
    function defaultSelection() {
        for (var i = 0; i < entries.length; i++) {
            if (entries[i].kind === 'page') {
                return i;
            }
        }
        return entries.length - 1;
    }

    // ── Chrome ──────────────────────────────────────────────────────────────

    /** The stylesheet, injected once. */
    function css() {
        return ''
        + '#pramnos-debugbar{position:fixed;bottom:0;left:0;right:0;z-index:2147483000;'
        + 'font:12px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;color:#cdd6f4;'
        + 'background:#1e1e2e;border-top:2px solid #89b4fa;box-sizing:border-box}'
        + '#pramnos-debugbar *{box-sizing:border-box}'
        + '#pdb-bar{display:flex;align-items:center;justify-content:space-between;padding:0 8px;height:28px;gap:6px;'
        + 'overflow:hidden;white-space:nowrap}'
        + '#pdb-left{display:flex;align-items:center;gap:4px;flex:1;min-width:0;overflow:hidden}'
        + '#pdb-brand{color:#89b4fa;font-weight:bold;margin-right:4px;flex-shrink:0}'
        + '#pdb-tabs-wrap{display:flex;align-items:center;flex:1;min-width:0;overflow-x:auto;scrollbar-width:thin;scrollbar-color:#45475a transparent}'
        + '#pdb-tabs-wrap::-webkit-scrollbar{height:3px}'
        + '#pdb-tabs-wrap::-webkit-scrollbar-thumb{background:#45475a;border-radius:2px}'
        + '#pdb-tabs{display:flex;align-items:center;gap:3px;white-space:nowrap}'
        + '#pdb-right{display:flex;align-items:center;gap:4px;flex-shrink:0;margin-left:auto}'
        + '.pdb-tab{background:none;border:none;color:#cdd6f4;cursor:pointer;padding:2px 8px;'
        + 'border-radius:4px;font:inherit;flex-shrink:0}'
        + '.pdb-tab:hover,.pdb-tab.pdb-active{background:#313244;color:#89b4fa}'
        + '.pdb-tab-count{background:#45475a;border-radius:8px;padding:0 5px;margin-left:4px;font-size:10px}'
        // The one tab that must be readable as "look here" from across the bar,
        // including while another tab is the active one.
        + '.pdb-tab-alert{color:#f38ba8;font-weight:bold}'
        + '.pdb-tab-alert:hover,.pdb-tab-alert.pdb-active{color:#f38ba8;background:#45303a}'
        + '.pdb-tab-alert .pdb-tab-count{background:#f38ba8;color:#11111b;font-weight:bold}'
        + '#pdb-info{display:flex;gap:4px;align-items:center;flex-shrink:0}'
        + '.pdb-more-wrap{position:relative;display:inline-block}'
        + '.pdb-more-btn{background:none;border:1px solid #45475a;color:#cdd6f4;cursor:pointer;padding:2px 6px;border-radius:4px;font:inherit;flex-shrink:0}'
        + '.pdb-more-btn:hover,.pdb-more-btn.pdb-active{background:#313244;color:#89b4fa;border-color:#89b4fa}'
        + '.pdb-more-menu{position:fixed;z-index:2147483005;background:#1e1e2e;border:1px solid #45475a;border-radius:6px;padding:4px;display:flex;flex-direction:column;gap:2px;box-shadow:0 4px 14px rgba(0,0,0,0.6);min-width:140px}'
        + '.pdb-more-menu[hidden]{display:none!important}'
        + '.pdb-more-menu .pdb-tab{text-align:left;width:100%;display:flex;justify-content:space-between;align-items:center;padding:4px 8px;border-radius:3px}'
        + '@media(max-width:900px){#pdb-brand{font-size:11px;margin-right:2px}.pdb-tab{padding:2px 5px;font-size:11px}.pdb-devpanel{padding:2px 4px;font-size:11px}#pdb-info{gap:3px}}'
        + '@media(max-width:600px){#pdb-brand{display:none}.pdb-devpanel{display:none}}'
        + '.pdb-chip{font-size:10px;color:#6c7086;padding:1px 6px;background:#313244;border-radius:3px}'
        + '.pdb-fetch-logs{background:#313244;border:1px solid #45475a;color:#89b4fa;cursor:pointer;'
        + 'font:inherit;font-size:11px;padding:2px 8px;border-radius:4px}'
        + '.pdb-fetch-logs:hover{border-color:#89b4fa}'
        + '.pdb-fetch-logs[disabled]{color:#6c7086;cursor:default}'
        + 'button.pdb-unpick{border:1px solid #45475a;font:inherit;font-size:10px;cursor:pointer;color:#cdd6f4}'
        + 'button.pdb-unpick:hover{color:#f38ba8;border-color:#f38ba8}'
        + '.pdb-devpanel{color:#a6e3a1;text-decoration:none;padding:2px 8px;font:inherit;flex-shrink:0;margin-left:6px}'
        + '.pdb-devpanel:hover{color:#cba6f7}'
        + '.pdb-help{color:#89b4fa;text-decoration:none;font:inherit;font-weight:bold;'
        + 'padding:2px 7px;flex-shrink:0;margin-left:4px}'
        + '.pdb-help:hover{color:#cba6f7}'
        + '.pdb-close{background:none;border:none;color:#f38ba8;cursor:pointer;margin-left:4px;'
        + 'font:inherit;flex-shrink:0}'
        + '#pdb-restore{position:fixed;right:8px;bottom:8px;z-index:2147483000;display:none;'
        + 'background:#1e1e2e;color:#89b4fa;border:1px solid #313244;border-radius:6px;padding:2px 7px;'
        + 'cursor:pointer;font:12px/1.4 ui-monospace,monospace;box-shadow:0 2px 6px rgba(0,0,0,.4)}'
        + '#pdb-restore:hover{color:#cba6f7;border-color:#cba6f7}'
        + '#pdb-panel{height:320px;overflow:auto;padding:8px 12px;background:#181825;'
        + 'border-top:1px solid #313244;display:none}'
        + '#pdb-grip{display:none;height:6px;cursor:ns-resize;background:#313244}'
        + '#pdb-grip:hover{background:#89b4fa}'
        + '#pdb-grip.pdb-dragging{background:#cba6f7}'
        // A type scale, because without one the panel had none: an explanation, a
        // section heading and a table cell were all 12px, so the prose shouted as
        // loudly as the data it was describing. Reported from a screenshot.
        + '#pdb-panel{font-size:11px;line-height:1.45}'
        + '#pdb-panel p{margin:0 0 6px;max-width:104ch}'
        // Prose is quieter than data, and capped in measure: a 2560px monitor
        // otherwise gives a 300-character line, which nobody reads twice.
        + '#pdb-panel p.pdb-muted{font-size:10px;line-height:1.5;color:#7f849c}'
        + '.pdb-h{margin:10px 0 4px;font-size:11px;font-weight:bold;color:#89b4fa;'
        + 'letter-spacing:.02em}'
        + '.pdb-h:first-child{margin-top:0}'
        // The one line that says what a tab is for, set apart from both.
        + '.pdb-lead{font-size:10px;color:#9399b2;margin:0 0 8px;max-width:104ch}'
        + '.pdb-table{width:100%;border-collapse:collapse;font-size:11px}'
        + '.pdb-table th{background:#313244;padding:4px 8px;text-align:left;color:#89b4fa}'
        + '.pdb-table td{padding:3px 8px;border-bottom:1px solid #1e1e2e;vertical-align:top}'
        + '.pdb-row{cursor:pointer}'
        + '.pdb-row:hover td{background:#1e1e2e}'
        + '.pdb-row.pdb-selected td{background:#313244}'
        // A failed request is found by scanning the list, so the whole row
        // carries the colour — a red cell in the narrowest column of six is a
        // signal placed where nobody is looking.
        + '.pdb-row-bad td{color:#f38ba8;background:#2a1e26}'
        + '.pdb-row-bad:hover td{background:#3a2530}'
        + '.pdb-row-bad.pdb-selected td{background:#45303a}'
        + '.pdb-row-bad td.pdb-muted,.pdb-row-bad td.pdb-time{color:#f38ba8}'
        + '.pdb-time{white-space:nowrap;color:#a6e3a1;min-width:50px}'
        + '.pdb-slow .pdb-time{color:#f38ba8}'
        + '.pdb-cached{color:#a6e3a1!important;font-size:9px;letter-spacing:.05em;font-weight:bold}'
        + '.pdb-sql{word-break:break-all}'
        + '.pdb-copy{background:none;border:1px solid #45475a;color:#6c7086;cursor:pointer;'
        + 'font:10px ui-monospace,monospace;padding:0 3px;border-radius:2px;line-height:14px;'
        + 'vertical-align:middle}'
        + '.pdb-copy:hover{background:#313244;color:#cba6f7;border-color:#cba6f7}'
        + '.pdb-copy.pdb-copied{color:#a6e3a1;border-color:#a6e3a1}'
        + '.pdb-copy-all{color:#cba6f7;padding:1px 6px;margin-left:6px}'
        // An action button, not a copy button. They looked identical and were
        // therefore written with the same class — which put them behind the
        // copy handler, so clicking one copied nothing and did nothing.
        + '.pdb-btn{background:none;border:1px solid #45475a;color:#cdd6f4;cursor:pointer;'
        + 'font:10px ui-monospace,monospace;padding:0 5px;border-radius:2px;line-height:15px;'
        + 'vertical-align:middle}'
        + '.pdb-btn:hover{background:#313244;color:#cba6f7;border-color:#cba6f7}'
        + '.pdb-btn-primary{color:#a6e3a1;border-color:#a6e3a1;padding:1px 8px}'
        + '.pdb-btn-primary:hover{background:#313244;color:#cba6f7;border-color:#cba6f7}'
        + '.pdb-dl{display:grid;grid-template-columns:auto 1fr;gap:2px 12px;margin:0}'
        + '.pdb-dl dt{color:#6c7086}'
        + '.pdb-dl dd{margin:0}'
        + '.pdb-timeline{position:relative;height:16px;background:#11111b;border-radius:3px;overflow:hidden}'
        + '.pdb-tl-seg{position:absolute;top:0;height:16px;font-size:9px;line-height:16px;'
        + 'text-align:center;color:#11111b;overflow:hidden}'
        + '.pdb-s-2{color:#a6e3a1}.pdb-s-3{color:#89b4fa}.pdb-s-4{color:#fab387}'
        + '.pdb-s-5{color:#f38ba8}.pdb-s-0{color:#f38ba8}'
        + '.pdb-level-error,.pdb-level-critical{color:#f38ba8}'
        // The one green in this palette, already used by pdb-cached and the primary button.
        + '.pdb-ok{color:#a6e3a1}'
        + '.pdb-level-warning{color:#fab387}'
        + '.pdb-pre{margin:2px 0 0;white-space:pre-wrap;word-break:break-all;background:#1e1e2e;'
        + 'padding:6px;border-radius:3px;max-height:240px;overflow:auto}'
        + '.pdb-muted{color:#6c7086}'
        + '.pdb-id-copy{opacity:0;transition:opacity .1s}'
        + '.pdb-row:hover .pdb-id-copy{opacity:1}'
        + '.pdb-requests td.pdb-wf-cell{width:38%;min-width:120px;padding:3px 8px}'
        + '.pdb-wf-track{position:relative;display:block;width:100%;height:8px;background:#181825;'
        + 'border-radius:2px}'
        + '.pdb-wf-bar{position:absolute;top:0;height:100%;border-radius:2px;min-width:2px}'
        + '.pdb-wf-mark{opacity:.5}'
        + '.pdb-split{display:flex;height:14px;border-radius:3px;overflow:hidden;background:#45475a;margin:4px 0 0}'
        + '.pdb-split-server{background:#89b4fa}'
        + '.pdb-split-away{background:#45475a;flex:1}'
        + '.pdb-pg-input{background:#11111b;color:#cdd6f4;border:1px solid #45475a;'
        + 'border-radius:3px;font:inherit;padding:2px 5px;width:60%;max-width:100%}'
        + 'textarea.pdb-pg-input{width:100%;margin-top:4px;resize:vertical}'
        + '.pdb-pg-input:focus{outline:none;border-color:#89b4fa}'
        + '.pdb-body{margin:0 0 8px}'
        + '.pdb-body>summary{cursor:pointer;color:#89b4fa;font-size:11px}'
        + '.pdb-body>.pdb-pre{margin:2px 0 0;white-space:pre-wrap;word-break:break-all;'
        + 'background:#11111b;padding:6px;border-radius:3px;max-height:260px;overflow:auto}';
    }

    /**
     * The DevPanel link, when there is a DevPanel to link to.
     *
     * The DevPanel is a server-rendered page behind MVC routing — a controller,
     * a layout, an admin session. A SPA has none of that: its shell is a static
     * file, its server speaks JSON, and `/devpanel` is a 404 there. The link was
     * drawn in both deliveries on the assumption that a framework route exists
     * wherever the framework does, which is not true of a project that never
     * boots the MVC stack.
     *
     * The data island is the test, and it is exact rather than a guess: an
     * island exists only because a page went through the middleware that emits
     * it, and that middleware is the MVC pipeline the DevPanel lives in. No
     * island, no page — so no link, rather than a link to nothing.
     */
    function devPanelLink() {
        if (!hasMvcPage || !devPanelEnabled) {
            return '';
        }

        return '<a class="pdb-devpanel" href="' + escAttr(devPanelUrl())
            + '" title="DevPanel">&#128270; DevPanel</a>' + adminerLink();
    }

    /**
     * The database tool, beside the DevPanel link.
     *
     * The toolbar is the one thing a developer already has open on the page, so this is where
     * the link belongs. Drawn only when the server said so: the payload carries a URL when the
     * package is installed *and* the account would be served, and null otherwise — a link that
     * answers 404 reads as a broken tool rather than an absent one.
     */
    function adminerLink() {
        if (!adminerUrl) {
            return '';
        }

        return '<a class="pdb-devpanel" href="' + escAttr(adminerUrl)
            + '" title="Adminer — the database, behind this site\'s own gate">'
            + '&#128451; Adminer</a>';
    }

    /**
     * Where the toolbar's own instructions are.
     *
     * The published documentation site, not anything local. This script ships inside
     * `vendor/`, so a relative link would point at a file the project does not have —
     * and the docs are the framework's, not per-installation.
     *
     * Unconditional, unlike the DevPanel link: there is no delivery in which the page
     * explaining the toolbar does not exist.
     */
    var HELP_URL = 'https://mrpc.github.io/PramnosFramework/Pramnos_Debug_Toolbar_Usage/';

    /**
     * The `?` that opens the usage guide in a new tab.
     *
     * A toolbar is where somebody is standing when they need to be told what it does,
     * and the alternative was knowing that a documentation site exists and which page
     * of it to look for.
     */
    function helpLink() {
        return '<a class="pdb-help" href="' + escAttr(HELP_URL) + '" '
            + 'target="_blank" rel="noopener noreferrer" '
            + 'title="How to use this toolbar (opens the documentation)">?</a>';
    }

    /** Where the DevPanel lives, resolved the same way the log endpoint is. */
    function devPanelUrl() {
        if (devPanelCustomUrl) {
            return devPanelCustomUrl;
        }
        try {
            return new URL('devpanel', document.baseURI || location.origin).toString();
        } catch (e) {
            return '/devpanel';
        }
    }

    /** Build the bar the first time there is something to show. */
    function ensureBar() {
        if (root) {
            return;
        }

        styleEl = document.createElement('style');
        styleEl.textContent = css();
        if (NONCE && styleEl.setAttribute) {
            // Both the property and the attribute: browsers hide the attribute
            // from script after parse, and only the property is read back — but
            // the attribute is what the policy is checked against.
            styleEl.setAttribute('nonce', NONCE);
            styleEl.nonce = NONCE;
        }

        root = document.createElement('div');
        root.id = 'pramnos-debugbar';
        root.innerHTML = '<div id="pdb-grip" title="Drag to resize the panel"></div>'
            + '<div id="pdb-bar">'
            + '<div id="pdb-left"><span id="pdb-brand">&#9881; Pramnos</span>'
            + '<div id="pdb-tabs-wrap"><span id="pdb-tabs"></span></div></div>'
            + '<div id="pdb-right"><span id="pdb-info"></span>'
            + devPanelLink()
            + helpLink()
            + '<button class="pdb-close" id="pdb-close-btn" title="Hide the toolbar">&#x2715;</button></div></div>'
            + '<div id="pdb-panel"></div>';

        // Hiding the bar has to leave something to bring it back, and it has to
        // live outside the bar: nested inside, hiding the bar would hide the only
        // way back.
        handleEl = document.createElement('button');
        handleEl.id = 'pdb-restore';
        handleEl.title = 'Show the Pramnos toolbar';
        handleEl.textContent = '⚙';
        handleEl.addEventListener('click', function () {
            setHidden(false);
        });

        document.head.appendChild(styleEl);
        document.body.appendChild(root);
        document.body.appendChild(handleEl);

        tabsEl = root.querySelector('#pdb-tabs');
        panelEl = root.querySelector('#pdb-panel');
        infoEl = root.querySelector('#pdb-info');
        gripEl = root.querySelector('#pdb-grip');

        installResize();
        applyStoredHeight();

        // One delegated listener: rows and buttons are redrawn constantly, and
        // per-element handlers would leak with them.
        root.addEventListener('click', onClick);

        setHidden(isHiddenStored());
    }

    /**
     * Remember which tab is open, or that none is.
     *
     * `sessionStorage`, not `localStorage`: this is "where I was a moment ago", and it should
     * end with the tab rather than greet somebody a week later with a panel they opened once.
     * The hidden flag is the opposite — that is a preference, and it stays.
     */
    function rememberTab() {
        try {
            if (activeTab === null) {
                sessionStorage.removeItem(TAB_KEY);
            } else {
                sessionStorage.setItem(TAB_KEY, activeTab);
            }
        } catch (e) {
            /* see isHiddenStored — storage throws outright in some modes */
        }
    }

    /**
     * The tab that was open on the previous page, if it is still a tab.
     *
     * Validated against what this response actually has: a payload without a `queries` key
     * would otherwise render an empty panel with no tab highlighted, which reads as a broken
     * bar rather than as a tab that does not apply here.
     */
    function restoreTab(entry) {
        var stored;

        try {
            stored = sessionStorage.getItem(TAB_KEY);
        } catch (e) {
            return null;
        }

        if (!stored) {
            return null;
        }

        if (stored === 'requests' || stored === 'client') {
            return stored;
        }

        var data = entry && entry.payload ? entry.payload : null;

        return data && Object.prototype.hasOwnProperty.call(data, stored) ? stored : null;
    }

    /** Whether the bar was hidden on a previous page. */
    function isHiddenStored() {
        try {
            return localStorage.getItem(HIDDEN_KEY) === '1';
        } catch (e) {
            // Reading storage throws outright in Safari's private mode and on a
            // blocked origin. Not remembering is not a reason to fail.
            return false;
        }
    }

    /**
     * Show or hide the whole bar, and remember which.
     *
     * The page's bottom padding is released with the bar, by the same code path
     * that hides it — when those were separate, a hidden toolbar left a 36px gap
     * that nothing on screen explained.
     */
    function setHidden(hidden) {
        if (!root) {
            return;
        }

        root.style.display = hidden ? 'none' : '';
        if (handleEl) {
            handleEl.style.display = hidden ? 'block' : 'none';
        }
        document.body.style.paddingBottom = hidden ? '' : '30px';

        try {
            if (hidden) {
                localStorage.setItem(HIDDEN_KEY, '1');
            } else {
                localStorage.removeItem(HIDDEN_KEY);
            }
        } catch (e) {
            /* see isHiddenStored */
        }
    }

    /**
     * Let the panel be resized by dragging its top edge.
     *
     * 320px suits a SQL tab with four queries in it and is too short for a
     * waterfall, a stack trace or a JSON response — and the panel is where all of
     * those are read. The height is remembered like the hidden flag, because
     * re-dragging it on every page load would be worse than the fixed height it
     * replaced.
     *
     * Listeners go on the document rather than the grip: a pointer that leaves the
     * 6px strip mid-drag must not end the drag, which is what makes a hand-built
     * resize feel broken.
     */
    function installResize() {
        if (!gripEl || typeof document.addEventListener !== 'function') {
            return;
        }

        var startY = 0;
        var startHeight = 0;
        var dragging = false;

        gripEl.addEventListener('mousedown', function (event) {
            dragging = true;
            startY = event.clientY || 0;
            startHeight = panelEl.offsetHeight || currentHeight();
            if (gripEl.classList) {
                gripEl.classList.add('pdb-dragging');
            }
            if (event.preventDefault) {
                event.preventDefault();
            }
        });

        document.addEventListener('mousemove', function (event) {
            if (!dragging) {
                return;
            }
            // Upwards is taller: the panel grows from the bottom of the window.
            setPanelHeight(startHeight + (startY - (event.clientY || 0)));
        });

        document.addEventListener('mouseup', function () {
            if (!dragging) {
                return;
            }
            dragging = false;
            if (gripEl.classList) {
                gripEl.classList.remove('pdb-dragging');
            }
            rememberHeight(currentHeight());
        });
    }

    /** The panel's height as it stands, in pixels. */
    function currentHeight() {
        var value = parseInt(String(panelEl && panelEl.style ? panelEl.style.height : ''), 10);
        return isNaN(value) ? 320 : value;
    }

    /** Set the panel's height, within the bounds a panel is useful at. */
    function setPanelHeight(height) {
        var bounded = Math.max(MIN_HEIGHT, Math.min(MAX_HEIGHT, Math.round(height)));
        if (panelEl && panelEl.style) {
            panelEl.style.height = bounded + 'px';
        }
        return bounded;
    }

    /** Restore the height this reader last chose. */
    function applyStoredHeight() {
        try {
            var stored = parseInt(String(localStorage.getItem(HEIGHT_KEY)), 10);
            if (!isNaN(stored)) {
                setPanelHeight(stored);
            }
        } catch (e) {
            /* a height that cannot be read is a height that stays at its default */
        }
    }

    /** Remember it for the next page. */
    function rememberHeight(height) {
        try {
            localStorage.setItem(HEIGHT_KEY, String(height));
        } catch (e) {
            /* see isHiddenStored */
        }
    }

    function positionCategoryMenu() {
        if (openCategory && root) {
            var btn = root.querySelector('[data-cat-toggle="' + openCategory + '"]');
            var menu = root.querySelector('.pdb-cat-menu-' + openCategory);
            if (btn && menu && btn.getBoundingClientRect) {
                var rect = btn.getBoundingClientRect();
                var vh = typeof window !== 'undefined' ? window.innerHeight : 800;
                menu.style.left = Math.max(8, rect.left) + 'px';
                menu.style.bottom = Math.round(vh - rect.top + 2) + 'px';
            }
        }
    }

    // ── Rendering ───────────────────────────────────────────────────────────

    var CATEGORIES = {
        route: 'App',
        views: 'App',
        models: 'App',
        migrations: 'App',

        auth: 'User',
        gate: 'User',
        session: 'User',

        logs: 'Logs',
        exceptions: 'Logs',
        errors: 'Logs'
    };

    /** Redraw the tabs, the info strip and the open panel. */
    function render() {
        if (!root) {
            return;
        }

        var entry = entries[selected] || null;

        var html = '<button class="pdb-tab' + (activeTab === 'requests' ? ' pdb-active' : '')
            + '" data-panel="requests" title="List all captured HTTP requests">requests<span class="pdb-tab-count">'
            + entries.length + '</span></button>';

        var mainHtml = '';
        var categoryGroups = {};

        TABS.forEach(function (tab) {
            var data = entry && entry.payload ? entry.payload[tab.key] : null;
            var stream = aggregating() && STREAMS[tab.key] ? streamAcross(tab.key) : null;

            if (!data && STATE_TABS[tab.key] && !userPicked) {
                data = newestPayloadFor(tab.key);
            }

            if (tab.key === 'errors') {
                data = clientErrors.length ? { count: clientErrors.length } : null;
            }
            if (tab.key === 'client' || tab.key === 'api') {
                data = { count: null };
            }

            if (!data && !(stream && stream.length)) {
                return;
            }
            var count = stream ? stream.length : tabCount(tab.key, data, entry);
            var alarming = (tab.key === 'exceptions' && count > 0)
                || (tab.key === 'errors' && count > 0)
                || (tab.key === 'auth' && credentialExpired(data));

            var tabBtn = '<button class="pdb-tab' + (activeTab === tab.key ? ' pdb-active' : '')
                + (alarming ? ' pdb-tab-alert' : '')
                + '" data-panel="' + tab.key + '" title="' + escAttr(tab.title || tab.label) + '">'
                + (alarming ? '⚠ ' : '') + esc(tab.label)
                + (count === null ? '' : '<span class="pdb-tab-count">' + count + '</span>')
                + '</button>';

            var cat = CATEGORIES[tab.key];
            if (!cat || activeTab === tab.key) {
                mainHtml += tabBtn;
            } else {
                categoryGroups[cat] = categoryGroups[cat] || { items: [], alarming: false };
                categoryGroups[cat].items.push(tabBtn);
                if (alarming) {
                    categoryGroups[cat].alarming = true;
                }
            }
        });

        html += mainHtml;

        Object.keys(categoryGroups).forEach(function (cat) {
            var group = categoryGroups[cat];
            if (!group || !group.items || group.items.length === 0) {
                return;
            }
            if (group.items.length === 1) {
                html += group.items[0];
                return;
            }
            var catKey = cat.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
            var isOpen = openCategory === catKey;
            var isAlarm = group.alarming;
            html += '<div class="pdb-more-wrap">'
                + '<button class="pdb-more-btn' + (isOpen ? ' pdb-active' : '')
                + (isAlarm ? ' pdb-tab-alert' : '')
                + '" data-cat-toggle="' + catKey + '" title="' + escAttr(cat) + ' tabs">'
                + (isAlarm ? '⚠ ' : '') + esc(cat)
                + ' <span class="pdb-tab-count">' + group.items.length + '</span> &#9660;</button>'
                + '<div class="pdb-more-menu pdb-cat-menu-' + catKey + '"' + (isOpen ? '' : ' hidden') + '>'
                + group.items.join('') + '</div></div>';
        });

        tabsEl.innerHTML = html;
        infoEl.innerHTML = infoStrip(entry);

        positionCategoryMenu();

        if (activeTab === null) {
            panelEl.style.display = 'none';
            if (gripEl && gripEl.style) {
                gripEl.style.display = 'none';
            }
            return;
        }

        panelEl.style.display = 'block';
        if (gripEl && gripEl.style) {
            gripEl.style.display = 'block';
        }
        panelEl.innerHTML = activeTab === 'requests'
            ? renderRequests()
            : requestBodySection(entry) + renderTab(activeTab, entry);
    }

    /**
     * What the page sent with this request, if anything.
     *
     * Shown above whichever tab is open, because it belongs to the request
     * rather than to a collector — and because "what did I send" is the first
     * question when a call comes back wrong.
     *
     * Collapsed by default: a datatables body is two kilobytes of column
     * metadata, and expanded it would push everything worth reading off the
     * screen. `<details>` keeps it one click away with no script of its own.
     *
     * The body never left the browser — it is what this page just sent — but
     * secrets are masked anyway, because this panel gets screenshotted and a
     * password in a bug report is a password that has to be changed.
     */
    function requestBodySection(entry) {
        if (!entry || !entry.body) {
            return '';
        }

        // Computed once per request and kept on the entry. render() runs on
        // every recorded request — a polling page calls it every few seconds —
        // and re-laying-out the same body each time is work nobody asked for.
        if (entry.bodyHtml === undefined) {
            var shown = formatBody(entry.body);
            var note  = String(entry.body).indexOf('… truncated') > -1
                ? ' <span class="pdb-muted">(truncated)</span>'
                : '';

            entry.bodyHtml = '<details class="pdb-body"><summary>Request body · '
                + esc(sizeOf(entry.body)) + note + '</summary>'
                + '<div style="margin:4px 0 0">' + copyButton(shown) + '</div>'
                + '<pre class="pdb-pre">' + esc(shown) + '</pre></details>';
        }

        return entry.bodyHtml;
    }

    /**
     * The services payload of one request, or an empty one.
     *
     * Its own collector, shown inside the Domain tab rather than beside it: a
     * reader asking "what did this request do to the domain layer" should not
     * have to know whether this application puts that logic on models or in
     * services, and a project built one way would otherwise stare at a
     * permanently empty tab named after the other.
     */
    function servicesOf(entry) {
        var data = entry && entry.payload ? entry.payload.services : null;
        return data && !data.error ? data : { count: 0, ops: 0, services: [], operations: [] };
    }

    /** The number worth putting on a tab, or null when a count means nothing. */
    function tabCount(key, data, entry) {
        switch (key) {
            case 'errors':
                // Distinct failures, not occurrences: a loop that throws the same
                // thing 4000 times is one problem, and the row says 4000.
                return clientErrors.length;
            case 'client':
            case 'api':
                // Nothing to count — a configuration is not a quantity, and
                // neither is a tool.
                return null;
            case 'queries':
                return typeof data.count === 'number' ? data.count : (data.queries || []).length;
            case 'logs':
                return typeof data.count === 'number' ? data.count : (data.entries || []).length;
            case 'models':
                // Models *and* services: the badge counts what the tab contains.
                // A services-oriented request would otherwise show 0 above a
                // panel listing six service calls.
                return (typeof data.count === 'number' ? data.count : 0)
                    + (servicesOf(entry).count || 0);
            case 'views':
            case 'exceptions':
                return typeof data.count === 'number' ? data.count : null;
            case 'migrations':
                return (data.this_request || []).length || null;
            case 'session':
                return data.active ? Object.keys(data.data || {}).length : null;
            case 'auth':
                // Not a count — there is nothing to count. An expired credential
                // is the one thing worth seeing without opening the tab, because
                // it explains every 401 above it in the list.
                return null;
            case 'gate':
                // Refusals, not checks. A page doing forty allowed checks is
                // working; one refusal is the thing worth a glance.
                return data.refused || null;
            default:
                return null;
        }
    }

    /**
     * Has the credential this request used already run out?
     *
     * Worth knowing without opening a tab, because it explains every refusal
     * above it in the list at once — and because the alternative is reading four
     * 401s and guessing.
     */
    function credentialExpired(data) {
        var token = data && data.token;
        return !!(token && token.expires_at && token.expires_at <= Math.floor(Date.now() / 1000));
    }

    /** Time, memory and route for the selected entry. */
    function infoStrip(entry) {
        if (!entry) {
            return '';
        }
        var bits = [];
        var ms = serverMs(entry);
        if (ms !== null) {
            bits.push('<span class="pdb-chip" title="Server response time: ' + ms + 'ms">' + ms + 'ms</span>');
        }
        if (entry.ms !== null && entry.ms !== undefined) {
            bits.push('<span class="pdb-chip" title="Client round-trip time: ' + entry.ms + 'ms">' + entry.ms + 'ms</span>');
        }
        var mb = memoryMb(entry);
        if (mb !== null) {
            bits.push('<span class="pdb-chip" title="Peak memory usage: ' + mb + 'MB">' + mb + 'MB</span>');
        }
        bits.push(userPicked
            ? '<button class="pdb-chip pdb-unpick" title="Pinned request (click to unpin): ' + escAttr(entry.method + ' ' + entry.path) + '">'
                + esc(entry.method) + ' ' + esc(entry.path) + ' ✕</button>'
            : '<span class="pdb-chip" title="Request path: ' + escAttr(entry.method + ' ' + entry.path) + '">' + esc(entry.method) + ' ' + esc(entry.path) + '</span>');
        return bits.join('');
    }

    /**
     * A way to copy this request's id, without a column for it.
     *
     * The id is sixteen characters of noise that somebody reads once in their
     * life — when they are pasting it into a bug report or a log search. A
     * column would spend a sixth of a narrow table on it permanently; a button
     * that appears on hover costs nothing until it is wanted.
     */
    function requestIdChip(entry) {
        var id = requestIdOf(entry);
        if (!id) {
            return '';
        }

        return ' <button class="pdb-copy pdb-id-copy" title="'
            + escAttr('Copy request id ' + id) + '" data-copy="' + escAttr(id) + '">id</button>';
    }

    /**
     * Did this request go wrong?
     *
     * Three ways, and the row is red for all of them: the status says so, the
     * request never got a status at all (a network failure, `status: 0`), or it
     * answered 200 while raising something — which is the one nobody would look
     * for. Colouring the status cell alone put the signal in the narrowest column
     * of a wide table.
     */
    function wentWrong(entry) {
        if (!entry.status || entry.status >= 400) {
            return true;
        }
        var ex = (entry.payload || {}).exceptions;
        return !!(ex && !ex.error && (ex.count || 0) > 0);
    }

    /** The list of requests — the tab that makes the others make sense. */
    function renderRequests() {
        var axis = timeAxis();
        var rows = '';

        for (var i = entries.length - 1; i >= 0; i--) {
            var e = entries[i];
            var q = queryCount(e);
            var ms = serverMs(e);
            rows += '<tr class="pdb-row' + (i === selected ? ' pdb-selected' : '')
                + (wentWrong(e) ? ' pdb-row-bad' : '') + '" data-entry="' + i + '">'
                + '<td class="pdb-muted">' + clockTime(e.at) + '</td>'
                + '<td>' + esc(e.method) + '</td>'
                + '<td class="pdb-sql">' + esc(e.path)
                + (e.kind === 'page' ? ' <span class="pdb-muted">(page)</span>' : '')
                + requestIdChip(e) + '</td>'
                + '<td class="pdb-s-' + String(e.status).charAt(0) + '">' + esc(e.status) + '</td>'
                + '<td class="pdb-time">' + (ms === null ? '—' : ms + 'ms') + '</td>'
                + '<td class="pdb-time">' + (q === null ? '—' : q) + '</td>'
                + timelineCell(e, i, axis)
                + '</tr>';
        }

        return '<p class="pdb-muted">Click a request to see what it did.'
            + (entries.length > 1
                ? ' The bars share one axis — ' + Math.round(axis.span) + 'ms from the first '
                    + 'request to the last — so calls that overlap look overlapped.'
                : '')
            + ' <button class="pdb-btn" id="pdb-clear">clear the list</button></p>'
            + '<table class="pdb-table pdb-requests"><thead><tr><th>At</th><th>Method</th>'
            + '<th>Path</th><th>Status</th><th>Server</th><th>SQL</th>'
            + '<th>' + (entries.length > 1 ? 'Timeline' : '') + '</th></tr></thead><tbody>'
            + rows + '</tbody></table>';
    }

    /**
     * The shared time axis every row's bar is drawn against.
     *
     * @returns {{first: number, span: number}}
     */
    function timeAxis() {
        if (entries.length === 0) {
            return { first: 0, span: 1 };
        }

        var first = entries[0].at.getTime();
        var last  = first;

        entries.forEach(function (e) {
            var end = e.at.getTime() + (typeof e.ms === 'number' ? e.ms : 0);
            if (end > last) {
                last = end;
            }
        });

        return { first: first, span: Math.max(1, last - first) };
    }

    /**
     * One request's bar, in the row that describes it.
     *
     * The waterfall used to be a second list above this one: the same requests
     * again, in the opposite order — the table newest-first, the waterfall
     * oldest-first — so moving between "what did this call do" and "how do these
     * calls relate" meant scrolling past one to reach the other, and the two
     * disagreed about which row was which. Drawing the bar in the row it belongs
     * to removes the second list, and the column reads as a waterfall because
     * every bar shares one axis.
     *
     * Requests with no client duration — the page itself, or a response that only
     * carried a header — are drawn as a mark rather than a bar, because a width
     * would be a guess.
     */
    function timelineCell(entry, index, axis) {
        if (entries.length < 2) {
            return '<td></td>';
        }

        var known  = typeof entry.ms === 'number' && entry.ms > 0;
        var offset = ((entry.at.getTime() - axis.first) / axis.span) * 100;
        var width  = known ? Math.max(0.6, (entry.ms / axis.span) * 100) : 0.6;
        var colour = wentWrong(entry) ? '#f38ba8' : PALETTE[index % PALETTE.length];

        return '<td class="pdb-wf-cell"><span class="pdb-wf-track">'
            + '<span class="pdb-wf-bar' + (known ? '' : ' pdb-wf-mark') + '" style="left:'
            + Math.min(99.4, Math.max(0, offset)).toFixed(2) + '%;width:' + width.toFixed(2) + '%;'
            + 'background:' + colour + '" title="'
            + escAttr(entry.method + ' ' + entry.path + (known ? ' — ' + entry.ms + 'ms' : ''))
            + '"></span></span></td>';
    }

    /**
     * Forget every request recorded so far, and keep the bar.
     *
     * A polling SPA fills this list until the call somebody is looking for has
     * scrolled away, and there was no way to say "start from here". Clearing is
     * therefore about attention rather than memory: the next request repopulates
     * the list, and the panel says plainly that nothing is recorded rather than
     * pretending the page has done nothing.
     */
    function clearEntries() {
        entries = [];
        serverLogs = {};
        clientErrors = [];
        selected = -1;
        userPicked = false;
        render();
    }

    /** The tail of a path, which is the part that identifies it in a narrow column. */
    function shortPath(path) {
        var text = String(path).replace(/^https?:\/\/[^/]+/, '');
        return text.length > 28 ? '…' + text.slice(-27) : text;
    }

    /**
     * Go and get the detail for any request that reported an exception it could
     * not describe.
     *
     * The count arrived without messages — an error page cannot carry a payload,
     * so the header said "1 raised" and nothing more. The toolbar already knows
     * *which* request that was, so making somebody find it in a list and press a
     * button to ask about it is a step with no decision in it. Opening the tab is
     * the decision; the fetch follows.
     *
     * Only for requests actually missing detail, only once each, and only when
     * the server offered an endpoint.
     */
    function fetchMissingExceptionDetail() {
        if (logsUnavailable) {
            return;
        }

        entries.forEach(function (e) {
            var ex = e.payload ? e.payload.exceptions : null;
            if (!ex || ex.error) {
                return;
            }

            var missing = (typeof ex.count === 'number' ? ex.count : 0) - ((ex.items || []).length);
            var id = requestIdOf(e);
            if (missing > 0 && id && !serverLogs[id]) {
                fetchServerLogs(id, null);
            }
        });
    }

    /** Draw one collector's data. */
    function renderTab(key, entry) {
        if (key === 'exceptions') {
            fetchMissingExceptionDetail();
        }

        // Before every payload check below: this tab's data never travelled in a
        // response, and the request in view may well be the one that carried
        // nothing back because the browser broke while handling it.
        if (key === 'errors') {
            return renderClientErrors();
        }

        if (key === 'client') {
            return renderClientState();
        }

        if (key === 'api') {
            return renderPlayground();
        }

        // Checked before the payload: a stream tab answers for every request
        // until one is picked, including the requests the selected one is not.
        if (aggregating() && STREAMS[key]) {
            return renderStream(key, entry);
        }

        if (STATE_TABS[key] && !userPicked) {
            var current = newestPayloadFor(key);
            if (current) {
                return renderTab_state(key, current, entry);
            }
        }

        if (entry && !entry.payload) {
            // Saying why beats an empty panel: each of these has a different
            // fix, and this is where somebody looks first.
            return '<p class="pdb-muted">This response carried no debug data, so '
                + 'there is nothing to show for it. Either it never reached this '
                + 'application, or it ended before the debug headers were sent — '
                + 'an uncaught error, a redirect, a cached or cross-origin '
                + 'response. The other requests below are still readable, and an '
                + 'error raised here should appear in <strong>Logs</strong> or '
                + '<strong>Exceptions</strong>.</p>';
        }

        var data = entry && entry.payload ? entry.payload[key] : null;
        if (!data) {
            return '<p class="pdb-muted">Nothing recorded for this request.</p>';
        }
        if (data.error) {
            // A collector that threw is reported, not hidden: instrumentation
            // failing silently is how a blank panel gets misread as "no data".
            return '<p style="color:#f38ba8">This collector failed: ' + esc(data.error) + '</p>';
        }

        switch (key) {
            case 'queries':    return renderQueries(data);
            case 'timers':     return renderTimers(data, entry);
            case 'route':      return renderKeyValue(data);
            case 'auth':       return renderAuth(data);
            case 'gate':       return renderGate(data);
            case 'session':    return renderSession(data);
            case 'logs':       return renderLogs(data, entry);
            case 'views':      return renderViews(data);
            case 'models':     return renderDomain(data, servicesOf(entry));
            case 'migrations': return renderMigrations(data);
            case 'exceptions': return renderExceptions(data, entry);
            default:           return '<pre class="pdb-pre">' + esc(JSON.stringify(mask(data), null, 2)) + '</pre>';
        }
    }

    /**
     * The most recent value of a state key, whichever request carried it.
     *
     * Signing in changes who you are; the request that reported it is over. What
     * a reader wants is the state now, and the newest answer is it.
     */
    function newestPayloadFor(key) {
        for (var i = entries.length - 1; i >= 0; i--) {
            var payload = entries[i].payload;
            if (payload && payload[key]) {
                return payload[key];
            }
        }
        return null;
    }

    /**
     * Draw a state tab, saying so when the state shown is newer than the
     * request in view.
     *
     * Silently showing a different request's data would be the same mistake in
     * the other direction: the panel would be right and unexplainable.
     */
    function renderTab_state(key, current, entry) {
        var own    = entry && entry.payload ? entry.payload[key] : null;
        var note   = '';

        if (own && own !== current) {
            note = '<p class="pdb-muted">This is the state as of the most recent request. '
                + 'Pick a request to see what it was for that one.</p>';
        }

        return note + (key === 'auth' ? renderAuth(current) : renderTab(key, entry));
    }

    /** A copy button carrying the given text. */
    function copyButton(text, label) {
        return '<button class="pdb-copy" title="Copy" data-copy="' + escAttr(text) + '">'
            + (label || '⎘') + '</button>';
    }

    function renderQueries(data) {
        var list = data.queries || data.statements || [];
        var rows = '';
        var all = [];

        list.forEach(function (q) {
            var sql = q.sql || q.query || q.statement || '';
            var ms = q.time === undefined ? (q.duration || 0) : q.time;
            var cached = !!q.from_cache;
            // A cached statement took no time because it did not run. Showing it
            // as 0ms reads as "instant", and the difference between those two is
            // the reason for opening the panel.
            rows += '<tr class="' + (!cached && ms > 100 ? 'pdb-slow' : '') + '">'
                + (cached ? '<td class="pdb-time pdb-cached">CACHE</td>' : '<td class="pdb-time">' + ms + 'ms</td>')
                + '<td class="pdb-sql">' + copyButton(sql) + ' ' + esc(sql) + '</td></tr>';
            all.push('-- ' + (cached ? 'CACHE' : ms + 'ms') + '\n' + sql + ';');
        });

        var count = typeof data.count === 'number' ? data.count : list.length;
        var cachedCount = data.cached || 0;
        var info = cachedCount > 0 ? ' (' + (count - cachedCount) + ' live · ' + cachedCount + ' from cache)' : '';
        var truncated = data.truncated
            ? ' <span class="pdb-muted">' + data.truncated + ' more not shown</span>'
            : '';
        var copyAll = count > 0
            ? ' ' + copyButton(all.join('\n\n'), '⎘ Copy all').replace('class="pdb-copy"', 'class="pdb-copy pdb-copy-all"')
            : '';

        return '<p><strong>' + count + ' queries' + info + '</strong> — ' + (data.total_ms || 0)
            + 'ms total' + copyAll + truncated + '</p>'
            + '<table class="pdb-table"><thead><tr><th>Time</th><th>SQL</th></tr></thead><tbody>'
            + rows + '</tbody></table>';
    }

    function renderTimers(data, entry) {
        var total = Number(data.request_ms || 0);
        var named = data.named_timers || [];
        var html = '<p><strong>Request:</strong> ' + total + 'ms '
            + '<span class="pdb-muted">started ' + esc(data.start_time || '') + '</span></p>'
            + whereTheTimeWent(entry, total);

        if (!named.length) {
            return html;
        }

        html += '<div class="pdb-timeline">';
        named.forEach(function (t, i) {
            var left = total > 0 ? Math.min(100, (t.offset_ms / total) * 100) : 0;
            var width = total > 0 ? Math.max(0.5, Math.min(100 - left, (t.ms / total) * 100)) : 1;
            html += '<div class="pdb-tl-seg" style="left:' + left.toFixed(2) + '%;width:'
                + width.toFixed(2) + '%;background:' + PALETTE[i % PALETTE.length] + '" title="'
                + escAttr(t.name + ': ' + t.ms + 'ms') + '">' + (width > 4 ? esc(t.name) : '') + '</div>';
        });
        html += '</div>';

        var rows = '';
        named.forEach(function (t, i) {
            var pct = total > 0 ? Math.round((t.ms / total) * 1000) / 10 : 0;
            rows += '<tr><td><span style="display:inline-block;width:10px;height:10px;border-radius:2px;'
                + 'background:' + PALETTE[i % PALETTE.length] + ';margin-right:4px"></span>' + esc(t.name) + '</td>'
                + '<td class="pdb-time">' + t.ms + 'ms</td><td class="pdb-muted">' + pct + '%</td></tr>';
        });

        return html + '<table class="pdb-table" style="margin-top:8px"><thead><tr><th>Phase</th>'
            + '<th>Duration</th><th>%</th></tr></thead><tbody>' + rows + '</tbody></table>';
    }

    /**
     * Where a request's time actually went, from numbers already collected.
     *
     * Two subtractions nobody does by hand, and both change what you look at
     * next:
     *
     *  - **client versus server.** The toolbar knows both — the browser measured
     *    the call, the server reported its own share — and the difference is
     *    network, queueing and the browser's own work. A call that spends 40ms
     *    in PHP and 210ms in the air is not a slow endpoint, and optimising the
     *    endpoint is the wrong afternoon.
     *  - **SQL as a share of server time.** `total_ms` is right there in the
     *    query collector, and "42ms of 61ms was the database" is the difference
     *    between an indexing problem and an application one.
     *
     * Absent rather than zeroed when a number is missing: a bar claiming 0ms of
     * network for a response that only carried a header would be inventing.
     */
    function whereTheTimeWent(entry, serverMsTotal) {
        if (!entry) {
            return '';
        }

        var client = entry.ms;
        var server = serverMsTotal > 0 ? serverMsTotal : serverMs(entry);
        var rows = '';

        if (typeof client === 'number' && typeof server === 'number' && client > 0) {
            var elsewhere = Math.max(0, Math.round((client - server) * 10) / 10);
            var serverPct = Math.max(0, Math.min(100, (server / client) * 100));

            rows += '<div class="pdb-split">'
                + '<div class="pdb-split-server" style="width:' + serverPct.toFixed(2) + '%"'
                + ' title="' + escAttr('server ' + server + 'ms') + '"></div>'
                + '<div class="pdb-split-away" title="'
                + escAttr('network and browser ' + elsewhere + 'ms') + '"></div>'
                + '</div>'
                + '<p class="pdb-muted" style="margin:2px 0 8px">client ' + client + 'ms = '
                + '<span style="color:#89b4fa">server ' + server + 'ms</span> + '
                + elsewhere + 'ms elsewhere</p>';
        }

        var queries = (entry.payload || {}).queries;
        var sqlMs = queries && typeof queries.total_ms === 'number' ? queries.total_ms : null;
        if (sqlMs !== null && typeof server === 'number' && server > 0) {
            var sqlPct = Math.round((sqlMs / server) * 1000) / 10;
            rows += '<p class="pdb-muted" style="margin:0 0 8px">SQL ' + sqlMs + 'ms — '
                + '<strong style="color:' + (sqlPct > 50 ? '#f38ba8' : '#a6e3a1') + '">'
                + sqlPct + '%</strong> of server time</p>';
        }

        return rows;
    }

    function renderKeyValue(data) {
        var rows = '';
        Object.keys(data).forEach(function (k) {
            var v = data[k];
            rows += '<tr><td>' + esc(k) + '</td><td class="pdb-sql">'
                + esc(Array.isArray(v) ? v.join(', ') : (v && typeof v === 'object' ? JSON.stringify(v) : v))
                + '</td></tr>';
        });
        return '<table class="pdb-table"><tbody>' + rows + '</tbody></table>';
    }

    /**
     * Who this request was, and what convinced the server of it.
     *
     * "It worked and then it stopped" is almost always one of three things: the
     * credential expired, the client sent a different one than it thinks, or it
     * sent none and the server fell back to a session cookie that only exists on
     * the developer's own machine. All three are here.
     *
     * The token's value is never in the payload — only its claims — so nothing
     * on this panel can be used to authenticate as anybody.
     */
    function renderAuth(data) {
        var user = data.user || {};
        var html = '';

        html += user.authenticated
            ? '<p><strong>' + esc(user.username || ('user ' + user.userid)) + '</strong> '
                + '<span class="pdb-muted">#' + esc(user.userid)
                + ' · type ' + esc(user.usertype) + '</span></p>'
            : '<p class="pdb-muted">Anonymous — the server did not identify anybody.</p>';

        var credential = data.credential || 'none';

        if (credential !== 'none') {
            html += '<p>Authenticated by <strong>' + esc(credential) + '</strong>'
                + (data.source ? ' <span class="pdb-muted">(' + esc(data.source) + ')</span>' : '')
                + '</p>';
        } else if (data.source) {
            // "No credential" and "this request signed out" are both anonymous
            // and they are not the same answer: one is a call that never carried
            // anything, the other is a logout that worked. Dropping the second
            // made a successful sign-out look like a request that had simply
            // forgotten its token.
            html += '<p class="pdb-muted">' + esc(data.source) + '.</p>';
        } else {
            html += '<p class="pdb-muted">No credential was presented.</p>';
        }

        html += renderTwoFactor(data.twofactor);

        var token = data.token;
        if (!token) {
            return html;
        }

        if (token.format !== 'jwt') {
            return html + '<p class="pdb-muted">The token is opaque — there is nothing '
                + 'inside it to read.</p>';
        }

        html += expiryLine(token.expires_at);

        var rows = '';
        Object.keys(token.claims || {}).forEach(function (claim) {
            rows += '<tr><td>' + esc(claim) + '</td><td class="pdb-sql">'
                + esc(token.claims[claim]) + '</td></tr>';
        });

        return html + '<table class="pdb-table"><thead><tr><th>Claim</th><th>Value</th></tr>'
            + '</thead><tbody>' + rows + '</tbody></table>';
    }

    /**
     * The second factor: what this account holds, what the site demands, what is in flight.
     *
     * Three questions a developer otherwise answers by reading a session they cannot see:
     *
     *  - why am I being asked for a code (the floor applies, or the account enrolled one);
     *  - why does every page redirect me to the setup screen (the enrolment floor applies
     *    and what the account holds is not strong enough) — without this the wall reads as
     *    a redirect loop, and the first guess is always a routing bug;
     *  - where in the step-up am I (a half-finished login lives only in the session, so from
     *    outside a stuck sign-in and a fresh one look identical).
     *
     * No code and no secret is in the payload — only whether a code exists and how long the
     * resend has left. A live six-digit code in a network log is a live six-digit code, and
     * this panel gets pasted into bug reports.
     */
    function renderTwoFactor(state) {
        if (!state) {
            return '';
        }

        if (state.error) {
            return '<p class="pdb-muted">Second factor: could not be read — '
                + esc(state.error) + '</p>';
        }

        var html = '<p><strong>Second factor</strong> ';

        html += state.held && state.held.length
            ? esc(state.held.join(', '))
            : '<span class="pdb-muted">nothing enrolled</span>';

        if (state.required_to_sign_in) {
            html += ' <span class="pdb-muted">· required to sign in (usertype '
                + esc(state.sign_in_floor) + '+)</span>';
        } else if (state.sign_in_floor) {
            html += ' <span class="pdb-muted">· below the sign-in floor ('
                + esc(state.sign_in_floor) + ')</span>';
        }

        html += '</p>';

        if (state.must_enrol) {
            // Loud on purpose. This is the one state that makes every other page in the
            // application misbehave, and it is invisible from the page it lands on.
            html += '<p class="pdb-level-warning">Enrolment required: every page redirects to the '
                + 'second-factor setup screen until this account holds something stronger '
                + 'than a mailed code (floor ' + esc(state.enrolment_floor) + ').</p>';
        }

        html += renderRevealedCodes(state.revealed);

        var pending = state.pending;

        if (!pending) {
            return html;
        }

        html += '<p>Step-up pending for <strong>#' + esc(pending.userid) + '</strong>'
            + ' <span class="pdb-muted">· ' + esc(pending.waiting_for) + 's ago</span></p>';

        html += '<table class="pdb-table"><tbody>'
            + '<tr><td>Methods offered</td><td>'
            + (pending.methods && pending.methods.length
                ? esc(pending.methods.join(', '))
                : '<span class="pdb-muted">none — the floor demands a mailed code</span>')
            + '</td></tr>'
            + '<tr><td>Mailed code live</td><td>' + (pending.mailed_code ? 'yes' : 'no')
            + '</td></tr>'
            + '<tr><td>Resend allowed in</td><td>'
            + (pending.resend_in > 0 ? esc(pending.resend_in) + 's' : 'now')
            + '</td></tr></tbody></table>';

        return html;
    }

    /**
     * The codes themselves, where the installation asked for them.
     *
     * `debug.reveal_factor_codes` is off unless an application sets it, so most installations
     * never see this block. Where it is on, it saves the loop a developer otherwise runs
     * twenty times a day: open the mail catcher, find the newest message, copy six digits,
     * come back.
     *
     * Marked as what it is. A panel that shows a live credential without saying so is a panel
     * somebody screenshots into a ticket.
     */
    function renderRevealedCodes(revealed) {
        if (!revealed) {
            return '';
        }

        var rows = '';

        if (revealed.totp_now) {
            rows += '<tr><td>Code now</td><td class="pdb-sql"><strong>'
                + esc(revealed.totp_now) + '</strong></td></tr>';
        }

        if (revealed.totp_secret) {
            rows += '<tr><td>Authenticator secret</td><td class="pdb-sql">'
                + esc(revealed.totp_secret) + '</td></tr>';
        }

        if (revealed.mailed_code) {
            rows += '<tr><td>Last mailed code</td><td class="pdb-sql"><strong>'
                + esc(revealed.mailed_code) + '</strong></td></tr>';
        }

        ['totp_error', 'mailed_error'].forEach(function (key) {
            if (revealed[key]) {
                rows += '<tr><td>' + esc(key.replace('_', ' ')) + '</td><td class="pdb-muted">'
                    + esc(revealed[key]) + '</td></tr>';
            }
        });

        if (rows === '') {
            return '<p class="pdb-muted">No code to reveal — nothing is enrolled and nothing '
                + 'has been mailed.</p>';
        }

        return '<p class="pdb-level-warning">Live credentials below — '
            + 'debug.reveal_factor_codes is on. Development only.</p>'
            + '<table class="pdb-table"><tbody>' + rows + '</tbody></table>';
    }

    /**
     * How long the credential has left, as of now.
     *
     * Counted from the token's own absolute expiry rather than from a "seconds
     * remaining" the server worked out: the response may have been sitting in
     * the browser for a while before anybody opened this tab, and a countdown
     * that started when the request was made would be reassuring and wrong.
     */
    function expiryLine(expiresAt) {
        if (!expiresAt) {
            return '<p class="pdb-muted">The token does not say when it expires.</p>';
        }

        var left = expiresAt - Math.floor(Date.now() / 1000);
        var when = new Date(expiresAt * 1000);

        if (left <= 0) {
            return '<p style="color:#f38ba8"><strong>Expired</strong> '
                + esc(humanDuration(-left)) + ' ago — at ' + esc(clockTime(when)) + '. '
                + 'Every call with it will be refused from here on.</p>';
        }

        return '<p style="color:' + (left < 300 ? '#fab387' : '#a6e3a1') + '">Valid for another '
            + '<strong>' + esc(humanDuration(left)) + '</strong> '
            + '<span class="pdb-muted">— until ' + esc(clockTime(when)) + '</span></p>';
    }

    /** Seconds as something a person reads without counting zeros. */
    function humanDuration(seconds) {
        var s = Math.max(0, Math.floor(seconds));
        if (s < 60) {
            return s + 's';
        }
        if (s < 3600) {
            return Math.floor(s / 60) + 'm ' + (s % 60) + 's';
        }
        if (s < 86400) {
            return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
        }
        return Math.floor(s / 86400) + 'd ' + Math.floor((s % 86400) / 3600) + 'h';
    }

    /**
     * What each authorization step means, in one line each.
     *
     * Shown in the panel rather than only in the guide, because the whole value of this tab is
     * telling the reader *which* step decided — and a step name they have to go and look up is
     * a step name they will guess at instead.
     */
    var GATE_STEPS = {
        before:  'a global before() hook decided',
        ability: 'a named Gate::define() rule',
        policy:  'a policy method',
        store:   'the permission store',
        'default': 'nothing claimed this ability — refused by default',
        after:   'a rule answered, an after() hook overrode it'
    };

    function renderGate(data) {
        var decisions = data.decisions || [];
        if (!decisions.length) {
            return '<p class="pdb-muted">No authorization checks in this request.</p>'
                + '<p class="pdb-muted">Gate::allows(), denies(), authorize() and a controller\'s '
                + 'can()/cannot() all appear here, with the step that decided.</p>';
        }

        var rows = '';
        decisions.forEach(function (d) {
            var step = String(d.step || '');
            // The undefined case is the one this panel earns its place with: a typo in an
            // ability name and a deliberate deny both produce false, and only the step
            // tells them apart.
            var stepClass = step === 'default' ? 'pdb-level-warning' : 'pdb-muted';
            var what = d.detail ? esc(d.detail) : (GATE_STEPS[step] || step);

            rows += '<tr>'
                + '<td>' + (d.allowed
                    ? '<span class="pdb-ok">allowed</span>'
                    : '<span class="pdb-level-error">refused</span>') + '</td>'
                + '<td class="pdb-sql">' + esc(d.ability || '')
                + (d.times > 1 ? ' <span class="pdb-muted">×' + d.times + '</span>' : '') + '</td>'
                + '<td class="' + stepClass + '">' + esc(step) + '</td>'
                + '<td class="pdb-sql">' + what + '</td>'
                + '<td class="pdb-muted pdb-sql">' + esc(d.subject || '—') + '</td>'
                + '</tr>';
        });

        var lead = '<p class="pdb-lead">' + (data.checks || 0) + ' check'
            + ((data.checks === 1) ? '' : 's') + ', ' + (data.refused || 0) + ' refused';
        if (data.undefined) {
            lead += ' — <strong>' + data.undefined + ' hit no rule at all</strong>, which is what a '
                + 'mistyped ability name looks like';
        }
        lead += '.</p>';

        return lead
            + '<table class="pdb-table"><thead><tr>'
            + '<th>Result</th><th>Ability</th><th>Decided by</th><th>What</th><th>Subject</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table>'
            + '<p class="pdb-muted">Arguments are not shown: a policy check receives whole models '
            + 'and this payload travels to the browser. Subjects appear as a class name only.</p>';
    }

    function renderSession(data) {
        if (!data.active) {
            return '<p class="pdb-muted">No active session.</p>';
        }
        var rows = '';
        var values = data.data || {};
        Object.keys(values).forEach(function (k) {
            rows += '<tr><td>' + esc(k) + '</td><td class="pdb-sql">' + esc(values[k]) + '</td></tr>';
        });
        return '<p><strong>Session ID:</strong> ' + esc(data.session_id || '') + '</p>'
            + '<table class="pdb-table"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>'
            + rows + '</tbody></table>';
    }

    /** One log row; `from` adds the request it came from, for the stream view. */
    function logRow(e, from) {
        var level = e.level || 'info';
        var at = e.time ? clockTime(new Date(Number(e.time) * 1000)) : '';
        return '<tr><td class="pdb-muted">' + esc(at) + '</td>'
            + '<td class="pdb-level-' + esc(level) + '">' + esc(level) + '</td>'
            + '<td class="pdb-sql">' + esc(e.message || '') + '</td>'
            + (from ? '<td class="pdb-muted pdb-sql">' + esc(from.path) + '</td>' : '')
            + '</tr>';
    }

    function renderLogs(data, entry) {
        var rows = '';
        (data.entries || []).forEach(function (e) {
            rows += logRow(e, null);
        });
        return logTable(rows, false) + serverLogSection(entry);
    }

    /**
     * The absolute URL of the log endpoint for this installation.
     *
     * The base is whatever the page already knows about itself:
     *
     *  - `window.__PRAMNOS__.base`, which a SPA shell already publishes for its
     *    own router — the one case where the toolbar cannot infer anything,
     *    because the API may not live where the page does;
     *  - otherwise the document's own base URL, which is right for a
     *    server-rendered page including one served from a subdirectory.
     *
     * Nothing here comes from a debug payload.
     */
    function logsEndpoint() {
        try {
            var runtime = window.__PRAMNOS__ || {};
            var base = runtime.base || runtime.appBase || document.baseURI || location.origin;
            return new URL(LOGS_PATH, base).toString();
        } catch (e) {
            return '/' + LOGS_PATH;
        }
    }

    /** This request's id, if the server named it. */
    function requestIdOf(entry) {
        var p = entry && entry.payload ? entry.payload.request : null;
        return p && p.id ? String(p.id) : null;
    }

    /**
     * The server's own log lines for a request — on request.
     *
     * The response already carried what the collectors captured. This is for
     * what it could not: lines written after the payload was built, and every
     * line of a request that died before it could send one. Fetched rather than
     * pushed, because most requests never need it and nobody wants their log
     * duplicated into every response.
     */
    function serverLogSection(entry) {
        if (logsUnavailable) {
            return '';
        }

        // While nothing is picked, every request that has a name is offerable —
        // and naming them matters more than it sounds. Asking about "this
        // request" in the default view asks about the *page*, which is usually
        // the one that logged nothing, while the call that failed sits two rows
        // below with a different id. The button now says whose log it fetches.
        var targets = aggregating()
            ? entries.filter(function (e) { return requestIdOf(e) !== null; })
            : (requestIdOf(entry) ? [entry] : []);

        if (!targets.length) {
            return '';
        }

        var offers = '';
        var tables = '';

        targets.forEach(function (target) {
            var id = requestIdOf(target);
            var label = target.method + ' ' + target.path;
            var lines = serverLogs[id];

            if (!lines) {
                offers += ' <button class="pdb-fetch-logs" data-request="' + escAttr(id) + '">'
                    + esc(label) + '</button>';
                return;
            }

            if (!lines.length) {
                tables += '<p class="pdb-muted" style="margin-top:8px">The server logged nothing '
                    + 'for ' + esc(label) + ' (request ' + esc(id) + '). Another request on this '
                    + 'page may have — each one has its own id.</p>';
                return;
            }

            var rows = '';
            lines.forEach(function (line) {
                rows += '<tr><td class="pdb-muted">' + esc(line.timestamp || '') + '</td>'
                    + '<td class="pdb-level-' + esc(line.level || 'info') + '">' + esc(line.level || '') + '</td>'
                    + '<td class="pdb-sql">' + logMessageHtml(line.message || '') + '</td>'
                    + '<td class="pdb-muted">' + esc(line.file || '') + '</td></tr>';
            });

            tables += '<p style="margin-top:8px"><strong>From the server\'s log</strong> — '
                + lines.length + ' line(s) for ' + esc(label) + '</p>'
                + '<table class="pdb-table"><thead><tr><th>Time</th><th>Level</th><th>Message</th>'
                + '<th>File</th></tr></thead><tbody>' + rows + '</tbody></table>';
        });

        var ask = offers === ''
            ? ''
            : '<p style="margin-top:8px" class="pdb-muted">Ask the server for a request\'s own '
                + 'log lines:' + offers + '</p>';

        return ask + tables;
    }

    /**
     * A logged message, with its stack trace folded away.
     *
     * Logger stores a multi-line message with its newlines escaped, so a trace
     * arrives as one 2000-character line reading `…\n#0 /var/www/…\n#1 …`. The
     * sentence is what identifies the error; the trace is what somebody opens
     * once they believe it.
     */
    function logMessageHtml(message) {
        var text = String(message).replace(/\\n/g, '\n');
        var head = text.split('\n')[0];
        var rest = text.slice(head.length).replace(/^\n/, '');

        if (rest === '') {
            return esc(head);
        }

        return esc(head)
            + '<details><summary style="cursor:pointer;color:#89b4fa">trace</summary>'
            + '<pre class="pdb-pre" style="white-space:pre-wrap;margin:2px 0 0">'
            + esc(rest) + '</pre></details>';
    }

    /**
     * Ask the endpoint for one request's log lines.
     *
     * Through the unwrapped `fetch`: going through the toolbar's own wrapper
     * would record the act of looking as one more request to look at.
     */
    function fetchServerLogs(id, button) {
        var send = rawFetch || (typeof window.fetch === 'function' ? window.fetch : null);
        if (!send || !id || fetching[id] || logsUnavailable) {
            return;
        }

        // render() runs on every recorded request, so an automatic fetch would
        // otherwise be re-issued a few times a second by a polling page.
        fetching[id] = true;

        if (button) {
            button.textContent = 'Asking the server…';
            button.disabled = true;
        }

        var endpoint = logsEndpoint();
        var url = endpoint + (endpoint.indexOf('?') > -1 ? '&' : '?')
            + 'request=' + encodeURIComponent(id);

        send.call(window, url, { credentials: 'same-origin' }).then(function (response) {
            // The route not being there — the DevPanel feature is off, or the
            // grant expired — is answered by the response rather than declared
            // in advance. Asked once, then not offered again.
            if (response.status === 404 || response.status === 403 || response.status === 401) {
                logsUnavailable = true;
                return null;
            }
            return response.json();
        }).then(function (data) {
            if (data === null) {
                if (button) {
                    button.disabled = false;
                    button.textContent = 'The server does not offer this';
                }
                render();
                return;
            }
            serverLogs[id] = (data && data.lines) || [];
            render();
        }, function () {
            // The endpoint is off, the grant expired, or the network refused.
            // Say so on the button rather than leaving it spinning — and let it
            // be asked again.
            fetching[id] = false;
            if (button) {
                button.disabled = false;
                button.textContent = 'The server did not answer — try again';
            }
        });
    }

    /** The Logs table, with or without the request column. */
    function logTable(rows, withSource) {
        var span = withSource ? 4 : 3;
        return '<table class="pdb-table"><thead><tr><th>Time</th><th>Level</th><th>Message</th>'
            + (withSource ? '<th>Request</th>' : '') + '</tr>'
            + '</thead><tbody>'
            + (rows || '<tr><td colspan="' + span + '" class="pdb-muted">No log entries</td></tr>')
            + '</tbody></table>';
    }

    function renderViews(data) {
        var rows = '';
        (data.views || []).forEach(function (v) {
            var ms = v.render_ms || 0;
            var cached = !!v.from_cache;
            rows += '<tr class="' + (!cached && ms > 50 ? 'pdb-slow' : '') + '">'
                + (cached ? '<td class="pdb-time pdb-cached">CACHE</td>' : '<td class="pdb-time">' + ms + 'ms</td>')
                + '<td>' + esc(v.view || '') + '</td><td class="pdb-sql">' + esc(v.template || '') + '</td></tr>';
        });
        var count = data.count || 0;
        var cachedCount = data.cached || 0;
        var info = cachedCount > 0 ? ' (' + (count - cachedCount) + ' rendered · ' + cachedCount + ' from cache)' : '';
        return '<p><strong>' + count + ' template(s)' + info + '</strong></p>'
            + '<table class="pdb-table"><thead><tr><th>Time</th><th>View</th><th>Template</th></tr></thead>'
            + '<tbody>' + (rows || '<tr><td colspan="3" class="pdb-muted">No views rendered</td></tr>')
            + '</tbody></table>';
    }

    /**
     * The domain layer of one request: its models and its services.
     *
     * Both sections are drawn even when one of them is empty, and the empty one
     * says what would have put something there. That sentence is the whole
     * reason this tab was rewritten: a Services project saw an empty Models tab
     * and had no way to tell "nothing happened" from "this is not where your
     * code appears".
     *
     * @param {Object} models   The `models` payload
     * @param {Object} services The `services` payload
     */
    function renderDomain(models, services) {
        return renderModels(models) + renderServices(services);
    }

    function renderModels(data) {
        var rows = '';
        (data.operations || []).forEach(function (op) {
            rows += '<tr><td>' + esc(op.class || '') + '</td><td>' + esc(op.table || '') + '</td>'
                + '<td>' + esc(op.op || '') + '</td><td>' + esc(op.key === undefined || op.key === null ? '—' : op.key)
                + '</td></tr>';
        });
        return heading('Models')
            + '<p class="pdb-muted">' + (data.count || 0) + ' class(es), '
            + (data.ops || 0) + ' operation(s)</p>'
            + '<table class="pdb-table"><thead><tr><th>Class</th><th>Table</th><th>Op</th><th>Key</th></tr>'
            + '</thead><tbody>' + (rows || '<tr><td colspan="4" class="pdb-muted">No model operations</td></tr>')
            + '</tbody></table>';
    }

    /**
     * The services section of the Domain tab.
     *
     * A service appears here because it extends `Pramnos\Application\Service`;
     * a timing appears because a method wrapped its work in `measure()`. Those
     * are two different absences and the panel distinguishes them, because the
     * fix is different: one is a base class, the other is one call.
     */
    function renderServices(data) {
        var used = data.services || [];
        var head = heading('Services')
            + '<p class="pdb-muted">' + (data.count || 0) + ' service(s), '
            + (data.ops || 0) + ' measured call(s)</p>';

        if (!used.length) {
            return head + '<p class="pdb-muted">No services recorded. A service is '
                + 'recorded when it extends <code>Pramnos\\Application\\Service</code> '
                + '— a plain class has nothing for the framework to observe.</p>';
        }

        var rows = '';
        used.forEach(function (service) {
            rows += '<tr><td class="pdb-time">'
                + (service.ops ? (service.ms || 0) + 'ms' : '—')
                + '</td><td>' + esc(service.class || '') + '</td>'
                + '<td>' + (service.ops || 0) + '</td></tr>';
        });

        var calls = '';
        (data.operations || []).forEach(function (op) {
            calls += '<tr><td class="pdb-time">' + (op.ms || 0) + 'ms</td>'
                + '<td>' + esc(op.class || '') + '</td><td>' + esc(op.op || '') + '</td></tr>';
        });

        return head
            + '<table class="pdb-table"><thead><tr><th>Time</th><th>Service</th><th>Calls</th>'
            + '</tr></thead><tbody>' + rows + '</tbody></table>'
            + (calls
                ? '<table class="pdb-table" style="margin-top:6px"><thead><tr><th>Time</th>'
                    + '<th>Service</th><th>Operation</th></tr></thead><tbody>' + calls
                    + '</tbody></table>'
                : '<p class="pdb-muted" style="margin-top:6px">No call was timed. Wrap a '
                    + 'method’s work in <code>$this-&gt;measure(\'name\', fn() =&gt; …)</code> '
                    + 'to see it here.</p>');
    }

    function renderMigrations(data) {
        var ran = data.this_request || [];
        if (!ran.length) {
            return '<p class="pdb-muted">No migrations ran this request.</p>';
        }
        var rows = '';
        ran.forEach(function (m) {
            var failed = m.status === 'failed';
            rows += '<tr class="' + (failed ? 'pdb-slow' : '') + '">'
                + (failed
                    ? '<td class="pdb-time" style="color:#f38ba8">FAILED</td>'
                    : '<td class="pdb-time">' + (m.ms || 0) + 'ms</td>')
                + '<td>' + esc(m.slug || '') + '</td></tr>';
        });
        return '<p><strong>' + ran.length + ' migration(s) ran this request</strong></p>'
            + '<table class="pdb-table"><thead><tr><th>Time</th><th>Migration</th></tr></thead><tbody>'
            + rows + '</tbody></table>';
    }

    /** One exception row; `from` adds the request it came from. */
    function exceptionRow(item, from) {
        var kind = item.type === 'php_error' ? 'PHP'
            : (item.type === 'summary' ? '···' : (item.type === 'server' ? 'LOG' : 'EXC'));
        // A summary row *is* the request whose detail did not make it back, so
        // the way to go and get it belongs on that row rather than somewhere
        // below the table.
        if (item.type === 'summary' && from && requestIdOf(from) && !logsUnavailable) {
            item = {
                type: 'summary',
                class: item.class,
                message: item.message,
                file: item.file,
                line: item.line,
                fetch: requestIdOf(from)
            };
        }
        return '<tr><td style="color:#f38ba8;white-space:nowrap">' + kind + '</td>'
            + '<td style="color:#fab387">' + esc(item.class || '') + '</td>'
            + '<td>' + esc(item.message || '')
            + (item.fetch
                ? ' <button class="pdb-fetch-logs" data-request="' + escAttr(item.fetch) + '">'
                    + 'Ask the server</button>'
                : '')
            + '</td>'
            + '<td class="pdb-sql">' + esc(item.file || '')
            + (item.line ? ':' + esc(item.line) : '') + '</td>'
            + (from ? '<td class="pdb-muted pdb-sql">' + esc(from.path) + '</td>' : '')
            + '</tr>';
    }

    function renderExceptions(data, entry) {
        var rows = '';
        (data.items || []).forEach(function (item) {
            rows += exceptionRow(item, null);
        });

        // A count with no items came from the `X-Pramnos-Debug` header, which
        // carries numbers only — messages in a header end up in access logs.
        // Saying so beats an empty table under a heading that promises rows.
        if (data.summary_only && !rows) {
            return '<p><strong>' + (data.count || 0) + ' exception(s) / error(s)</strong> '
                + 'were raised by this request.</p>'
                + '<p class="pdb-muted">This response could not carry the details — '
                + 'only the header summary, which never includes messages. The '
                + 'application error log has them.</p>'
                + serverLogSection(entry);
        }

        return '<p><strong>' + (data.count || 0) + ' exception(s) / error(s)</strong></p>'
            + exceptionTable(rows, false) + serverLogSection(entry);
    }

    /** The Exceptions table, with or without the request column. */
    function exceptionTable(rows, withSource) {
        var span = withSource ? 5 : 4;
        return '<table class="pdb-table"><thead><tr><th>Type</th><th>Class</th><th>Message</th>'
            + '<th>Location</th>' + (withSource ? '<th>Request</th>' : '') + '</tr></thead><tbody>'
            + (rows || '<tr><td colspan="' + span + '" style="color:#a6e3a1">No exceptions</td></tr>')
            + '</tbody></table>';
    }

    /**
     * What the browser threw, oldest first.
     *
     * The server's Exceptions tab and this one are deliberately separate: they
     * have different fields (a PHP file and line versus a JavaScript stack),
     * different lifetimes (one request versus the whole page), and mixing them
     * would make "where did this come from" ambiguous in the one table somebody
     * reads while something is broken.
     *
     * Each row names the request it happened after, which is the correlation the
     * roadmap asked for — the request is a fact about the error, printed on it,
     * rather than a filter the reader has to apply.
     */
    function renderClientErrors() {
        if (!clientErrors.length) {
            return '<p class="pdb-muted">Nothing has been thrown in the browser.</p>';
        }

        var rows = '';
        clientErrors.forEach(function (item) {
            var during = item.during;
            rows += '<tr>'
                + '<td class="pdb-time">' + esc(clockTime(item.at)) + '</td>'
                + '<td style="color:#fab387;white-space:nowrap">' + esc(item.kind)
                + (item.status ? ' ' + esc(item.status) : '')
                + (item.times > 1
                    ? ' <span class="pdb-muted">×' + item.times + '</span>'
                    : '')
                + '</td>'
                + '<td class="pdb-sql">' + esc(item.message)
                + (item.stack
                    ? '<details class="pdb-body"><summary>stack</summary>'
                        + '<pre class="pdb-pre">' + esc(maskFlat(item.stack)) + '</pre></details>'
                    : '')
                + '</td>'
                + '<td class="pdb-muted pdb-sql">'
                + (during
                    ? 'after ' + esc(during.method || '') + ' ' + esc(shortPath(during.path || ''))
                        + (during.status ? ' · ' + esc(during.status) : '')
                    : '—')
                + '</td>'
                + '</tr>';
        });

        return '<p><strong>' + clientErrors.length + ' browser error(s)</strong> '
            + '<span class="pdb-muted">— raised by this page, not by the server. '
            + 'The server\'s own are in <strong>Exceptions</strong>.</span></p>'
            + '<table class="pdb-table"><thead><tr><th>Time</th><th>Kind</th>'
            + '<th>Message</th><th>Request</th></tr></thead><tbody>'
            + rows + '</tbody></table>';
    }

    /**
     * A section heading inside the panel.
     *
     * One place, because these were written as `<p><strong>…</strong></p>` at each
     * site and therefore had no size of their own: a heading, an explanation and a
     * table cell all came out at the same size, which is a panel with no hierarchy.
     *
     * @param {string} text
     */
    function heading(text) {
        return '<p class="pdb-h">' + esc(text) + '</p>';
    }

    /**
     * What the browser knows about this application.
     *
     * Three sections, and a lead that says so — because the tab used to open on
     * three boxes with no statement of what they were for. Reported plainly: "I do
     * not understand what this does, especially in an MVC app." In an MVC
     * application two of the three are *not applicable* rather than empty, and
     * saying "the page injected no window.__PRAMNOS__" answered a question nobody
     * had asked, in the name of a variable that is the framework's business.
     */
    function renderClientState() {
        return '<p class="pdb-lead">What the browser knows about this application: '
            + 'what the server told it, where it thinks it is, and what it has '
            + 'stored.</p>'
            + runtimeSection() + routerSection() + storageSection();
    }

    /**
     * The configuration the server handed the browser, masked.
     *
     * A single-page application is given its API address, its key and its feature
     * flags before any of its code runs, and that hand-off is invisible in the
     * source of either side. It is the first thing to check when calls go to the
     * wrong place.
     *
     * A server-rendered page hands the browser nothing, because it has no reason
     * to: it renders the pages itself. That is why the absence is read differently
     * depending on where we are — the data island is the fact that settles it, and
     * it exists only on a page the framework rendered.
     */
    function runtimeSection() {
        var runtime = window.__PRAMNOS__;
        var lead = heading('What the server told the browser');

        if (!runtime || typeof runtime !== 'object') {
            if (hasMvcPage) {
                return lead + '<p class="pdb-muted">This page was rendered by the '
                    + 'server, so the browser was given no configuration of its own — '
                    + 'nothing to show here, and nothing wrong. This section is for a '
                    + 'single-page application, where the server hands the front end '
                    + 'its API address and key before any code runs.</p>';
            }

            return lead + '<p style="color:#fab387">The front end was given no '
                + 'configuration.</p><p class="pdb-muted">In a single-page '
                + 'application the server\'s shell normally hands it the API address '
                + 'and key before any code runs. Without that, the API client falls '
                + 'back to its <strong>built-in defaults</strong> — a common reason '
                + 'for calls going to the wrong path, or being refused for want of a '
                + 'key.</p>';
        }

        return lead
            + '<pre class="pdb-pre">' + esc(JSON.stringify(mask(runtime), null, 2)) + '</pre>'
            + '<p class="pdb-muted">Secrets are masked. In the page source this is '
            + '<code>window.__PRAMNOS__</code>.</p>';
    }

    /**
     * The URL, and where the router says it is inside it.
     *
     * The base is printed next to the path because that pair is the whole
     * deep-link failure: an application mounted at `/app` whose router has an
     * empty base resolves every deep link to its home screen, and nothing on
     * screen says why.
     */
    function routerSection() {
        var loc = typeof location === 'undefined' ? {} : location;
        var base = clientRoute && clientRoute.base !== null && clientRoute.base !== undefined
            ? clientRoute.base
            : ((window.__PRAMNOS__ || {}).routerBase);

        var rows = ''
            + row('URL', (loc.pathname || '') + (loc.search || '') + (loc.hash || ''))
            + (base === undefined || base === null
                ? ''
                : row('Router base', base === '' ? '(site root)' : base));

        if (clientRoute) {
            rows += row('Route', clientRoute.name);
            if (clientRoute.params) {
                rows += row('Params', JSON.stringify(mask(clientRoute.params)));
            }
            rows += row('Since', clockTime(clientRoute.at));
        }

        var note = '';
        if (!clientRoute) {
            note = hasMvcPage
                ? '<p class="pdb-muted">The server decided which page this URL is, so '
                    + 'there is no client-side route to report — see the '
                    + '<strong>Route</strong> tab for the controller and action it '
                    + 'chose. This section fills in for a single-page application, '
                    + 'where the routing happens in the browser.</p>'
                : '<p class="pdb-muted">No router has reported a route, so the URL '
                    + 'above is all this panel knows. A router can say where it '
                    + 'arrived by calling <code>reportRoute(name, { base })</code> '
                    + 'from <code>lib/debug.js</code> — the scaffolded one does.</p>';
        }

        // The mismatch worth naming, since it is the reported failure: a path that
        // does not start with the base cannot resolve to anything but the fallback.
        if (base && loc.pathname && loc.pathname.indexOf(base) !== 0) {
            note += '<p style="color:#fab387">The current path does not start with the '
                + 'router base, so no route can match it — this is the deep link that '
                + '"404s" and quietly lands on the home screen instead.</p>';
        }

        return heading('Where the application thinks it is')
            + '<table class="pdb-table"><tbody>' + rows + '</tbody></table>' + note;
    }

    /** One label/value row, for the small tables in this tab. */
    function row(label, value) {
        return '<tr><td style="color:#89b4fa;white-space:nowrap">' + esc(label) + '</td>'
            + '<td class="pdb-sql">' + esc(value) + '</td></tr>';
    }

    /**
     * Everything in `localStorage` and `sessionStorage`, with secrets masked.
     *
     * A stale token in `localStorage` is already documented as a trap in the
     * generated front-end testing guide: it survives a deploy, the server signs
     * with a new key, and every call fails in a way that looks like a server
     * problem. Being able to *see* the key is the difference between that and an
     * afternoon.
     *
     * Values are masked by key name and truncated. Access itself can throw —
     * private mode, a blocked origin — and that is reported rather than allowed
     * to empty the tab.
     */
    function storageSection() {
        return heading('What the browser has stored')
            + '<p class="pdb-muted">Where a stale sign-in token shows up: it survives a '
            + 'deploy, the server signs with a new key, and every call then fails in a '
            + 'way that looks like a server problem. Secret-looking values are masked '
            + 'by key name.</p>'
            + storageTable('localStorage')
            + storageTable('sessionStorage');
    }

    /**
     * One storage area as a table, or the reason it could not be read.
     *
     * Everything is inside one `try`, because storage refuses in more than one
     * place: a blocked origin throws on *access* to the object, and a hostile or
     * exotic implementation can throw on `length` or `getItem` instead. Only the
     * first of those was ever guarded, which is how a private-mode browser could
     * have got a half-drawn panel.
     */
    function storageTable(which) {
        try {
            // Bare globals, not `window[which]`: reading storage through `window`
            // misses a host that exposes it only as a global, and the rest of this
            // file already reads `localStorage` directly for the hidden flag.
            var store = which === 'sessionStorage'
                ? (typeof sessionStorage === 'undefined' ? null : sessionStorage)
                : (typeof localStorage === 'undefined' ? null : localStorage);

            if (!store) {
                return '<p class="pdb-muted">' + esc(which) + ' is unavailable here.</p>';
            }

            var rows = '';
            for (var i = 0; i < store.length; i++) {
                var name = store.key(i);
                var value = String(store.getItem(name));
                // Masked by key name, and the length kept: "there is a token and
                // it is 900 characters long" is the whole finding, and printing
                // the token would put a credential in a screenshot.
                var shown = SECRET.test(name)
                    ? '••••••••  ' + value.length + ' chars'
                    : (value.length > VALUE_LIMIT
                        ? value.slice(0, VALUE_LIMIT) + '… (' + value.length + ' chars)'
                        : value);
                rows += '<tr><td style="color:#89b4fa;white-space:nowrap">' + esc(name) + '</td>'
                    + '<td class="pdb-sql">' + esc(shown) + '</td></tr>';
            }

            return '<table class="pdb-table"><thead><tr><th>' + esc(which) + '</th><th>Value</th>'
                + '</tr></thead><tbody>'
                + (rows || '<tr><td colspan="2" class="pdb-muted">empty</td></tr>')
                + '</tbody></table>';
        } catch (e) {
            return '<p class="pdb-muted">' + esc(which) + ' cannot be read here '
                + '(private mode, or a blocked origin).</p>';
        }
    }

    // ── API playground ──────────────────────────────────────────────────────

    /**
     * Call the application's own API, from the toolbar, and read the answer with
     * its `_debug` attached.
     *
     * The endpoint list comes from the OpenAPI document the project already
     * generates, so this costs nothing to keep current: an endpoint appears here
     * because it is documented, and one that is missing is a documentation bug
     * worth knowing about too.
     *
     * The request is a real one. It goes through the same server, the same
     * middleware and the same authentication as the application's, is recorded
     * in the requests list like any other, and can therefore be read in every
     * other tab — which is the point. A playground that stubbed the call would
     * answer a question nobody asked.
     */
    function renderPlayground() {
        if (pg.loading) {
            return '<p class="pdb-muted">Reading the OpenAPI document…</p>';
        }

        if (pg.error) {
            return heading('API playground')
                + '<p style="color:#f38ba8">' + esc(pg.error) + '</p>'
                + '<p class="pdb-muted">The endpoint list comes from the project\'s '
                + 'OpenAPI document. Generate it with <code>npm run docs:build</code> '
                + '(or <code>./dockernpm run docs:build</code>), then '
                + '<button class="pdb-btn pdb-pg-reload">try again</button>.</p>';
        }

        if (!pg.doc) {
            // First open. The fetch is started here rather than on boot: a
            // document nobody looked at should not cost a request on every page.
            loadOpenApi();
            return '<p class="pdb-muted">Reading the OpenAPI document…</p>';
        }

        return heading('API playground')
            + '<p class="pdb-lead">' + pg.ops.length + ' documented endpoint(s), called '
            + 'against <code>' + esc(apiBase() || '(same origin)') + '</code>. The call is '
            + 'real: it is recorded in the requests list like any other, so every tab '
            + 'answers for it.</p>'
            + playgroundForm()
            + playgroundResult()
            + playgroundList();
    }

    /** The endpoints, as clickable rows. */
    function playgroundList() {
        var rows = '';
        pg.ops.forEach(function (op, index) {
            rows += '<tr class="pdb-row pdb-pg-op' + (index === pg.selected ? ' pdb-selected' : '')
                + '" data-op="' + index + '">'
                + '<td style="color:#89b4fa;white-space:nowrap">' + esc(op.method) + '</td>'
                + '<td class="pdb-sql">' + esc(op.path) + '</td>'
                + '<td class="pdb-muted">' + esc(op.summary || '') + '</td></tr>';
        });

        if (!rows) {
            return '<p class="pdb-muted">The document lists no paths.</p>';
        }

        return '<table class="pdb-table"><thead><tr><th>Method</th><th>Path</th>'
            + '<th>Summary</th></tr></thead><tbody>' + rows + '</tbody></table>';
    }

    /**
     * The request being composed, once an endpoint is picked.
     *
     * The parameters the document declares are fields, because editing `{braces}`
     * inside a path string is not a form — and query parameters were not offered at
     * all, so the only way to send one was to type it into the path by hand.
     */
    function playgroundForm() {
        if (pg.selected < 0 || !pg.ops[pg.selected]) {
            return '<p class="pdb-muted" style="margin-top:6px">Pick an endpoint above '
                + 'to call it.</p>';
        }

        var op = pg.ops[pg.selected];
        var token = storedToken();

        var fields = '';
        (op.params || []).forEach(function (param, index) {
            fields += '<tr><td style="color:#89b4fa;white-space:nowrap">' + esc(param.name)
                + (param.required ? ' <span style="color:#f38ba8">*</span>' : '')
                + '</td>'
                + '<td class="pdb-muted" style="white-space:nowrap">' + esc(param.in) + '</td>'
                + '<td><input class="pdb-pg-input pdb-pg-param" id="pdb-pg-p' + index + '" '
                + 'value="' + escAttr(pg.params[param.name] === undefined ? '' : pg.params[param.name])
                + '" placeholder="' + escAttr(param.description || param.name) + '"></td></tr>';
        });

        return '<div style="margin-top:8px">'
            + heading('Request')
            + (fields !== ''
                ? '<table class="pdb-table"><thead><tr><th>Parameter</th><th>In</th>'
                    + '<th>Value</th></tr></thead><tbody>' + fields + '</tbody></table>'
                : '')
            + '<p style="margin-top:6px"><strong>' + esc(op.method) + '</strong> '
            + '<input id="pdb-pg-path" class="pdb-pg-input" value="' + escAttr(pg.path) + '">'
            + ' <button class="pdb-btn pdb-btn-primary" id="pdb-pg-send">Send</button></p>'
            + (op.path.indexOf('{') > -1 && (op.params || []).length === 0
                ? '<p class="pdb-muted">This path has parameters the document does not '
                    + 'declare — replace the <code>{braces}</code> above by hand.</p>'
                : '')
            + (op.body !== null
                ? '<textarea id="pdb-pg-body" class="pdb-pg-input" rows="5">'
                    + esc(pg.body) + '</textarea>'
                : '')
            + '<p class="pdb-muted">'
            + (token
                ? 'Sending the stored token from <code>' + esc(token.key) + '</code>'
                    + ' <button class="pdb-btn pdb-pg-token">'
                    + (pg.sendToken ? 'don\'t' : 'do') + '</button>'
                : 'No stored token was found, so this call is anonymous unless a '
                    + 'session cookie authenticates it.')
            + '</p></div>';
    }

    /**
     * What came back, directly under the button that asked for it.
     *
     * Reported: "when I press send, where do I see the result?" — it was rendered
     * below the endpoint list, which on a full document meant somewhere off the
     * bottom of the panel. It is now the first thing after the form, the status is
     * announced in words as well as a number, and while a call is in flight the
     * panel says so rather than looking unchanged.
     */
    function playgroundResult() {
        if (pg.sending) {
            return heading('Response')
                + '<p class="pdb-muted">Sending…</p>';
        }

        if (!pg.result) {
            return '';
        }

        var r = pg.result;
        var ok = r.status >= 200 && r.status < 300;
        var colour = ok ? '#a6e3a1' : '#f38ba8';

        var shown = withoutDebugKey(r.text);

        return heading('Response')
            + '<p><strong style="color:' + colour + '">'
            + esc(r.status || 'failed') + (r.statusText ? ' ' + esc(r.statusText) : '')
            + '</strong> <span class="pdb-muted">in ' + esc(r.ms) + 'ms'
            + (r.url ? ' — ' + esc(r.url) : '') + '</span></p>'
            + '<pre class="pdb-pre">' + esc(maskFlat(formatBody(shown.text))) + '</pre>'
            + '<p class="pdb-muted">'
            + (shown.stripped
                ? 'The response\'s own <code>_debug</code> payload is left out of this '
                    + 'view — it is what every other tab is already showing for this '
                    + 'call. '
                : '')
            + 'This call is in the <strong>requests</strong> list too, so Time, SQL and '
            + 'Logs answer for it like any other request.</p>';
    }

    /**
     * The response body without the debug payload it carries.
     *
     * In development every JSON response carries a `_debug` key, and here it is
     * pure noise: it is an order of magnitude larger than most answers, and it is
     * the same data every other tab of this toolbar is already showing for this
     * exact call. Left in, it pushed `{"user":{…}}` — the thing the reader pressed
     * Send to see — off the top of a 240px box.
     *
     * Anything that is not a JSON object is returned untouched, and a failure to
     * parse costs nothing: the raw text is what gets shown.
     *
     * @param  {string} text
     * @returns {{text: string, stripped: boolean}}
     */
    function withoutDebugKey(text) {
        try {
            var parsed = JSON.parse(text);
            if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)
                || parsed._debug === undefined) {
                return { text: text, stripped: false };
            }
            delete parsed._debug;

            return { text: JSON.stringify(parsed), stripped: true };
        } catch (e) {
            return { text: text, stripped: false };
        }
    }

    /**
     * The word that goes with a status code.
     *
     * "404" and "404 Not Found" carry the same information and are not equally
     * quick to read, and this panel is read while something is already going wrong.
     * Only the codes an API actually answers with — a full table would be a table.
     *
     * @param {number} status
     */
    function statusPhrase(status) {
        var phrases = {
            200: 'OK', 201: 'Created', 202: 'Accepted', 204: 'No Content',
            301: 'Moved Permanently', 302: 'Found', 304: 'Not Modified',
            400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden',
            404: 'Not Found', 405: 'Method Not Allowed', 409: 'Conflict',
            410: 'Gone', 422: 'Unprocessable Content', 429: 'Too Many Requests',
            500: 'Server Error', 502: 'Bad Gateway', 503: 'Service Unavailable',
            504: 'Gateway Timeout'
        };

        return phrases[status] || '';
    }

    /**
     * Where the OpenAPI document is, from the browser's point of view.
     *
     * Derived from the API prefix the shell injected: the generator writes
     * `www/api/openapi.json`, which is one level above the versioned prefix the
     * client calls (`/api/1.0`). Falling back to `/api/openapi.json` covers a
     * server-rendered page, which has no injected configuration at all.
     */
    function openApiUrl() {
        var prefix = (window.__PRAMNOS__ || {}).apiPrefix;
        if (typeof prefix !== 'string' || prefix === '') {
            return '/api/openapi.json';
        }
        // Drop the trailing version segment: /api/1.0 → /api
        var trimmed = prefix.replace(/\/+$/, '').replace(/\/[^/]*$/, '');
        return (trimmed || '/api') + '/openapi.json';
    }

    /**
     * The base every playground call is sent against.
     *
     * The injected prefix wins over the document's own `servers` list: a
     * generated document names production URLs, and sending a development call
     * to production because a list happened to be ordered that way is the one
     * mistake this tab must not make.
     */
    function apiBase() {
        var prefix = (window.__PRAMNOS__ || {}).apiPrefix;
        if (typeof prefix === 'string' && prefix !== '') {
            return prefix.replace(/\/+$/, '');
        }
        var servers = (pg.doc && pg.doc.servers) || [];
        var url = servers.length && servers[0] && servers[0].url ? String(servers[0].url) : '';
        // Only a same-origin server. A document's production URL is not somewhere
        // a debug toolbar should be sending anything.
        return url.indexOf('http') === 0 ? '' : url.replace(/\/+$/, '');
    }

    /** Read the document once, then draw. */
    function loadOpenApi() {
        var send = rawFetch || (typeof window.fetch === 'function' ? window.fetch : null);
        if (!send) {
            pg.error = 'This browser has no fetch, so the document cannot be read.';
            return;
        }

        pg.loading = true;
        var url = openApiUrl();

        send(url, { credentials: 'same-origin' }).then(function (response) {
            if (!response || response.status !== 200) {
                throw new Error('The document at ' + url + ' answered '
                    + ((response && response.status) || 'nothing') + '.');
            }
            return response.text();
        }).then(function (text) {
            pg.doc = JSON.parse(text);
            pg.ops = operationsOf(pg.doc);
            pg.loading = false;
            pg.error = null;
            render();
        }).catch(function (e) {
            pg.loading = false;
            pg.error = e && e.message ? e.message : 'The OpenAPI document could not be read.';
            render();
        });
    }

    /**
     * Flatten an OpenAPI document into one row per operation.
     *
     * Paths are normalised on the way through: a generated document can carry a
     * doubled slash (`//status`) when a prefix and a path are joined without
     * care, and a playground that sent that verbatim would produce a 404 that
     * looks like a routing bug in the application.
     */
    function operationsOf(doc) {
        var ops = [];
        var paths = (doc && doc.paths) || {};

        Object.keys(paths).forEach(function (path) {
            var item = paths[path] || {};
            Object.keys(item).forEach(function (method) {
                if (['get', 'post', 'put', 'patch', 'delete'].indexOf(method) === -1) {
                    return;
                }
                var op = item[method] || {};
                ops.push({
                    method: method.toUpperCase(),
                    path: String(path).replace(/\/{2,}/g, '/'),
                    summary: op.summary || op.operationId || '',
                    body: bodySampleOf(op),
                    // Path- and query-level parameters both, since a document may
                    // declare them on the path item rather than on the operation.
                    params: parametersOf(item.parameters, op.parameters)
                });
            });
        });

        return ops;
    }

    /**
     * The parameters an operation declares, as fields the reader can fill in.
     *
     * Reported: "how do I define parameters?" — and the honest answer was that you
     * edited `{braces}` inside a path string, which is not a form and does not
     * mention the query parameters at all. The document already declares both, so
     * they become inputs: path parameters substituted into the path, query
     * parameters appended.
     *
     * @param  {Array} onPath      Parameters declared on the path item
     * @param  {Array} onOperation Parameters declared on the operation
     * @returns {Array<{name: string, in: string, required: boolean, description: string}>}
     */
    function parametersOf(onPath, onOperation) {
        var seen = {};
        var out = [];

        [onPath || [], onOperation || []].forEach(function (list) {
            (list || []).forEach(function (spec) {
                if (!spec || !spec.name || seen[spec.in + ':' + spec.name]) {
                    return;
                }
                if (spec.in !== 'path' && spec.in !== 'query') {
                    // A header or cookie parameter is the credential machinery,
                    // which this tab handles itself rather than asking about.
                    return;
                }
                seen[spec.in + ':' + spec.name] = true;
                out.push({
                    name: String(spec.name),
                    in: String(spec.in),
                    required: !!spec.required,
                    description: String(spec.description || '')
                });
            });
        });

        return out;
    }

    /**
     * A starting point for the request body, or null when the operation takes none.
     *
     * An example from the document is used as it is; otherwise the schema's
     * properties become a skeleton with their types as placeholders. One level
     * deep on purpose — a generated skeleton of a deeply nested schema is harder
     * to correct than an empty object is to fill.
     */
    function bodySampleOf(op) {
        var content = op && op.requestBody && op.requestBody.content;
        if (!content) {
            return null;
        }

        var json = content['application/json'];
        if (!json) {
            return '{}';
        }
        if (json.example !== undefined) {
            return JSON.stringify(json.example, null, 2);
        }

        var properties = (json.schema && json.schema.properties) || null;
        if (!properties) {
            return '{}';
        }

        var sample = {};
        Object.keys(properties).forEach(function (name) {
            var spec = properties[name] || {};
            sample[name] = spec.example !== undefined ? spec.example : ('<' + (spec.type || 'value') + '>');
        });

        return JSON.stringify(sample, null, 2);
    }

    /**
     * A bearer token the page has stored, if there is one.
     *
     * Found by key name, because the key is the application's to choose (the
     * scaffold uses `<app>-token`). The value is never shown — only which key it
     * came from, which is what a reader needs in order to distrust it.
     */
    function storedToken() {
        try {
            var store = typeof localStorage === 'undefined' ? null : localStorage;
            if (!store) {
                return null;
            }
            for (var i = 0; i < store.length; i++) {
                var key = store.key(i);
                if (/token/i.test(key)) {
                    var value = store.getItem(key);
                    if (value) {
                        return { key: key, value: value };
                    }
                }
            }
        } catch (e) {
            /* storage that refuses is the same as storage with no token in it */
        }
        return null;
    }

    /** Take whatever the reader has typed out of the DOM and into state. */
    function capturePlaygroundInputs() {
        try {
            var pathEl = panelEl && panelEl.querySelector('#pdb-pg-path');
            if (pathEl && typeof pathEl.value === 'string') {
                pg.path = pathEl.value;
            }
            var bodyEl = panelEl && panelEl.querySelector('#pdb-pg-body');
            if (bodyEl && typeof bodyEl.value === 'string') {
                pg.body = bodyEl.value;
            }

            var op = pg.ops[pg.selected];
            ((op && op.params) || []).forEach(function (param, index) {
                var el = panelEl && panelEl.querySelector('#pdb-pg-p' + index);
                if (el && typeof el.value === 'string') {
                    pg.params[param.name] = el.value;
                }
            });
        } catch (e) {
            /* an input that cannot be read leaves the last known value in place */
        }
    }

    /**
     * The path and query string this call is being sent to.
     *
     * Path parameters are substituted where the document said they go; query
     * parameters are appended. A parameter left blank is left out rather than sent
     * empty — `?status=` and "no status filter" are different requests, and the
     * second is what an empty box means.
     *
     * @returns {string}
     */
    function playgroundTarget() {
        var op = pg.ops[pg.selected];
        var path = pg.path;
        var query = [];

        ((op && op.params) || []).forEach(function (param) {
            var value = pg.params[param.name];
            if (value === undefined || String(value) === '') {
                return;
            }
            if (param.in === 'path') {
                path = path.split('{' + param.name + '}').join(encodeURIComponent(value));
            } else {
                query.push(encodeURIComponent(param.name) + '=' + encodeURIComponent(value));
            }
        });

        return path + (query.length ? (path.indexOf('?') > -1 ? '&' : '?') + query.join('&') : '');
    }

    /** Pick an endpoint, and start composing a call to it. */
    function selectOperation(index) {
        var op = pg.ops[index];
        if (!op) {
            return;
        }
        pg.selected = index;
        pg.path = op.path;
        pg.params = {};
        pg.body = op.body === null ? '' : op.body;
        pg.result = null;
        render();
    }

    /**
     * Send the composed request.
     *
     * Recorded explicitly rather than left to the transport wrapper: a SPA has no
     * wrapper (its API client reports its own calls), and on a server-rendered
     * page the unwrapped `rawFetch` is used precisely so that this records once
     * rather than twice.
     */
    function playgroundSend() {
        capturePlaygroundInputs();

        var op = pg.ops[pg.selected];
        if (!op) {
            return;
        }

        var send = rawFetch || (typeof window.fetch === 'function' ? window.fetch : null);
        if (!send) {
            return;
        }

        var headers = { Accept: 'application/json' };
        var runtime = window.__PRAMNOS__ || {};
        if (runtime.apiKey) {
            headers.apiKey = runtime.apiKey;
        }
        var token = pg.sendToken ? storedToken() : null;
        if (token) {
            headers.accessToken = token.value;
        }

        var body = null;
        if (op.body !== null && pg.body.trim() !== '') {
            headers['Content-Type'] = 'application/json';
            body = pg.body;
        }

        var target = playgroundTarget();
        var url = (apiBase() + target).replace(/([^:])\/{2,}/g, '$1/');
        var started = now();

        pg.result = null;
        pg.sending = true;
        render();

        send(url, {
            method: op.method,
            headers: headers,
            credentials: 'same-origin',
            body: body
        }).then(function (response) {
            return response.text().then(function (text) {
                return { status: response.status, text: text };
            });
        }).then(function (answer) {
            var ms = Math.round(now() - started);
            pg.sending = false;
            pg.result = {
                status: answer.status,
                statusText: statusPhrase(answer.status),
                ms: ms,
                text: answer.text,
                url: target
            };
            // Recorded like any other call, so the reader can open Time, SQL or
            // Logs for the request they just made.
            record(op.method, target, answer.status, fromText(answer.text), {
                ms: ms,
                body: body,
                kind: 'playground'
            });
            render();
        }).catch(function (e) {
            pg.sending = false;
            pg.result = {
                status: 0,
                statusText: 'no response',
                ms: Math.round(now() - started),
                text: 'The request failed before a response arrived: '
                    + ((e && e.message) || 'unknown error'),
                url: target
            };
            render();
        });
    }

    // ── Interaction ─────────────────────────────────────────────────────────

    /** Handle every click inside the bar. */
    function onClick(event) {
        try {
            var copy = event.target.closest('.pdb-copy');
            if (copy) {
                event.stopPropagation();
                copyText(copy.dataset.copy, copy);
                return;
            }

            var catBtn = event.target.closest('[data-cat-toggle]');
            if (catBtn) {
                event.stopPropagation();
                var catKey = catBtn.dataset.catToggle;
                openCategory = openCategory === catKey ? null : catKey;
                render();
                positionCategoryMenu();
                return;
            }

            var tab = event.target.closest('.pdb-tab');
            if (tab) {
                // Clicking the open tab closes the panel — the bar stays, which is
                // what the ✕ is for.
                activeTab = activeTab === tab.dataset.panel ? null : tab.dataset.panel;
                openCategory = null;
                rememberTab();
                render();
                return;
            }

            if (event.target.closest('#pdb-close-btn')) {
                if (activeTab !== null) {
                    // Panel is expanded: first close/collapse the panel
                    activeTab = null;
                    openCategory = null;
                    rememberTab();
                    render();
                } else {
                    // Panel is already collapsed: hide the entire toolbar
                    setHidden(true);
                }
                return;
            }

            if (openCategory && !event.target.closest('.pdb-more-wrap')) {
                openCategory = null;
                render();
            }

            if (event.target.closest('#pdb-clear')) {
                event.stopPropagation();
                clearEntries();
                return;
            }

            var ask = event.target.closest('.pdb-fetch-logs');
            if (ask) {
                event.stopPropagation();
                fetchServerLogs(ask.dataset.request, ask);
                return;
            }

            if (event.target.closest('.pdb-unpick')) {
                clearPick();
                return;
            }

            // The playground's own controls, before the generic row handler: its
            // endpoint rows carry `pdb-row` for the hover and selection styling,
            // and must not be read as "the reader picked a request".
            if (event.target.closest('#pdb-pg-send')) {
                event.stopPropagation();
                playgroundSend();
                return;
            }

            if (event.target.closest('.pdb-pg-reload')) {
                event.stopPropagation();
                pg.error = null;
                loadOpenApi();
                render();
                return;
            }

            if (event.target.closest('.pdb-pg-token')) {
                event.stopPropagation();
                capturePlaygroundInputs();
                pg.sendToken = !pg.sendToken;
                render();
                return;
            }

            var operation = event.target.closest('.pdb-pg-op');
            if (operation) {
                event.stopPropagation();
                selectOperation(Number(operation.dataset.op));
                return;
            }

            var row = event.target.closest('.pdb-row');
            if (row) {
                // Clicking the row that is already selected releases it, the way
                // clicking the open tab closes the panel.
                if (userPicked && Number(row.dataset.entry) === selected) {
                    clearPick();
                    return;
                }
                // From here on the selection is theirs: later requests are added
                // to the list without moving the panel off what they are reading.
                userPicked = true;
                selected = Number(row.dataset.entry);
                // The open tab stays open. Switching to SQL on every pick meant
                // somebody comparing the same tab across two requests had to
                // navigate back to it each time, and a tab changing under a
                // click nobody aimed at it reads as the toolbar losing its place.
                render();
            }
        } catch (e) {
            /* a click handler that throws must not take the page with it */
        }
    }

    /**
     * Give the selection back to the toolbar.
     *
     * Back to the page's own request (or the newest, in a SPA), with the streams
     * showing everything again — the state the bar opens in.
     */
    function clearPick() {
        userPicked = false;
        selected = defaultSelection();
        render();
    }

    /** Put text on the clipboard, and say so on the button. */
    function copyText(text, button) {
        var original = button.innerHTML;
        var done = function () {
            button.classList.add('pdb-copied');
            button.textContent = '✓';
            setTimeout(function () {
                button.classList.remove('pdb-copied');
                button.innerHTML = original;
            }, 1500);
        };

        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(done, done);
            return;
        }

        var area = document.createElement('textarea');
        area.value = text;
        area.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(area);
        area.select();
        try {
            document.execCommand('copy');
        } catch (e) {
            /* clipboard refused; the button simply does not confirm */
        }
        document.body.removeChild(area);
        done();
    }

    // ── The requests a page makes after it renders ───────────────────────────

    /**
     * Wrap fetch and XMLHttpRequest so later requests are recorded too.
     *
     * Everything else about a server-rendered page describes the request that
     * built it. But a datatable pages and sorts, a form saves, a widget polls —
     * and those ran queries nobody was watching.
     */
    function wrapTransports() {
        var nativeFetch = window.fetch;
        if (typeof nativeFetch === 'function') {
            // Kept for the toolbar's own request to the log endpoint: going
            // through the wrapper would record the act of looking as one more
            // request to look at.
            rawFetch = nativeFetch;

            window.fetch = function (input, init) {
                var started = now();
                var method = (init && init.method) || (input && input.method) || 'GET';
                var url = typeof input === 'string' ? input : (input && input.url) || '';
                // Only the init object's body. A Request instance owns its own,
                // and reading it would consume the stream the application is
                // about to send.
                var body = captureBody(init && init.body);
                var result = nativeFetch.apply(this, arguments);

                try {
                    return result.then(function (response) {
                        harvest(method, url, response, Math.round(now() - started), body);
                        return response;
                    });
                } catch (e) {
                    return result;
                }
            };
        }

        var NativeXhr = window.XMLHttpRequest;
        if (typeof NativeXhr === 'function') {
            var open = NativeXhr.prototype.open;
            var send = NativeXhr.prototype.send;

            NativeXhr.prototype.open = function (method, url) {
                try {
                    this.__pdbMethod = method;
                    this.__pdbUrl = url;
                } catch (e) {
                    /* a frozen instance is not a reason to fail the request */
                }
                return open.apply(this, arguments);
            };

            NativeXhr.prototype.send = function (sendBody) {
                var xhr = this;
                var started = now();
                var body = captureBody(sendBody);
                try {
                    xhr.addEventListener('load', function () {
                        try {
                            var payload = fromText(xhr.responseText);
                            if (!payload) {
                                // The header is the only channel left when the
                                // body is not a JSON object — a 204, a redirect,
                                // an HTML fragment, or an error page where a
                                // handler took over. The fetch path has always
                                // read it; this one did not, so a datatable (all
                                // of which are XHR) reported "—" for every call
                                // that went wrong.
                                payload = headerPayload(function (name) {
                                    return xhr.getResponseHeader(name);
                                });
                            }
                            record(xhr.__pdbMethod || 'GET', xhr.__pdbUrl || '', xhr.status,
                                payload, { ms: Math.round(now() - started), body: body });
                        } catch (e) {
                            /* see above */
                        }
                    });
                } catch (e) {
                    /* see above */
                }
                return send.apply(this, arguments);
            };
        }
    }

    /** Monotonic-ish milliseconds, wherever they come from. */
    function now() {
        return typeof performance !== 'undefined' && performance.now
            ? performance.now()
            : Date.now();
    }

    /**
     * Read a response's `_debug`, without consuming the body.
     *
     * Only through `clone()`: the application still has to be able to read its
     * own response.
     */
    function harvest(method, url, response, ms, body) {
        try {
            var get = function (name) {
                return response.headers && response.headers.get
                    ? response.headers.get(name)
                    : null;
            };

            response.clone().text().then(function (text) {
                // A 204, a redirect, an HTML fragment or a top-level JSON array
                // has nowhere to put a `_debug` key. The headers carry a summary
                // — never statements, because headers land in access logs and
                // every proxy in between.
                var payload = fromText(text) || headerPayload(get);
                record(method, url, response.status, payload, { ms: ms, body: body });
            }, function () {
                record(method, url, response.status, headerPayload(get), { ms: ms, body: body });
            });
        } catch (e) {
            /* never interfere with the response */
        }
    }

    /** The `_debug` key of a JSON object body, or null. */
    function fromText(text) {
        try {
            var parsed = JSON.parse(text);
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
                ? (parsed._debug || null)
                : null;
        } catch (e) {
            return null;
        }
    }

    /**
     * Turn the `X-Pramnos-Debug` summary into a payload shape.
     *
     * `ApiDebugPayload::summary()` writes a JSON object, so that is what is tried
     * first. The `k=v;k=v` reading is kept as a fallback for a proxy or a gateway
     * that rewrites the value — a summary that cannot be read costs the whole row
     * its numbers, and those rows are the 204s and redirects with no body to
     * carry anything else.
     */
    function summaryFromHeader(header) {
        var out = {};
        try {
            var parsed = JSON.parse(header);
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                out = parsed;
            }
        } catch (e) {
            String(header).split(';').forEach(function (part) {
                var pair = part.split('=');
                if (pair.length === 2) {
                    out[pair[0].trim()] = pair[1].trim();
                }
            });
        }

        var ms = parseFloat(out.time);
        return {
            request: {
                time: isNaN(ms) ? null : ms,
                memory: out.memory ? parseFloat(out.memory) : null,
                // Without this a request that *died* — the one case the whole
                // log endpoint exists for — arrives anonymous and cannot be
                // asked about. The summary is the only thing such a response
                // gets to send, and the id is in it.
                id: out.id ? String(out.id) : undefined
            },
            queries: out.queries === undefined ? undefined : { count: Number(out.queries), queries: [] },
            route: out.route ? { route: out.route } : undefined,
            // A count with no items: the header never carries messages, and the
            // Exceptions tab says as much rather than drawing an empty table
            // under a heading that claims one. Knowing the call raised something
            // is what sends somebody to the server log.
            exceptions: out.errors === undefined
                ? undefined
                : { count: Number(out.errors), items: [], summary_only: true }
        };
    }

    /**
     * A payload built from whatever debug headers a response carries.
     *
     * @param {function(string): ?string} get Header reader for this response
     */
    function headerPayload(get) {
        try {
            var summary = get('X-Pramnos-Debug');
            if (summary) {
                return summaryFromHeader(summary);
            }

            // Server-Timing is the one a proxy is least likely to strip, and it
            // is present even where the summary header was never sent.
            var timing = get('Server-Timing');
            var m = timing ? /(?:^|,)\s*app;dur=([0-9.]+)/.exec(String(timing)) : null;
            return m ? { request: { time: parseFloat(m[1]), memory: null } } : null;
        } catch (e) {
            return null;
        }
    }

    // ── Boot ────────────────────────────────────────────────────────────────

    /**
     * Seed the page's own request from the data island, if there is one.
     *
     * Read from a `<div hidden>` rather than a `<script type="application/json">`
     * so there is nothing for a strict Content-Security-Policy to weigh up: a
     * data island inside a script element is handled differently by different
     * browsers, and this has to work on every install.
     */
    function boot() {
        try {
            // Before the island check, and therefore in a SPA too: the first
            // thing worth catching is often the error that stopped the
            // application from ever making a request.
            watchForErrors();

            var island = document.getElementById('pramnos-debug-data');
            if (!island) {
                // No island means a SPA: its shell never went through the
                // middleware, so there is no page request to seed — and no
                // transport wrapping either. The application's API client calls
                // record() itself, and wrapping fetch as well would record every
                // one of those twice.
                return;
            }

            hasMvcPage = true;

            var payload = JSON.parse(island.textContent || '{}');
            devPanelEnabled = payload.devpanel_enabled !== false;
            devPanelCustomUrl = payload.devpanel_url || null;
            adminerUrl = payload.adminer_url || null;
            record(
                payload.request_method || 'GET',
                payload.request_path || (location.pathname + location.search),
                payload.status_code || 200,
                payload,
                { kind: 'page', ms: null }
            );
            wrapTransports();
        } catch (e) {
            /* a toolbar that cannot boot must still leave the page alone */
        }
    }

    window.__pramnosDebugBar = {
        record: record,
        boot: boot,
        reportError: reportError,
        reportRoute: reportRoute
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
