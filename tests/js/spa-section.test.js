/**
 * A single-page section inside a server page: `lib/section.js` (mount and router) and
 * `lib/page.js` (the page's flash, title and side panel), as a project receives them.
 *
 * What has to hold: the section finds its mount element and hands the page's API configuration
 * to the client; its router reads the path under the base, so a deep link opens the right
 * screen; a click on a link inside the base stays in the section and one outside it does not;
 * back/forward re-route; and what the section says to the page lands where the page says
 * things — the layout's `.pf-flash`, the document title, the side panel's page.
 *
 * Run:
 *   node --test tests/js/spa-section.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const fs       = require('node:fs');
const os       = require('node:os');
const path     = require('node:path');
const { pathToFileURL } = require('node:url');

const { createDom, Event } = require('../../resources/js/pramnos-testing');

const TEMPLATES = path.join(__dirname, '..', '..', 'scaffolding', 'templates');

/** Import a stub as the ES module a project gets, against a fresh window. */
async function load(stub, window) {
    const dir  = fs.mkdtempSync(path.join(os.tmpdir(), 'pf-section-'));
    const file = path.join(dir, stub.replace('.js.stub', '.mjs'));
    fs.copyFileSync(path.join(TEMPLATES, stub), file);
    globalThis.window = window;
    globalThis.document = window.document;
    try {
        return await import(pathToFileURL(file).href + '?' + Math.random());
    } finally {
        fs.rmSync(dir, { recursive: true, force: true });
    }
}

/** A page with a section mounted at /admin/Users. */
function page(url = 'https://app.test/admin/Users/edit/5?tab=roles') {
    const dom = createDom({ url });
    const mount = dom.document.createElement('div');
    mount.setAttribute('data-pf-section', 'users');
    mount.setAttribute('data-base', '/admin/Users');
    mount.setAttribute('data-config', JSON.stringify({ apiPrefix: '/api/1.0', apiKey: 'k' }));
    mount.setAttribute('data-props', JSON.stringify({ canDelete: true }));
    dom.document.body.appendChild(mount);

    return { ...dom, mount };
}

test('the section finds its element and hands the page configuration to the API client', async () => {
    // Arrange
    const { window, mount } = page();
    window.__PRAMNOS__ = { appName: 'App' };
    const { section } = await load('spa-section.js.stub', window);

    // Act
    const users = section('users');

    // Assert
    assert.equal(users.element, mount);
    assert.equal(users.base, '/admin/Users');
    assert.deepEqual(users.props, { canDelete: true });
    assert.deepEqual(window.__PRAMNOS__, { appName: 'App', apiPrefix: '/api/1.0', apiKey: 'k' });
    assert.throws(() => section('orders'), /no SPA section "orders"/);
});

test('a deep link is a route: the path under the base, its segments and its query', async () => {
    // Arrange
    const { window } = page();
    const { section } = await load('spa-section.js.stub', window);
    const { router } = section('users');

    // Act
    const route = router.current();

    // Assert
    assert.equal(route.path, '/edit/5');
    assert.deepEqual(route.segments, ['edit', '5']);
    assert.equal(route.query.get('tab'), 'roles');
    assert.equal(router.href('/edit/6', { tab: '', page: 2 }), '/admin/Users/edit/6?page=2');
    assert.equal(router.href('/'), '/admin/Users');
});

test('links inside the base stay in the section; others, and modified clicks, are left to the browser', async () => {
    // Arrange
    const { window, document } = page('https://app.test/admin/Users');
    const { section } = await load('spa-section.js.stub', window);
    const { router } = section('users');
    const routes = [];
    const stop = router.start((route) => routes.push(route.path));
    const link = (href, attrs = {}) => {
        const a = document.body.appendChild(document.createElement('a'));
        a.setAttribute('href', href);
        Object.entries(attrs).forEach(([k, v]) => a.setAttribute(k, v));
        return a;
    };

    // Act
    const inside = link('/admin/Users/edit/7');
    const insideClick = new Event('click', { bubbles: true });
    inside.dispatchEvent(insideClick);
    const outside = new Event('click', { bubbles: true });
    link('/admin/Roles').dispatchEvent(outside);
    const newTab = new Event('click', { bubbles: true });
    newTab.ctrlKey = true;
    link('/admin/Users/edit/8').dispatchEvent(newTab);
    const blank = new Event('click', { bubbles: true });
    link('/admin/Users/edit/9', { target: '_blank' }).dispatchEvent(blank);
    router.go('/edit/10', null, { replace: true });
    window.dispatchEvent(new Event('popstate'));
    stop();
    router.go('/');

    // Assert
    assert.deepEqual(routes, ['/', '/edit/7', '/edit/10', '/edit/10']);
    assert.equal(insideClick.defaultPrevented, true);
    assert.equal(outside.defaultPrevented, false, 'another page of the site');
    assert.equal(newTab.defaultPrevented, false);
    assert.equal(blank.defaultPrevented, false);
    assert.deepEqual(window.history.entries, ['/admin/Users/edit/7', '/admin/Users']);
});

test('the page says what the section says: flash, title, and what the side panel is about', async () => {
    // Arrange — a page whose layout drew no flash, with a heading and an open side panel
    const { window, document, mount } = page();
    const heading = document.body.appendChild(document.createElement('h1'));
    heading.setAttribute('data-pf-page-title', '');
    const panel = document.body.appendChild(document.createElement('aside'));
    panel.setAttribute('data-page', JSON.stringify({ screen: 'users', kind: '', id: '' }));
    const template = document.body.appendChild(document.createElement('template'));
    template.setAttribute('data-pf-assist', '');
    template.content = document.createElement('fragment');
    const inside = template.content.appendChild(document.createElement('aside'));
    inside.setAttribute('data-page', 'not json');
    const { flash, title, about } = await load('spa-page.js.stub', window);

    // Act
    flash('Saved.');
    flash('Could not delete.', 'error');
    title('Anna');
    about('user', 5, 'Anna');

    // Assert
    const box = document.querySelector('.pf-flash');
    assert.ok(box, 'drawn beside the section');
    assert.equal(box.parentNode, mount.parentNode);
    assert.deepEqual(box.children.map((p) => [p.className, p.getAttribute('role'), p.textContent]), [
        ['pf-flash-message', 'status', 'Saved.'],
        ['pf-flash-error', 'alert', 'Could not delete.'],
    ]);
    assert.equal(document.title, 'Anna');
    assert.equal(heading.textContent, 'Anna');
    assert.deepEqual(JSON.parse(panel.getAttribute('data-page')), { screen: 'users', kind: 'user', id: '5', label: 'Anna' });
    assert.deepEqual(JSON.parse(inside.getAttribute('data-page')), { kind: 'user', id: '5', label: 'Anna' });
});

test('a flash the layout already drew is added to, and with no section the body takes it', async () => {
    // Arrange
    const { window, document } = createDom();
    const existing = document.body.appendChild(document.createElement('div'));
    existing.className = 'pf-flash';
    const { flash } = await load('spa-page.js.stub', window);

    // Act
    flash('One.');
    existing.remove();
    flash('Two.');

    // Assert
    assert.equal(existing.children.length, 1);
    assert.equal(document.body.querySelector('.pf-flash').textContent, 'Two.');
});
