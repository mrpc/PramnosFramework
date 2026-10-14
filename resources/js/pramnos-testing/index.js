/**
 * `pramnos-testing`: what an application's JavaScript tests need, shipped with the framework.
 *
 * ```js
 * const { test } = require('node:test');
 * const assert = require('node:assert/strict');
 * const { createDom, loadScript, stubFetch, installApiHeaders, installJquery } = require('pramnos-testing');
 *
 * test('the button saves', async () => {
 *     const { window, document } = createDom({ meta: { csrf: 't0ken' } });
 *     window.fetch = stubFetch({ 'POST /api/notes': { status: 201, body: { id: 7 } } });
 *     installApiHeaders(window);
 *     document.body.appendChild(document.createElement('button')).setAttribute('data-save', '');
 *
 *     loadScript('www/assets/js/notes.js', window);
 *     document.querySelector('[data-save]').click();
 *     await new Promise((done) => setImmediate(done));
 *
 *     assert.equal(window.fetch.calls[0].headers['X-CSRF-Token'], 't0ken');
 * });
 * ```
 *
 * `php bin/<app> test:js` puts this directory on `NODE_PATH`, so `require('pramnos-testing')`
 * resolves wherever the application is installed.
 */
'use strict';

module.exports = {
    ...require('./dom.js'),
    ...require('./load.js'),
    ...require('./fetch.js'),
    ...require('./jquery.js'),
};
