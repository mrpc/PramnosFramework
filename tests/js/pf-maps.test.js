/**
 * A metric on a map (report.map), drawn by pf-utils with Leaflet where the page has it: a circle
 * at each place, the largest the biggest, the view fitted to them, the table under it hidden from
 * sight but kept for a screen reader; without Leaflet the table stays and nothing is drawn.
 *
 * Run:
 *   node --test tests/js/pf-maps.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/** A page with one map card, Leaflet faked (or absent), and what was drawn. */
function page(withLeaflet) {
    const { window, document } = createDom({ url: 'https://app.test/' });
    const box = document.createElement('div');
    box.setAttribute('data-pf-map', JSON.stringify([{ lat: 40.6, lng: 22.9, label: 'North', value: 9, text: '9' }, { lat: 37.9, lng: 23.7, label: 'South', value: 1, text: '1' }]));
    box.hidden = true;
    const card = document.createElement('section');
    card.appendChild(box);
    const table = document.createElement('table');
    table.setAttribute('data-pf-map-table', '');
    card.appendChild(table);
    document.body.appendChild(card);
    const drawn = { circles: [], fitted: null, tiles: 0 };
    if (withLeaflet) {
        const layer = { addTo() { return this; }, bindTooltip() { return this; } };
        window.L = {
            map: () => ({ fitBounds: (bounds) => { drawn.fitted = bounds; } }),
            tileLayer: () => { drawn.tiles++; return layer; },
            circleMarker: (at, options) => { drawn.circles.push({ at, radius: options.radius }); return layer; },
        };
    }
    loadScript(SOURCE, window);
    window.pfCharts.draw(document);

    return { box, table, drawn };
}

test('a map card is drawn with Leaflet: a circle per place, sized by its figure', () => {
    // Arrange & Act
    const p = page(true);

    // Assert
    assert.equal(p.box.hidden, false);
    assert.equal(p.drawn.tiles, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(p.drawn.circles.map((c) => c.at))), [[40.6, 22.9], [37.9, 23.7]]);
    assert.ok(p.drawn.circles[0].radius > p.drawn.circles[1].radius, 'the largest the biggest');
    assert.deepEqual(JSON.parse(JSON.stringify(p.drawn.fitted)), [[40.6, 22.9], [37.9, 23.7]]);
    assert.ok(p.table.classList.contains('pf-visually-hidden'), 'the table kept for a screen reader');
});

test('without Leaflet the table stays and nothing is drawn', () => {
    // Arrange & Act
    const p = page(false);

    // Assert
    assert.equal(p.box.hidden, true);
    assert.equal(p.table.classList.contains('pf-visually-hidden'), false);
});
