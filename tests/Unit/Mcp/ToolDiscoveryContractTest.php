<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Container;
use Pramnos\Application\Settings;
use Pramnos\Mcp\McpServer;
use Pramnos\Mcp\McpServiceProvider;
use Pramnos\Mcp\McpToolInterface;

/**
 * What a client is told about every tool before it calls one.
 *
 * `name()`, `description()` and `inputSchema()` are the whole of tool discovery: an MCP client reads
 * them once and builds its calls from the schema. Every existing test of these tools calls `execute()`
 * directly with an array it wrote itself — which is the useful thing to test and also means the
 * discovery half was never exercised. Two of the tools had all three methods at **zero hits**.
 *
 * The failure that hides is specific and total: a malformed schema is not a wrong answer, it is a tool
 * a client will not call. It disappears from the client's list, or the client sends a shape the tool
 * does not read, and nothing on the server logs anything — the request never arrives.
 *
 * ## Over every registered tool, not one at a time
 *
 * Because the contract belongs to the interface rather than to any tool, and a per-tool test is a test
 * the next tool does not have. This asks the service provider for the real server and holds every tool
 * on it to the same rules, so a tool added tomorrow is covered the day it is registered.
 */
#[CoversClass(McpServiceProvider::class)]
class ToolDiscoveryContractTest extends TestCase
{
    protected function setUp(): void
    {
        /*
         * The one setting the provider reads, and **not** `clearSettings()`.
         *
         * `clearSettings()` empties the store including everything loaded from
         * `app/settings/settings.php`, and nothing reloads that file inside a running process — so
         * calling it in a `setUp()` that runs sixty-nine times hands the rest of the suite an
         * installation with no settings, repeatedly. Measured: 2:42 → 3:15 for a class that runs in
         * one second.
         *
         * `setSetting(..., false)` writes the in-memory value only and leaves the rest standing.
         */
        Settings::setSetting('title', 'Discovery Contract', false);
    }

    /**
     * Every built-in tool, as a client would receive them.
     *
     * @return list<array{0: string, 1: McpToolInterface}>
     */
    public static function tools(): array
    {
        /*
         * Booted once for the whole class.
         *
         * A data provider runs per test method, and booting the provider reads the filesystem — four
         * methods plus the duplicate-name test meant five full boots for a list that cannot change
         * between them.
         */
        static $cases = null;

        if ($cases !== null) {
            return $cases;
        }

        Settings::setSetting('title', 'Discovery Contract', false);

        $application = new class extends Application {
            public function __construct()
            {
            }
        };

        // `getContainer()` creates one on demand and keeps it, which is the seam: there is no setter,
        // and there does not need to be — asking for it once is what the provider does too.
        $container = $application->getContainer();

        $provider = new McpServiceProvider($application);
        $provider->register();
        $provider->boot();

        /** @var McpServer $server */
        $server = $container->get('mcp.server');

        $cases = [];
        foreach ($server->getTools() as $tool) {
            $cases[$tool->name() !== '' ? $tool->name() : get_class($tool)] = [$tool->name(), $tool];
        }

        return $cases;
    }

    /**
     * Every tool has a name a client can address it by.
     *
     * The name is the wire identifier — a client sends `tools/call` with it and nothing else — so an
     * empty or duplicated one is not cosmetic. Lower case with hyphens because that is what the
     * registered set already uses, and a tool that broke the convention would be the odd one in a
     * list a person reads.
     *
     * @param string $name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tools')]
    public function testEveryToolHasAnAddressableName(string $name, McpToolInterface $tool): void
    {
        // Assert
        $this->assertNotSame('', $name, get_class($tool) . ' has no name');
        $this->assertMatchesRegularExpression(
            '/^[a-z][a-z0-9-]*$/',
            $name,
            $name . ' is not a wire-safe tool name'
        );
    }

    /**
     * Every tool describes itself in a sentence a client can act on.
     *
     * The description is the only thing that decides *whether* a tool is used: a client chooses
     * between fifteen of them by reading these and nothing else. So an empty one is a tool nobody
     * calls, and a one-word one is a tool called for the wrong thing — which is worse, because the
     * answer looks like an answer.
     *
     * @param string $name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tools')]
    public function testEveryToolDescribesWhatItIsFor(string $name, McpToolInterface $tool): void
    {
        // Act
        $description = $tool->description();

        // Assert
        $this->assertNotSame('', trim($description), $name . ' does not say what it is for');
        $this->assertGreaterThan(
            30,
            strlen($description),
            $name . ' describes itself too briefly for a client to choose it'
        );
    }

    /**
     * Every tool's input schema is a JSON Schema object a client can build a call from.
     *
     * This is the assertion with teeth, and it is why the class exists. A schema that is not
     * `{"type": "object", "properties": {…}}` is not a wrong answer — it is a tool the client will not
     * call: it vanishes from the list, or the client sends a shape the tool does not read, and nothing
     * on the server logs anything because the request never arrives.
     *
     * Each property is checked to have a `type` and a `description` too. The type is what the client
     * validates against; the description is what tells it what to put there, and a parameter with no
     * description is a parameter that gets guessed.
     *
     * @param string $name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tools')]
    public function testEveryToolsSchemaIsUsable(string $name, McpToolInterface $tool): void
    {
        // Act
        $schema = $tool->inputSchema();

        // Assert
        $this->assertSame('object', $schema['type'] ?? null, $name . ': the schema is not an object');
        $this->assertArrayHasKey('properties', $schema, $name . ': the schema has no properties key');
        $this->assertIsArray($schema['properties']);

        foreach ($schema['properties'] as $property => $definition) {
            $this->assertIsArray($definition, $name . '.' . $property . ' is not a definition');
            $this->assertArrayHasKey(
                'type',
                $definition,
                $name . '.' . $property . ' has no type, so a client cannot validate it'
            );
            $this->assertNotSame(
                '',
                trim((string) ($definition['description'] ?? '')),
                $name . '.' . $property . ' has no description, so it gets guessed'
            );
        }

        // …and it survives the round trip it actually makes, which is JSON over a transport.
        $encoded = json_encode($schema);
        $this->assertIsString($encoded, $name . ': the schema cannot be encoded as JSON');
        $this->assertSame(
            $schema,
            json_decode($encoded, true),
            $name . ': the schema does not survive JSON encoding unchanged'
        );
    }

    /**
     * A `required` list names properties that exist.
     *
     * A schema requiring a parameter it does not define is one a strict client rejects outright and a
     * lenient one sends nothing for — so the tool is either uncallable or called without the thing it
     * said it needed. Both are silent.
     *
     * @param string $name
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tools')]
    public function testARequiredPropertyIsOneThatExists(string $name, McpToolInterface $tool): void
    {
        // Act
        $schema = $tool->inputSchema();
        $required = $schema['required'] ?? [];

        // Assert
        $this->assertIsArray($required, $name . ': required is not a list');

        foreach ($required as $property) {
            $this->assertArrayHasKey(
                (string) $property,
                (array) ($schema['properties'] ?? []),
                $name . ' requires `' . $property . '`, which it does not define'
            );
        }
    }

    /**
     * No two tools answer to the same name.
     *
     * The server keys them by name, so a duplicate does not collide loudly — the second registration
     * replaces the first, and a tool the provider registered simply is not there. Which is exactly the
     * kind of thing that happens when a tool is copied to make another.
     */
    public function testNoTwoToolsShareAName(): void
    {
        // Act
        $names = array_map(
            static fn (array $case): string => $case[0],
            array_values(self::tools())
        );

        // Assert
        $this->assertSame(
            count($names),
            count(array_unique($names)),
            'two tools answer to one name, so one of them is unreachable'
        );
        $this->assertGreaterThan(5, count($names), 'the provider registered almost nothing');
    }
}
