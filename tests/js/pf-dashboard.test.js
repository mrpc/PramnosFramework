/**
 * The dashboard each person arranges, in `pf-utils.js`, over the markup the dashboard views draw.
 *
 * What has to hold: "Customise" shows each card's tools and makes the cards draggable, and "Done"
 * hides them again; moving a card up or down, making it wide or narrow and hiding it each change
 * the page and save the new layout at once, with the page's CSRF token; choosing a card to add
 * saves the layout with it at the end and reloads; a drag saves the order it leaves; and a save
 * the server refuses is said, not swallowed.
 *
 * Run:
 *   node --test tests/js/pf-dashboard.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

const settle = async () => { for (let i = 0; i < 6; i++) { await new Promise((r) => setImmediate(r)); } };

/**
 * A dashboard of three cards, the script over it, and every save it sends.
 *
 * @param {boolean} ok Whether the server accepts the saves
 */
function page(ok = true) {
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
    const el = (tag, attrs = {}, parent = null) => {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
        (parent || document.body).appendChild(node);
        return node;
    };
    const edit   = el('button', { 'data-pf-dashboard-edit': '', 'aria-pressed': 'false' });
    const bar    = el('div', { 'data-pf-dashboard-bar': '' });
    bar.hidden   = true;
    const add    = el('select', { 'data-pf-dashboard-add': '' }, bar);
    const done   = el('button', { 'data-pf-dashboard-done': '' }, bar);
    const status = el('p', { 'data-pf-dashboard-status': '' });
    const board  = el('div', { 'data-pf-dashboard': '', 'data-save': '/admin/Dashboard/savelayout' });
    const card = (id, size) => {
        const section = el('section', { 'data-pf-widget': id, 'data-size': size, 'aria-label': id.toUpperCase() }, board);
        const tools = el('div', { 'data-pf-widget-tools': '' }, section);
        tools.hidden = true;
        ['-1', '1'].forEach((step) => el('button', { 'data-pf-widget-move': step }, tools));
        el('button', { 'data-pf-widget-resize': '' }, tools);
        el('button', { 'data-pf-widget-hide': '' }, tools);
        return section;
    };
    const cards = { a: card('a', 'half'), b: card('b', 'full'), c: card('c', 'half') };
    el('p', { 'data-pf-dashboard-empty': '' }, board).hidden = true;

    const saves = [];
    window.fetch = (url, init) => {
        saves.push({ url, headers: init.headers, body: JSON.parse(init.body) });
        return Promise.resolve({ ok, status: ok ? 200 : 403, json: () => Promise.resolve({}) });
    };
    loadScript(SOURCE, window);

    const order = () => board.querySelectorAll('[data-pf-widget]').map((one) => one.getAttribute('data-pf-widget'));
    const click = (node) => node.dispatchEvent(new Event('click', { bubbles: true }));
    const tool  = (id, attr, value = null) => cards[id].querySelector(value === null ? `[${attr}]` : `[${attr}="${value}"]`);

    return { window, document, edit, bar, add, done, status, board, cards, saves, order, click, tool };
}

/** Customise shows the tools and makes the cards draggable; Done puts them away. */
test('customising shows the tools, and done hides them', () => {
    // Arrange
    const p = page();

    // Act & Assert
    p.click(p.edit);
    assert.equal(p.edit.getAttribute('aria-pressed'), 'true');
    assert.equal(p.bar.hidden, false);
    assert.equal(p.cards.a.querySelector('[data-pf-widget-tools]').hidden, false);
    assert.equal(p.cards.a.getAttribute('draggable'), 'true');

    p.click(p.done);
    assert.equal(p.bar.hidden, true);
    assert.equal(p.cards.a.querySelector('[data-pf-widget-tools]').hidden, true);
    assert.equal(p.cards.a.hasAttribute('draggable'), false);
});

/** Moving a card changes the order on the page and saves it, with the page's token. */
test('moving a card saves the new order', async () => {
    // Arrange
    const p = page();
    p.click(p.edit);

    // Act — c up one, then a down one
    p.click(p.tool('c', 'data-pf-widget-move', '-1'));
    p.click(p.tool('a', 'data-pf-widget-move', '1'));
    p.click(p.tool('a', 'data-pf-widget-move', '1'));
    p.click(p.tool('b', 'data-pf-widget-move', '-1'));
    p.click(p.tool('b', 'data-pf-widget-move', '-1'));
    await settle();

    // Assert
    assert.deepEqual(p.order(), ['b', 'c', 'a']);
    const last = p.saves[p.saves.length - 1];
    assert.equal(last.url, '/admin/Dashboard/savelayout');
    assert.equal(last.headers['X-CSRF-Token'], 't0ken');
    assert.deepEqual(last.body.layout.map((w) => w.id), ['b', 'c', 'a']);
    assert.match(p.status.textContent, /B: 1 \/ 3/);
});

/** A card at the top cannot move up: nothing changes and nothing is saved. */
test('the first card does not move up', () => {
    // Arrange
    const p = page();

    // Act
    p.click(p.tool('a', 'data-pf-widget-move', '-1'));

    // Assert
    assert.deepEqual(p.order(), ['a', 'b', 'c']);
    assert.equal(p.saves.length, 0);
});

/** Wide or narrow toggles the card's width and saves it. */
test('resizing a card saves its width', async () => {
    // Arrange
    const p = page();

    // Act
    p.click(p.tool('a', 'data-pf-widget-resize'));
    await settle();

    // Assert
    assert.equal(p.cards.a.getAttribute('data-size'), 'full');
    assert.ok(p.cards.a.classList.contains('pf-dashboard-full'));
    assert.deepEqual(p.saves[0].body.layout[0], { id: 'a', size: 'full' });
});

/** Hiding a card takes it off the page and out of the saved layout, focus moving to the next. */
test('hiding a card saves the layout without it', async () => {
    // Arrange
    const p = page();

    // Act
    p.click(p.tool('b', 'data-pf-widget-hide'));
    await settle();

    // Assert
    assert.deepEqual(p.order(), ['a', 'c']);
    assert.deepEqual(p.saves[0].body.layout.map((w) => w.id), ['a', 'c']);
    assert.equal(p.document.activeElement, p.tool('c', 'data-pf-widget-hide'));
});

/** Choosing a card to add saves the layout with it at the end, then reloads to draw it. */
test('adding a card saves it at the end and reloads', async () => {
    // Arrange
    const p = page();
    p.add.value = 'health';

    // Act
    p.add.dispatchEvent(new Event('change'));
    await settle();

    // Assert
    assert.deepEqual(p.saves[0].body.layout.map((w) => w.id), ['a', 'b', 'c', 'health']);
    assert.equal(p.window.reloaded, 1);
});

/** A drag moves the card over the one it passes and saves the order when it ends. */
test('dragging a card saves the order it leaves', async () => {
    // Arrange
    const p = page();
    p.click(p.edit);
    const dragEvent = (type, target) => {
        const e = new Event(type, { bubbles: true });
        e.dataTransfer = { setData() {}, effectAllowed: '' };
        target.dispatchEvent(e);
    };

    // Act — a over c
    dragEvent('dragstart', p.cards.a);
    dragEvent('dragover', p.cards.c);
    dragEvent('dragend', p.cards.a);
    await settle();

    // Assert
    assert.deepEqual(p.order(), ['b', 'c', 'a']);
    assert.deepEqual(p.saves[0].body.layout.map((w) => w.id), ['b', 'c', 'a']);
    assert.equal(p.cards.a.classList.contains('pf-dashboard-dragging'), false);
});

/** A save the server refuses is said in the live region. */
test('a refused save is said', async () => {
    // Arrange
    const p = page(false);

    // Act
    p.click(p.tool('a', 'data-pf-widget-resize'));
    await settle();

    // Assert
    assert.equal(p.status.textContent, 'The dashboard could not be saved.');
});
