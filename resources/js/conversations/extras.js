/**
 * What the application adds to the conversations, set once at mount from `Client::props()`: the
 * reference types it draws as cards, and its `/commands`. The cards of references are read in
 * batches — every message asking at once makes one request — and kept for the page.
 */
let types = new Set();
let commandList = [];
let asks = false;
let gifs = null;                  // the GIF provider, where the application has one
let analyst = { name: 'Ask', avatar: null, mentions: ['ask'], html: '' };
const cards = new Map();          // key → card | null, or a promise while being read
let waiting = new Map();          // key → [resolve]
let timer = null;

/** The application's reference types and commands. */
export function configure(props) {
    types = new Set((props && props.refTypes) || []);
    commandList = (props && props.commands) || [];
    asks = !!(props && props.ask);
    gifs = (props && props.gifs && props.gifs.provider) || null;
    const a = (props && props.assistant) || {};
    analyst = { name: a.name || 'Ask', avatar: a.avatar || null, mentions: (a.mentions && a.mentions.length ? a.mentions : ['ask']).map(String),
        html: a.html || '' };
}

/** Whether a conversation is the reader's own with the analyst — the bot `Ask`, whatever it is called. */
export function isAnalyst(conversation) {
    return !!conversation && conversation.kind === 'bot' && conversation.name === 'Ask';
}

/**
 * The analyst's living picture follows its conversations: thinking while it answers, talking as
 * the answer arrives (pfAssistant, in pf-utils.js, where the page has it).
 */
export function analystState(state) {
    if (typeof window !== 'undefined' && window.pfAssistant && typeof window.pfAssistant.state === 'function') {
        window.pfAssistant.state(state);
    }
}

/** The analyst as the application names it: `{ name, avatar, mentions }` — `Ask` unless told otherwise. */
export function assistant() {
    return analyst;
}

/** The GIF provider the application has — `giphy`, `klipy` — or null for no GIFs. */
export function gifProvider() {
    return gifs;
}

/** Whether the reader may call on the analyst with `@Ask`. */
export function canAsk() {
    return asks;
}

/** The application's commands: `[{ name, description, usage }]`. */
export function commands() {
    return commandList;
}

/** The references of a message that the application draws. */
export function drawable(refs) {
    return (refs || []).filter((key) => types.has(String(key).split(':')[0].toLowerCase()));
}

/**
 * The cards of these references, read with whatever else was asked in the same moment.
 *
 * @param {{get: Function}} api
 * @param {string[]} keys
 * @returns {Promise<Object<string, ?object>>}
 */
export function cardsFor(api, keys) {
    const wanted = drawable(keys);
    const pending = wanted.filter((key) => !cards.has(key));
    if (pending.length === 0) {
        return Promise.resolve(Object.fromEntries(wanted.map((key) => [key, cards.get(key)])));
    }
    const promise = new Promise((resolve) => {
        pending.forEach((key) => waiting.set(key, (waiting.get(key) || []).concat([resolve])));
    }).then(() => Object.fromEntries(wanted.map((key) => [key, cards.get(key) ?? null])));
    if (!timer) {
        timer = setTimeout(() => flush(api), 0);
    }

    return promise;
}

/** Read what was asked, fifty at a time, and answer everybody who asked. */
function flush(api) {
    timer = null;
    const asked = waiting;
    waiting = new Map();
    const keys = [...asked.keys()];
    const batches = [];
    for (let i = 0; i < keys.length; i += 50) {
        batches.push(keys.slice(i, i + 50));
    }
    Promise.all(batches.map((batch) => api.get('refs', { keys: batch.join(',') })
        .then((answer) => batch.forEach((key) => cards.set(key, (answer.cards || {})[key] ?? null)))
        .catch(() => batch.forEach((key) => cards.set(key, null)))))
        .then(() => asked.forEach((resolvers) => resolvers.forEach((resolve) => resolve())));
}

/** Forget the cards read: tests. */
export function forget() {
    asks = false;
    cards.clear();
    waiting = new Map();
    timer = null;
}

// Where this script was loaded from: pf-chat.js, which draws Ask's charts, sits beside it.
const scriptSrc = typeof document !== 'undefined' && document.currentScript ? document.currentScript.src : '';
let chartScript = null;

/**
 * Ask's charts in a message (`data-pf-chart`), drawn as on Ask's page — by pf-chat.js, loaded
 * beside this script the first time a chart is there. Without it the chart's code stays. A Svelte
 * action: `<div use:charts={body}>`.
 */
export function charts(node) {
    const draw = () => {
        if (!node.querySelector('[data-pf-chart]:not([data-pf-chart-drawn])')) {
            return;
        }
        chartScript ??= window.PfChat?.drawCharts ? Promise.resolve() : new Promise((resolve) => {
            const script = document.createElement('script');
            script.src = scriptSrc.replace(/pf-conversations\.js(\?.*)?$/, 'pf-chat.js$1');
            script.onload = script.onerror = resolve;
            document.head.appendChild(script);
        });
        chartScript.then(() => window.PfChat?.drawCharts?.(node));
    };
    draw();

    return { update: draw };
}
