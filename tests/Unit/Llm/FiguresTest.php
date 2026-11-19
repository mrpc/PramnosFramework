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
     * A markdown table — one `\n` per row, never two, never a `\n` followed by a space —
     * reported in production as one giant "sentence" under the old splitter: `(?<=[.!?\n])\s+`
     * needs a whitespace character AFTER the boundary it matches, and a single bare `\n`
     * straight into the next line's first letter has none, so nothing ever split there. The
     * header row's own words ("Περιοχή", "Κατάσταση") then rode along into whatever data row's
     * traced number gated the check, and were flagged as fabricated names. A bare `\n` must be
     * its own boundary.
     */
    public function testAMarkdownTableRowIsItsOwnSentenceNotOneBlobWithTheHeader(): void
    {
        // Arrange — header row's words are real column labels, not area names
        $results = ['{"locationid":5,"requests":41}', '{"areas":["Βέροια"]}'];
        $table   = "| Περιοχή | Κατάσταση |\n| Βέροια | 41 |";

        // Act
        $untraced = Figures::untraced($table, $results);

        // Assert — "Περιοχή" and "Κατάσταση" are on a DIFFERENT line than the traced 41,
        // so the gate never reaches them once the table's rows are split correctly
        $this->assertSame([], $untraced);
    }

    /**
     * The failure this exists for: a count that genuinely traces (41), sitting beside a name a
     * failed join never fetched, so the model invented one. The area name DID come back for
     * other rows — the results carry "Βέροια" and "Λάρισα" as plain strings — just not for this
     * one, and "Αθήνα" is nowhere among them.
     */
    public function testATracedCountBesideAnInventedNameIsNamedToo(): void
    {
        // Arrange
        $results = ['{"locationid":5,"requests":41}', '{"areas":["Βέροια","Λάρισα"]}'];

        // Act
        $untraced = Figures::untraced('Στην Αθήνα καταγράφηκαν 41 αιτήματα.', $results);

        // Assert — 41 itself traces (it is the "requests" value); "Αθήνα" does not
        $this->assertSame(['Αθήνα'], $untraced);
    }

    /**
     * The real area name, written in a different grammatical case than the results hold it in
     * ("Βεροίας", genitive, against "Βέροια" in the results) — Greek declension, not a different
     * place. Must not be flagged: a correct answer should never trigger a "figures" correction.
     */
    public function testACorrectNameInADifferentGrammaticalCaseIsNotFlagged(): void
    {
        // Arrange
        $results = ['{"locationid":5,"requests":41}', '{"areas":["Βέροια","Λάρισα"]}'];

        // Act
        $untraced = Figures::untraced('Στης Βεροίας καταγράφηκαν 41 αιτήματα.', $results);

        // Assert
        $this->assertSame([], $untraced);
    }

    /**
     * A sentence's own opening capital is not a name — "Overall, …" style prose beside a number
     * that happens to trace must not be flagged just because nothing in the results matches an
     * ordinary narrative word.
     */
    public function testASentencesOwnOpeningWordIsNotTakenForAName(): void
    {
        // Arrange — "Overall" opens the sentence; nothing here claims it is a place or a person
        $results = ['{"locationid":5,"requests":41}', '{"areas":["Βέροια"]}'];

        // Act
        $untraced = Figures::untraced('Overall, 41 requests were logged.', $results);

        // Assert
        $this->assertSame([], $untraced);
    }

    /**
     * No string anywhere in the results — a tool that answers with pure numbers, the ordinary
     * case this check is gated to stay out of. A capitalized word in the prose is never checked
     * against nothing: only the number itself is, same as before this existed.
     */
    public function testNoNameIsCheckedWhenTheResultsCarryNoStringAtAll(): void
    {
        // Act — same report as the file's own fixture; "Αθήνα" here names nothing a tool returned
        $untraced = Figures::untraced('84 renewals and 107 checkouts failed in Αθήνα, 191 in all.', [self::REPORT]);

        // Assert
        $this->assertSame([], $untraced);
    }

    /**
     * A sentence with no traced number in it never has its names checked at all — the gate
     * this whole check sits behind. "Αθήνα" here is in neither the results nor the question,
     * but its OWN sentence never claims a figure, so it is left alone; only the second
     * sentence, which does trace a number, gets its name checked.
     */
    public function testASentenceWithNoTracedNumberNeverChecksItsNames(): void
    {
        // Arrange
        $results = ['{"locationid":5,"requests":41}', '{"areas":["Λάρισα"]}'];

        // Act
        $untraced = Figures::untraced(
            'Η Αθήνα λειτουργεί κανονικά. Στη Λάρισα καταγράφηκαν 41 αιτήματα.',
            $results
        );

        // Assert — "Λάρισα" is a known string so it passes too; nothing is named
        $this->assertSame([], $untraced);
    }

    /**
     * A year does not count as a traced number for this gate either — same rule the plain
     * number check already follows. A sentence whose only number is a year never gates a
     * name check on it, even where the name is otherwise unverifiable.
     */
    public function testAYearAloneNeverGatesTheNameCheck(): void
    {
        // Arrange
        $results = ['{"locationid":5,"requests":41}', '{"areas":["Λάρισα"]}'];

        // Act — "Αθήνα" sits beside only a year, 2026, which traces nothing
        $untraced = Figures::untraced('Το 2026 στην Αθήνα δεν καταγράφηκαν αιτήματα.', $results);

        // Assert — the sentence traces no number, so "Αθήνα" is never checked
        $this->assertSame([], $untraced);
    }

    /**
     * A name the question itself named is not a hallucination for repeating it back — even
     * though it is not one of the results' own strings either (the gate below only cares
     * whether there is a string worth comparing against at all, not whether this one matches).
     */
    public function testANameTheQuestionAlreadyNamedIsNotFlagged(): void
    {
        // Arrange — some other area's name is in the results, "Αθήνα" is not — only $asked has it
        $results = ['{"locationid":5,"requests":41}', '{"areas":["Λάρισα"]}'];

        // Act
        $untraced = Figures::untraced(
            'Στην Αθήνα καταγράφηκαν 41 αιτήματα.',
            $results,
            'Πόσα αιτήματα είχε η Αθήνα;'
        );

        // Assert
        $this->assertSame([], $untraced);
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
