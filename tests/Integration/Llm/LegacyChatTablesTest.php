<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Framework\Migrations\Llm\MakeOrganizationIdNullableOnChatTables;
use Pramnos\Health\Checks\LlmTablesCheck;
use Pramnos\Llm\Analyst\Analyst;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * An application that had its own conversation tables first, with `organization_id NOT NULL`,
 * on MySQL and PostgreSQL.
 *
 * The framework's migrations leave a table that exists, so the analyst's first question — a row
 * outside any organisation — failed to insert and `/admin/Ai` answered 500. The health check
 * says so before anybody asks, and the migration makes the column take a null.
 */
#[CoversClass(MakeOrganizationIdNullableOnChatTables::class)]
#[CoversClass(LlmTablesCheck::class)]
class LegacyChatTablesTest extends LlmTestCase
{
    use ChatDatabase;

    private ?array $features = null;

    protected function setUp(): void
    {
        parent::setUp();
        $enabled        = new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled');
        $this->features = (array) $enabled->getValue();
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['llm']);
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, $this->features);
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /**
     * Degraded while the application's tables refuse a null organisation, ok once the migration
     * has run — and then the analyst's question is written.
     */
    #[DataProvider('databases')]
    public function testAnApplicationsOwnTablesAreMadeToTakeANullOrganisation(string $type, string $host, int $port): void
    {
        // Arrange — the application's tables: organization_id NOT NULL, as GlideDay's were
        $this->connectDatabase($type, $host, $port);
        $this->llmSchemaChanged();
        // What an application's settings give a PostgreSQL connection; the test's has none.
        $this->db->schema = $type === 'postgresql' ? 'public' : '';
        $schema = $this->db->schema();
        foreach (MakeOrganizationIdNullableOnChatTables::TABLES as $table) {
            $schema->alterTable($table, function ($t) {
                $t->modifyColumn('organization_id', 'integer')->nullable(false);
            });
        }

        // Act
        $before = (new LlmTablesCheck())->run();
        $this->migration(MakeOrganizationIdNullableOnChatTables::class)->up();
        $this->migration(MakeOrganizationIdNullableOnChatTables::class)->up();
        $after  = (new LlmTablesCheck())->run();
        $askId  = (new \Pramnos\Llm\Chat\Asks(Analyst::conversations(7), new RecordingQueue()))->start(Analyst::scope(7), null, 'How many accounts?');

        // Assert
        $this->assertSame('degraded', $before->status->value);
        $this->assertContains('chat_asks.organization_id: NOT NULL', $before->details['problems']);
        $this->assertStringContainsString('migrate', $before->message);
        $this->assertSame('ok', $after->status->value, 'run twice, the second a no-op');
        $this->assertGreaterThan(0, $askId, 'a row outside any organisation is written');
        $this->assertTrue($schema->columnDetails('chat_asks')['organization_id']['nullable']);
    }

    /** A missing table, a missing `space`, and the feature off, as the check reports them. */
    #[DataProvider('databases')]
    public function testWhatTheCheckReports(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $this->llmSchemaChanged();
        // What an application's settings give a PostgreSQL connection; the test's has none.
        $this->db->schema = $type === 'postgresql' ? 'public' : '';
        $schema = $this->db->schema();
        $schema->alterTable('chat_memory', function ($t) {
            $t->dropColumn('space');
        });
        $schema->dropTableIfExists('pramnos.ai_routines');
        // And no run history yet
        $schema->dropTableIfExists('pramnos.ai_task_runs');

        // Act
        $found = (new LlmTablesCheck())->run();
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, ['auth' => true]);
        $off   = (new LlmTablesCheck())->run();

        // Assert
        $this->assertSame(['chat_memory.space: missing', 'pramnos.ai_routines: missing', 'pramnos.ai_task_runs: missing'], $found->details['problems']);

        // A routines table of the older shape: the columns the code reads since are named
        (new \ReflectionProperty(\Pramnos\Application\FeatureRegistry::class, 'enabled'))->setValue(null, ['llm' => true]);
        $app = $this->getMockBuilder(\Pramnos\Application\Application::class)->disableOriginalConstructor()->getMock();
        $app->database = $this->db;
        (new \Pramnos\Framework\Migrations\Llm\CreateAiRoutinesTable($app))->up();
        (new \Pramnos\Framework\Migrations\Llm\AddSpaceToChatTables($app))->up();
        $older = (new LlmTablesCheck())->run();
        $this->assertContains('pramnos.ai_routines.kind: missing', $older->details['problems']);
        $this->assertContains('pramnos.ai_routines.locked_until: missing', $older->details['problems']);
        $this->assertSame(['ok', 'The llm feature is off'], [$off->status->value, $off->message]);
        $this->assertSame('llm_tables', $off->name);
    }
}
