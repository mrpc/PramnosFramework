/**
 * The dashboard each person arranges, in `pf-utils.js`, over the markup the dashboard views draw.
 *
 * What has to hold: "Customise" shows each card's tools, grip and corner, and "Done" hides them
 * again; moving a card up or down, changing its width and hiding it each change the page and save
 * the new layout at once, with the page's CSRF token; choosing a card to add saves it, and draws
 * it in place or reloads; a card's settings are saved and the card redrawn; a drag by the grip
 * saves the order it leaves, and a drag of the corner the width it reaches; and a save the server
 * refuses is said, not swallowed.
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
function page(ok = true, cardUrl = '', before = null, url = 'https://app.test/') {
    const { window, document } = createDom({ url, meta: { csrf: 't0ken' } });
    // The fake DOM does not parse HTML: a div given the server's card builds the one element it
    // names — `<section data-pf-widget="x" data-size="m">` — enough for the script to place it.
    const create = document.createElement;
    document.createElement = (tag) => {
        const node = create(tag);
        if (tag !== 'div') { return node; }
        let html = '';
        Object.defineProperty(node, 'innerHTML', {
            get: () => html,
            set: (value) => {
                html = String(value);
                const found = html.match(/data-pf-widget="([^"]+)" data-size="([^"]+)"/);
                if (found) {
                    const section = create('section');
                    section.setAttribute('data-pf-widget', found[1]);
                    section.setAttribute('data-size', found[2]);
                    section.setAttribute('data-drawn', 'server');
                    const tools = create('div');
                    tools.setAttribute('data-pf-widget-tools', '');
                    section.appendChild(tools);
                    // A card that carries its own style and scripts, as a map does: drawn with
                    // the card request's nonce, which is not the page's.
                    if (html.includes('data-with-scripts')) {
                        const style = create('style');
                        style.setAttribute('nonce', 'card-response-nonce');
                        style.textContent = '#map{height:70vh}';
                        const lib = create('script');
                        lib.setAttribute('src', '/js/leaflet.js');
                        lib.setAttribute('nonce', 'card-response-nonce');
                        const init = create('script');
                        init.textContent = 'drawMap()';
                        [style, lib, init].forEach((n) => section.appendChild(n));
                    }
                    node.appendChild(section);
                }
            },
        });
        return node;
    };
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
    const board  = el('div', cardUrl ? { 'data-pf-dashboard': '', 'data-save': '/admin/Dashboard/savelayout', 'data-card': cardUrl } : { 'data-pf-dashboard': '', 'data-save': '/admin/Dashboard/savelayout' });
    const card = (id, size) => {
        const section = el('section', { 'data-pf-widget': id, 'data-size': size, 'aria-label': id.toUpperCase() }, board);
        const tools = el('div', { 'data-pf-widget-tools': '' }, section);
        tools.hidden = true;
        ['-1', '1'].forEach((step) => el('button', { 'data-pf-widget-move': step }, tools));
        el('span', { 'data-pf-widget-grip': '' }, tools);
        el('button', { 'data-pf-widget-resize': '' }, tools);
        el('button', { 'data-pf-widget-hide': '' }, tools);
        el('span', { 'data-pf-widget-size-handle': '' }, section).hidden = true;
        return section;
    };
    const cards = { a: card('a', 'half'), b: card('b', 'full'), c: card('c', 'half') };
    // The page's own nonce, on a script of the page, as the CSP injector leaves it.
    el('script', { nonce: 'page-n0nce' }, document.head || document.body);
    el('p', { 'data-pf-dashboard-empty': '' }, board).hidden = true;

    const saves = [];
    const drawn = [];
    const charted = [];
    window.fetch = (url, init) => {
        if (init.method !== 'POST') {
            drawn.push(url);
            const id = decodeURIComponent(String(url).split('id=')[1] || '');
            const extra = id === 'map' ? ' data-with-scripts' : '';
            return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('<section class="card" data-pf-widget="' + id + '" data-size="s"' + extra + '>drawn</section>') });
        }
        saves.push({ url, headers: init.headers, body: JSON.parse(init.body) });
        return Promise.resolve({ ok, status: ok ? 200 : 403, json: () => Promise.resolve({}) });
    };
    // What a test puts on the page before the script reads it.
    if (before) { before(el, document, window); }
    loadScript(SOURCE, window);
    // After pf-utils, which defines its own: a card drawn in is handed to it.
    window.pfCharts = { draw: (root) => charted.push(root) };

    const order = () => board.querySelectorAll('[data-pf-widget]').map((one) => one.getAttribute('data-pf-widget'));
    const click = (node) => node.dispatchEvent(new Event('click', { bubbles: true }));
    const tool  = (id, attr, value = null) => cards[id].querySelector(value === null ? `[${attr}]` : `[${attr}="${value}"]`);

    return { window, document, edit, bar, add, done, status, board, cards, saves, drawn, charted, order, click, tool, el };
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
    assert.equal(p.cards.a.querySelector('[data-pf-widget-size-handle]').hidden, false, 'the corner too');

    p.click(p.done);
    assert.equal(p.bar.hidden, true);
    assert.equal(p.cards.a.querySelector('[data-pf-widget-tools]').hidden, true);
    assert.equal(p.cards.a.querySelector('[data-pf-widget-size-handle]').hidden, true);
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

/**
 * The width goes round the four — s, m, l, full — and each is saved; a card of the older `half`
 * is m, so its next width is l.
 */
test('resizing a card goes round the four widths and saves each', async () => {
    // Arrange
    const p = page();
    const sizes = [];

    // Act — four presses on a card saved as half
    for (let i = 0; i < 4; i++) {
        p.click(p.tool('a', 'data-pf-widget-resize'));
        await settle();
        sizes.push(p.cards.a.getAttribute('data-size'));
    }

    // Assert
    assert.deepEqual(sizes, ['l', 'full', 's', 'm']);
    assert.ok(!p.cards.a.classList.contains('pf-dashboard-full'), 'full no longer');
    assert.deepEqual(p.saves[1].body.layout[0], { id: 'a', size: 'full', settings: {} });
    assert.equal(p.status.textContent, 'A: medium');
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

/**
 * A page with geometry: a grid of four 200px columns, each card where the test puts it, and the
 * card under the pointer the one the test names — what a browser would answer.
 */
function geometry(p, rects, under) {
    const box = (r) => ({ left: r[0], top: r[1], width: r[2], height: r[3], right: r[0] + r[2], bottom: r[1] + r[3] });
    p.board.getBoundingClientRect = () => box([0, 0, 800, 600]);
    Object.entries(rects).forEach(([id, r]) => { p.cards[id].getBoundingClientRect = () => box(r); });
    p.window.getComputedStyle = () => ({ gridTemplateColumns: '200px 200px 200px 200px', columnGap: '0px' });
    p.window.innerHeight = 800;
    p.window.requestAnimationFrame = () => 1;
    p.window.cancelAnimationFrame = () => {};
    p.document.elementsFromPoint = () => [p.cards[under()]];
}

/** A pointer event, as the browser sends it. */
function pointer(type, target, x, y) {
    const e = new Event(type, { bubbles: true });
    Object.assign(e, { clientX: x, clientY: y, button: 0 });
    target.dispatchEvent(e);
}

/**
 * Dragging a card by its grip: it is lifted, a placeholder of its width keeps its place and goes
 * to the side of the card the pointer is on, and letting go puts the card there and saves the
 * order — nothing before the pointer has moved a few pixels.
 */
test('dragging a card by its grip saves the order it leaves', async () => {
    // Arrange — a, b, c in a row; the pointer ends on c's right half
    const p = page();
    p.click(p.edit);
    let over = 'b';
    geometry(p, { a: [0, 0, 400, 100], b: [400, 0, 400, 100], c: [0, 120, 400, 100] }, () => over);
    const grip = p.cards.a.querySelector('[data-pf-widget-grip]');

    // Act
    pointer('pointerdown', grip, 20, 20);
    const lifted = [p.cards.a.classList.contains('pf-dashboard-dragging'), p.board.querySelector('.pf-dashboard-placeholder') !== null];
    pointer('pointermove', p.document, 22, 21);
    const still = p.order();
    over = 'c';
    pointer('pointermove', p.document, 350, 150);
    pointer('pointerup', p.document, 350, 150);
    await settle();

    // Assert
    assert.deepEqual(lifted, [true, true]);
    assert.deepEqual(still, ['a', 'b', 'c'], 'two pixels move nothing');
    assert.deepEqual(p.order(), ['b', 'c', 'a']);
    assert.deepEqual(p.saves[0].body.layout.map((w) => w.id), ['b', 'c', 'a']);
    assert.equal(p.cards.a.classList.contains('pf-dashboard-dragging'), false);
    assert.equal(p.board.querySelector('.pf-dashboard-placeholder'), null, 'the placeholder gone');
    assert.equal(p.cards.a.style.left, '');
});

/** Resizing by the corner takes the width of the columns reached, shows it, and saves it. */
test('resizing a card by its corner saves the width reached', async () => {
    // Arrange — a is m (two columns of 200px)
    const p = page();
    p.click(p.edit);
    geometry(p, { a: [0, 0, 400, 100], b: [400, 0, 400, 100], c: [0, 120, 400, 100] }, () => 'b');
    const corner = p.cards.a.querySelector('[data-pf-widget-size-handle]');

    // Act — one column wider
    pointer('pointerdown', corner, 398, 98);
    const badge = p.cards.a.querySelector('.pf-dashboard-size-badge').textContent;
    pointer('pointermove', p.document, 598, 98);
    const during = [p.cards.a.getAttribute('data-size'), p.cards.a.querySelector('.pf-dashboard-size-badge').textContent];
    pointer('pointerup', p.document, 598, 98);
    await settle();

    // Assert
    assert.equal(badge, 'M · 2');
    assert.deepEqual(during, ['l', 'L · 3']);
    assert.equal(p.cards.a.querySelector('.pf-dashboard-size-badge'), null);
    assert.deepEqual(p.saves[0].body.layout[0], { id: 'a', size: 'l', settings: {} });
    assert.equal(p.status.textContent, 'A: large');
});

/** Outside customising, or with another button, the grip does nothing; a drag that ends where it began saves nothing. */
test('a grip outside customising does nothing, and an unmoved drag saves nothing', async () => {
    // Arrange
    const p = page();
    geometry(p, { a: [0, 0, 400, 100], b: [400, 0, 400, 100], c: [0, 120, 400, 100] }, () => 'b');
    const grip = p.cards.a.querySelector('[data-pf-widget-grip]');

    // Act
    pointer('pointerdown', grip, 20, 20);
    const notEditing = p.cards.a.classList.contains('pf-dashboard-dragging');
    p.click(p.edit);
    pointer('pointerdown', grip, 20, 20);
    pointer('pointerup', p.document, 20, 20);
    await settle();

    // Assert
    assert.equal(notEditing, false);
    assert.equal(p.saves.length, 0);
});

/** The arithmetic of a drag: widths by columns, the side of a card, a placeholder that does not flicker, scrolling near the edges. */
test('the arithmetic of dragging', () => {
    // Arrange
    const { window } = page();
    const { sizeForSpan, dropBefore, placeAt, autoScrollStep } = window.pfDashboard;
    const rect = { left: 100, top: 100, width: 200, height: 100 };
    const order = ['today', 'activeusers', 'jobposts', 'online'];

    // Act & Assert — widths
    assert.equal(sizeForSpan(1.2, 4, 'full'), 's');
    assert.equal(sizeForSpan(2.6, 4, 's'), 'l');
    assert.equal(sizeForSpan(9, 4, 's'), 'full', 'clamped to the grid');
    assert.equal(sizeForSpan(0, 4, 'l'), 's', 'never under one column');
    assert.equal(sizeForSpan(2, 2, 's'), 'm', 'two columns: a small card widened is medium');
    assert.equal(sizeForSpan(2, 2, 'l'), 'l', 'and a wide one keeps its width');
    assert.equal(sizeForSpan(1, 2, 'l'), 's');
    assert.equal(sizeForSpan(1, 1, 'l'), 'l', 'one column: nothing to resize');
    // The side of a card
    assert.equal(dropBefore(rect, 150, 190, false), true, 'left half of a card beside others');
    assert.equal(dropBefore(rect, 250, 110, false), false);
    assert.equal(dropBefore(rect, 250, 110, true), true, 'top half of a card that fills its row');
    assert.equal(dropBefore(rect, 150, 190, true), false);
    // The placeholder
    assert.deepEqual(placeAt(order, 'online', 'activeusers', true), ['today', 'online', 'activeusers', 'jobposts']);
    assert.deepEqual(placeAt(order, 'today', 'online', false), ['activeusers', 'jobposts', 'online', 'today']);
    assert.equal(placeAt(order, 'online', 'jobposts', false), null, 'already after it: nothing moves');
    assert.equal(placeAt(order, 'online', 'online', true), null, 'not onto itself');
    assert.equal(placeAt(order, 'online', 'nope', true), null);
    // Scrolling
    assert.equal(autoScrollStep(400, 800, 70, 20), 0, 'away from the edges');
    assert.equal(autoScrollStep(0, 800, 70, 20), -20, 'at the top: up, fastest');
    assert.ok(autoScrollStep(60, 800, 70, 20) < 0 && autoScrollStep(60, 800, 70, 20) > -20, 'near the top: slower');
    assert.equal(autoScrollStep(900, 800, 70, 20), 20, 'past the bottom: no faster');
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

/**
 * With the address of one card, choosing a card to add saves it and draws it from the server in
 * place — no reload — with its tools as the mode has them, its charts drawn, and the option gone.
 */
test('adding a card draws it in place', async () => {
    // Arrange
    const p = page(true, '/admin/Dashboard/card');
    const option = p.el('option', { value: 'health' }, p.add);
    p.click(p.edit);
    p.add.value = 'health';

    // Act
    p.add.dispatchEvent(new Event('change'));
    await settle();

    // Assert
    assert.deepEqual(p.saves[0].body.layout.map((w) => w.id), ['a', 'b', 'c', 'health']);
    assert.deepEqual(p.drawn, ['/admin/Dashboard/card?id=health']);
    assert.deepEqual(p.order(), ['a', 'b', 'c', 'health']);
    const fresh = p.board.querySelector('[data-pf-widget="health"]');
    assert.equal(fresh.getAttribute('data-drawn'), 'server');
    assert.equal(fresh.querySelector('[data-pf-widget-tools]').hidden, false, 'customising: its tools shown');
    assert.deepEqual(p.charted, [fresh]);
    assert.equal(p.window.reloaded, undefined);
    assert.equal(option.hidden, true, 'no longer offered — hidden, not removed');
});

/**
 * A card added live runs its scripts and styles as a page load would.
 *
 * innerHTML never runs a script, and the card's markup carries the card request's nonce, which
 * the page's policy refuses — a map card stayed empty and 0px tall until a reload. Each is made
 * again with the page's nonce, in order, a script with a src waited for before the next; the
 * charts and the `pf:dashboard:card` event come after all of them.
 */
test('a card added live runs its scripts and styles with the page\'s nonce', async () => {
    // Arrange
    const p = page(true, '/admin/Dashboard/card');
    p.el('option', { value: 'map' }, p.add);
    const events = [];
    p.board.addEventListener('pf:dashboard:card', (e) => events.push(e.detail.id));
    p.click(p.edit);
    p.add.value = 'map';

    // Act — drawn, and the library not loaded yet
    p.add.dispatchEvent(new Event('change'));
    await settle();
    const fresh = p.board.querySelector('[data-pf-widget="map"]');
    const before = fresh.querySelectorAll('script, style').map((n) => [n.tagName, n.getAttribute('nonce')]);
    const lib = fresh.querySelector('script[src]');
    lib.onload();

    // Assert — style and library re-made with the page's nonce, the inline script waiting for it
    assert.deepEqual(before, [['STYLE', 'page-n0nce'], ['SCRIPT', 'page-n0nce'], ['SCRIPT', null]],
        'the inline script is not re-made until the library has loaded');
    assert.equal(fresh.querySelector('style').textContent, '#map{height:70vh}');
    const after = fresh.querySelectorAll('script').map((n) => n.getAttribute('nonce'));
    assert.deepEqual(after, ['page-n0nce', 'page-n0nce']);
    assert.equal(fresh.querySelectorAll('script')[1].textContent, 'drawMap()');
    // Charts and the event only once every script has run
    assert.deepEqual(p.charted, [fresh]);
    assert.deepEqual(events, ['map']);
});

/**
 * A card's settings: the gear shows its form; applying keeps the choices on the card, saves them
 * with the layout, and draws the card again from the server in its place.
 */
test('applying a card\'s settings saves them and redraws it', async () => {
    // Arrange
    const p = page(true, '/admin/Dashboard/card?x=1');
    const toggle = p.el('button', { 'data-pf-widget-settings-toggle': '', 'aria-expanded': 'false' }, p.tool('a', 'data-pf-widget-tools') ? p.cards.a.querySelector('[data-pf-widget-tools]') : p.cards.a);
    const form = p.el('form', { 'data-pf-widget-settings': '' }, p.cards.a);
    form.hidden = true;
    const period = p.el('select', { name: 'period' }, form);
    period.value = '30';

    // Act
    p.click(toggle);
    const opened = [form.hidden, toggle.getAttribute('aria-expanded')];
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    await settle();

    // Assert
    assert.deepEqual(opened, [false, 'true']);
    assert.deepEqual(p.saves[0].body.layout[0], { id: 'a', size: 'm', settings: { period: '30' } });
    assert.deepEqual(p.drawn, ['/admin/Dashboard/card?x=1&id=a']);
    assert.deepEqual(p.order(), ['a', 'b', 'c'], 'redrawn in its place');
    assert.equal(p.board.querySelector('[data-pf-widget="a"]').getAttribute('data-drawn'), 'server');
});

/** A card whose settings cannot be read is saved with none, rather than breaking the layout. */
test('unreadable settings are saved as none', async () => {
    // Arrange
    const p = page();
    p.cards.a.setAttribute('data-settings', '{broken');

    // Act
    p.click(p.tool('b', 'data-pf-widget-hide'));
    await settle();

    // Assert
    assert.deepEqual(p.saves[0].body.layout[0], { id: 'a', size: 'm', settings: {} });
});

/**
 * The catalogue: opened by its button, searched by its field; a card added from it is drawn and
 * marked as on the board; a card hidden is offered again.
 */
test('the catalogue adds, searches, and offers a hidden card again', async () => {
    // Arrange
    const p = page(true, '/admin/Dashboard/card');
    const opener = p.el('button', { 'data-pf-dashboard-catalogue': '' });
    const dialog = p.el('dialog', { 'data-pf-dashboard-catalogue-dialog': '' });
    let opened = 0;
    dialog.showModal = () => { opened++; dialog.open = true; };
    dialog.close = () => { dialog.open = false; };
    const search = p.el('input', { 'data-pf-dashboard-catalogue-search': '' }, dialog);
    const item = (id, words) => {
        const li = p.el('li', { 'data-pf-dashboard-option': id }, dialog);
        li.textContent = words;
        const button = p.el('button', { 'data-pf-dashboard-add': id }, li);
        const present = p.el('span', { 'data-pf-dashboard-present': '' }, li);
        present.hidden = true;
        return { li, button, present };
    };
    const health = item('health', 'Health — every check');
    const b = item('b', 'B card');
    b.button.hidden = true;
    b.present.hidden = false;
    p.click(p.edit);

    // Act — open, search, add, hide another
    p.click(opener);
    search.value = 'CHECK';
    search.dispatchEvent(new Event('input', { bubbles: true }));
    const filtered = [health.li.hidden, b.li.hidden];
    p.click(health.button);
    await settle();
    p.click(p.tool('b', 'data-pf-widget-hide'));
    await settle();

    // Assert
    assert.equal(opened, 1);
    assert.deepEqual(filtered, [false, true], 'only what matches');
    assert.equal(dialog.open, false, 'closed once a card is chosen');
    assert.deepEqual(p.drawn, ['/admin/Dashboard/card?id=health']);
    assert.deepEqual([health.button.hidden, health.present.hidden], [true, false], 'added: on the board');
    assert.deepEqual([b.button.hidden, b.present.hidden], [false, true], 'hidden: offered again');
});

/**
 * A widget a board has many cards of: each add is a card of its own, `<id>~<key>`, and the
 * catalogue keeps offering it.
 */
test('a repeatable widget is added again and again, each card under a key of its own', async () => {
    // Arrange
    const p = page(true, '/admin/Dashboard/card');
    const dialog = p.el('dialog', { 'data-pf-dashboard-catalogue-dialog': '' });
    const li = p.el('li', { 'data-pf-dashboard-option': 'report.table' }, dialog);
    const button = p.el('button', { 'data-pf-dashboard-add': 'report.table', 'data-pf-dashboard-repeat': '' }, li);
    p.click(p.edit);

    // Act — the same widget twice
    p.click(button);
    await settle();
    p.click(button);
    await settle();

    // Assert
    const added = p.saves.map((save) => save.body.layout[save.body.layout.length - 1].id);
    assert.equal(added.length, 2);
    added.forEach((id) => assert.match(id, /^report\.table~[a-z0-9]{1,16}$/, 'a key the server keeps'));
    assert.notEqual(added[0], added[1], 'two cards, not one');
    assert.equal(button.hidden, false, 'still offered');
});

/** A setting waiting on another is shown only for its value, and not sent while hidden. */
test('a setting shown only for another\'s value', async () => {
    // Arrange — without the one-card address, so the card stays where it is between submits
    const p = page();
    const form = p.el('form', { 'data-pf-widget-settings': '' }, p.cards.a);
    const period = p.el('select', { name: 'period' }, form);
    period.value = '30';
    const label = p.el('label', { 'data-pf-when': '{"period":"custom"}' }, form);
    label.hidden = true;
    const from = p.el('input', { name: 'from' }, label);
    from.value = '2026-10-01';

    // Act — submitted hidden, then shown and submitted
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    await settle();
    period.value = 'custom';
    period.dispatchEvent(new Event('change', { bubbles: true }));
    const shown = !label.hidden;
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    await settle();

    // Assert
    assert.deepEqual(p.saves[0].body.layout[0].settings, { period: '30' }, 'hidden: not sent');
    assert.equal(shown, true);
    assert.deepEqual(p.saves[1].body.layout[0].settings, { period: 'custom', from: '2026-10-01' }, 'shown: sent');
});

/** What the script says comes from the page: the board's data-pf-dashboard-say. */
test('the words are the page\'s', async () => {
    // Arrange
    const p = page(false);
    p.board.setAttribute('data-pf-dashboard-say', '{"failed":"Δεν αποθηκεύτηκε.","sizes":{"s":"μικρό","m":"μεσαίο","l":"μεγάλο","full":"όλο"}}');
    const q = page(false);
    q.board.setAttribute('data-pf-dashboard-say', '{broken');

    // Act — the script read the attribute when it loaded: load it again over these boards
    loadScript(SOURCE, p.window);
    loadScript(SOURCE, q.window);
    p.click(p.tool('a', 'data-pf-widget-resize'));
    q.click(q.tool('a', 'data-pf-widget-resize'));
    await settle();

    // Assert
    assert.equal(p.status.textContent, 'Δεν αποθηκεύτηκε.');
    assert.equal(q.status.textContent, 'The dashboard could not be saved.', 'unreadable: the defaults');
});

/**
 * While lifted, the card's position, layer and pointer events are set on the card itself — a
 * theme's `.card { position: relative }` sits in a layer no stylesheet rule of ours can beat —
 * and so is the page's text selection, for iOS too; all of it is put back when let go. While
 * customising, the handles take no touch gesture of the browser's.
 */
test('the lift is set on the card itself, and put back', async () => {
    // Arrange
    const p = page();
    p.click(p.edit);
    geometry(p, { a: [0, 0, 400, 100], b: [400, 0, 400, 100], c: [0, 120, 400, 100] }, () => 'b');
    const grip = p.cards.a.querySelector('[data-pf-widget-grip]');
    const touch = grip.style.touchAction;

    // Act
    pointer('pointerdown', grip, 20, 20);
    const lifted = [p.cards.a.style.position, p.cards.a.style.zIndex, p.cards.a.style.pointerEvents, p.document.body.style.webkitUserSelect];
    pointer('pointerup', p.document, 20, 20);
    const after = [p.cards.a.style.position, p.cards.a.style.pointerEvents, p.document.body.style.webkitUserSelect];
    p.click(p.done);

    // Assert
    assert.equal(touch, 'none', 'customising: a finger drags');
    assert.deepEqual(lifted, ['fixed', '60', 'none', 'none']);
    assert.deepEqual(after, ['', '', '']);
    assert.equal(grip.style.touchAction, '', 'done: the page scrolls again');
});

/**
 * The title row drags too, but only once the pointer has moved a few pixels — a click stays a
 * click — and never from a link in it.
 */
test('the title row is a grip, after a few pixels, and not its links', async () => {
    // Arrange
    const p = page();
    const head = p.el('div', { 'data-pf-widget-head': '' }, p.cards.a);
    const link = p.el('a', { href: '/orders' }, head);
    p.click(p.edit);
    geometry(p, { a: [0, 0, 400, 100], b: [400, 0, 400, 100], c: [0, 120, 400, 100] }, () => 'c');

    // Act — a click on the link, a press that barely moves, then a real drag
    pointer('pointerdown', link, 20, 20);
    pointer('pointermove', p.document, 300, 150);
    const fromLink = p.cards.a.style.position || '';
    pointer('pointerup', p.document, 300, 150);
    pointer('pointerdown', head, 20, 20);
    pointer('pointermove', p.document, 22, 21);
    const barely = p.cards.a.style.position || '';
    pointer('pointermove', p.document, 350, 150);
    const dragged = p.cards.a.style.position;
    pointer('pointerup', p.document, 350, 150);
    await settle();

    // Assert
    assert.equal(fromLink, '', 'a link in the title is a link');
    assert.equal(barely, '', 'a few pixels: still a click');
    assert.equal(dragged, 'fixed');
    assert.deepEqual(p.order(), ['b', 'c', 'a']);
});

/**
 * While the title row waits for the pointer to move, the browser's own drag and text selection
 * are refused — they would take the pointer and cancel the drag — and allowed again once the
 * press ends; while customising, the row's text is not selectable.
 */
test('the title row refuses the browser\'s own gestures while it waits', async () => {
    // Arrange
    const p = page();
    const head = p.el('div', { 'data-pf-widget-head': '' }, p.cards.a);
    p.click(p.edit);
    geometry(p, { a: [0, 0, 400, 100], b: [400, 0, 400, 100], c: [0, 120, 400, 100] }, () => 'c');
    const gesture = (type) => { const e = new Event(type, { bubbles: true }); p.document.dispatchEvent(e); return e.defaultPrevented; };

    // Act
    pointer('pointerdown', head, 20, 20);
    const waiting = [gesture('dragstart'), gesture('selectstart')];
    pointer('pointerup', p.document, 20, 20);
    const after = [gesture('dragstart'), gesture('selectstart')];

    // Assert
    assert.deepEqual(waiting, [true, true], 'refused while waiting');
    assert.deepEqual(after, [false, false], 'the press ended: allowed again');
    assert.equal(head.style.userSelect, 'none');
    assert.equal(head.style.webkitUserSelect, 'none');
});

/**
 * The presets dialog opens from its button and closes from its own: its forms post and come back,
 * so opening and closing it is all the script does for it.
 */
test('the presets dialog opens and closes', () => {
    // Arrange
    const p = page();
    const open = p.el('button', { 'data-pf-dashboard-presets': '' }, p.bar);
    const dialog = p.el('dialog', { 'data-pf-dashboard-presets-dialog': '' });
    const close = p.el('button', { 'data-pf-dashboard-presets-close': '' }, dialog);

    // Act
    p.click(open);
    const opened = dialog.hasAttribute('open');
    p.click(close);

    // Assert — without showModal (the fake DOM), the open attribute is the state
    assert.equal(opened, true);
    assert.equal(dialog.hasAttribute('open'), false);
});

/**
 * A group of cards as tabs, added to a page: the frame, its tools, a strip of tabs and a panel per
 * card — the active one drawn, the others waiting to be drawn.
 */
function group(p, id, tabs, active) {
    const section = p.el('section', { 'data-pf-widget': id, 'data-pf-widget-group': '', 'data-size': 'full', 'data-active': active, 'data-title': 'Ops' }, p.board);
    const tools = p.el('div', { 'data-pf-widget-tools': '' }, section);
    ['-1', '1'].forEach((step) => p.el('button', { 'data-pf-tab-move': step }, tools));
    p.el('button', { 'data-pf-tab-ungroup': '' }, tools);
    p.el('button', { 'data-pf-widget-resize': '' }, tools);
    const strip = p.el('div', { 'data-pf-tabs': '', 'data-active-class': 'active' }, section);
    tabs.forEach((tab) => {
        const button = p.el('button', { 'data-pf-tab': tab }, strip);
        if (tab === active) { button.classList.add('active'); }
    });
    tabs.forEach((tab) => {
        const panel = p.el('div', tab === active ? { 'data-pf-tab-panel': tab, 'data-settings': '{"period":"30"}' } : { 'data-pf-tab-panel': tab, 'data-pf-tab-lazy': '' }, section);
        panel.hidden = tab !== active;
    });
    return section;
}

/**
 * A group is saved as one item with its tabs and active tab; opening another tab shows it, draws
 * it by its inside (part=body) the first time, tells its script it is shown, and saves the active
 * tab with the layout.
 */
test('a group of tabs is saved as one item, and opening a tab draws and shows it', async () => {
    // Arrange
    const p = page(true, '/admin/Dashboard/card');
    const g = group(p, 'tabs:x1', ['map', 'stats'], 'map');
    const shown = [];
    p.board.addEventListener('pf:dashboard:shown', (e) => shown.push(e.detail.id));

    // Act
    p.click(g.querySelector('[data-pf-tab="stats"]'));
    await settle();

    // Assert — the panel shown, drawn from the server's inside, the event, the save
    const panel = g.querySelector('[data-pf-tab-panel="stats"]');
    assert.equal(panel.hidden, false);
    assert.equal(g.querySelector('[data-pf-tab-panel="map"]').hidden, true);
    assert.deepEqual(p.drawn, ['/admin/Dashboard/card?id=stats&part=body']);
    assert.equal(panel.hasAttribute('data-pf-tab-lazy'), false);
    assert.deepEqual(shown, ['stats']);
    assert.equal(g.querySelector('[data-pf-tab="stats"]').classList.contains('active'), true, 'the theme\'s own active class');
    const saved = p.saves[0].body.layout[3];
    assert.deepEqual(saved, { id: 'tabs:x1', size: 'full', active: 'stats', title: 'Ops', tabs: [{ id: 'map', settings: { period: '30' } }, { id: 'stats', settings: {} }] });
});

/** "Group into tabs with the next card" makes the two one group, saved, and the page redrawn. */
test('grouping a card with the next one saves a group and reloads', async () => {
    // Arrange
    const p = page();
    p.el('button', { 'data-pf-widget-group-next': '' }, p.cards.a.querySelector('[data-pf-widget-tools]'));

    // Act
    p.click(p.cards.a.querySelector('[data-pf-widget-group-next]'));
    await settle();

    // Assert
    const layout = p.saves[0].body.layout;
    assert.deepEqual(layout.map((w) => w.id), ['', 'c'], 'the group, given its id by the server, then c');
    assert.deepEqual(layout[0].tabs.map((t) => t.id), ['a', 'b']);
    assert.equal(layout[0].active, 'a');
    assert.equal(p.window.reloaded, 1);
});

/**
 * A group's active tab moves along the strip — the tab and its panel, saved — and taken out it
 * becomes a card after the group, saved and the page redrawn.
 */
test('a tab moves along its strip, and is taken out of its group', async () => {
    // Arrange
    const p = page();
    const g = group(p, 'tabs:x2', ['one', 'two', 'three'], 'one');

    // Act — move the active tab right, then take it out
    p.click(g.querySelector('[data-pf-tab-move="1"]'));
    await settle();
    const moved = p.saves[0].body.layout[3].tabs.map((t) => t.id);
    p.click(g.querySelector('[data-pf-tab-ungroup]'));
    await settle();

    // Assert
    assert.deepEqual(moved, ['two', 'one', 'three']);
    const after = p.saves[1].body.layout;
    assert.deepEqual(after.slice(3).map((w) => w.id), ['tabs:x2', 'one']);
    assert.deepEqual(after[3].tabs.map((t) => t.id), ['two', 'three']);
    assert.equal(p.window.reloaded, 1);
});

/** Resizing a group tells its visible tab's script it has a new size. */
test('resizing a group tells its visible tab it is shown at a new size', async () => {
    // Arrange
    const p = page();
    const g = group(p, 'tabs:x3', ['map', 'stats'], 'map');
    const shown = [];
    p.board.addEventListener('pf:dashboard:shown', (e) => shown.push(e.detail.id));

    // Act
    p.click(g.querySelector('[data-pf-widget-resize]'));
    await settle();

    // Assert
    assert.deepEqual(shown, ['map']);
    assert.equal(g.getAttribute('data-size'), 's', 'full goes round to small');
});

/**
 * A tab's own settings: the group's gear opens the active tab's form; applying keeps the settings
 * on the tab's panel, saves them with the layout, and draws the tab's inside again in place.
 */
test('a card set inside a group of tabs is saved and drawn again in place', async () => {
    // Arrange — the active tab carries its settings form and its body
    const p = page(true, '/admin/Dashboard/card');
    const g = group(p, 'tabs:s1', ['sales', 'stats'], 'sales');
    p.el('button', { 'data-pf-tab-settings-toggle': '' }, g.querySelector('[data-pf-widget-tools]'));
    const panel = g.querySelector('[data-pf-tab-panel="sales"]');
    const form = p.el('form', { 'data-pf-widget-settings': '' }, panel);
    form.hidden = true;
    const field = p.el('select', { name: 'period' }, form);
    field.value = '90';
    p.el('div', { 'data-pf-tab-body': '' }, panel);

    // Act
    p.click(g.querySelector('[data-pf-tab-settings-toggle]'));
    const opened = !form.hidden;
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    await settle();

    // Assert
    assert.equal(opened, true, 'the active tab\'s form');
    assert.deepEqual(p.saves[0].body.layout[3].tabs[0], { id: 'sales', settings: { period: '90' } });
    assert.deepEqual(p.drawn, ['/admin/Dashboard/card?id=sales&part=body'], 'its inside, drawn again');
    assert.equal(form.hidden, true);
});

/** A card dragged by its grip and let go over a group's strip of tabs joins the group, as its active tab. */
test('a card dropped on a group\'s strip of tabs joins the group', async () => {
    // Arrange
    const p = page();
    const g = group(p, 'tabs:d1', ['map', 'stats'], 'map');
    const strip = g.querySelector('[data-pf-tabs]');
    p.click(p.edit);
    let under = 'b';
    geometry(p, { a: [0, 0, 400, 100], b: [400, 0, 400, 100], c: [0, 120, 400, 100] }, () => under);
    p.document.elementsFromPoint = () => (under === 'strip' ? [strip] : [p.cards[under]]);
    const grip = p.cards.a.querySelector('[data-pf-widget-grip]');

    // Act
    pointer('pointerdown', grip, 20, 20);
    under = 'strip';
    pointer('pointermove', p.document, 300, 400);
    const marked = strip.classList.contains('pf-dashboard-tabs-target');
    pointer('pointerup', p.document, 300, 400);
    await settle();

    // Assert
    assert.equal(marked, true, 'the strip shows it will take the card');
    const layout = p.saves[0].body.layout;
    assert.deepEqual(layout.map((w) => w.id), ['b', 'c', 'tabs:d1'], 'a is no longer a card of its own');
    assert.deepEqual(layout[2].tabs.map((t) => t.id), ['map', 'stats', 'a']);
    assert.equal(layout[2].active, 'a');
    assert.equal(p.window.reloaded, 1);
});

/**
 * A tab dragged along its strip changes place, saved without a reload; dragged out of its group
 * it becomes a card after the group; a press that does not move does nothing.
 */
test('a tab is dragged along its strip, and out of its group', async () => {
    // Arrange
    const p = page();
    const g = group(p, 'tabs:d2', ['one', 'two', 'three'], 'one');
    p.click(p.edit);
    const tab = (id) => g.querySelector(`[data-pf-tab="${id}"]`);
    tab('three').getBoundingClientRect = () => ({ left: 200, top: 0, width: 100, height: 30, right: 300, bottom: 30 });
    let under = [];
    p.document.elementsFromPoint = () => under;

    // Act — a press without a move, then a drag onto three's right half, then out of the group
    pointer('pointerdown', tab('one'), 10, 10);
    pointer('pointerup', p.document, 10, 10);
    const afterPress = p.saves.length;
    pointer('pointerdown', tab('one'), 10, 10);
    pointer('pointermove', p.document, 280, 12);
    under = [tab('three'), g];
    pointer('pointerup', p.document, 280, 12);
    await settle();
    const reordered = p.saves[p.saves.length - 1].body.layout[3].tabs.map((t) => t.id);
    const strip = Array.prototype.map.call(g.querySelectorAll('[data-pf-tab]'), (b) => b.getAttribute('data-pf-tab'));
    pointer('pointerdown', tab('two'), 10, 10);
    pointer('pointermove', p.document, 900, 900);
    under = [p.board];
    pointer('pointerup', p.document, 900, 900);
    await settle();

    // Assert
    assert.equal(afterPress, 0, 'a press is a click, not a drag');
    assert.deepEqual(reordered, ['two', 'three', 'one']);
    assert.deepEqual(strip, ['two', 'three', 'one'], 'the strip in the new order, without a reload');
    const out = p.saves[p.saves.length - 1].body.layout;
    assert.deepEqual(out.slice(3).map((w) => w.id), ['tabs:d2', 'two']);
    assert.deepEqual(out[3].tabs.map((t) => t.id), ['three', 'one']);
    assert.equal(p.window.reloaded, 1);
});


/**
 * The presets dialog as the views draw it: tabs, a form whose role field shows only for "from a
 * type up", and the person picker.
 */
function presetsDialog(el) {
    const dialog = el('dialog', { 'data-pf-dashboard-presets-dialog': '' });
    const strip = el('div', { 'data-pf-presets-tabs': '', 'data-active-class': 'active' }, dialog);
    ['list', 'save', 'copy'].forEach((key, i) => {
        const tab = el('button', { 'data-pf-presets-tab': key }, strip);
        if (i === 0) { tab.classList.add('active'); }
    });
    ['list', 'save', 'copy'].forEach((key, i) => { el('div', { 'data-pf-presets-pane': key }, dialog).hidden = i > 0; });
    const pane = (key) => dialog.querySelector(`[data-pf-presets-pane="${key}"]`);
    const save = el('form', {}, pane('save'));
    const visibility = el('select', { name: 'visibility' }, save);
    visibility.value = 'private';
    const roleLabel = el('label', { 'data-pf-show-when': 'visibility=role' }, save);
    el('select', { name: 'audience_role' }, roleLabel);
    const copy = el('form', { 'data-pf-person-form': '' }, pane('copy'));
    el('input', { type: 'hidden', name: 'userid', 'data-pf-person-id': '' }, copy).value = '';
    el('input', { type: 'search', 'data-pf-person-search': '', 'data-url': '/admin/Dashboard/people' }, copy);
    el('ul', { 'data-pf-person-results': '', 'data-pick-class': 'pick' }, copy).hidden = true;
    const submit = el('button', { type: 'submit', 'data-pf-person-submit': '' }, copy);
    submit.disabled = true;
    el('button', { 'data-pf-dashboard-presets-close': '' }, dialog);
    return dialog;
}

/**
 * The dialog's tabs show one pane at a time; a field for one choice shows, and counts, only for
 * it; and a form coming back opens the dialog on its tab (#pf-presets=save), the hash then cleared.
 */
test('the presets dialog: its tabs, a field for one choice, and reopening on a tab', async () => {
    // Arrange — the page as a form's post-redirect left it
    let dialog = null;
    const p = page(true, '', (el) => { dialog = presetsDialog(el); }, 'https://app.test/admin/Dashboard#pf-presets=save');
    const pane = (key) => dialog.querySelector(`[data-pf-presets-pane="${key}"]`);
    const role = dialog.querySelector('[data-pf-show-when]');

    // Assert — reopened on the save tab, the role field hidden and off for "only me"
    assert.equal(dialog.hasAttribute('open'), true);
    assert.equal(pane('save').hidden, false);
    assert.equal(pane('list').hidden, true);
    assert.equal(dialog.querySelector('[data-pf-presets-tab="save"]').classList.contains('active'), true);
    assert.equal(role.hidden, true);
    assert.equal(role.querySelector('select').disabled, true, 'a hidden field is not posted');
    assert.equal(p.window.location.hash, '', 'the hash cleared');

    // Act — another tab, then "from a type up"
    p.click(dialog.querySelector('[data-pf-presets-tab="list"]'));
    const listShown = !pane('list').hidden && pane('save').hidden;
    const select = dialog.querySelector('[name="visibility"]');
    select.value = 'role';
    select.dispatchEvent(new Event('change', { bubbles: true }));

    // Assert
    assert.equal(listShown, true);
    assert.equal(role.hidden, false);
    assert.equal(role.querySelector('select').disabled, false);
});

/**
 * The person picker asks the server from two letters, lists who it answers, and a choice fills the
 * form's userid and lets it be sent; without a choice the form is not sent.
 */
test('the person picker finds a person by name and fills the form', async () => {
    // Arrange
    let dialog = null;
    const p = page(true, '', (el) => { dialog = presetsDialog(el); });
    const asked = [];
    p.window.fetch = (url) => {
        asked.push(url);
        return Promise.resolve({ ok: true, json: () => Promise.resolve({ people: [
            { userid: 42, name: 'Alice Smith', username: 'alice', email: 'a@x.gr', type: 'Administrator' },
            { userid: 43, name: 'admin@x.gr', username: 'admin@x.gr', email: 'admin@x.gr', type: 'Root' },
            { userid: 44, name: 'Admin Admin', username: 'uw@x.gr', email: 'uw@x.gr', type: 'Administrator' },
        ] }) });
    };
    p.window.setTimeout = (fn) => { fn(); return 1; };
    p.window.clearTimeout = () => {};
    const search = dialog.querySelector('[data-pf-person-search]');
    const form = dialog.querySelector('[data-pf-person-form]');
    const submit = form.querySelector('[data-pf-person-submit]');

    // Act — one letter asks nothing; two ask; sending before a choice is held back
    search.value = 'a';
    search.dispatchEvent(new Event('input', { bubbles: true }));
    search.value = 'al';
    search.dispatchEvent(new Event('input', { bubbles: true }));
    await settle();
    const held = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(held);
    const pick = form.querySelector('[data-pf-person-pick]');
    const labels = form.querySelectorAll('[data-pf-person-pick]').map((b) => b.textContent);
    p.click(pick);

    // Assert
    assert.deepEqual(asked, ['/admin/Dashboard/people?q=al']);
    assert.equal(held.defaultPrevented, true, 'no person chosen: not sent');
    assert.equal(pick === null, false);
    assert.deepEqual(labels, ['Alice Smith (alice) · a@x.gr · Administrator', 'admin@x.gr · Root', 'Admin Admin · uw@x.gr · Administrator'],
        'nothing said twice, and always one identifier: a username that is the email still tells two Admins apart');
    assert.equal(form.querySelector('[data-pf-person-id]').value, '42');
    assert.equal(search.value, 'Alice Smith');
    assert.equal(submit.disabled, false);
    assert.equal(form.querySelector('[data-pf-person-results]').hidden, true);
});

/**
 * A structured setting: opening the form draws the list editor from its spec; adding, filling,
 * moving and removing items writes the hidden input's JSON; a breakdown offers the chosen
 * metric's dimensions; filters are dimension and value; and the form sends the structure, not
 * text.
 */
test('a list setting is edited as items and sent as a structure', async () => {
    // Arrange
    const p = page(true, '/admin/Dashboard/card?x=1');
    const tools = p.cards.a.querySelector('[data-pf-widget-tools]');
    const toggle = p.el('button', { 'data-pf-widget-settings-toggle': '', 'aria-expanded': 'false' }, tools);
    const form = p.el('form', { 'data-pf-widget-settings': '' }, p.cards.a);
    form.hidden = true;
    const hidden = p.el('input', { type: 'hidden', name: 'rows', 'data-pf-json': '', value: '[{"metric":"sales"}]' }, form);
    p.el('div', {
        'data-pf-structured': 'list',
        'data-spec': JSON.stringify({ input: 'list', fields: {
            metric: { label: 'Metric', input: 'select', values: { sales: 'Sales', buyers: 'Buyers' } },
            breakdown: { label: 'By', input: 'select', by: 'metric', valuesBy: { sales: { region: 'Region' }, buyers: {} } },
            filters: { label: 'Filters', input: 'filters', dimensions: { region: { label: 'Region', values: { north: 'North' } }, plan: { label: 'Plan', values: {} } }, by: 'metric', dimsBy: { sales: ['region', 'plan'] } },
            total: { label: 'Total', input: 'checkbox' },
        } }),
        'data-classes': '{"select":"s","input":"i","button":"b"}',
    }, form);
    const items = () => form.querySelectorAll('[data-pf-list-item]');
    const sub = (item, key) => item.querySelector('[data-pf-subfield="' + key + '"]');
    const change = (node, value) => { node.value = value; node.dispatchEvent(new Event(node.tagName.toLowerCase() === 'select' ? 'change' : 'input', { bubbles: true })); };

    // Act — open, fill the first, add a second, move it up, add a filter
    p.click(toggle);
    const drawn = items().length;
    const grid = items()[0].getAttribute('style');
    change(sub(items()[0], 'breakdown'), 'region');
    p.click(form.querySelector('[data-pf-list-add]'));
    change(sub(items()[1], 'metric'), 'buyers');
    p.click(items()[1].querySelector('[data-pf-list-move="-1"]'));
    p.click(items()[1].querySelector('[data-pf-filter-add]'));
    const filterRow = items()[1].querySelector('[data-pf-filter]');
    change(filterRow.querySelector('select'), 'region');
    // Its value: the dimension's own values, the row drawn again for it.
    change(items()[1].querySelector('[data-pf-filter]').querySelectorAll('select')[1], 'north');
    const total = sub(items()[1], 'total');
    total.checked = true;
    total.dispatchEvent(new Event('change', { bubbles: true }));
    const kept = JSON.parse(hidden.value);
    p.click(items()[0].querySelector('[data-pf-list-remove]'));
    form.dispatchEvent(new Event('submit', { bubbles: true }));
    await settle();

    // Assert
    assert.equal(drawn, 1, 'the saved item drawn');
    // A fixed grid, a column a field (filters wider, the checkbox its own width), so items line up.
    assert.ok(grid.includes('display:grid;grid-template-columns:auto minmax(6rem,1fr) minmax(6rem,1fr) minmax(12rem,2fr) auto auto auto auto'), grid);
    assert.deepEqual(kept, [{ metric: 'buyers' }, { metric: 'sales', breakdown: 'region', filters: { region: 'north' }, total: true }]);
    assert.deepEqual(p.saves[0].body.layout[0].settings.rows, [{ metric: 'sales', breakdown: 'region', filters: { region: 'north' }, total: true }], 'sent as a structure');
});

/** A board whose Customise button says so — a new, empty report — opens in Customise at once. */
test('a board asked to open for arranging starts in Customise', () => {
    // Arrange
    const p = page(true, '', (el, document) => document.querySelector('[data-pf-dashboard-edit]').setAttribute('data-pf-dashboard-open', ''));

    // Act — nothing: the page is loaded

    // Assert
    assert.equal(p.edit.getAttribute('aria-pressed'), 'true');
    assert.equal(p.bar.hidden, false, 'the bar to add a card is shown');
    assert.equal(p.tool('a', 'data-pf-widget-hide').closest('[data-pf-widget-tools]').hidden, false, 'the cards\' tools are out');
});

/**
 * A chart chosen under a report's table is drawn in place: that card alone fetched from the
 * board's card address (part=body, with the link's query), its frame replaced and drawn, the
 * address kept — not the whole report loaded again.
 */
test('a chart chosen under a table is fetched for that card alone and drawn in place', async () => {
    // Arrange
    const p = page(true, '/admin/MetricReports/card?board=report%3A7', null, 'https://app.test/admin/MetricReports/show?report=7');
    const holder = p.el('div', { 'data-pf-chart-holder': 'k1' }, p.cards.a);
    const link = p.el('a', { 'data-pf-chart-choice': '', href: '/admin/MetricReports/show?report=7&chart%5Bk1%5D=pie#pf-chart-k1' }, holder);
    const asked = [];
    p.window.fetch = (url) => { asked.push(url); return Promise.resolve({ ok: true, text: () => Promise.resolve('<div data-pf-chart-holder="k1">pie</div>') }); };

    // Act
    const event = new Event('click', { bubbles: true, cancelable: true });
    link.dispatchEvent(event);
    await settle();

    // Assert
    assert.equal(asked[0], '/admin/MetricReports/card?board=report%3A7&id=a&part=body&report=7&chart%5Bk1%5D=pie', 'this card alone, with the choice');
    assert.equal(event.defaultPrevented, true, 'not a page load');
    assert.equal(p.cards.a.children.includes(holder), false, 'its frame replaced');
    assert.equal(p.charted.length > 0, true, 'and drawn');
    assert.match(p.window.location.href, /chart%5Bk1%5D=pie/, 'the address says what is shown');
});

/**
 * What is folded is remembered in this browser, for each report: the page opens as it was left,
 * another report's folds are its own, and unfolding forgets it.
 */
test('a folded section is remembered for its report', () => {
    // Arrange
    const url = 'https://app.test/admin/MetricReports/show?report=7';
    const key = 'pf-report-folded:/admin/MetricReports/show:7';
    const first = page(true, '', null, url);
    const toggle = first.el('button', { 'data-pf-report-section': '', 'aria-expanded': 'true' }, first.cards.a);

    // Act
    first.click(toggle);
    const kept = first.window.localStorage.getItem(key);
    const again = page(true, '', (el, document, window) => {
        window.localStorage.setItem(key, kept);
        el('button', { 'data-pf-report-section': '', 'aria-expanded': 'true' }, document.querySelector('[data-pf-widget="a"]'));
    }, url);
    const other = page(true, '', (el, document, window) => {
        window.localStorage.setItem(key, kept);
        el('button', { 'data-pf-report-section': '', 'aria-expanded': 'true' }, document.querySelector('[data-pf-widget="a"]'));
    }, 'https://app.test/admin/MetricReports/show?report=8');
    first.click(toggle);

    // Assert
    assert.equal(kept, '["a"]', 'the folded section, by its card');
    assert.equal(again.cards.b.hidden, true, 'opened again as it was left');
    assert.equal(again.document.querySelector('[data-pf-report-section]').getAttribute('aria-expanded'), 'false');
    assert.equal(other.cards.b.hidden, false, 'another report\'s folds are its own');
    assert.equal(first.window.localStorage.getItem(key), '[]', 'unfolded: forgotten');
});

/** A report's section heading folds the cards under it away, up to the next section, and back. */
test('a section heading folds the cards under it', () => {
    // Arrange
    const p = page();
    const toggle = p.el('button', { 'data-pf-report-section': '', 'aria-expanded': 'true' }, p.cards.a);
    p.el('button', { 'data-pf-report-section': '' }, p.cards.c);

    // Act
    p.click(toggle);
    const folded = [p.cards.b.hidden, p.cards.c.hidden, toggle.getAttribute('aria-expanded')];
    p.click(toggle);

    // Assert
    assert.deepEqual(folded, [true, false, 'false'], 'up to the next section');
    assert.equal(p.cards.b.hidden, false, 'shown again');
});
