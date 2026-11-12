<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Llm\Figures;

/**
 * An answer's numbers against what its numeric tools returned: what follows from the figures
 * passes, what does not is named — the check that keeps a reporting answer from inventing one.
 */
#[CoversClass(Figures::class)]
class FiguresTest extends TestCase
{
    /** The report behind the failure this exists for: renewals and checkouts by reason. */
    private const REPORT = '{"renewals":[75,8,1],"checkouts":[75,29,2,1]}';

    /**
     * The report's own case: 41 is no value, total, sum or difference of it, so it is named; the
     * totals the report adds up to — and their sum — are borne out.
     */
    public function testANumberNoFigureMakesIsNamed(): void
    {
        // Arrange
        $invented = '41 attempts — 40 network and 1 card. 41 if we count only the card.';
        $honest   = '84 renewals and 107 checkouts failed, 191 in all; 75 of the renewals were the card.';

        // Act
        $named = Figures::untraced($invented, [self::REPORT]);
        $none  = Figures::untraced($honest, [self::REPORT]);

        // Assert
        $this->assertSame(['41', '40'], $named, '1 is in the report; 41 and 40 are not, each named once');
        $this->assertSame([], $none);
    }

    /**
     * Written as people write them: Greek and English separators, decimals to their precision, a
     * percentage as a share or a change, a round hundred approximately; not dates, times, years,
     * a list's numbering or what the question said.
     */
    public function testNumbersAsPeopleWriteThem(): void
    {
        // Arrange — rows: a column's total is a figure too
        $rows   = '[{"day":"2026-11-01","revenue":1234.56,"orders":75},{"day":"2026-11-02","revenue":1000,"orders":25}]';
        $answer = "1. Revenue was 2.234,56 € (2,234.56), 1.234,6 on the 1st, about 1,200.\n"
            . "2. Orders: 100, 75% on 1/11 at 09:30; down 66.7% the next day, in 2026. Top 5 as asked.";

        // Act
        $untraced = Figures::untraced($answer, [$rows], 'the top 5 days');
        $off      = Figures::untraced('Revenue 1.234,7 and about 1.800.', [$rows]);
        $text     = Figures::untraced('12 open, 3 late, 15 in all, 9 more.', ['open: 12, late: 3']);

        // Assert
        $this->assertSame([], $untraced, 'every one of them follows from the rows');
        $this->assertSame(['1.234,7', '1.800'], $off, 'a decimal written is held to its precision; 1,800 is not about anything here');
        $this->assertSame([], $text, 'a text result read by its numbers: 15 their sum, 9 their difference');
    }

    /**
     * Where the flag comes from: an MCP tool that says `numeric()`, through `Tools::fromMcp()` and
     * `ToolRegistry::registerMcp()`, and a tool registered with `'numeric' => true` — each handed
     * to the client marked; a tool that says nothing is not.
     */
    public function testTheToolSaysItAnswersWithFigures(): void
    {
        // Arrange
        $report = new class implements \Pramnos\Mcp\McpToolInterface {
            public function name(): string { return 'report'; }
            public function description(): string { return ''; }
            public function inputSchema(): array { return []; }
            public function execute(array $input): mixed { return [1, 2]; }
            /** It answers with figures. */
            public function numeric(): bool { return true; }
        };
        $plain = new class implements \Pramnos\Mcp\McpToolInterface {
            public function name(): string { return 'plain'; }
            public function description(): string { return ''; }
            public function inputSchema(): array { return []; }
            public function execute(array $input): mixed { return 'x'; }
        };

        // Act
        $mcp      = \Pramnos\Llm\Tools::fromMcp([$report, $plain]);
        $registry = (new \Pramnos\Llm\ToolRegistry())->registerMcp($report)->registerMcp($plain)
            ->register('count', static fn (): int => 3, ['numeric' => true])->tools();

        // Assert
        $this->assertTrue($mcp['report']['numeric']);
        $this->assertArrayNotHasKey('numeric', $mcp['plain']);
        $this->assertTrue($registry['report']['numeric']);
        $this->assertTrue($registry['count']['numeric']);
        $this->assertArrayNotHasKey('numeric', $registry['plain']);
    }
}
