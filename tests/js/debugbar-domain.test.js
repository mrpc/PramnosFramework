/**
 * The toolbar's Domain tab: models *and* services in one place.
 *
 * This exists because of a reported dead end. In a Services + API + SPA project
 * the tab was labelled "Models", the project has no models, and so the tab was
 * empty for a request that had done all of its work in services — with nothing
 * on screen to distinguish "nothing happened" from "your code does not appear
 * here". The fix has two halves and this file drives the client one: the tab is
 * named after the layer rather than one implementation of it, it draws both
 * sections, and the badge counts what the panel contains.
 *
 * The payload key stays `models` — a front end already reading it must not
 * break — so the mapping from that key to a tab named Domain is itself part of
 * what is asserted here.
 *
 * Run:
 *   node --test tests/js/debugbar-domain.test.js
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');

const { loadToolbar, clickInBar } = require('./support/toolbar-dom');

/**
 * A rendered page's data island, with whichever domain payloads a test needs.
 *
 * Both keys are always sent by a framework that has the collectors registered,
 * so a test that omits one is describing an application, not an older server.
 */
function island({ models = null, services = null } = {}) {
    var payload = {
        request: { time: 12.5, memory: 2.5 },
        queries: { count: 0, total_ms: 0, queries: [] },
        request_method: 'GET',
        request_path: '/api/1.0/status',
        status_code: 200,
    };
    if (models !== null) { payload.models = models; }
    if (services !== null) { payload.services = services; }
    return payload;
}

/** An empty-but-present models payload, as a project with no models produces. */
function noModels() {
    return { count: 0, ops: 0, operations: [] };
}

/** Open a tab the way the delegated click listener sees it. */
function openTab(dom, panel) {
    clickInBar(dom, '.pdb-tab', { dataset: { panel } });
}

describe('the Domain tab', () => {
    test('the tab is named after the layer, not after models', () => {
        // Arrange & Act
        const { dom } = loadToolbar({
            payload: island({ models: noModels(), services: { count: 0, ops: 0, services: [], operations: [] } }),
        });

        // Assert — the label a reader sees, and the key the data travels under
        const tabs = dom.byId['pdb-tabs'].innerHTML;
        assert.match(tabs, /data-panel="models"[^>]*>Domain/);
        assert.doesNotMatch(tabs, />Models</, 'the old label is gone');
    });

    test('a services-only request shows its services, and counts them on the tab', () => {
        // Arrange — no models at all, which is the whole reported case
        const { dom } = loadToolbar({
            payload: island({
                models: noModels(),
                services: {
                    count: 2,
                    ops: 3,
                    services: [
                        { class: 'StatusService', ops: 1, ms: 4.2 },
                        { class: 'BillingService', ops: 2, ms: 11.5 },
                    ],
                    operations: [
                        { class: 'StatusService', op: 'snapshot', ms: 4.2 },
                        { class: 'BillingService', op: 'overdue', ms: 9.5 },
                        { class: 'BillingService', op: 'invoice', ms: 2 },
                    ],
                },
            }),
        });

        // Act
        openTab(dom, 'models');
        const html = dom.byId['pdb-panel'].innerHTML;

        // Assert — every service, and every timed call
        assert.match(html, /2 service\(s\)/);
        assert.match(html, /3 measured call\(s\)/);
        assert.match(html, /StatusService/);
        assert.match(html, /BillingService/);
        assert.match(html, /snapshot/);
        assert.match(html, /11\.5ms/);

        // Assert — the badge counts the tab's contents. Reading 0 above a panel
        // listing three calls was the bug in miniature.
        assert.match(dom.byId['pdb-tabs'].innerHTML, /data-panel="models"[^>]*>Domain<span[^>]*>2</);
    });

    test('models and services are both drawn, in one panel', () => {
        // Arrange
        const { dom } = loadToolbar({
            payload: island({
                models: {
                    count: 1,
                    ops: 1,
                    operations: [{ class: 'Invoice', table: 'invoices', op: 'save', key: 17 }],
                },
                services: {
                    count: 1,
                    ops: 1,
                    services: [{ class: 'BillingService', ops: 1, ms: 6 }],
                    operations: [{ class: 'BillingService', op: 'charge', ms: 6 }],
                },
            }),
        });

        // Act
        openTab(dom, 'models');
        const html = dom.byId['pdb-panel'].innerHTML;

        // Assert — an application built both ways sees both, in reading order
        assert.match(html, /1 class\(es\), 1 operation\(s\)/);
        assert.match(html, /1 service\(s\)/);
        assert.ok(
            html.indexOf('Models') < html.indexOf('Services'),
            'models first: they are the older half of the domain layer'
        );
        assert.match(html, /invoices/);
        assert.match(html, /charge/);
        // Both counts, on one badge.
        assert.match(dom.byId['pdb-tabs'].innerHTML, /Domain<span[^>]*>2</);
    });

    test('an empty services section says what would fill it', () => {
        // Arrange — a project whose services are plain classes
        const { dom } = loadToolbar({
            payload: island({
                models: noModels(),
                services: { count: 0, ops: 0, services: [], operations: [] },
            }),
        });

        // Act
        openTab(dom, 'models');
        const html = dom.byId['pdb-panel'].innerHTML;

        // Assert — the sentence is the feature: an empty panel that explains
        // itself is the difference between "nothing ran" and "extend the base".
        assert.match(html, /No services recorded/);
        assert.match(html, /Pramnos\\+Application\\+Service/);
    });

    test('a service that ran without timing anything still appears', () => {
        // Arrange — construction is automatic, measure() is opt-in
        const { dom } = loadToolbar({
            payload: island({
                models: noModels(),
                services: {
                    count: 1,
                    ops: 0,
                    services: [{ class: 'StatusService', ops: 0, ms: 0 }],
                    operations: [],
                },
            }),
        });

        // Act
        openTab(dom, 'models');
        const html = dom.byId['pdb-panel'].innerHTML;

        // Assert — listed, with no duration invented for it, and told how to get one
        assert.match(html, /StatusService/);
        assert.match(html, /—/);
        assert.match(html, /No call was timed/);
        assert.match(html, /measure/);
    });

    test('a services collector that failed does not take the models with it', () => {
        // Arrange — instrumentation can throw; the panel is still worth drawing
        const { dom } = loadToolbar({
            payload: island({
                models: {
                    count: 1,
                    ops: 1,
                    operations: [{ class: 'Invoice', table: 'invoices', op: 'load', key: 3 }],
                },
                services: { error: 'reflection failed' },
            }),
        });

        // Act
        openTab(dom, 'models');
        const html = dom.byId['pdb-panel'].innerHTML;

        // Assert — the half that worked is shown
        assert.match(html, /1 class\(es\), 1 operation\(s\)/);
        assert.match(html, /invoices/);
        assert.match(html, /No services recorded/);
        // And the badge counts only what it can count, rather than throwing.
        assert.match(dom.byId['pdb-tabs'].innerHTML, /Domain<span[^>]*>1</);
    });

    test('a response with no services key at all is drawn as if empty', () => {
        // Arrange — an application that registered its own collectors, or a
        // payload from before this collector existed
        const { dom } = loadToolbar({
            payload: island({
                models: {
                    count: 1,
                    ops: 1,
                    operations: [{ class: 'Invoice', table: 'invoices', op: 'load', key: 3 }],
                },
            }),
        });

        // Act
        openTab(dom, 'models');

        // Assert — no crash, and the models still read
        const html = dom.byId['pdb-panel'].innerHTML;
        assert.match(html, /1 class\(es\), 1 operation\(s\)/);
        assert.match(html, /No services recorded/);
    });
});
