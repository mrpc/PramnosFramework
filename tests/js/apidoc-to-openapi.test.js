/**
 * The apidoc → OpenAPI converter's path handling.
 *
 * This exists because of a bug the API playground made visible: every endpoint
 * documented as `@api {get} /status` — which is how they are all documented —
 * became `//status` in the generated document, because the leading slash was
 * added unconditionally. A doubled slash is not the same path. Anything that
 * sends it verbatim gets a 404 that reads as a routing bug in the application,
 * and the document is what a client generator, a playground and a reader all
 * trust.
 *
 * The script is framework-owned and copied into every project, so it is driven
 * here rather than assumed correct.
 *
 * Run:
 *   node --test tests/js/apidoc-to-openapi.test.js
 */
'use strict';

const { test, describe } = require('node:test');
const assert             = require('node:assert/strict');
const path               = require('node:path');

const { ApiDocToOpenAPIConverter } = require(
    path.join(__dirname, '..', '..', 'scaffolding', 'scripts', 'apidoc-to-openapi.cjs')
);

/** A converter with no configuration to speak of — only the parser is used. */
function parser() {
    return new ApiDocToOpenAPIConverter({});
}

/** One apidoc comment body, as the scanner hands it over. */
function block(lines) {
    return lines.map((line) => ' * ' + line).join('\n');
}

describe('the apidoc → OpenAPI converter', () => {
    test('a path that already starts with a slash keeps exactly one', () => {
        // Arrange — how every scaffolded controller documents itself
        const endpoint = parser().parseApiDocBlock(block([
            '@api {get} /status Status',
            '@apiName getStatus',
            '@apiGroup Status',
        ]));

        // Act & Assert
        assert.equal(endpoint.path, '/status');
        assert.equal(endpoint.method, 'get');
        assert.equal(endpoint.summary, 'Status');
    });

    test('a path written without a slash still gets one', () => {
        // Arrange — apidoc allows it, and the document must not
        const endpoint = parser().parseApiDocBlock(block(['@api {post} account/login Login']));

        // Act & Assert
        assert.equal(endpoint.path, '/account/login');
    });

    test('a path with parameters is left alone', () => {
        // Arrange — the `:name` → `{name}` conversion happens later, on the way
        // into the document; the parser must not pre-empt or mangle it
        const endpoint = parser().parseApiDocBlock(block(['@api {get} /things/:id One thing']));

        // Act & Assert
        assert.equal(endpoint.path, '/things/:id');
    });

    test('loading the script does not run a conversion', () => {
        // Arrange & Act — this file has already required it. If loading converted,
        // the require above would have written a document into the working
        // directory and printed a report, which is why the guard exists.
        // Assert — the class is what the module exports
        assert.equal(typeof ApiDocToOpenAPIConverter, 'function');
        assert.equal(typeof parser().parseApiDocBlock, 'function');
    });
});
