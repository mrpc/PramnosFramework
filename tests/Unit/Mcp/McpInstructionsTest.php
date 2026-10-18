<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Llm\Analyst\AnalystAssistant;
use Pramnos\Mcp\Controllers\McpController;
use Pramnos\Mcp\McpServer;

/** An application's analyst, as one would write it: what the application is, and which tool answers what. */
final class InstructedAnalyst extends AnalystAssistant
{
    /** What the application is. */
    protected function about(): string
    {
        return 'A booking service for clinics; a visit is an appointment.';
    }

    /** Which tool answers what. */
    protected function toolGuidance(): string
    {
        return 'Visits and their counts: visits_report.';
    }
}

/**
 * The MCP `initialize` answer carries `instructions` (FW-161).
 *
 * MCP 2025-03-26 has the server tell the client's model what it is and how to use it, and
 * clients pass that on. Without it, everything the application's analyst knows — what the
 * application is, which tool answers what — was lost over MCP: asked about an application's
 * visits, a model wrote SQL against the schema instead of calling the tool made for visits. The
 * endpoint now sends what the analyst would know, so Ask and MCP answer alike.
 */
#[CoversClass(McpServer::class)]
#[CoversClass(AnalystAssistant::class)]
#[CoversClass(McpController::class)]
class McpInstructionsTest extends TestCase
{
    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Application::class);
        $ref->getProperty('appInstances')->setValue(null, []);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
    }

    /** `initialize`'s result, from a server. */
    private static function initialize(McpServer $server): array
    {
        return $server->dispatch(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])['result'];
    }

    /** Instructions set are sent with `initialize`; none set, the field is absent rather than empty. */
    public function testInitializeCarriesTheInstructions(): void
    {
        // Act
        $with    = self::initialize((new McpServer('App'))->setInstructions("  Use visits_report.  "));
        $without = self::initialize(new McpServer('App'));

        // Assert
        $this->assertSame('Use visits_report.', $with['instructions']);
        $this->assertArrayNotHasKey('instructions', $without);
    }

    /**
     * The framework's analyst says what holds anywhere: its clock, to prefer a tool made for the
     * question over schema and sql, its rule about people, that masked values stay masked. An
     * application's analyst adds what it is and replaces the tool line with its own.
     */
    public function testTheAnalystSaysWhatItKnows(): void
    {
        // Act
        $framework   = (new AnalystAssistant())->mcpInstructions();
        $application = (new InstructedAnalyst())->mcpInstructions();

        // Assert
        $this->assertStringContainsString('Dates and days are in ', $framework);
        $this->assertStringContainsString('schema and sql are the last resort', $framework);
        $this->assertStringContainsString('Use figures, not people', $framework);
        $this->assertStringContainsString('never try to recover them', $framework);
        $this->assertStringNotContainsString('About this application', $framework, 'nothing to say about an application that said nothing');
        $this->assertStringStartsWith('About this application: A booking service for clinics', $application);
        $this->assertStringContainsString('Visits and their counts: visits_report.', $application);
        $this->assertStringNotContainsString('last resort', $application, 'its own guidance in place of the framework line');
    }

    /** The endpoint's server takes its instructions from the analyst the application configured. */
    public function testTheEndpointSendsTheConfiguredAnalystsInstructions(): void
    {
        // Arrange — an application whose analyst is InstructedAnalyst
        $ref = new \ReflectionClass(Application::class);
        $ref->getProperty('appInstances')->setValue(null, []);
        $ref->getProperty('lastUsedApplication')->setValue(null, null);
        $app = new Application();
        $ref->getProperty('initialized')->setValue($app, true);
        $app->applicationInfo['llm'] = ['analyst' => ['assistant' => InstructedAnalyst::class]];
        $controller = new class (null) extends McpController {
            public function __construct($a)
            {
            }

            /** The server, out where a test can reach it. */
            public function serverForTest(): McpServer
            {
                return $this->server([]);
            }
        };

        // Act
        $result = self::initialize($controller->serverForTest());

        // Assert
        $this->assertStringContainsString('A booking service for clinics', $result['instructions'] ?? '');
    }
}
