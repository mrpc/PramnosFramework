/**
 * The debug toolbar's hide button, on a server-rendered page.
 *
 * This exists because of a real failure, reported from two applications at once:
 * the `✕` did nothing at all. It toggled the *panel's* inline `display` starting
 * from `''`, while the stylesheet already hid the panel — so the first click set
 * `display:none` on something invisible, and the second handed it back to the
 * stylesheet, which hid it again. Two clicks, no effect, in both the
 * server-rendered toolbar and the SPA panel: one mistake written twice, which is
 * why there is now one source and this file drives what ships.
 *
 * A PHP test cannot catch that. It can assert the button is emitted, not that
 * clicking it does anything.
 *
 * Run:
 *   node --test tests/js/debugbar-hide.test.js
 *
 * Zero npm dependencies: node:test, node:assert, node:vm, node:child_process.
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');

const { loadToolbar, clickInBar } = require('./support/toolbar-dom');

/** The data island a rendered page carries. */
function island() {
    return {
        request: { time: 12.5, memory: 2.5 },
        queries: { count: 0, total_ms: 0, queries: [] },
        request_method: 'GET',
        request_path: '/dashboard',
        status_code: 200,
    };
}

/** Click the ✕. */
function clickClose(dom) {
    clickInBar(dom, '#pdb-close-btn');
}

describe('the toolbar’s hide button', () => {
    test('it hides the whole bar and gives the page its strip back', () => {
        // Arrange
        const { dom, store } = loadToolbar({ payload: island() });
        assert.equal(dom.byId['pramnos-debugbar'].style.display, '');
        assert.equal(dom.document.body.style.paddingBottom, '30px');

        // Act
        clickClose(dom);

        // Assert — the bar goes, not just the panel; that was the whole bug
        assert.equal(dom.byId['pramnos-debugbar'].style.display, 'none');
        // A hidden bar that still reserves 30px is a gap nothing on screen
        // explains, which is how the two used to drift apart.
        assert.equal(dom.document.body.style.paddingBottom, '');
        assert.equal(dom.byId['pdb-restore'].style.display, 'block', 'a way back appears');
        assert.equal(store['pramnos.debugbar.hidden'], '1', 'and the choice is remembered');
    });

    test('the restore handle brings it back and forgets the choice', () => {
        // Arrange
        const { dom, store } = loadToolbar({ payload: island() });
        clickClose(dom);

        // Act — the handle lives outside the bar and has its own listener: nested
        // inside, hiding the bar would hide the only way back.
        dom.byId['pdb-restore'].listeners.click();

        // Assert
        assert.equal(dom.byId['pramnos-debugbar'].style.display, '');
        assert.equal(dom.byId['pdb-restore'].style.display, 'none');
        assert.equal(dom.document.body.style.paddingBottom, '30px');
        assert.equal('pramnos.debugbar.hidden' in store, false, 'nothing left to re-hide it');
    });

    /**
     * Hiding it on one page and having it return on the next reads as the button
     * not working — which is exactly what was reported.
     */
    test('a bar hidden on an earlier page loads hidden', () => {
        // Arrange & Act
        const { dom } = loadToolbar({ payload: island(), stored: '1' });

        // Assert
        assert.equal(dom.byId['pramnos-debugbar'].style.display, 'none');
        assert.equal(dom.byId['pdb-restore'].style.display, 'block');
        assert.equal(dom.document.body.style.paddingBottom, '', 'no strip for a bar nobody sees');
    });

    /**
     * Clicking the open tab closes the panel and leaves the bar — the ✕ is for
     * the bar. Two controls, two jobs; conflating them is what produced a hide
     * button that hid nothing.
     */
    test('a tab closes its own panel without hiding the bar', () => {
        // Arrange
        const { dom } = loadToolbar({ payload: island() });

        // Act — open, then click the same tab again
        clickInBar(dom, '.pdb-tab', { dataset: { panel: 'requests' } });
        assert.equal(dom.byId['pdb-panel'].style.display, 'block');
        clickInBar(dom, '.pdb-tab', { dataset: { panel: 'requests' } });

        // Assert
        assert.equal(dom.byId['pdb-panel'].style.display, 'none', 'the panel closed');
        assert.equal(dom.byId['pramnos-debugbar'].style.display, '', 'the bar stayed');
    });

    /**
     * Storage access throws outright in Safari's private mode and on a blocked
     * origin. Not being able to remember the choice is no reason to ignore it.
     */
    test('storage that throws costs the memory, not the button', () => {
        // Arrange
        const { dom } = loadToolbar({ payload: island(), storageThrows: true });

        // Act & Assert
        assert.doesNotThrow(() => clickClose(dom));
        assert.equal(dom.byId['pramnos-debugbar'].style.display, 'none', 'hiding still works');
    });
});
