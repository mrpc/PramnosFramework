/**
 * Run a browser script — a classic `<script>`, not a module — the way a page runs it.
 */
'use strict';

const vm   = require('node:vm');
const fs   = require('node:fs');

/**
 * Run `file` against `window`: its globals are the window's, and what it assigns to `window` (or
 * declares at the top level) is on the returned context.
 *
 * @param {string} file           Path to the script, or its source with `{ source: true }`
 * @param {object} window         From `createDom()`, with whatever stubs the test added
 * @param {object} [options]
 * @param {boolean} [options.source] `file` is the source itself
 * @returns {object} The context the script ran in — the window
 */
function loadScript(file, window, options = {}) {
    const code = options.source ? file : fs.readFileSync(file, 'utf8');
    const context = vm.isContext(window) ? window : vm.createContext(window);
    vm.runInContext(code, context, { filename: options.source ? 'inline.js' : file });

    return context;
}

module.exports = { loadScript };
