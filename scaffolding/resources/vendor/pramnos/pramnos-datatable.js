/**
 * PramnosDataTable — DataTables 2.x serverSide adapter for the Pramnos REST API.
 *
 * Translates DataTables {draw, start, length, search, order, columns} parameters
 * to the Pramnos API format (?page=N&search=...&order=FIELD+dir&fields=f1,f2) and
 * maps the {data, pagination} response back to the DataTables 2.x envelope
 * {draw, data, recordsTotal, recordsFiltered}.
 *
 * Usage:
 *   <table id="users-table" data-dt-api="/api/1.0/users"></table>
 *
 *   PramnosDataTable.init('#users-table');
 *
 *   // With explicit columns and extra DataTables options:
 *   PramnosDataTable.init('#users-table', {
 *       columns: [
 *           { data: 'userid', title: 'ID' },
 *           { data: 'username', title: 'Username' }
 *       ]
 *   });
 *
 * CSRF: automatically reads <meta name="csrf-token"> and sends
 *       X-CSRF-Token header on every request (Phase 16 session auth).
 *
 * Requires: jQuery 3.x, DataTables 2.x (datatables.net).
 */
(function (window, $) {
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
     * Build a DataTables ajax configuration object that calls the Pramnos API.
     *
     * Uses a closure to carry the last draw counter from the request into the
     * response so that responses that arrive out-of-order are discarded by DT.
     *
     * @param {string} apiUrl  Absolute or root-relative API endpoint
     * @returns {object}       DataTables ajax config
     */
    function buildAjaxConfig(apiUrl) {
        var lastDraw = 0;

        return {
            url: apiUrl,
            type: 'GET',
            beforeSend: function (xhr) {
                var token = getCsrfToken();
                if (token) {
                    xhr.setRequestHeader('X-CSRF-Token', token);
                }
            },
            data: function (dtParams) {
                // Capture draw counter for the response transformation.
                lastDraw = dtParams.draw;

                // Convert DT2 zero-based start/length → 1-based page number.
                var length = dtParams.length || 10;
                var page   = Math.floor((dtParams.start || 0) / length) + 1;

                // Global search value.
                var search = (dtParams.search && dtParams.search.value) ? dtParams.search.value : '';

                // Build "field dir[,field2 dir2]" order string.
                var orderParts = [];
                if (dtParams.order && dtParams.order.length) {
                    $.each(dtParams.order, function (_, o) {
                        var col = dtParams.columns && dtParams.columns[o.column];
                        if (col && col.data) {
                            orderParts.push(col.data + ' ' + (o.dir === 'desc' ? 'desc' : 'asc'));
                        }
                    });
                }

                // Build comma-separated fields list from columns definition.
                var fields = [];
                if (dtParams.columns && dtParams.columns.length) {
                    $.each(dtParams.columns, function (_, col) {
                        if (col.data && col.data !== '') {
                            fields.push(col.data);
                        }
                    });
                }

                return {
                    page   : page,
                    search : search,
                    order  : orderParts.join(','),
                    fields : fields.join(',')
                };
            },
            dataFilter: function (json) {
                var resp;
                try {
                    resp = JSON.parse(json);
                } catch (e) {
                    return json;
                }

                // If the server already returned DT2 format (draw + recordsTotal), pass through.
                if (resp.hasOwnProperty('recordsTotal') && resp.hasOwnProperty('data')) {
                    if (!resp.draw) {
                        resp.draw = lastDraw;
                    }
                    return JSON.stringify(resp);
                }

                // Convert Pramnos clean REST format → DT2 envelope.
                var totalItems = 0;
                if (resp.pagination) {
                    totalItems = parseInt(resp.pagination.totalitems, 10) || 0;
                } else if (Array.isArray(resp.data)) {
                    totalItems = resp.data.length;
                }

                return JSON.stringify({
                    draw            : lastDraw,
                    data            : resp.data || [],
                    recordsTotal    : totalItems,
                    recordsFiltered : totalItems
                });
            }
        };
    }

    /**
     * Namespace exposed on window.
     */
    var PramnosDataTable = {

        /**
         * Initialise a DataTables 2.x serverSide instance.
         *
         * Reads the API URL from the table element's data-dt-api attribute
         * (falls back to data-api). Merges caller-supplied options on top of
         * the serverSide defaults.
         *
         * @param {string} selector   CSS selector targeting the <table> element
         * @param {object} [options]  Any DataTables options to merge in
         * @returns {DataTables.Api|null}
         */
        init: function (selector, options) {
            var $table = $(selector);
            if (!$table.length) {
                return null;
            }

            var apiUrl = $table.data('dt-api') || $table.data('api');
            if (!apiUrl) {
                console.warn('PramnosDataTable: missing data-dt-api attribute on', selector);
                return null;
            }

            var defaults = {
                serverSide  : true,
                processing  : true,
                ajax        : buildAjaxConfig(apiUrl),
                language    : {
                    processing : '<span class="spinner-border spinner-border-sm" role="status"></span>'
                }
            };

            return $table.DataTable($.extend(true, {}, defaults, options || {}));
        },

        /**
         * Return a DataTables ajax config for manual use.
         *
         * Useful when you construct the DataTable yourself and just need
         * the ajax block pre-wired for the Pramnos API.
         *
         * @param {string} apiUrl
         * @returns {object}
         */
        buildAjaxConfig: buildAjaxConfig
    };

    window.PramnosDataTable = PramnosDataTable;

}(window, jQuery));
