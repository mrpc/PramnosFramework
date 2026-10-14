/**
 * `pramnos-testing`, the helpers an application's JavaScript tests use — tested themselves.
 *
 * What has to hold is what an application's test relies on without looking: a selector finds what
 * the browser would, an event bubbles to the document a page script delegates on, a fetch stub
 * answers and records, and a real shipped page script runs in the window it builds.
 *
 * Run:
 *   node --test tests/js/pramnos-testing.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, stubFetch, installApiHeaders, installJquery, Event } =
    require('../../resources/js/pramnos-testing');

const ROOT = path.join(__dirname, '..', '..');

test('selectors find what a browser would', () => {
    // Arrange
    const { document } = createDom();
    const nav = document.body.appendChild(document.createElement('nav'));
    const list = nav.appendChild(document.createElement('ul'));
    const item = list.appendChild(document.createElement('li'));
    const link = item.appendChild(document.createElement('a'));
    link.setAttribute('href', '/admin/users');
    link.className = 'nav-link active';
    link.id = 'users';
    link.dataset.screenName = 'users';

    // Act / Assert
    assert.equal(document.querySelector('nav a'), link, 'descendant');
    assert.equal(document.querySelector('ul > li > a.nav-link'), link, 'child and class');
    assert.equal(document.querySelector('ul > a'), null, 'not a child of ul');
    assert.equal(document.getElementById('users'), link);
    assert.equal(document.querySelector('[href^="/admin"]'), link);
    assert.equal(document.querySelector('[href$="users"]'), link);
    assert.equal(document.querySelector('[href*="min/u"]'), link);
    assert.equal(document.querySelector('[data-screen-name="users"]'), link, 'dataset writes data-*');
    assert.equal(document.querySelectorAll('li, a').length, 2, 'comma list');
    assert.equal(link.closest('nav'), nav);
    assert.equal(link.dataset.screenName, 'users');
    assert.throws(() => document.querySelector('a:hover'), /not supported/);
});

test('an event bubbles to the document and the window, unless stopped', () => {
    // Arrange
    const { window, document } = createDom();
    const button = document.body.appendChild(document.createElement('button'));
    const heard = [];
    document.addEventListener('click', (e) => heard.push(['document', e.target === button]));
    window.addEventListener('click', () => heard.push(['window']));

    // Act
    button.click();
    button.addEventListener('click', (e) => e.stopPropagation());
    button.click();
    const custom = new Event('saved', { bubbles: false });
    button.dispatchEvent(custom);

    // Assert
    assert.deepEqual(heard, [['document', true], ['window']]);
});

test('classes, storage, location and removal behave as a page expects', () => {
    // Arrange
    const { window, document } = createDom({ url: 'https://app.test/admin/users?page=2' });
    const box = document.body.appendChild(document.createElement('div'));

    // Act
    box.classList.add('a', 'b');
    box.classList.toggle('a');
    window.localStorage.setItem('tab', 'help');
    window.location.assign('/admin/roles');
    box.remove();

    // Assert
    assert.equal(box.className, 'b');
    assert.equal(window.localStorage.getItem('tab'), 'help');
    assert.equal(window.localStorage.length, 1);
    assert.equal(window.navigatedTo, 'https://app.test/admin/roles');
    assert.equal(document.body.children.length, 0);
});

test('the fetch stub answers from its table, records each call, and 404s the rest', async () => {
    // Arrange
    const fetch = stubFetch({
        'GET /api/notes': [{ id: 1 }],
        'POST /api/notes': { status: 201, body: { id: 2 } },
        '/api/echo': (request) => ({ method: request.method }),
    });

    // Act
    const list = await (await fetch('https://app.test/api/notes?page=1')).json();
    const made = await fetch('/api/notes', { method: 'POST', body: '{}', headers: { A: 'b' } });
    const echo = await (await fetch('/api/echo', { method: 'DELETE' })).json();
    const none = await fetch('/api/missing');

    // Assert
    assert.deepEqual(list, [{ id: 1 }]);
    assert.equal(made.status, 201);
    assert.deepEqual(await made.json(), { id: 2 });
    assert.deepEqual(echo, { method: 'DELETE' });
    assert.equal(none.status, 404);
    assert.equal(none.ok, false);
    assert.deepEqual(fetch.calls.map((c) => c.method + ' ' + c.path), ['GET /api/notes?page=1', 'POST /api/notes', 'DELETE /api/echo', 'GET /api/missing']);
    assert.deepEqual(fetch.calls[1].headers, { A: 'b' });
});

test('a real page script runs in the window: pf-utils.js reads the CSRF token for pfApiHeaders', () => {
    // Arrange
    const { window } = createDom({ meta: { csrf: 't0ken' } });

    // Act
    loadScript(path.join(ROOT, 'scaffolding/assets/js/pf-utils.js'), window);

    // Assert — the shipped script's own pfApiHeaders, and the stub's, agree
    assert.deepEqual(window.pfApiHeaders({ Accept: 'application/json' }), { Accept: 'application/json', 'X-CSRF-Token': 't0ken' });
    const { window: other } = createDom({ meta: { csrf: 'x' } });
    assert.deepEqual(installApiHeaders(other)(), { 'X-CSRF-Token': 'x' });
});

test('an inline script sees the window as its globals', () => {
    // Arrange
    const { window } = createDom();

    // Act
    const context = loadScript('var answer = 41 + 1; window.ready = document.readyState;', window, { source: true });

    // Assert
    assert.equal(context.answer, 42);
    assert.equal(window.ready, 'complete');
});

test('the jQuery-shaped stub: events, delegation, values, classes and ajax through fetch', async () => {
    // Arrange
    const { window, document } = createDom();
    window.fetch = stubFetch({ 'POST /save': { ok: true }, 'GET /fail': { status: 500, body: { error: 'x' } } });
    const $ = installJquery(window);
    const form = document.body.appendChild(document.createElement('form'));
    const input = form.appendChild(document.createElement('input'));
    input.setAttribute('name', 'title');
    const button = form.appendChild(document.createElement('button'));
    button.className = 'save';
    const clicks = [];

    // Act
    $(form).on('click', '.save', function () { clicks.push(this.className); return false; });
    $('input[name="title"]').val('Hello').addClass('dirty').attr('data-x', '1');
    $('.save').trigger('click');
    const saved = await $.post('/save', { title: $('input').val() });
    let failed = null;
    await $.ajax({ url: '/fail', error: (e) => { failed = e.status; } }).catch(() => {});
    $(form).off('click');
    $('.save').trigger('click');

    // Assert
    assert.deepEqual(clicks, ['save'], 'delegated, then off');
    assert.equal(input.value, 'Hello');
    assert.ok($('input').hasClass('dirty'));
    assert.equal($('input').data('x'), '1');
    assert.deepEqual(saved, { ok: true });
    assert.equal(window.fetch.calls[0].body, 'title=Hello');
    assert.equal(failed, 500);
    assert.equal($('nothing').length, 0);
});
