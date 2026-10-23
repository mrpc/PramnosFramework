/**
 * The conversations client's logic (`resources/js/conversations/logic.js`), without a DOM: what the
 * Svelte components draw is worked out here.
 *
 * What has to hold: a message arriving twice is kept once, in order; a day gets its line, the unread
 * start where the reader stopped — never at their own message — and the same writer's messages
 * within minutes read as one block; the sidebar counts unread and mentions only where the reader is
 * not looking and did not write; a picked `@Name` is sent as `<@id>` and comes back as `@Name` to
 * edit; `@` and `:` are recognised at the cursor; people are matched ignoring accents.
 *
 * Run:
 *   node --test tests/js/conversations-logic.test.js
 */
'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const load = () => import(pathToFileURL(path.join(__dirname, '..', '..', 'resources', 'js', 'conversations', 'logic.js')).href);

/** A message as the server gives it, with what a test needs to say. */
const message = (id, authorId, at, extra = {}) => ({ id, authorId, authorType: authorId === null ? 'system' : 'user', at, card: null, threadRootId: null, ...extra });

test('a message arriving twice is kept once, the newer copy, in order; replace adds nothing', async () => {
    // Arrange
    const { upsert, replace } = await load();
    const list = [message(1, 5, 100), message(3, 5, 300)];

    // Act
    const added = upsert(upsert(list, message(2, 7, 200)), message(2, 7, 200, { body: 'edited' }));
    const replaced = replace(list, message(9, 5, 900));

    // Assert
    assert.deepEqual(added.map((m) => m.id), [1, 2, 3], 'by id, oldest first');
    assert.equal(added[1].body, 'edited', 'the newer copy wins');
    assert.deepEqual(replaced.map((m) => m.id), [1, 3], 'a message not there is not added by replace');
});

test('a message stands by when it was written, then its id: history arriving later sits in its place, and unread starts after what was read', async () => {
    // Arrange — 7 was read; 9 is history written before it, 5 a message of the same second as 7
    const { upsert, timeline } = await load();
    let list = [message(4, 5, 100), message(7, 5, 300), message(8, 5, 400)];

    // Act
    list = upsert(list, message(9, 6, 200));
    list = upsert(list, message(5, 6, 300));
    const marks = timeline(list, 7, 1).map((i) => i.type === 'new' ? 'new' : i.message?.id).filter(Boolean);

    // Assert
    assert.deepEqual(list.map((m) => m.id), [4, 9, 5, 7, 8], 'by when written, then id');
    // 9 has a higher id than 7 but was written before it: read, as everything before 7.
    assert.deepEqual(marks, [4, 9, 5, 7, 'new', 8]);
});

test('the timeline: a line per day, the unread line once and never at the reader\'s own, blocks by writer', async () => {
    // Arrange
    const { timeline } = await load();
    const day1 = new Date(2026, 9, 21, 10, 0).getTime() / 1000;
    const day2 = new Date(2026, 9, 22, 9, 0).getTime() / 1000;
    const messages = [
        message(1, 7, day1),
        message(2, 7, day1 + 60),              // same writer, a minute later: continued
        message(3, 7, day1 + 60 * 20),         // twenty minutes later: a new block
        message(4, 5, day2),                   // the reader's own, after what they read: no line
        message(5, 9, day2 + 30),              // the first unread from someone else: the line
        message(6, null, day2 + 40, { card: { title: 'x' } }),   // a card is never continued
        message(7, null, day2 + 50, { card: { title: 'y' } }),
    ];

    // Act
    const items = timeline(messages, 3, 5);

    // Assert
    assert.deepEqual(items.map((i) => (i.type === 'message' ? i.message.id + (i.continued ? 'c' : '') : i.type)),
        ['day', '1', '2c', '3', 'day', '4', 'new', '5', '6', '7']);
    assert.equal(timeline(messages, 0, 5).some((i) => i.type === 'new'), false, 'nothing read yet: no line');
});

test('a picked @Name is sent as <@id>, longest names first, and comes back as @Name to edit', async () => {
    // Arrange
    const { mentionsToTokens, tokensToText } = await load();
    const picked = [{ id: 7, name: 'Μαρία' }, { id: 8, name: 'Μαρία Κ.' }];

    // Act
    const sent = mentionsToTokens('@Μαρία Κ. και @Μαρία, δείτε το', picked);
    const back = tokensToText('<@8> και <@7> και <@99>', { 7: 'Μαρία', 8: 'Μαρία Κ.' });

    // Assert
    assert.equal(sent, '<@8> και <@7>, δείτε το');
    assert.equal(back.text, '@Μαρία Κ. και @Μαρία και <@99>', 'an unknown id stays as it is');
    assert.deepEqual(back.picked, [{ id: 8, name: 'Μαρία Κ.' }, { id: 7, name: 'Μαρία' }]);
    assert.equal(mentionsToTokens(back.text, back.picked), '<@8> και <@7> και <@99>', 'round trip');
});

test('@ and : are recognised at the cursor; people are matched by any word, ignoring accents', async () => {
    // Arrange
    const { wordAt, suggestPeople, fold } = await load();
    const people = [{ id: 5, name: 'Ελένη Σ.' }, { id: 7, name: 'Μαρία Κ.' }, { id: 9, name: 'Νίκος Παππάς' }];

    // Act
    const mention = wordAt('γεια @μαρ', 9);
    const emoji = wordAt('τέλειο :thu', 11);
    const none = wordAt('email@host', 10);
    const found = suggestPeople(people, 'παπ', 5);
    const all = suggestPeople(people, 'a', 5);

    // Assert
    assert.deepEqual(mention, { sign: '@', word: 'μαρ', start: 5 });
    assert.deepEqual(emoji, { sign: ':', word: 'thu', start: 7 });
    assert.equal(none, null, 'an @ inside a word is not a mention');
    assert.deepEqual(found.map((p) => p.id), [9], 'by the surname, without its accent');
    assert.deepEqual(all.map((p) => p.name), ['all'], 'and @all where it matches; never the reader');
    assert.equal(fold('Νίκος'), 'νικοσ');
});

test('who is typing fades; faces and sizes', async () => {
    // Arrange
    const { typingNow, initials, hue, size } = await load();

    // Act
    const typing = typingNow({ 5: { name: 'Α', until: 2000 }, 7: { name: 'Β', until: 500 } }, 1000);

    // Assert
    assert.deepEqual(Object.keys(typing), ['5']);
    assert.equal(initials('μαρία κωνσταντίνου'), 'ΜΚ');
    assert.equal(initials(''), '?');
    assert.equal(hue(7), hue(7));
    assert.deepEqual([size(500), size(2048), size(1536 * 1024), size(2 * 1024 * 1024)], ['500 B', '2 KB', '1.5 MB', '2 MB']);
});

test('the dock: the windows that fit, newest kept; the faces of recent conversations without one', async () => {
    // Arrange
    const { dockLayout } = await load();
    const conversations = [
        { id: 1, members: null, lastMessageAt: 900 },                 // a channel: never a face
        { id: 2, members: [5, 7], lastMessageAt: 800 },
        { id: 3, members: [5, 9], lastMessageAt: 700 },
        { id: 4, members: [5, 9, 12], lastMessageAt: 950 },
        { id: 5, members: [5, 14], lastMessageAt: 100 },
        { id: 6, members: [5, 15], lastMessageAt: 990, archivedAt: 1 },
    ];
    const windows = [{ id: 2, min: false }, { id: 3, min: true }, { id: 5, min: false }, { id: 4, min: false }];

    // Act
    const wide = dockLayout(conversations, windows, 1440);
    const middling = dockLayout(conversations, windows, 800);
    const narrow = dockLayout(conversations, windows, 390);
    const none = dockLayout(conversations, [], 1440);

    // Assert
    assert.deepEqual(wide.windows.map((w) => w.id), [3, 5, 4], 'three fit, the most recent');
    assert.deepEqual(wide.heads.map((c) => c.id), [2], 'a face for the one whose window did not fit');
    assert.deepEqual(middling.windows.map((w) => w.id), [5, 4], 'two fit');
    assert.deepEqual(narrow.windows.map((w) => w.id), [4], 'one on a phone');
    assert.deepEqual(narrow.heads, [], 'and no faces');
    assert.deepEqual(none.heads.map((c) => c.id), [4, 2, 3], 'newest first, three at most; no channel, nothing archived');
});

test('a command is one the application declared; anything else is sent as written', async () => {
    // Arrange
    const { commandIn } = await load();
    const list = [{ name: 'jira' }, { name: 'remind' }];

    // Act
    const found = [commandIn('/jira NAN-1', list), commandIn('  /Remind 15:00', list), commandIn('/shrug', list), commandIn('δες /jira', list), commandIn('/jira', list)];

    // Assert
    assert.deepEqual(found, ['jira', 'remind', null, null, 'jira']);
});

test('references are read in one request for everybody who asked at once, and kept', async () => {
    // Arrange
    const extras = await import(pathToFileURL(path.join(__dirname, '..', '..', 'resources', 'js', 'conversations', 'extras.js')).href);
    extras.forget();
    extras.configure({ refTypes: ['user', 'jira'], commands: [{ name: 'jira' }] });
    const asked = [];
    const api = { get: (p, q) => { asked.push(q.keys); return Promise.resolve({ cards: { 'user:5': { title: 'Ελένη' }, 'jira:NAN-1': null } }); } };

    // Act
    const [a, b] = await Promise.all([extras.cardsFor(api, ['user:5', 'invoice:9']), extras.cardsFor(api, ['jira:NAN-1', 'user:5'])]);
    const again = await extras.cardsFor(api, ['user:5']);
    const failing = { get: () => Promise.reject(new Error('down')) };
    const down = await extras.cardsFor(failing, ['user:7']);

    // Assert
    assert.deepEqual(asked, ['user:5,jira:NAN-1'], 'one request; nobody draws invoices');
    assert.deepEqual(a, { 'user:5': { title: 'Ελένη' } });
    assert.deepEqual(b, { 'jira:NAN-1': null, 'user:5': { title: 'Ελένη' } });
    assert.deepEqual(again, { 'user:5': { title: 'Ελένη' } }, 'kept: nothing asked again');
    assert.deepEqual(down, { 'user:7': null }, 'a failure shows the plain text');
    assert.deepEqual(extras.drawable(['user:1', 'x:2']), ['user:1']);
    assert.deepEqual(extras.commands(), [{ name: 'jira' }]);
});

test('a deleted message is left out, unless replies hang from it', async () => {
    // Arrange
    const { timeline } = await load();
    const at = new Date(2026, 9, 22, 10, 0).getTime() / 1000;
    const messages = [
        message(1, 7, at),
        message(2, 7, at + 10, { deletedAt: at + 20, replyCount: 0 }),
        message(3, 9, at + 30, { deletedAt: at + 40, replyCount: 2 }),
        message(4, 7, at + 50),
    ];

    // Act
    const shown = timeline(messages, 0, 5).filter((i) => i.type === 'message').map((i) => i.message.id);

    // Assert
    assert.deepEqual(shown, [1, 3, 4], 'gone, but a thread keeps its root');
});

/**
 * A face put away stays away until its conversation has a newer message, and nothing takes its
 * place — otherwise closing one face would only bring up the next, and the owner could never
 * clear the dock.
 */
test('the dock: a face put away stays away until a newer message, and nothing takes its place', async () => {
    // Arrange
    const { dockLayout } = await load();
    const conversations = [
        { id: 2, members: [5, 7], lastMessageAt: 800, lastMessageId: 20 },
        { id: 3, members: [5, 9], lastMessageAt: 700, lastMessageId: 30 },
        { id: 4, members: [5, 9, 12], lastMessageAt: 950, lastMessageId: 40 },
        { id: 5, members: [5, 14], lastMessageAt: 100, lastMessageId: 50 },
    ];

    // Act
    const away = dockLayout(conversations, [], 1440, { 4: 40 });
    const back = dockLayout(conversations.map((c) => (c.id === 4 ? { ...c, lastMessageId: 41 } : c)), [], 1440, { 4: 40 });

    // Assert
    assert.deepEqual(away.heads.map((c) => c.id), [2, 3], 'two faces, not the fourth moved up');
    assert.deepEqual(back.heads.map((c) => c.id), [4, 2, 3], 'a newer message brings it back');
});

/**
 * What the reader left in the sidebar is read back per reader, and a browser with nothing kept,
 * something unreadable, or storage that throws gives the sidebar as it starts — never an error.
 */
test('the sidebar\'s choices are kept per reader, and storage that fails is the sidebar as it starts', async () => {
    // Arrange
    const { sidebarPrefs, keepSidebarPrefs } = await load();
    const box = {};
    const storage = { getItem: (k) => box[k] ?? null, setItem: (k, v) => { box[k] = v; } };
    const broken = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } };

    // Act
    keepSidebarPrefs(5, { collapsed: { channels: true }, sort: {}, unreadOnly: true, noFaces: false }, storage);
    keepSidebarPrefs(5, { unreadOnly: true }, broken);
    box['pf-conv-side:6'] = '{nope';

    // Assert
    assert.equal(sidebarPrefs(5, storage).collapsed.channels, true);
    assert.equal(sidebarPrefs(5, storage).unreadOnly, true);
    assert.deepEqual(sidebarPrefs(6, storage), { collapsed: {}, sort: {}, unreadOnly: false, noFaces: false }, 'unreadable');
    assert.deepEqual(sidebarPrefs(7, broken), { collapsed: {}, sort: {}, unreadOnly: false, noFaces: false }, 'refused');
});

/**
 * A draft is kept per reader and conversation, an empty one forgotten — and storage that refuses
 * keeps nothing, without an error in the composer.
 */
test('drafts: kept per conversation, an empty one forgotten', async () => {
    // Arrange
    const { draftOf, keepDraft } = await load();
    const box = new Map();
    const storage = {
        getItem: (k) => (box.has(k) ? box.get(k) : null), setItem: (k, v) => box.set(k, v), removeItem: (k) => box.delete(k),
        key: (i) => [...box.keys()][i] ?? null, get length() { return box.size; },
    };
    const broken = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); }, removeItem() { throw new Error('denied'); },
        key() { throw new Error('denied'); }, get length() { return 1; } };

    // Act
    keepDraft(5, 1, 'μισό', storage, 100);
    keepDraft(5, 2, 'άλλο', storage, 200);
    keepDraft(5, 3, 'θα σβηστεί', storage, 300);
    keepDraft(5, 3, '   ', storage, 400);
    keepDraft(6, 1, 'κάποιου άλλου', storage, 500);
    keepDraft(5, 9, 'x', broken);

    // Assert
    assert.equal(draftOf(5, 1, storage), 'μισό');
    assert.equal(draftOf(5, 3, storage), '', 'an empty draft is forgotten');
    assert.equal(draftOf(5, 1, broken), '');
});

/**
 * Somebody inactive is suggested for an `@` only when a whole part of their name is typed, so the
 * people who have long stopped signing in do not crowd the suggestions; their last sign-in reads as
 * a date, and never as none.
 */
test('an inactive person is suggested only by a whole part of their name', async () => {
    // Arrange
    const { suggestPeople, lastSignIn } = await load();
    const people = [{ id: 7, name: 'Νίκος Παππάς', inactive: 0 }, { id: 8, name: 'Νικολέτα Σ.', inactive: null }, { id: 9, name: 'Νίκη Α.' }];

    // Act
    const partial = suggestPeople(people, 'νικ', 5).map((p) => p.id);
    const whole = suggestPeople(people, 'νικος', 5).map((p) => p.id);

    // Assert
    assert.deepEqual(partial, [8, 9], 'not the inactive one');
    assert.deepEqual(whole, [7], 'his whole first name');
    assert.equal(lastSignIn(0), null);
    assert.equal(typeof lastSignIn(1700000000), 'string');
});

/**
 * A link card's video plays in its site's own player, YouTube's from its cookie-free domain and
 * Vimeo's without tracking, starting at once; an id cannot break out of the address, and a
 * provider with no player has none.
 */
test('a card\'s video plays in its site\'s player, without cookies where there is such a player', async () => {
    // Arrange
    const { playerUrl } = await load();

    // Act
    const youtube = playerUrl({ provider: 'youtube', id: 'dQw4w9WgXcQ' });
    const vimeo = playerUrl({ provider: 'vimeo', id: '76979871' });
    const odd = playerUrl({ provider: 'youtube', id: 'a"b/c' });

    // Assert
    assert.equal(youtube, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1');
    assert.equal(vimeo, 'https://player.vimeo.com/video/76979871?autoplay=1&dnt=1');
    assert.equal(odd, 'https://www.youtube-nocookie.com/embed/a%22b%2Fc?autoplay=1', 'escaped');
    assert.equal(playerUrl({ provider: 'spotify', id: 'x' }), null);
    assert.equal(playerUrl(null), null);
});
