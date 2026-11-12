/**
 * The notification bell in `pf-utils.js`, driven over the markup `Html\NotificationBell` draws.
 *
 * What has to hold: the count is asked for on the interval only while the tab is visible, and at
 * once when it comes back; opening the bell loads the list, each item with its title, message and
 * time; a tab loads its group; an item is marked read before its link is followed; read-all marks
 * the group read; a `pf:notifications:refresh` event, or the page's realtime connection, refreshes
 * it at once; every request carries the page's CSRF token.
 *
 * Run:
 *   node --test tests/js/pf-notifications.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, stubFetch, Event, CustomEvent } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

const settle = async () => { for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); } };

/** The bell's markup, as NotificationBell draws it, and the script over it. */
function page({ realtime = null, hidden = false, except = null, onView = null, items = null } = {}) {
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    const el = (tag, attrs = {}, parent = null) => {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
        (parent || document.body).appendChild(node);
        return node;
    };
    const bell   = el('div', { class: 'pf-notify', 'data-pf-notifications': '', 'data-url': '/Notifications/', 'data-interval': '30', 'data-channel': 'users.5',
        ...(except ? { 'data-except': except } : {}), ...(onView ? { 'data-read-on-view': String(onView) } : {}) });
    const toggle = el('button', { 'data-pf-notifications-toggle': '', 'data-label': 'Notifications' }, bell);
    const count  = el('span', { 'data-pf-notifications-count': '' }, toggle);
    count.hidden = true;
    const panel  = el('div', { 'data-pf-notifications-panel': '' }, bell);
    panel.hidden = true;
    const all    = el('button', { 'data-pf-notifications-read-all': '' }, panel);
    const tabAll = el('button', { 'data-pf-notifications-group': '', 'aria-selected': 'true' }, panel);
    const tab    = el('button', { 'data-pf-notifications-group': 'tasks', 'aria-selected': 'false' }, panel);
    const tabCount = el('span', { 'data-pf-notifications-group-count': 'tasks' }, tab);
    const list   = el('ul', { 'data-pf-notifications-list': '' }, panel);
    const empty  = el('p', { 'data-pf-notifications-empty': '' }, panel);
    const outside = el('main');

    const item = (id, title, url = null, read = false) => ({ id, title, message: 'msg ' + id, url, read, created_at: '2026-10-15 10:00:00' });
    window.fetch = stubFetch({
        'GET /Notifications/count': { unread: 3, groups: { tasks: 2 } },
        'GET /Notifications?per_page=10&collapse=1': { items: items || [item('a1', 'One', '/tasks/1'), item('a2', '', null, true)], unread: 3 },
        'GET /Notifications?per_page=10&collapse=1&group=tasks': { items: [], unread: 3 },
        'POST /Notifications/read': { read: true, unread: 2 },
        'POST /Notifications/readall': { marked: 2, unread: 1 },
        'POST /Notifications/snooze': { snoozed: true, unread: 2 },
        'POST /leave/9/approve': { ok: true },
    });
    const timers = [];
    window.setInterval = (fn) => { timers.push(fn); return timers.length; };
    window.clearInterval = (id) => { timers[id - 1] = null; };
    window.pfRealtime = realtime;
    document.hidden = hidden;
    loadScript(SOURCE, window);

    return { window, document, bell, toggle, count, panel, all, tabAll, tab, tabCount, list, empty, outside, timers };
}

test('the count is polled while the tab is visible, and at once when it comes back', async () => {
    // Arrange
    const ui = page();

    // Act
    ui.timers[0]();
    await settle();
    ui.document.hidden = true;
    ui.document.dispatchEvent(new Event('visibilitychange'));
    const stopped = ui.timers[0];
    ui.document.hidden = false;
    ui.document.dispatchEvent(new Event('visibilitychange'));
    await settle();

    // Assert
    assert.equal(ui.count.textContent, '3');
    assert.equal(ui.count.hidden, false);
    assert.equal(ui.toggle.getAttribute('aria-label'), 'Notifications (3)');
    assert.equal(ui.tabCount.textContent, '2');
    assert.equal(stopped, null, 'a hidden tab asks nothing');
    assert.equal(ui.timers.filter(Boolean).length, 1, 'and polls again when visible');
    assert.deepEqual(ui.window.fetch.calls.map((c) => c.path), ['/Notifications/count', '/Notifications/count']);
    assert.equal(ui.window.fetch.calls[0].headers['X-CSRF-Token'], 't0ken');
});

test('a background tab does not start polling', () => {
    // Act
    const ui = page({ hidden: true });

    // Assert
    assert.equal(ui.timers.length, 0);
});

test('opening loads the list; a tab loads its group; outside or Esc closes it', async () => {
    // Arrange
    const ui = page();

    // Act
    ui.toggle.click();
    await settle();
    const items = ui.list.children.map((li) => [li.querySelector('a').getAttribute('href'), li.querySelector('[data-field="title"]').textContent, li.classList.contains('is-unread')]);
    ui.tab.click();
    await settle();
    const tabSelected = ui.tab.getAttribute('aria-selected');
    ui.outside.click();
    const afterOutside = ui.panel.hidden;
    ui.toggle.click();
    ui.document.dispatchEvent(Object.assign(new Event('keydown'), { key: 'Escape' }));

    // Assert
    assert.deepEqual(items, [['/tasks/1', 'One', true], ['#', 'msg a2', false]], 'a title, or the message when there is none');
    assert.equal(ui.empty.hidden, false, 'the tasks tab has none: the empty note shows');
    assert.equal(ui.list.children.length, 0);
    assert.equal(tabSelected, 'true');
    assert.equal(afterOutside, true);
    assert.equal(ui.panel.hidden, true, 'Esc closes it');
    assert.equal(ui.toggle.getAttribute('aria-expanded'), 'false');
});

test('an item is marked read before its link is followed; read-all marks the group', async () => {
    // Arrange
    const ui = page();
    ui.toggle.click();
    await settle();

    // Act
    const click = new Event('click', { bubbles: true });
    ui.list.children[0].querySelector('a').dispatchEvent(click);
    await settle();
    ui.all.click();
    await settle();

    // Assert
    assert.equal(click.defaultPrevented, true);
    const posts = ui.window.fetch.calls.filter((c) => c.method === 'POST');
    assert.deepEqual(posts.map((c) => c.path), ['/Notifications/read?id=a1', '/Notifications/readall']);
    assert.equal(posts[0].headers['X-CSRF-Token'], 't0ken');
    assert.equal(ui.window.navigatedTo, 'https://app.test/tasks/1');
});

test('a refresh event, and the page realtime connection, refresh it at once', async () => {
    // Arrange — a websocket connection on the page
    const listeners = {};
    let channelName = null;
    const realtime = { transport: 'websocket', channel: (name) => { channelName = name; return { listen: (event, fn) => { listeners[event] = fn; } }; } };
    const ui = page({ realtime });
    const heard = [];
    page({ realtime: { transport: 'sse', on: (event) => { heard.push(event); } } });

    // Act
    ui.document.dispatchEvent(new CustomEvent('pf:notifications:refresh'));
    listeners['notification.created']();
    // Read elsewhere (another window, the conversation it was about): counted again too.
    listeners['notification.read']();
    await settle();

    // Assert
    assert.equal(channelName, 'users.5');
    assert.deepEqual(Object.keys(listeners), ['notification.created', 'notification.read']);
    assert.equal(ui.window.fetch.calls.filter((c) => c.path === '/Notifications/count').length, 3);
    assert.deepEqual(heard, ['notification.created', 'notification.read'], 'an SSE connection, by event name');
});

test('a bell that leaves groups out asks every request without them', async () => {
    // Arrange — `except` of Html\NotificationBell, on its markup
    const ui = page({ except: 'conversations' });
    ui.window.fetch = (function (calls) {
        const fake = (url, opts = {}) => {
            calls.push({ path: String(url), method: (opts.method || 'GET').toUpperCase() });
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ unread: 0, items: [], groups: {} }) });
        };
        fake.calls = calls;
        return fake;
    })([]);

    // Act
    ui.document.dispatchEvent(new CustomEvent('pf:notifications:refresh'));
    ui.toggle.click();
    await settle();
    ui.all.click();
    await settle();

    // Assert — the count, the list and read-all each carry it
    const paths = ui.window.fetch.calls.map((c) => c.method + ' ' + c.path);
    assert.ok(paths.includes('GET /Notifications/count?except=conversations'), paths.join(', '));
    assert.ok(paths.includes('GET /Notifications?per_page=10&collapse=1&except=conversations'), paths.join(', '));
    assert.ok(paths.includes('POST /Notifications/readall?except=conversations'), paths.join(', '));
});

test('read on view: what stays in sight of the open list is read, all of a collapsed item at once', async () => {
    // Arrange — data-read-on-view, and a group app.php collapses ("You have 2 to-dos")
    const ui = page({ onView: 500, items: [
        { id: 'b1,b2', title: 'You have 2 to-dos', message: '', url: '/Todo', read: false, collapsed: true, created_at: '2026-10-15 10:00:00' },
        { id: 'a2', title: 'Old', message: '', url: null, read: true, created_at: '2026-10-15 09:00:00' },
        { id: 'k1', title: 'Deadline today', message: '', url: null, read: false, ack: true, created_at: '2026-10-15 08:00:00' },
    ] });
    const waits = [];
    ui.window.setTimeout = (fn, ms) => { waits.push([fn, ms]); return waits.length; };
    ui.window.clearTimeout = () => {};
    ui.list.getBoundingClientRect = () => ({ top: 0, bottom: 300, height: 300 });

    // Act
    ui.toggle.click();
    await settle();
    ui.list.children.forEach((li) => { li.getBoundingClientRect = () => ({ top: 10, bottom: 40, height: 30 }); });
    const collapsed = ui.list.children[0].classList.contains('is-collapsed');
    const delay = waits.length ? waits[waits.length - 1][1] : null;
    waits[waits.length - 1][0]();
    await settle();

    // Assert
    const posts = ui.window.fetch.calls.filter((c) => c.method === 'POST').map((c) => c.path);
    assert.equal(collapsed, true, 'drawn as one');
    assert.equal(delay, 500, 'after the delay asked');
    assert.deepEqual(posts, ['/Notifications/read?id=b1%2Cb2'], 'the unread in sight, every one a collapsed item stands for; the read one not again');
    assert.equal(ui.list.children[0].classList.contains('is-unread'), false);
    assert.equal(ui.list.children[2].classList.contains('is-ack'), true, 'one that must be acknowledged: its own mark');
    assert.equal(ui.list.children[2].classList.contains('is-unread'), true, 'never read by being in sight');
});

test('without read on view, opening reads nothing', async () => {
    // Arrange
    const ui = page();

    // Act
    ui.toggle.click();
    await settle();

    // Assert
    assert.deepEqual(ui.window.fetch.calls.filter((c) => c.method === 'POST'), [], 'only a click reads');
});

test('data-pf-settles: what the page shows is settled once, by the bell\'s address, and the bell told', async () => {
    // Arrange — two marks on the page, a key repeated
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    const bell = document.createElement('div');
    bell.setAttribute('data-pf-notifications', '');
    bell.setAttribute('data-url', '/Notifications/');
    document.body.appendChild(bell);
    ['todo:42 comment:7', 'todo:42'].forEach((keys) => {
        const el = document.createElement('section');
        el.setAttribute('data-pf-settles', keys);
        document.body.appendChild(el);
    });
    window.fetch = stubFetch({ 'POST /Notifications/settle': { settled: 2 } });
    let told = 0;
    document.addEventListener('pf:notifications:refresh', () => { told++; });

    // Act
    loadScript(SOURCE, window);
    await settle();

    // Assert
    const posts = window.fetch.calls.filter((c) => c.method === 'POST');
    assert.deepEqual(posts.map((c) => c.path), ['/Notifications/settle?about=todo%3A42%2Ccomment%3A7'], 'one request, each key once');
    assert.equal(posts[0].headers['X-CSRF-Token'], 't0ken');
    assert.equal(told, 1, 'the bell refreshes');
});

test('an unread item is put off to a moment; a read one offers nothing', async () => {
    // Arrange
    const ui = page();
    ui.toggle.click();
    await settle();

    // Act
    const offers = ui.list.children.map((li) => li.querySelectorAll('[data-pf-notifications-snooze]').length);
    ui.list.children[0].querySelector('[data-pf-notifications-snooze]').click();
    await settle();

    // Assert
    const posts = ui.window.fetch.calls.filter((c) => c.method === 'POST').map((c) => c.path);
    assert.deepEqual(offers, [3, 0], 'an hour, three, tomorrow — for the unread one alone');
    assert.deepEqual(posts, ['/Notifications/snooze?id=a1&for=1h']);
});

test('why it is here, and an action done from the bell before the item is read', async () => {
    // Arrange
    const ui = page({ items: [{ id: 'l1', title: 'Leave', message: '', url: null, read: false, created_at: '2026-10-15 10:00:00',
        why: { text: 'You approve leave', url: '/Myaccount/notifications' }, actions: [{ label: 'Approve', url: '/leave/9/approve', style: 'primary' }] }] });
    ui.toggle.click();
    await settle();

    // Act
    const why = ui.list.children[0].querySelector('.pf-notify-why');
    ui.list.children[0].querySelector('[data-pf-notifications-action]').click();
    await settle();

    // Assert
    const posts = ui.window.fetch.calls.filter((c) => c.method === 'POST');
    assert.deepEqual([why.textContent, why.getAttribute('href')], ['You approve leave', '/Myaccount/notifications']);
    assert.deepEqual(posts.map((c) => c.path), ['/leave/9/approve', '/Notifications/read?id=l1'], 'the action, then read');
    assert.equal(posts[0].headers['X-CSRF-Token'], 't0ken', 'with the page\'s token');
});

