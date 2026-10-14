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
function page({ realtime = null, hidden = false } = {}) {
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    const el = (tag, attrs = {}, parent = null) => {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
        (parent || document.body).appendChild(node);
        return node;
    };
    const bell   = el('div', { class: 'pf-notify', 'data-pf-notifications': '', 'data-url': '/Notifications/', 'data-interval': '30', 'data-channel': 'users.5' });
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
        'GET /Notifications?per_page=10': { items: [item('a1', 'One', '/tasks/1'), item('a2', '', null, true)], unread: 3 },
        'GET /Notifications?per_page=10&group=tasks': { items: [], unread: 3 },
        'POST /Notifications/read': { read: true, unread: 2 },
        'POST /Notifications/readall': { marked: 2, unread: 1 },
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
    let listener = null;
    const realtime = { transport: 'websocket', channel: (name) => ({ listen: (event, fn) => { listener = [name, event, fn]; } }) };
    const ui = page({ realtime });
    let heard = null;
    page({ realtime: { transport: 'sse', on: (event) => { heard = event; } } });

    // Act
    ui.document.dispatchEvent(new CustomEvent('pf:notifications:refresh'));
    listener[2]();
    await settle();

    // Assert
    assert.deepEqual(listener.slice(0, 2), ['users.5', 'notification.created']);
    assert.equal(ui.window.fetch.calls.filter((c) => c.path === '/Notifications/count').length, 2);
    assert.equal(heard, 'notification.created', 'an SSE connection, by event name');
});
