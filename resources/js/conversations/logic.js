/**
 * The conversations' client logic, without a DOM: what the Svelte components draw is worked out
 * here, so it is tested with `node --test` (tests/js/conversations-logic.test.js) and the components
 * stay drawing.
 */

/** Two messages by the same writer this close together read as one block. */
export const RUN_SECONDS = 5 * 60;

/** How long a "is typing" lasts without being said again. */
export const TYPING_MS = 6000;

/**
 * A list of messages with one more, or one changed: by id, oldest first, the newer copy kept.
 *
 * @param {Array<object>} list
 * @param {object} message
 * @returns {Array<object>}
 */
export function upsert(list, message) {
    const rest = list.filter((m) => m.id !== message.id);
    rest.push(message);
    rest.sort((a, b) => a.id - b.id);

    return rest;
}

/**
 * A list with a message replaced where it is, and nothing added where it is not.
 *
 * @param {Array<object>} list
 * @param {object} message
 * @returns {Array<object>}
 */
export function replace(list, message) {
    return list.map((m) => (m.id === message.id ? message : m));
}

/**
 * What a conversation shows, in order: a line for each day, a line where what was not read
 * starts, and each message — `continued` when it follows the same writer's within a few minutes,
 * so it is drawn without the name again. A deleted message is left out, unless it has a thread:
 * then it keeps its place, for the replies under it.
 *
 * @param {Array<object>} messages Oldest first
 * @param {number} lastReadId What the reader had read when the conversation opened
 * @param {number} me The reader
 * @returns {Array<{type: string, key: string, at?: number, message?: object, continued?: boolean}>}
 */
export function timeline(messages, lastReadId, me) {
    const items = [];
    let day = '';
    let previous = null;
    let marked = false;
    for (const message of messages) {
        if (message.deletedAt !== null && message.deletedAt !== undefined && !(message.replyCount > 0)) {
            continue;
        }
        const today = dayKey(message.at);
        if (today !== day) {
            items.push({ type: 'day', key: 'day-' + today, at: message.at });
            day = today;
            previous = null;
        }
        if (!marked && lastReadId > 0 && message.id > lastReadId && message.authorId !== me) {
            items.push({ type: 'new', key: 'new' });
            marked = true;
            previous = null;
        }
        const continued = previous !== null && previous.authorId === message.authorId && previous.authorType === message.authorType
            && message.authorType === 'user' && !message.card && message.at - previous.at < RUN_SECONDS;
        items.push({ type: 'message', key: 'm' + message.id, message, continued });
        previous = message;
    }

    return items;
}

/** A day as the reader's calendar has it, `YYYY-M-D`. */
export function dayKey(unix) {
    const d = new Date(unix * 1000);

    return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
}

/**
 * What the composer sends: each `@Name` the writer picked from the list written as `<@id>`, the
 * form a mention is kept in, so a name can change without the message changing.
 *
 * @param {string} text
 * @param {Array<{id: number, name: string}>} picked
 * @returns {string}
 */
export function mentionsToTokens(text, picked) {
    let out = text;
    // The longest names first, so "Μαρία Κ." is not taken for "Μαρία".
    for (const person of [...picked].sort((a, b) => b.name.length - a.name.length)) {
        out = out.split('@' + person.name).join('<@' + person.id + '>');
    }

    return out;
}

/**
 * A message's text for editing: each `<@id>` as `@Name`, and who those are, so sending it again
 * writes them back as they were.
 *
 * @param {string} body
 * @param {Object<number, string>} names
 * @returns {{text: string, picked: Array<{id: number, name: string}>}}
 */
export function tokensToText(body, names) {
    const picked = [];
    const text = body.replace(/<@(\d+)>/g, (whole, id) => {
        const name = names[id];
        if (!name) {
            return whole;
        }
        if (!picked.some((p) => p.id === Number(id))) {
            picked.push({ id: Number(id), name });
        }

        return '@' + name;
    });

    return { text, picked };
}

/**
 * The word being typed at the cursor when it starts with `@` or `:`, for the suggestions —
 * `{ sign, word, start }` — or null.
 *
 * @param {string} text
 * @param {number} cursor
 */
export function wordAt(text, cursor) {
    const before = text.slice(0, cursor);
    const match = /(^|\s)([@:])([\p{L}\p{N}_+-]*)$/u.exec(before);
    if (!match) {
        return null;
    }

    return { sign: match[2], word: match[3], start: cursor - match[3].length - 1 };
}

/**
 * The people a typed `@word` may mean: those whose name has a word starting with it, ignoring case
 * and accents, then `all` and `online` where they match.
 *
 * @param {Array<{id: number, name: string}>} people
 * @param {string} word
 * @param {number} me
 */
export function suggestPeople(people, word, me) {
    const w = fold(word);
    // Somebody inactive only when a whole part of their name is typed.
    const found = people.filter((p) => p.id !== me && fold(p.name).split(/\s+/)
        .some((part) => (p.inactive != null ? part === w : part.startsWith(w)))).slice(0, 8);
    for (const special of ['all', 'online']) {
        if (special.startsWith(w)) {
            found.push({ id: 0, name: special, special: true });
        }
    }

    return found;
}

/** A text without case and accents, `ς` as `σ`, for matching what was typed. */
export function fold(text) {
    return String(text).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/ς/g, 'σ');
}

/** Who is typing, without those who stopped: `{ id: { name, until } }` as of `now`. */
export function typingNow(typing, now) {
    const out = {};
    for (const [id, who] of Object.entries(typing)) {
        if (who.until > now) {
            out[id] = who;
        }
    }

    return out;
}

/** Two letters for a face: the first of the first two words. */
export function initials(name) {
    const parts = String(name || '?').trim().split(/\s+/).filter(Boolean);

    return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
}

/** A hue of its own for each person, the same every time. */
export function hue(id) {
    return (Number(id) * 137) % 360;
}

/** A size as people read it. */
export function size(bytes) {
    const value = Number(bytes || 0);
    if (value < 1024) {
        return value + ' B';
    }

    return value < 1024 * 1024 ? Math.round(value / 1024) + ' KB' : (value / (1024 * 1024)).toFixed(1).replace('.0', '') + ' MB';
}

/**
 * The command a text runs — `/jira NAN-6212` → `jira` — when it is one of the application's, else
 * null: `/shrug` that nobody declared is sent as it was written.
 *
 * @param {string} text
 * @param {Array<{name: string}>} list
 */
export function commandIn(text, list) {
    const match = /^\/([a-z0-9][a-z0-9_-]*)(\s|$)/i.exec(String(text).trim());

    return match && list.some((c) => c.name === match[1].toLowerCase()) ? match[1].toLowerCase() : null;
}

/** The widest a dock window is, with the gap beside it, in pixels. */
export const DOCK_WINDOW = 336;

/**
 * What the dock shows at a screen width: the windows that fit — the most recent ones, at most
 * three, one on a narrow screen — and the faces of the recent direct and group conversations
 * that have no window, at most three, none on a narrow screen — less those the reader put away.
 *
 * @param {Array<object>} conversations The reader's, as the sidebar has them
 * @param {Array<{id: number, min: boolean}>} windows Oldest first
 * @param {number} width
 * @param {Record<number, number>} hidden Faces put away: the newest message each had then
 * @returns {{windows: Array<{id: number, min: boolean}>, heads: Array<object>}}
 */
export function dockLayout(conversations, windows, width, hidden = {}) {
    const narrow = width < 761;
    const fit = narrow ? 1 : Math.max(1, Math.min(3, Math.floor((width - 96) / DOCK_WINDOW)));
    const shown = windows.slice(-fit);
    const open = new Set(shown.map((w) => w.id));
    const heads = narrow ? [] : conversations
        .filter((c) => c.members !== null && !open.has(c.id) && !c.archivedAt)
        .sort((a, b) => (b.lastMessageAt || 0) - (a.lastMessageAt || 0))
        .slice(0, 3)
        // A face put away stays away until a newer message; nothing takes its place.
        .filter((c) => hidden[c.id] === undefined || (c.lastMessageId || 0) > hidden[c.id]);

    return { windows: shown, heads };
}

/**
 * What this reader left in the sidebar — sections folded, each section's sort, only what is
 * unread, faces in the dock or not — from this browser. Nothing kept, or nothing readable, is the
 * sidebar as it starts.
 *
 * @returns {{collapsed: object, sort: object, unreadOnly: boolean, noFaces: boolean}}
 */
export function sidebarPrefs(me, storage = globalThis.localStorage) {
    const start = { collapsed: {}, sort: {}, unreadOnly: false, noFaces: false };
    try {
        const kept = JSON.parse(storage.getItem('pf-conv-side:' + me) || 'null');

        return kept && typeof kept === 'object' ? { ...start, ...kept } : start;
    } catch {
        return start;
    }
}

/** Keep what the reader left in the sidebar, for the next page; a browser that refuses keeps nothing. */
export function keepSidebarPrefs(me, prefs, storage = globalThis.localStorage) {
    try {
        storage.setItem('pf-conv-side:' + me, JSON.stringify(prefs));
    } catch {
        /* private window, or storage refused */
    }
}

const DRAFT = (me) => 'pf-conv-draft:' + me + ':';

/** What the reader left unsent in a conversation, from this browser, or ''. */
export function draftOf(me, conversationId, storage = globalThis.localStorage) {
    try {
        return JSON.parse(storage.getItem(DRAFT(me) + conversationId) || 'null')?.text ?? '';
    } catch {
        return '';
    }
}

/** Keep what is unsent in a conversation — or, when it is empty, forget it. */
export function keepDraft(me, conversationId, text, storage = globalThis.localStorage, now = Date.now()) {
    try {
        if (String(text).trim() === '') {
            storage.removeItem(DRAFT(me) + conversationId);
        } else {
            storage.setItem(DRAFT(me) + conversationId, JSON.stringify({ text, at: now }));
        }
    } catch {
        /* private window, or storage refused: the draft is not kept */
    }
}

/** When somebody inactive last signed in, as a date — or null for never. */
export function lastSignIn(since) {
    return since > 0 ? new Date(since * 1000).toLocaleDateString() : null;
}
