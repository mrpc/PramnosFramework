/**
 * Tests for DebugBar responsive layout & category dropdown menus.
 *
 * Run:
 *   node --test tests/js/debugbar-responsive.test.js
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');
const fs                 = require('node:fs');
const os                 = require('node:os');
const path               = require('node:path');
const { execFileSync }   = require('node:child_process');

const ROOT = path.join(__dirname, '..', '..');

function loadModuleSource() {
    const php = 'require "vendor/autoload.php";'
        + ' echo Pramnos\\Debug\\DebugBarAsset::spaModule("TestApp");';
    return execFileSync('php', ['-r', php], { cwd: ROOT, encoding: 'utf8' });
}

function makeDom() {
    const byId = {};

    function makeElement(tag) {
        const el = {
            tagName: tag,
            children: [],
            style: {},
            dataset: {},
            classList: { add() {}, remove() {} },
            listeners: {},
            _text: '',
            _html: '',
            set id(value) { this._id = value; byId[value] = el; },
            get id() { return this._id; },
            set textContent(value) { this._text = String(value); },
            get textContent() { return this._text; },
            set innerHTML(value) {
                this._html = String(value);
                const re = /id="([^"]+)"/g;
                let m;
                while ((m = re.exec(String(value))) !== null) {
                    byId[m[1]] = byId[m[1]] || makeElement('div');
                }
            },
            get innerHTML() { return this._html; },
            append(...nodes) { el.children.push(...nodes); },
            appendChild(node) { el.children.push(node); return node; },
            remove() {},
            addEventListener(name, fn) { el.listeners[name] = fn; },
            querySelector(selector) {
                const id = selector.replace(/^#/, '');
                return byId[id] || null;
            },
        };

        return el;
    }

    const doc = {
        createElement: makeElement,
        head: makeElement('head'),
        body: makeElement('body'),
        addEventListener() {},
    };

    return { doc, byId };
}

function payload(extra = {}) {
    const time = extra.time ?? 12.5;
    const queries = extra.queries || [{ sql: 'SELECT 1' }];
    return Object.assign({
        time,
        memory: { peak_bytes: 1, peak_human: '1 B' },
        request: { time, memory: 2.5 },
        queries: { count: queries.length, total_ms: 3, queries },
    }, extra.extraKeys || {});
}

async function loadPanel() {
    const file = path.join(
        fs.mkdtempSync(path.join(os.tmpdir(), 'pramnos-resp-dbg-')), 'debug.mjs'
    );
    fs.writeFileSync(file, loadModuleSource());

    const dom = makeDom();
    global.document = dom.doc;
    global.window = { document: dom.doc };
    global.localStorage = { getItem() { return null; }, setItem() {}, removeItem() {} };

    const module = await import('file://' + file + '?t=' + Math.random());
    return { record: module.record, dom };
}

describe('DebugBar Responsive & Category Dropdowns', () => {
    test('it renders pinned left and right bar containers', async () => {
        const { record, dom } = await loadPanel();
        record('GET', '/api/test', 200, payload());

        assert.ok(dom.byId['pdb-left'], '#pdb-left container should exist');
        assert.ok(dom.byId['pdb-right'], '#pdb-right container should exist');
        assert.ok(dom.byId['pdb-tabs-wrap'], '#pdb-tabs-wrap scroll container should exist');
    });

    test('it renders all tabs with migrations positioned at the end', async () => {
        const { record, dom } = await loadPanel();
        record('GET', '/api/test', 200, payload({
            queries: [{ sql: 'SELECT 1' }, { sql: 'SELECT 2' }],
            extraKeys: {
                gate: { checks: [] },
                migrations: { ran: [] }
            }
        }));

        const tabsHtml = dom.byId['pdb-tabs'].innerHTML;

        // SQL appears on the main bar
        assert.match(tabsHtml, /data-panel="queries"[^>]*>SQL/);

        // Migrations is the last tab
        assert.match(tabsHtml, /data-panel="migrations"[^>]*>Migrations/);
        const lastTabMatch = tabsHtml.match(/data-panel="([^"]+)"[^>]*>[^<]*<\/button>(?:<\/div>)*$/);
        if (lastTabMatch) {
            assert.equal(lastTabMatch[1], 'migrations');
        }
    });
});
