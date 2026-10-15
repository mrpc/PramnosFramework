/**
 * Charts in `pf-utils.js`, drawn over the markup `Html\Chart` renders.
 *
 * What has to hold: a chart is drawn from the JSON in its figure, with no inline script; its
 * colours are the theme's tokens — daisyUI's, Bootstrap's or the plain theme's names — read when
 * it is drawn, so a theme change draws it again in the new ones; numbers are formatted in the
 * page's language; and without Chart.js nothing is drawn and the table of the numbers stays, until
 * Chart.js arrives with the page's load event.
 *
 * Run:
 *   node --test tests/js/pf-chart.test.js
 */
'use strict';

const { test } = require('node:test');
const assert   = require('node:assert/strict');
const path     = require('node:path');

const { createDom, loadScript, Event } = require('../../resources/js/pramnos-testing');

const SOURCE = path.join(__dirname, '..', '..', 'scaffolding', 'assets', 'js', 'pf-utils.js');

/** A stand-in for Chart.js: records what it was asked to draw, and whether it was destroyed. */
function fakeChartJs() {
    const drawn = [];
    class Chart {
        /** Record one chart. */
        constructor(canvas, config) {
            this.canvas = canvas;
            this.config = config;
            this.destroyed = false;
            drawn.push(this);
        }

        /** Mark it gone, as Chart.js does before a redraw. */
        destroy() {
            this.destroyed = true;
        }
    }

    return { Chart, drawn };
}

/**
 * A page with one chart figure, as Chart::render() draws it, and its theme's tokens.
 *
 * @param {object} config  What the figure's JSON holds
 * @param {object} tokens  Custom properties getComputedStyle answers with
 * @param {object} opts    `chart` false for a page without Chart.js; `lang` for <html lang>
 */
function page(config, tokens, { chart = true, lang = 'en' } = {}) {
    const { window, document } = createDom();
    document.documentElement.setAttribute('lang', lang);
    const el = (tag, attrs = {}, parent = null) => {
        const node = document.createElement(tag);
        Object.entries(attrs).forEach(([k, v]) => node.setAttribute(k, v));
        (parent || document.body).appendChild(node);
        return node;
    };
    const figure = el('figure', { class: 'pf-chart-figure', 'data-pf-chart-figure': '' });
    const box    = el('div', { class: 'pf-chart-canvas' }, figure);
    box.hidden   = true;
    el('canvas', {}, box);
    const json   = el('script', { type: 'application/json', 'data-pf-chart-config': '' }, figure);
    json.textContent = JSON.stringify(Object.assign({ format: { decimals: 2 }, stacked: false, height: 240, options: {} }, config));
    const table  = el('table', { class: 'pf-chart-data' }, figure);

    const theme = { values: tokens };
    window.getComputedStyle = () => ({
        color: 'rgb(1, 2, 3)',
        getPropertyValue: (name) => theme.values[name] || '',
    });
    const observers = [];
    window.MutationObserver = class {
        /** Keep the callback, so the test can play a theme change. */
        constructor(callback) { observers.push(callback); }
        /** Nothing to watch in a fake DOM. */
        observe() {}
    };
    const scheme = [];
    window.matchMedia = () => ({ addEventListener: (type, fn) => scheme.push(fn) });

    const charts = fakeChartJs();
    if (chart) { window.Chart = charts.Chart; }
    loadScript(SOURCE, window);

    return { window, figure, box, table, theme, observers, scheme, charts };
}

const BARS = {
    type: 'bar',
    labels: ['Jan', 'Feb'],
    datasets: [{ label: 'Revenue', data: [1200, 1850.5] }, { label: 'Target', data: [1500, 1500], color: 'warning', type: 'line' }],
};

/**
 * A figure is drawn from its JSON in the theme's colours: the first series in the primary, a
 * series that names a theme colour in that one; the canvas shown at its height, the table kept
 * for screen readers only.
 */
test('a chart is drawn from its JSON in the theme colours', () => {
    // Arrange & Act
    const { box, table, charts } = page(BARS, { '--color-primary': 'oklch(50% .2 260)', '--color-warning': 'orange', '--color-base-content': 'black' });

    // Assert
    assert.equal(charts.drawn.length, 1);
    const { config } = charts.drawn[0];
    assert.equal(config.type, 'bar');
    assert.deepEqual(config.data.labels, ['Jan', 'Feb']);
    assert.equal(config.data.datasets[0].borderColor, 'oklch(50% .2 260)', 'the theme token, as the theme wrote it');
    assert.equal(config.data.datasets[1].borderColor, 'orange', 'a named theme colour');
    assert.equal(config.data.datasets[1].type, 'line', 'a line mixed into the bars');
    assert.equal(config.options.scales.x.ticks.color, 'black', 'axes in the theme\'s text colour');
    assert.equal(box.hidden, false);
    assert.equal(box.style.height, '240px');
    assert.ok(table.classList.contains('pf-visually-hidden'), 'the table stays for screen readers');
});

/** On a Bootstrap page the same names resolve to Bootstrap's variables. */
test('a bootstrap page lends its own variables', () => {
    // Arrange & Act
    const { charts } = page(BARS, { '--bs-primary': '#0d6efd', '--bs-warning': '#ffc107', '--bs-body-color': '#212529' });

    // Assert
    const { datasets } = charts.drawn[0].config.data;
    assert.equal(datasets[0].borderColor, '#0d6efd');
    assert.equal(datasets[1].borderColor, '#ffc107');
});

/** A pie gives each slice the next theme colour, and has no axes. */
test('a pie colours each slice', () => {
    // Arrange & Act
    const { charts } = page({ type: 'pie', labels: ['A', 'B', 'C'], datasets: [{ label: 'Share', data: [1, 2, 3] }] }, { '--color-primary': 'p', '--color-secondary': 's', '--color-accent': 'a' });

    // Assert
    const { config } = charts.drawn[0];
    assert.deepEqual(config.data.datasets[0].backgroundColor, ['p', 's', 'a']);
    assert.equal(config.options.scales, undefined);
    assert.equal(config.options.plugins.legend.display, true);
});

/** The tooltip and the axis read numbers as the page's language writes them, in its currency. */
test('numbers are formatted in the page language', () => {
    // Arrange
    const cfg = Object.assign({}, BARS, { format: { style: 'currency', currency: 'EUR', decimals: 2 } });
    const { charts } = page(cfg, {}, { lang: 'el' });
    const { options } = charts.drawn[0].config;
    const expected = new Intl.NumberFormat('el', { style: 'currency', currency: 'EUR', maximumFractionDigits: 2 });

    // Act
    const tip  = options.plugins.tooltip.callbacks.label({ raw: 1850.5, parsed: { y: 1850.5 }, dataset: { label: 'Revenue' } });
    const tick = options.scales.y.ticks.callback(1500);

    // Assert
    assert.equal(tip, 'Revenue: ' + expected.format(1850.5));
    assert.equal(tick, expected.format(1500));
});

/**
 * A theme change draws the chart again in the new colours: the observer on data-theme, and the
 * OS colour scheme, each destroy the old chart first.
 */
test('a theme change draws it again in the new colours', () => {
    // Arrange
    const { charts, theme, observers, scheme } = page(BARS, { '--color-primary': 'blue' });

    // Act — the theme flips, then the OS scheme does
    theme.values = { '--color-primary': 'skyblue' };
    observers.forEach((fn) => fn([]));
    scheme.forEach((fn) => fn({ matches: true }));

    // Assert
    assert.equal(charts.drawn.length, 3);
    assert.equal(charts.drawn[0].destroyed, true, 'the old one is gone');
    assert.equal(charts.drawn[1].destroyed, true);
    assert.equal(charts.drawn[2].config.data.datasets[0].borderColor, 'skyblue');
});

/**
 * Without Chart.js nothing is drawn and the table is the chart; Chart.js loading after this
 * script is drawn with the page's load event.
 */
test('without Chart.js the table stays, until the library arrives', () => {
    // Arrange
    const { window, box, table, charts } = page(BARS, {}, { chart: false });

    // Assert — nothing drawn, the numbers visible
    assert.equal(box.hidden, true);
    assert.equal(table.classList.contains('pf-visually-hidden'), false);

    // Act — the library arrives after this script
    window.Chart = charts.Chart;
    window.dispatchEvent(new Event('load'));

    // Assert
    assert.equal(charts.drawn.length, 1);
    assert.equal(box.hidden, false);
});

/** Options passed through `chart` are merged over the drawn ones, nested keys included. */
test('the page\'s own Chart.js options win', () => {
    // Arrange & Act
    const { charts } = page(Object.assign({}, BARS, { options: { plugins: { legend: { position: 'bottom' } }, indexAxis: 'y' } }), {});

    // Assert
    const { options } = charts.drawn[0].config;
    assert.equal(options.indexAxis, 'y');
    assert.equal(options.plugins.legend.position, 'bottom');
    assert.equal(options.plugins.legend.display, true, 'the drawn keys beside it are kept');
});
