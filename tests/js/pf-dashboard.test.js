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
function page(ok = true, cardUrl = '') {
    const { window, document } = createDom({ meta: { csrf: 't0ken' } });
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
    el('p', { 'data-pf-dashboard-empty': '' }, board).hidden = true;

    const saves = [];
    const drawn = [];
    const charted = [];
    window.fetch = (url, init) => {
        if (init.method !== 'POST') {
            drawn.push(url);
            const id = decodeURIComponent(String(url).split('id=')[1] || '');
            return Promise.resolve({ ok: true, status: 200, text: () => Promise.resolve('<section class="card" data-pf-widget="' + id + '" data-size="s">drawn</section>') });
        }
        saves.push({ url, headers: init.headers, body: JSON.parse(init.body) });
        return Promise.resolve({ ok, status: ok ? 200 : 403, json: () => Promise.resolve({}) });
    };
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
