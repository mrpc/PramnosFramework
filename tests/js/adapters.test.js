/**
 * Phase 17 — Client-side unit tests for PramnosDataTable and PramnosGridJS.
 *
 * Uses only Node.js built-ins (node:test + node:assert/strict + node:vm + node:fs)
 * so there are zero npm dependencies.
 *
 * Run:
 *   node --test tests/js/adapters.test.js
 *   node --test --test-reporter=tap tests/js/adapters.test.js
 *
 * Coverage strategy:
 *   - All pure transformation functions tested directly via the public API
 *   - DOM/jQuery dependencies mocked with minimal stubs
 *   - No real fetch, DataTables, or Grid.js instances created
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');
const vm                 = require('node:vm');
const fs                 = require('node:fs');
const path               = require('node:path');

// ─── File paths ────────────────────────────────────────────────────────────
const ADAPTERS_DIR = path.join(__dirname, '..', '..', 'scaffolding', 'resources', 'vendor', 'pramnos');
const DT_JS        = path.join(ADAPTERS_DIR, 'pramnos-datatable.js');
const GRID_JS      = path.join(ADAPTERS_DIR, 'pramnos-gridjs.js');

// ─── Loader helpers ─────────────────────────────────────────────────────────

/**
 * Create a minimal jQuery mock sufficient for pramnos-datatable.js.
 * Only $.each is needed by the adapter.
 */
function makejQuery() {
    function jQuery() { return null; }
    jQuery.each = function (arr, cb) {
        if (Array.isArray(arr)) {
            arr.forEach(function (item, i) { cb(i, item); });
        } else {
            Object.keys(arr).forEach(function (k) { cb(k, arr[k]); });
        }
    };
    jQuery.extend = function () {
        var out = {};
        for (var i = 0; i < arguments.length; i++) {
            Object.assign(out, arguments[i]);
        }
        return out;
    };
    return jQuery;
}

/**
 * Create a minimal document mock.
 * @param {string|null} csrfToken  Value for <meta name="csrf-token">.
 */
function makeDocument(csrfToken) {
    return {
        querySelector: function (sel) {
            if (sel === 'meta[name="csrf-token"]' && csrfToken) {
                return { getAttribute: function () { return csrfToken; } };
            }
            return null;
        }
    };
}

/**
 * Load pramnos-datatable.js in an isolated vm context.
 * Returns window.PramnosDataTable.
 */
function loadDataTable(csrfToken) {
    var src    = fs.readFileSync(DT_JS, 'utf8');
    var win    = {};
    var ctx    = vm.createContext({
        window  : win,
        document: makeDocument(csrfToken),
        jQuery  : makejQuery(),
        console : console
    });
    vm.runInContext(src, ctx);
    return ctx.window.PramnosDataTable;
}

/**
 * Load pramnos-gridjs.js in an isolated vm context.
 * Returns window.PramnosGridJS.
 */
function loadGridJS(csrfToken) {
    var src = fs.readFileSync(GRID_JS, 'utf8');
    var win = {};
    var ctx = vm.createContext({
        window  : win,
        document: makeDocument(csrfToken),
        console : console
    });
    vm.runInContext(src, ctx);
    return ctx.window.PramnosGridJS;
}

// ═══════════════════════════════════════════════════════════════════════════
// PramnosDataTable
// ═══════════════════════════════════════════════════════════════════════════

describe('PramnosDataTable', function () {

    // ── buildAjaxConfig — structure ────────────────────────────────────────

    test('buildAjaxConfig returns an object with required ajax keys', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);

        // Act
        var config = PramnosDataTable.buildAjaxConfig('/api/users');

        // Assert — DataTables ajax config must have these keys
        assert.ok(config, 'config should be truthy');
        assert.ok(typeof config.url === 'string',   'url must be a string');
        assert.ok(typeof config.data === 'function', 'data must be a function');
        assert.ok(typeof config.dataFilter === 'function', 'dataFilter must be a function');
        assert.ok(typeof config.beforeSend === 'function', 'beforeSend must be a function');
    });

    // ── data() — page number calculation ──────────────────────────────────

    test('data(): start=0 length=10 maps to page=1 (1-based)', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');

        // Act
        var params = config.data({ draw: 1, start: 0, length: 10, search: {}, order: [], columns: [] });

        // Assert — page is 1-based (API expects 1 for the first page)
        assert.strictEqual(params.page, 1);
    });

    test('data(): start=20 length=10 maps to page=3', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');

        // Act
        var params = config.data({ draw: 2, start: 20, length: 10, search: {}, order: [], columns: [] });

        // Assert
        assert.strictEqual(params.page, 3);
    });

    test('data(): start=0 length=25 maps to page=1', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');

        // Act
        var params = config.data({ draw: 1, start: 0, length: 25, search: {}, order: [], columns: [] });

        // Assert
        assert.strictEqual(params.page, 1);
    });

    // ── data() — search ────────────────────────────────────────────────────

    test('data(): empty search.value maps to empty search string', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');

        // Act
        var params = config.data({ draw: 1, start: 0, length: 10, search: { value: '' }, order: [], columns: [] });

        // Assert — empty search must be empty string, not undefined
        assert.strictEqual(params.search, '');
    });

    test('data(): search.value is passed through to the search parameter', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');

        // Act
        var params = config.data({ draw: 1, start: 0, length: 10, search: { value: 'alice' }, order: [], columns: [] });

        // Assert
        assert.strictEqual(params.search, 'alice');
    });

    // ── data() — order ─────────────────────────────────────────────────────

    test('data(): single order maps to "field dir" string', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');
        var dtParams = {
            draw   : 1,
            start  : 0,
            length : 10,
            search : { value: '' },
            order  : [{ column: 0, dir: 'asc' }],
            columns: [{ data: 'username' }]
        };

        // Act
        var params = config.data(dtParams);

        // Assert — Pramnos order format is "field dir"
        assert.strictEqual(params.order, 'username asc');
    });

    test('data(): desc order is preserved', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');
        var dtParams = {
            draw   : 1,
            start  : 0,
            length : 10,
            search : { value: '' },
            order  : [{ column: 0, dir: 'desc' }],
            columns: [{ data: 'created_at' }]
        };

        // Act
        var params = config.data(dtParams);

        // Assert
        assert.strictEqual(params.order, 'created_at desc');
    });

    test('data(): multiple order columns joined with comma', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');
        var dtParams = {
            draw   : 1,
            start  : 0,
            length : 10,
            search : { value: '' },
            order  : [
                { column: 0, dir: 'asc' },
                { column: 1, dir: 'desc' }
            ],
            columns: [
                { data: 'lastname' },
                { data: 'created_at' }
            ]
        };

        // Act
        var params = config.data(dtParams);

        // Assert — two sort fields, comma-separated
        assert.strictEqual(params.order, 'lastname asc,created_at desc');
    });

    // ── data() — fields ────────────────────────────────────────────────────

    // ── data() — perpage ───────────────────────────────────────────────────

    test('data(): length is forwarded as perpage param', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');

        // Act
        var params = config.data({ draw: 1, start: 0, length: 25, search: {}, order: [], columns: [] });

        // Assert — server needs perpage to apply the correct LIMIT
        assert.strictEqual(params.perpage, 25);
    });

    test('data(): length=10 forwards perpage=10', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');

        // Act
        var params = config.data({ draw: 1, start: 0, length: 10, search: {}, order: [], columns: [] });

        // Assert
        assert.strictEqual(params.perpage, 10);
    });

    // ── data() — fields ────────────────────────────────────────────────────

    test('data(): columns with data values are joined into fields param', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');
        var dtParams = {
            draw   : 1,
            start  : 0,
            length : 10,
            search : { value: '' },
            order  : [],
            columns: [
                { data: 'userid' },
                { data: 'username' },
                { data: 'email' }
            ]
        };

        // Act
        var params = config.data(dtParams);

        // Assert — comma-separated field list
        assert.strictEqual(params.fields, 'userid,username,email');
    });

    // ── dataFilter() — Pramnos → DT2 format ────────────────────────────────

    test('dataFilter(): converts Pramnos pagination envelope to DT2 format', function () {
        // Arrange
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');
        // Simulate one call to data() so lastDraw is set
        config.data({ draw: 5, start: 0, length: 10, search: {}, order: [], columns: [] });

        var pramnosResponse = JSON.stringify({
            data      : [{ userid: 1 }, { userid: 2 }],
            pagination: { totalitems: 42, page: 1 }
        });

        // Act
        var result = JSON.parse(config.dataFilter(pramnosResponse));

        // Assert — DT2 format
        assert.strictEqual(result.draw, 5);            // draw echoed from last request
        assert.strictEqual(result.recordsTotal, 42);
        assert.strictEqual(result.recordsFiltered, 42);
        assert.ok(Array.isArray(result.data));
        assert.strictEqual(result.data.length, 2);
    });

    test('dataFilter(): already-DT2 response is passed through unchanged', function () {
        // Arrange — response already has recordsTotal (native DT2 backend)
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');
        config.data({ draw: 3, start: 0, length: 10, search: {}, order: [], columns: [] });

        var dt2Response = JSON.stringify({
            draw         : 3,
            data         : [{ id: 1 }],
            recordsTotal : 100,
            recordsFiltered: 50
        });

        // Act
        var result = JSON.parse(config.dataFilter(dt2Response));

        // Assert — structure preserved
        assert.strictEqual(result.recordsTotal, 100);
        assert.strictEqual(result.recordsFiltered, 50);
        assert.strictEqual(result.draw, 3);
    });

    test('dataFilter(): no pagination → uses data array length as total', function () {
        // Arrange — response has data but no pagination block
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');
        config.data({ draw: 1, start: 0, length: 10, search: {}, order: [], columns: [] });

        var resp = JSON.stringify({ data: [{ id: 1 }, { id: 2 }, { id: 3 }] });

        // Act
        var result = JSON.parse(config.dataFilter(resp));

        // Assert — falls back to array length
        assert.strictEqual(result.recordsTotal, 3);
    });

    // ── beforeSend() — CSRF token ──────────────────────────────────────────

    test('beforeSend(): injects X-CSRF-Token header when meta tag is present', function () {
        // Arrange — load adapter with a CSRF token in the document
        var PramnosDataTable = loadDataTable('test-csrf-token-123');
        var config = PramnosDataTable.buildAjaxConfig('/api/users');
        var headers = {};
        var mockXhr = { setRequestHeader: function (k, v) { headers[k] = v; } };

        // Act
        config.beforeSend(mockXhr);

        // Assert — CSRF header set
        assert.strictEqual(headers['X-CSRF-Token'], 'test-csrf-token-123');
    });

    test('beforeSend(): does NOT inject X-CSRF-Token when meta tag is absent', function () {
        // Arrange — no CSRF token in the document
        var PramnosDataTable = loadDataTable(null);
        var config = PramnosDataTable.buildAjaxConfig('/api/users');
        var headers = {};
        var mockXhr = { setRequestHeader: function (k, v) { headers[k] = v; } };

        // Act
        config.beforeSend(mockXhr);

        // Assert — no header added
        assert.ok(!headers.hasOwnProperty('X-CSRF-Token'));
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// PramnosGridJS
// ═══════════════════════════════════════════════════════════════════════════

describe('PramnosGridJS', function () {

    // ── createConfig — structure ───────────────────────────────────────────

    test('createConfig returns object with server and pagination keys', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);

        // Act
        var config = PramnosGridJS.createConfig('/api/users');

        // Assert — required keys
        assert.ok(config.server,     'server key must exist');
        assert.ok(config.pagination, 'pagination key must exist');
    });

    test('createConfig includes search by default', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);

        // Act — search defaults to true
        var config = PramnosGridJS.createConfig('/api/users');

        // Assert
        assert.ok(config.search, 'search key must exist by default');
    });

    test('createConfig omits search when search=false', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);

        // Act
        var config = PramnosGridJS.createConfig('/api/users', { search: false });

        // Assert
        assert.ok(!config.search, 'search must not be present when disabled');
    });

    test('createConfig includes sort when sort=true', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);

        // Act
        var config = PramnosGridJS.createConfig('/api/users', { sort: true });

        // Assert
        assert.ok(config.sort, 'sort key must exist when sort=true');
    });

    test('createConfig default itemsPerPage is 10', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);

        // Act
        var config = PramnosGridJS.createConfig('/api/users');

        // Assert — pagination.limit encodes items-per-page
        assert.strictEqual(config.pagination.limit, 10);
    });

    test('createConfig custom itemsPerPage is respected', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);

        // Act
        var config = PramnosGridJS.createConfig('/api/users', { itemsPerPage: 25 });

        // Assert
        assert.strictEqual(config.pagination.limit, 25);
    });

    // ── pagination URL builder ─────────────────────────────────────────────

    test('pagination.server.url converts 0-based Grid.js page to 1-based API page', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users');
        var urlFn         = config.pagination.server.url;

        // Act — Grid.js passes 0-based page
        var url = urlFn('/api/users', 0, 10);

        // Assert — Pramnos API expects page=1 for the first page
        assert.ok(url.includes('page=1'), 'first page should be page=1, got: ' + url);
    });

    test('pagination.server.url converts page 2 (0-based) to page=3', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users');
        var urlFn         = config.pagination.server.url;

        // Act
        var url = urlFn('/api/users', 2, 10);

        // Assert
        assert.ok(url.includes('page=3'), 'page 2 (0-based) should map to page=3, got: ' + url);
    });

    test('pagination.server.url appends limit as items param', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users');
        var urlFn         = config.pagination.server.url;

        // Act
        var url = urlFn('/api/users', 0, 25);

        // Assert
        assert.ok(url.includes('items=25'), 'limit should be sent as items param, got: ' + url);
    });

    test('pagination.server.url includes fields param when fields option is set', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users', { fields: ['userid', 'email'] });
        var urlFn         = config.pagination.server.url;

        // Act
        var url = urlFn('/api/users', 0, 10);

        // Assert
        assert.ok(url.includes('fields=userid%2Cemail') || url.includes('fields=userid,email'),
            'fields param missing from URL: ' + url);
    });

    // ── search URL builder ─────────────────────────────────────────────────

    test('search.server.url appends search keyword to URL', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users', { search: true });
        var urlFn         = config.search.server.url;

        // Act
        var url = urlFn('/api/users', 'alice');

        // Assert
        assert.ok(url.includes('search=alice'), 'search param missing, got: ' + url);
    });

    // ── sort URL builder ───────────────────────────────────────────────────

    test('sort.server.url appends order param in field+dir format', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users', { sort: true });
        var urlFn         = config.sort.server.url;

        // Act — Grid.js direction 1 = asc
        var url = urlFn('/api/users', [{ id: 'username', direction: 1 }]);

        // Assert
        assert.ok(url.includes('order=username+asc') || url.includes('order=username%20asc'),
            'asc order missing from URL: ' + url);
    });

    test('sort.server.url maps direction -1 to desc', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users', { sort: true });
        var urlFn         = config.sort.server.url;

        // Act — Grid.js direction -1 = desc (any non-1 value)
        var url = urlFn('/api/users', [{ id: 'created_at', direction: -1 }]);

        // Assert
        assert.ok(url.includes('order=created_at+desc') || url.includes('order=created_at%20desc'),
            'desc order missing from URL: ' + url);
    });

    test('sort.server.url returns unchanged URL when no columns provided', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users', { sort: true });
        var urlFn         = config.sort.server.url;

        // Act — no sort columns
        var url = urlFn('/api/users?page=1', []);

        // Assert — URL unchanged
        assert.strictEqual(url, '/api/users?page=1');
    });

    // ── handle / transformResponse ─────────────────────────────────────────

    test('handle transforms Pramnos REST response to Grid.js {data, total} format', function () {
        // Arrange — create a mock Response-like object
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users');
        var handleFn      = config.server.handle;

        var pramnosBody = JSON.stringify({
            data      : [{ userid: 1 }, { userid: 2 }],
            pagination: { totalitems: 42, page: 1 }
        });

        var mockResponse = {
            ok     : true,
            status : 200,
            json   : function () { return Promise.resolve(JSON.parse(pramnosBody)); }
        };

        // Act — handle returns a Promise<{data, total}>
        return handleFn(mockResponse).then(function (result) {
            // Assert
            assert.strictEqual(result.total, 42);
            assert.ok(Array.isArray(result.data), 'data must be an array');
            assert.strictEqual(result.data.length, 2);
        });
    });

    test('handle throws on non-ok HTTP response', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users');
        var handleFn      = config.server.handle;

        var mockResponse = { ok: false, status: 500 };

        // Act — handle should throw on error status
        assert.throws(function () { handleFn(mockResponse); }, /HTTP 500/);
    });

    // ── CSRF headers ───────────────────────────────────────────────────────

    test('createConfig server.headers includes X-CSRF-Token when meta tag present', function () {
        // Arrange — load with a CSRF token
        var PramnosGridJS = loadGridJS('csrf-abc-123');
        var config        = PramnosGridJS.createConfig('/api/users');

        // Assert — headers object set at config creation time
        assert.strictEqual(config.server.headers['X-CSRF-Token'], 'csrf-abc-123');
    });

    test('createConfig server.headers has no X-CSRF-Token when meta tag absent', function () {
        // Arrange
        var PramnosGridJS = loadGridJS(null);
        var config        = PramnosGridJS.createConfig('/api/users');

        // Assert
        assert.ok(!config.server.headers.hasOwnProperty('X-CSRF-Token'));
    });
});
