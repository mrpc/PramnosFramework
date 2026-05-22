/**
 * PramnosGridJS — Grid.js 6.x server-side adapter for the Pramnos REST API.
 *
 * Translates Grid.js pagination, search, and sort server hooks to the Pramnos
 * API format (?page=N&search=...&order=FIELD+dir&fields=f1,f2) and maps the
 * {data, pagination} response back to the Grid.js expected shape
 * {data: [...], total: N}.
 *
 * Vanilla JS — no jQuery dependency.
 *
 * Usage:
 *   var config = PramnosGridJS.createConfig('/api/1.0/users', {
 *       fields: ['userid', 'username', 'email'],
 *       itemsPerPage: 10,
 *       search: true,
 *       sort: true
 *   });
 *
 *   new gridjs.Grid({
 *       columns: config.columns,
 *       server: config.server,
 *       pagination: config.pagination,
 *       search: config.search,
 *       sort: config.sort
 *   }).render(document.getElementById('grid-wrapper'));
 *
 * CSRF: automatically reads <meta name="csrf-token"> and sends
 *       X-CSRF-Token header on every request (Phase 16 session auth).
 *
 * Requires: Grid.js 6.x (gridjs.io).
 */
(function (window) {
    'use strict';

    /**
     * Read the CSRF token from the page's <meta name="csrf-token"> tag.
     *
     * @returns {string|null}
     */
    function getCsrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : null;
    }

    /**
     * Append a key=value pair to a URL, using ? or & as appropriate.
     *
     * @param {string} url
     * @param {string} key
     * @param {string} value
     * @returns {string}
     */
    function appendParam(url, key, value) {
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        return url + sep + encodeURIComponent(key) + '=' + encodeURIComponent(value);
    }

    /**
     * Build headers for the Pramnos API fetch, including CSRF if present.
     *
     * @returns {object}
     */
    function buildHeaders() {
        var headers = { 'Accept': 'application/json' };
        var token   = getCsrfToken();
        if (token) {
            headers['X-CSRF-Token'] = token;
        }
        return headers;
    }

    /**
     * Transform a Pramnos REST response to the shape Grid.js expects.
     *
     * Grid.js expects: { data: [...], total: N }
     * Pramnos returns: { data: [...], pagination: { totalitems: N, ... } }
     *
     * @param {object} resp  Parsed JSON response from the API
     * @returns {{ data: Array, total: number }}
     */
    function transformResponse(resp) {
        var data  = Array.isArray(resp.data) ? resp.data : [];
        var total = 0;

        if (resp.pagination && resp.pagination.totalitems !== undefined) {
            total = parseInt(resp.pagination.totalitems, 10) || 0;
        } else {
            total = data.length;
        }

        return { data: data, total: total };
    }

    /**
     * Namespace exposed on window.
     */
    var PramnosGridJS = {

        /**
         * Create a complete Grid.js configuration block for a Pramnos API endpoint.
         *
         * Returns an object whose keys map directly to Grid.js constructor options
         * so you can spread / extend them as needed.
         *
         * @param {string} apiUrl                API endpoint, e.g. "/api/1.0/users"
         * @param {object} [options]
         * @param {string[]} [options.fields]    Column field names to request
         * @param {number}   [options.itemsPerPage=10] Rows per page
         * @param {boolean}  [options.search=true]     Enable search server hook
         * @param {boolean}  [options.sort=false]      Enable sort server hook
         * @returns {object}  { server, pagination, search?, sort? }
         */
        createConfig: function (apiUrl, options) {
            options = options || {};

            var fields       = options.fields       || [];
            var itemsPerPage = options.itemsPerPage  || 10;
            var enableSearch = options.search !== false;
            var enableSort   = options.sort   === true;

            var fieldsParam = fields.join(',');

            // Base server config — handles data fetching + response transformation.
            var server = {
                url: function (prev, page, limit) {
                    // Grid.js passes 0-based page; Pramnos API expects 1-based.
                    var url = appendParam(prev, 'page', page + 1);
                    url     = appendParam(url,  'items', limit);
                    if (fieldsParam) {
                        url = appendParam(url, 'fields', fieldsParam);
                    }
                    return url;
                },
                handle: function (res) {
                    if (!res.ok) {
                        throw new Error('Pramnos API error: HTTP ' + res.status);
                    }
                    return res.json().then(transformResponse);
                },
                headers: buildHeaders()
            };

            var config = {
                server     : { url: apiUrl, handle: server.handle, headers: server.headers },
                pagination : {
                    limit  : itemsPerPage,
                    server : { url: server.url }
                }
            };

            if (enableSearch) {
                config.search = {
                    server: {
                        url: function (prev, keyword) {
                            return appendParam(prev, 'search', keyword || '');
                        }
                    }
                };
            }

            if (enableSort) {
                config.sort = {
                    server: {
                        url: function (prev, columns) {
                            if (!columns || !columns.length) {
                                return prev;
                            }
                            var parts = columns.map(function (col) {
                                return col.id + ' ' + (col.direction === 1 ? 'asc' : 'desc');
                            });
                            return appendParam(prev, 'order', parts.join(','));
                        }
                    }
                };
            }

            return config;
        },

        /**
         * Convenience wrapper: create and render a Grid.js instance.
         *
         * @param {string|Element} container  CSS selector or DOM element
         * @param {string}         apiUrl     API endpoint
         * @param {object[]}       columns    Grid.js column definitions
         * @param {object}         [options]  Options passed to createConfig() plus any
         *                                    extra Grid.js options (merged in directly)
         * @returns {gridjs.Grid|null}
         */
        init: function (container, apiUrl, columns, options) {
            if (typeof gridjs === 'undefined') {
                console.warn('PramnosGridJS: gridjs is not loaded');
                return null;
            }

            var el = typeof container === 'string'
                ? document.querySelector(container)
                : container;

            if (!el) {
                console.warn('PramnosGridJS: container not found', container);
                return null;
            }

            options = options || {};
            var gridConfig = this.createConfig(apiUrl, options);

            var instanceConfig = {
                columns    : columns || [],
                server     : gridConfig.server,
                pagination : gridConfig.pagination
            };

            if (gridConfig.search) {
                instanceConfig.search = gridConfig.search;
            }
            if (gridConfig.sort) {
                instanceConfig.sort = gridConfig.sort;
            }

            // Allow caller to override any Grid.js option.
            var extraKeys = ['language', 'fixedHeader', 'resizable', 'width', 'height', 'className'];
            extraKeys.forEach(function (key) {
                if (options[key] !== undefined) {
                    instanceConfig[key] = options[key];
                }
            });

            return new gridjs.Grid(instanceConfig).render(el);
        }
    };

    window.PramnosGridJS = PramnosGridJS;

}(window));
