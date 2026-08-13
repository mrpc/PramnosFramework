<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Routing\OpenApiClientGenerator;

/**
 * Endpoint functions generated from an OpenAPI document.
 *
 * The gap: screens hand-write path strings and field names while the document in the
 * same repository knows both, so a rename in the backend is found in the browser one
 * screen at a time. What the generator has to get right is narrower than "it emits
 * code", and each of these has a way of being quietly wrong:
 *
 *   - **stable output**, or a regeneration produces a diff nobody can review;
 *   - **paths built from parameters**, encoded, rather than interpolated raw;
 *   - **blank query values omitted**, because `?status=` and no filter differ;
 *   - **`any` where the document is silent**, because a type that is confidently
 *     wrong is worse than one that admits it does not know;
 *   - **valid JavaScript**, since nothing else will notice before a build does.
 */
#[CoversClass(OpenApiClientGenerator::class)]
class OpenApiClientGeneratorTest extends TestCase
{
    /**
     * A document in the shape this framework's generator writes.
     *
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'openapi' => '3.0.3',
            'info'    => ['title' => 'Acme API'],
            'components' => [
                'schemas' => [
                    'Thing' => [
                        'type'     => 'object',
                        'required' => ['id'],
                        'properties' => [
                            'id'    => ['type' => 'integer'],
                            'label' => ['type' => 'string'],
                            'state' => ['type' => 'string', 'enum' => ['draft', 'live']],
                        ],
                    ],
                ],
            ],
            'paths' => [
                '/things' => [
                    'get' => [
                        'operationId' => 'listThings',
                        'summary'     => 'Every thing',
                        'parameters'  => [
                            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                            ['name' => 'search', 'in' => 'query', 'schema' => ['type' => 'string'],
                             'description' => 'Free text'],
                        ],
                        'responses' => [
                            '200' => ['content' => ['application/json' => ['schema' => [
                                'type' => 'array', 'items' => ['$ref' => '#/components/schemas/Thing'],
                            ]]]],
                        ],
                    ],
                    'post' => [
                        'operationId' => 'createThing',
                        'requestBody' => ['content' => ['application/json' => ['schema' => [
                            '$ref' => '#/components/schemas/Thing',
                        ]]]],
                        'responses' => [
                            '201' => ['content' => ['application/json' => ['schema' => [
                                '$ref' => '#/components/schemas/Thing',
                            ]]]],
                        ],
                    ],
                ],
                '/things/{id}' => [
                    'parameters' => [
                        ['name' => 'id', 'in' => 'path', 'required' => true,
                         'schema' => ['type' => 'integer'], 'description' => 'Which thing'],
                    ],
                    'get'    => ['operationId' => 'readThing', 'responses' => ['200' => ['content' => [
                        'application/json' => ['schema' => ['$ref' => '#/components/schemas/Thing']],
                    ]]]],
                    'delete' => ['operationId' => 'deleteThing', 'responses' => ['204' => []]],
                ],
                // The generator that writes these documents produced a doubled slash
                // for every apidoc-derived path until recently.
                '//status' => ['get' => ['operationId' => 'getStatus', 'responses' => []]],
                '/internal' => ['head' => ['operationId' => 'peek']],
            ],
        ];
    }

    /**
     * Every callable operation is found, and only those.
     *
     * HEAD is not a method the client offers — there is nothing to do with the answer
     * that `get` does not already do.
     */
    public function testItFindsEveryCallableOperation(): void
    {
        // Arrange & Act
        $names = array_column((new OpenApiClientGenerator($this->document()))->operations(), 'name');

        // Assert
        $this->assertSame(
            ['createThing', 'deleteThing', 'getStatus', 'listThings', 'readThing'],
            $names,
            'sorted by name, so a regeneration is reviewable'
        );
    }

    /**
     * The doubled slash a document can carry is normalised.
     *
     * `//status` is not the same path as `/status`, and a client that sends it
     * verbatim gets a 404 that reads as a routing bug in the application.
     */
    public function testADoubledSlashIsNormalised(): void
    {
        // Arrange & Act
        $js = (new OpenApiClientGenerator($this->document()))->javaScript();

        // Assert
        $this->assertStringContainsString("api.get('/status')", $js);
        $this->assertStringNotContainsString('//status', $js);
    }

    /**
     * A path parameter becomes an argument, encoded into the URL.
     *
     * Interpolating it raw is how a label containing a slash turns one request into
     * another.
     */
    public function testAPathParameterBecomesAnEncodedArgument(): void
    {
        // Arrange & Act
        $js = (new OpenApiClientGenerator($this->document()))->javaScript();

        // Assert
        $this->assertStringContainsString('export function readThing(id) {', $js);
        $this->assertStringContainsString('`/things/${encodeURIComponent(id)}`', $js);
        // And the document's own description reaches the editor
        $this->assertStringContainsString('@param {number} id Which thing', $js);
    }

    /**
     * Query parameters arrive as one optional object, built by a helper that omits
     * blanks.
     */
    public function testQueryParametersBecomeAnOptionalObject(): void
    {
        // Arrange & Act
        $js = (new OpenApiClientGenerator($this->document()))->javaScript();

        // Assert
        $this->assertStringContainsString('export function listThings(query = {}) {', $js);
        $this->assertStringContainsString('${toQuery(query)}', $js);
        // The helper is emitted, and it leaves out anything blank
        $this->assertStringContainsString('function toQuery(params)', $js);
        $this->assertStringContainsString("value !== ''", $js);
    }

    /**
     * A body-taking operation takes a body argument, typed from the document.
     */
    public function testABodyTakingOperationTakesATypedBody(): void
    {
        // Arrange & Act
        $js = (new OpenApiClientGenerator($this->document()))->javaScript();

        // Assert
        $this->assertStringContainsString('export function createThing(body) {', $js);
        $this->assertStringContainsString('api.post(\'/things\', body)', $js);
        $this->assertStringContainsString('@param {Thing} body', $js);
    }

    /**
     * A POST with no documented body takes no body argument, and passes none.
     *
     * This shipped as a defect and was found by generating against a real document:
     * the call emitted `api.post(path, body)` while the signature took nothing, which
     * is valid JavaScript — so `node --check` passed — and a ReferenceError the first
     * time anybody called it. Every POST in the fixture happened to have a body.
     */
    public function testAPostWithNoDocumentedBodyPassesNone(): void
    {
        // Arrange
        $generator = new OpenApiClientGenerator([
            'paths' => ['/capabilities/sync' => ['post' => [
                'operationId' => 'syncCapabilities',
                'responses'   => ['200' => []],
            ]]],
        ]);

        // Act
        $js = $generator->javaScript();

        // Assert — no argument, and none passed
        $this->assertStringContainsString('export function syncCapabilities() {', $js);
        $this->assertStringContainsString("api.post('/capabilities/sync')", $js);
        $this->assertStringNotContainsString(', body)', $js);
    }

    /**
     * The declarations name the document's schemas, with optionality and enums.
     */
    public function testTheDeclarationsCarryTheDocumentsSchemas(): void
    {
        // Arrange & Act
        $types = (new OpenApiClientGenerator($this->document()))->declarations();

        // Assert — a required field, an optional one, and an enum as a union
        $this->assertStringContainsString('export interface Thing {', $types);
        $this->assertStringContainsString('id: number;', $types);
        $this->assertStringContainsString('label?: string;', $types);
        $this->assertStringContainsString("state?: 'draft' | 'live';", $types);
        // …and one signature per operation
        $this->assertStringContainsString('export function readThing(id: number): Promise<Thing>;', $types);
        $this->assertStringContainsString('export function listThings(query?:', $types);
    }

    /**
     * A 204 is typed as returning nothing, because that is what the client returns.
     */
    public function testA204IsTypedAsNull(): void
    {
        // Arrange & Act
        $types = (new OpenApiClientGenerator($this->document()))->declarations();

        // Assert
        $this->assertStringContainsString('deleteThing(id: number): Promise<null>;', $types);
    }

    /**
     * Where the document says nothing, the type is `any`.
     *
     * A generated type that is confidently wrong is worse than one that admits it
     * does not know: the first is trusted.
     */
    public function testAnUndocumentedResponseIsAny(): void
    {
        // Arrange & Act
        $types = (new OpenApiClientGenerator($this->document()))->declarations();

        // Assert
        $this->assertStringContainsString('getStatus(): Promise<any>;', $types);
    }

    /**
     * A document with no `operationId` still produces usable names.
     *
     * Not every document has them — the framework's own generator emits them from
     * `@apiName`, but an overrides file need not.
     */
    public function testNamesAreDerivedWhenTheDocumentHasNoOperationIds(): void
    {
        // Arrange
        $generator = new OpenApiClientGenerator([
            'paths' => ['/session/info' => ['get' => ['summary' => 'Session']]],
        ]);

        // Act
        $names = array_column($generator->operations(), 'name');

        // Assert
        $this->assertSame(['getSessionInfo'], $names);
    }

    /**
     * The output is valid JavaScript.
     *
     * Nothing else notices before a build does, and a generated file that cannot
     * parse turns one mistake into a broken project.
     */
    public function testTheGeneratedModuleParses(): void
    {
        // Arrange
        $js   = (new OpenApiClientGenerator($this->document()))->javaScript();
        $file = tempnam(sys_get_temp_dir(), 'endpoints') . '.mjs';
        file_put_contents($file, $js);

        // Act — node's own parser is the authority here
        $exit = 0;
        $out  = [];
        exec('node --check ' . escapeshellarg($file) . ' 2>&1', $out, $exit);
        @unlink($file);

        // Assert
        $this->assertSame(0, $exit, implode("\n", $out));
    }

    /**
     * The same document produces the same file, byte for byte.
     *
     * A generated file whose content depends on key order cannot be committed: every
     * regeneration is a diff, and a real change hides among them.
     */
    public function testGenerationIsDeterministic(): void
    {
        // Arrange
        $document = $this->document();

        // Act
        $first  = (new OpenApiClientGenerator($document))->javaScript();
        $second = (new OpenApiClientGenerator($document))->javaScript();

        // Assert
        $this->assertSame($first, $second);
    }

    /**
     * An empty document is reported rather than producing an empty module that looks
     * finished.
     */
    public function testADocumentWithNoPathsSaysSo(): void
    {
        // Arrange & Act
        $js = (new OpenApiClientGenerator(['info' => ['title' => 'Nothing']]))->javaScript();

        // Assert
        $this->assertStringContainsString('no operations', $js);
        $this->assertStringNotContainsString('export function', $js);
    }

    /**
     * A document of the wrong shape produces nothing rather than throwing.
     *
     * The document is a file on disk that something else wrote. A generator that dies
     * on a malformed one turns a bad document into a broken command, and the reader
     * then debugs the wrong thing.
     */
    public function testAMalformedDocumentIsSurvived(): void
    {
        // Arrange — paths is not a map, one item is a string, and a parameter is junk
        $generator = new OpenApiClientGenerator([
            'paths' => [
                '/ok'      => 'not-a-path-item',
                '/partial' => [
                    'parameters' => ['not-a-parameter', ['in' => 'query']],
                    'get' => [
                        'operationId' => 'partial',
                        'parameters'  => [['name' => 'apiKey', 'in' => 'header']],
                        'responses'   => [],
                    ],
                ],
            ],
            'components' => ['schemas' => 'not-a-map'],
        ]);

        // Act
        $js = $generator->javaScript();

        // Assert — the usable operation survives, and takes no arguments: the header
        // parameter is not asked about (lib/api.js sets the ones this framework uses)
        // and neither is the junk one.
        $this->assertStringContainsString('export function partial() {', $js);
        $this->assertStringNotContainsString('not-a-parameter', $js);
        // The header parameter would have shown up in the JSDoc had it been taken
        $this->assertStringNotContainsString('@param', $js);
    }

    /**
     * The types the generator declines to guess about.
     *
     * `oneOf`, an object with no declared properties, and a schema with no type at
     * all. Each becomes something honest rather than something invented.
     */
    public function testUnknownShapesBecomeHonestTypes(): void
    {
        // Arrange
        $generator = new OpenApiClientGenerator([
            'components' => ['schemas' => [
                'Mixed' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]],
                'Bag'   => ['type' => 'object'],
                'Flags' => ['type' => 'object', 'properties' => [
                    'enabled' => ['type' => 'boolean'],
                    'loose'   => ['type' => 'object'],
                    'unknown' => ['description' => 'no type at all'],
                ]],
            ]],
            'paths' => [],
        ]);

        // Act
        $types = $generator->declarations();

        // Assert
        $this->assertStringContainsString('export interface Mixed any', $types);
        $this->assertStringContainsString("export interface Bag {\n    [key: string]: any;\n}", $types);
        $this->assertStringContainsString('enabled?: boolean;', $types);
        $this->assertStringContainsString('loose?: Record<string, any>;', $types);
        $this->assertStringContainsString('unknown?: any;', $types);
    }

    /**
     * A name with nothing usable in it still produces a callable identifier.
     *
     * An `operationId` of "///" or "42" is not a JavaScript name, and emitting one
     * would break the whole module rather than one function.
     */
    public function testAnUnusableNameFallsBackToSomethingCallable(): void
    {
        // Arrange
        $generator = new OpenApiClientGenerator([
            'paths' => [
                '/a' => ['get' => ['operationId' => '///', 'responses' => []]],
                '/b' => ['get' => ['operationId' => 'GET_ALL', 'responses' => []]],
            ],
        ]);

        // Act
        $names = array_column($generator->operations(), 'name');

        // Assert — a fallback, and an all-capitals id read as words
        $this->assertContains('call', $names);
        $this->assertContains('getAll', $names);
    }

    /**
     * A field name that is not a bare identifier is quoted.
     *
     * `columns[0][data]` is a real field name in this framework's datatable payloads.
     */
    public function testAnAwkwardFieldNameIsQuoted(): void
    {
        // Arrange
        $generator = new OpenApiClientGenerator([
            'components' => ['schemas' => ['Payload' => [
                'type' => 'object',
                'properties' => ['columns[0][data]' => ['type' => 'string']],
            ]]],
            'paths' => [],
        ]);

        // Act
        $types = $generator->declarations();

        // Assert
        $this->assertStringContainsString("'columns[0][data]'?: string;", $types);
    }
}
