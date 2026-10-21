/**
 * A notification arriving, in `pf-utils.js`: the tab's title, a sound, and `pf:notification`.
 *
 * What has to hold: the arrival is read with the page's session and announced as
 * `pf:notification` with its type; the title carries "(N) " while N are unread and, when one
 * arrives in a tab nobody is looking at, alternates with its title until the tab is looked at,
 * then is put back; a sound plays only in the tab named to act for the browser, and not at all
 * without `data-sound`; `data-title-flash="0"` leaves the title alone.
 *
 * Run:
 *   node --test tests/js/pf-notification-arrival.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, stubFetch, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

const settle = async () => { for (let i = 0; i < 8; i++) { await new Promise((r) => setImmediate(r)); } };

/**
 * A page titled "Orders" with a bell on `users.5`, a realtime connection the test drives, and
 * the bell's attributes as given.
 */
function page({ hidden = true, attrs = {}, items = null, unread = 2 } = {}) {
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    document.title = 'Orders';
    const bell = document.createElement('div');
    Object.entries(Object.assign({ 'data-pf-notifications': '', 'data-url': '/Notifications', 'data-channel': 'users.5' }, attrs))
        .forEach(([k, v]) => bell.setAttribute(k, v));
    const toggle = document.createElement('button');
    toggle.setAttribute('data-pf-notifications-toggle', '');
    const panel = document.createElement('div');
    panel.setAttribute('data-pf-notifications-panel', '');
    panel.hidden = true;
    const list = document.createElement('ul');
    list.setAttribute('data-pf-notifications-list', '');
    bell.appendChild(toggle); bell.appendChild(panel); panel.appendChild(list);
    document.body.appendChild(bell);

    window.fetch = stubFetch({
        'GET /Notifications/count': { unread, groups: {} },
        'GET /Notifications?per_page=5': { unread, items: items || [
            { id: 'u2', type: 'App\\Notifications\\TodoNotification', title: 'New to-do', message: 'Call Anna', data: { todo: 7 } },
            { id: 'u1', type: 'App\\Notifications\\Other', title: 'Older', message: '', data: {} },
        ] },
    });
    const intervals = [];
    const timeouts = [];
    window.setInterval = (fn, ms) => { intervals.push({ fn, ms }); return intervals.length; };
    window.clearInterval = (id) => { if (intervals[id - 1]) { intervals[id - 1].fn = () => {}; } };
    window.setTimeout = (fn, ms) => { timeouts.push({ fn, ms }); return timeouts.length; };
    window.clearTimeout = () => {};
    const played = [];
    window.Audio = class { constructor(src) { this.src = src; } play() { played.push(this.src); return Promise.resolve(); } };
    let handler = null;
    window.pfRealtime = { channel: () => ({ listen: (event, fn) => { handler = fn; } }) };
    document.hidden = hidden;
    const announced = [];
    document.addEventListener('pf:notification', (e) => announced.push(e.detail));
    loadScript(SOURCE, window);

    /** The flashing interval's next tick. */
    const tick = () => intervals.filter((i) => i.ms === 1000).forEach((i) => i.fn());

    return { window, document, arrive: (data, meta) => handler(data, meta), announced, played, tick };
}

test('an arrival is read with the page\'s session and announced with its type', async () => {
    // Arrange
    const ui = page();

    // Act
    ui.arrive({ id: 'u2' }, { lead: false });
    await settle();

    // Assert
    assert.equal(ui.announced.length, 1);
    assert.equal(ui.announced[0].type, 'App\\Notifications\\TodoNotification', 'the app cannot tell what arrived');
    assert.equal(ui.announced[0].title, 'New to-do');
    assert.equal(ui.announced[0].data.todo, 7);
});

test('in a hidden tab the title alternates with the arrival\'s, and coming back puts it back', async () => {
    // Arrange
    const ui = page({ hidden: true });

    // Act
    ui.arrive({ id: 'u2' }, { lead: false });
    await settle();
    ui.tick();
    const flashed = ui.document.title;
    ui.tick();
    const between = ui.document.title;
    ui.document.hidden = false;
    ui.document.dispatchEvent(new Event('visibilitychange'));
    await settle();

    // Assert
    assert.equal(flashed, '(2) New to-do');
    assert.equal(between, '(2) Orders', 'the unread count is not on the title');
    assert.equal(ui.document.title, '(2) Orders', 'the original title was not put back');
});

test('the sound plays only in the tab named to act for the browser, and only when asked for', async () => {
    // Arrange
    const withSound = page({ attrs: { 'data-sound': '/sounds/ding.mp3' } });
    const without = page();

    // Act
    withSound.arrive({ id: 'u2' }, { lead: false });
    withSound.arrive({ id: 'u2' }, { lead: true });
    without.arrive({ id: 'u2' }, { lead: true });
    await settle();

    // Assert
    assert.deepEqual(withSound.played, ['/sounds/ding.mp3'], 'played in a tab not named, or not at all');
    assert.deepEqual(without.played, [], 'a sound nobody asked for');
});

test('data-title-flash="0" leaves the title alone', async () => {
    // Arrange
    const ui = page({ attrs: { 'data-title-flash': '0' } });

    // Act
    ui.arrive({ id: 'u2' }, { lead: false });
    await settle();
    ui.tick();

    // Assert
    assert.equal(ui.document.title, 'Orders');
    assert.equal(ui.announced.length, 1, 'the event does not depend on the title');
});

test('one written already read is not news: no flash, no sound — and the page is still told', async () => {
    // Arrange — a change the user made themselves, kept for the record, and nothing else unread
    const ui = page({ attrs: { 'data-sound': '/ding.mp3' }, unread: 0, items: [
        { id: 's1', type: 'App\\Notifications\\SecurityChanged', title: 'Security change', read: true, data: {} },
    ] });

    // Act
    ui.arrive({ id: 's1' }, { lead: true });
    await settle();
    ui.tick();

    // Assert
    assert.equal(ui.document.title, 'Orders', 'the title flashed for something already read');
    assert.deepEqual(ui.played, [], 'a sound for something already read');
    assert.equal(ui.announced[0].read, true, 'the page cannot tell it was read');
});

test('an id not among the newest is nothing to flash — never a guess at another item', async () => {
    // Arrange — u9 removed, or read, before this page asked
    const ui = page({ attrs: { 'data-sound': '/ding.mp3' } });

    // Act
    ui.arrive({ id: 'u9' }, { lead: true });
    await settle();
    ui.tick();

    // Assert
    assert.equal(ui.document.title, '(2) Orders', 'the title flashed another notification\'s title');
    assert.deepEqual(ui.played, []);
    assert.equal(ui.announced[0].item, null);
});
