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

describe('the docs page', () => {
    test('is RapiDoc by default and Scalar where apidoc.json says so, pinned and quiet', () => {
        // Arrange
        const converter = new ApiDocToOpenAPIConverter({});
        const specs     = { '1.1': 'openapi-v1.1.json', '1.0': 'openapi-v1.0.json' };

        // Act
        const rapidoc = converter.generateRapiDocHtml(['1.1', '1.0'], specs, {});
        const scalar  = converter.generateScalarHtml(['1.1', '1.0'], specs, { name: 'Shop </script>', defaultApiKey: 'k1' });

        // Assert — RapiDoc unchanged; Scalar a source per version, the latest first and default
        assert.match(rapidoc, /<rapi-doc/);
        assert.match(scalar, /@scalar\/api-reference@1\.75\.1\//);
        assert.match(scalar, /"telemetry":false/);
        assert.match(scalar, /"agent":\{"disabled":true\}/);
        assert.doesNotMatch(scalar, /proxyUrl/);
        assert.match(scalar, /"slug":"v1\.1","url":"\.\.\/openapi-v1\.1\.json","default":true/);
        assert.match(scalar, /"slug":"v1\.0","url":"\.\.\/openapi-v1\.0\.json","default":false/);
        assert.match(scalar, /"ApiKeyAuth":\{"value":"k1"\}/);
        // The title is escaped where it is HTML and cannot close a script
        assert.match(scalar, /<title>Shop &lt;\/script&gt;<\/title>/);
    });
});

describe('what an apidoc block says reaches the document', () => {
    test('allowed values become the type and its enum, numbers as numbers', () => {
        // Arrange & Act — `{Number=0,1}` was a string defaulting to "0"
        const numbers = parser().convertTypeToOpenAPI('Number=0,1');
        const words   = parser().convertTypeToOpenAPI('String="day","week"');

        // Assert
        assert.deepEqual(numbers, { type: 'number', enum: [0, 1] });
        assert.deepEqual(words, { type: 'string', enum: ['day', 'week'] });
    });

    test('an error documented under its status is a response of that status, listed', () => {
        // Arrange — `(Error 403)` without a {Type} did not match at all
        const converter = parser();
        const endpoint  = converter.parseApiDocBlock(block([
            '@api {get} /things Things',
            '@apiError (Error 403) NoAccess You may not see them',
            '            for this organisation',
            '@apiError (Error 400) {String} BadFilter The filter is wrong',
        ]));

        // Act
        const responses = converter.buildOpenAPIPath(endpoint).get.responses;

        // Assert — documented statuses added, the usual three still there; continuation joined
        assert.equal(responses['403'].description, 'Forbidden\n\n- `NoAccess`: You may not see them for this organisation');
        assert.equal(responses['400'].description, 'Bad Request\n\n- `BadFilter`: The filter is wrong');
        assert.equal(responses['401'].description, 'Unauthorized');
        assert.equal(responses['500'].description, 'Internal Server Error');
    });

    test('every permission is kept, not only the last', () => {
        // Arrange & Act
        const endpoint = parser().parseApiDocBlock(block(['@api {get} /x X', '@apiPermission admin', '@apiPermission owner']));

        // Assert
        assert.equal(endpoint.permission, 'admin, owner');
    });

    test('a parameter\'s continuation lines lose their alignment', () => {
        // Arrange & Act
        const endpoint = parser().parseApiDocBlock(block([
            '@api {post} /x X',
            '@apiBody {String} name The name,',
            '                 as the person writes it',
        ]));

        // Assert
        assert.equal(endpoint.body[0].description, 'The name, as the person writes it');
    });

    test('a description keeps its paragraphs and its list items', () => {
        // Arrange & Act
        const endpoint = parser().parseApiDocBlock(block([
            '@api {get} /x X',
            '@apiDescription First line',
            'goes on.',
            '',
            'Second paragraph:',
            '- one',
            '- two',
        ]));

        // Assert
        assert.equal(endpoint.description.trim(), 'First line goes on.\n\nSecond paragraph:\n- one\n- two');
    });

    test('a sample body sends a list as a list, an enum\'s first value and a boolean as false', () => {
        // Arrange
        const converter = parser();
        const endpoint  = converter.parseApiDocBlock(block([
            '@api {post} /x X',
            '@apiBody {Number[]} ids The ids',
            '@apiBody {String[]} tags The tags',
            '@apiBody {Number=2,3} level The level',
            '@apiBody {Boolean} on Whether',
            '@apiBody {Array} raw Anything',
        ]));

        // Act — `{Array}` without items crashed here
        const python = converter.generateCodeSamples(endpoint).find((s) => s.lang === 'Python').source;

        // Assert
        assert.match(python, /'ids': \[\s*0\s*\]/);
        assert.match(python, /'tags': \[\s*'value'\s*\]/);
        assert.match(python, /'level': 2/);
        assert.match(python, /'on': false/);
        assert.match(python, /'raw': \[\s*0\s*\]/);
    });
});
