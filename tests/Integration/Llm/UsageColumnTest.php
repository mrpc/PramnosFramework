<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Pramnos\Database\Grammar\Grammar;
use Pramnos\Tests\Unit\Llm\LlmTestCase;

/**
 * `llm_calls.usage` — a reserved word on MySQL — read through the query builder on both
 * databases: selected bare and qualified, filtered and sorted on.
 */
#[CoversClass(Grammar::class)]
class UsageColumnTest extends LlmTestCase
{
    use ChatDatabase;

    protected function tearDown(): void
    {
        $this->disconnectDatabase();
        parent::tearDown();
    }

    /** The column the call log keeps a provider's usage in can be queried by name. */
    #[DataProvider('databases')]
    public function testTheUsageColumnCanBeRead(string $type, string $host, int $port): void
    {
        // Arrange
        $this->connectDatabase($type, $host, $port);
        $qb = fn () => $this->db->queryBuilder()->table('llm_calls');
        foreach (['{"total_tokens":15}', '{"total_tokens":9}'] as $usage) {
            $qb()->insert(['purpose' => 'chat', 'provider' => 'openai', 'model' => 'm', 'usage' => $usage, 'created_at' => \Pramnos\Llm\CallLog::instant(time())]);
        }

        // Act
        $bare      = $qb()->select(['usage'])->where('usage', '{"total_tokens":15}')->first();
        $qualified = $this->db->queryBuilder()->table('llm_calls AS c')->select(['c.usage AS u'])->orderBy('c.usage')->get()->fetchAll();

        // Assert
        $this->assertSame('{"total_tokens":15}', $bare->fields['usage']);
        $this->assertSame(['{"total_tokens":15}', '{"total_tokens":9}'], array_column($qualified, 'u'));
    }
}
