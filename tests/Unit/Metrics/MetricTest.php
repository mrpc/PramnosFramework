<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Database\Database;
use Pramnos\Metrics\Dimension;
use Pramnos\Metrics\Metric;
use Pramnos\Metrics\Window;

/**
 * A metric's declaration, without a database: names, what is stored for a sum and a distinct,
 * what a source's answer becomes, who may see it, and a derived metric's arithmetic.
 */
#[CoversClass(Metric::class)]
#[CoversClass(Dimension::class)]
class MetricTest extends TestCase
{
    /**
     * A sum is stored by all its dimensions together (anything else adds up from it) and its
     * total; a distinct by each dimension alone and its total, or what it keeps.
     */
    public function testWhatIsStored(): void
    {
        // Arrange
        $sum      = Metric::flow('orders.count')->dimensions(['region', 'plan']);
        $alone    = Metric::flow('orders.total');
        $distinct = Metric::flow('customers.ordering')->aggregate(Metric::DISTINCT)->dimensions(['region', 'plan']);
        $kept     = Metric::flow('customers.kept')->aggregate(Metric::DISTINCT)->dimensions(['region', 'plan'])->keeps([['region', 'plan'], ['plan', 'region']]);

        // Act & Assert
        $this->assertSame([[], ['plan', 'region']], $sum->storedSets());
        $this->assertSame([[]], $alone->storedSets());
        $this->assertSame([[], ['region'], ['plan']], $distinct->storedSets());
        $this->assertSame([[], ['plan', 'region']], $kept->storedSets(), 'the same set twice is kept once, sorted');
        $this->assertSame(Metric::DISTINCT, $kept->getAggregate());
        $this->assertSame('orders.count', $sum->label(), 'no label: the name');
    }

    /**
     * A source's answer: rows with the dimensions asked and a value — the same breakdown twice
     * added together, a missing value 0 — or one number, null being 0.
     */
    public function testWhatASourcesAnswerBecomes(): void
    {
        // Arrange
        $db     = $this->createStub(Database::class);
        $window = Window::of(Window::DAY, '2026-09-17');
        $rows   = Metric::flow('orders.count')->dimensions(['region'])->source(static fn (Window $w, array $by): array => [
            ['region' => 'north', 'value' => 2], ['region' => 'north', 'value' => '3'], (object) ['region' => 'south'],
        ]);
        $number = Metric::level('customers.active')->source(static fn (): ?int => null);

        // Act
        $measured = $rows->measure($window, ['region'], $db);

        // Assert
        $this->assertSame([['dims' => ['region' => 'north'], 'value' => 5.0, 'tenant' => 0], ['dims' => ['region' => 'south'], 'value' => 0.0, 'tenant' => 0]], $measured);
        $this->assertSame([['dims' => [], 'value' => 0.0, 'tenant' => 0]], $number->measure($window, [], $db));
        $this->expectException(\UnexpectedValueException::class);
        $number->measure($window, ['region'], $db);   // one number cannot be a breakdown
    }

    /** No source, no measuring; no rows, no records; rows are what the closure gives. */
    public function testWithoutASourceOrRows(): void
    {
        // Arrange
        $db      = $this->createStub(Database::class);
        $window  = Window::of(Window::DAY, '2026-09-17');
        $bare    = Metric::flow('orders.count');
        $records = Metric::flow('orders.count')->rows(static fn (Window $w, array $f): \Generator => yield (object) ['id' => 7, 'region' => $f['region'] ?? '']);

        // Act & Assert
        $this->assertFalse($bare->hasSource());
        $this->assertFalse($bare->hasRows());
        $this->assertSame([], $bare->records($window, [], $db));
        $this->assertTrue($records->hasRows());
        $this->assertSame([['id' => 7, 'region' => 'north']], $records->records($window, ['region' => 'north'], $db));
        $this->expectException(\LogicException::class);
        $bare->measure($window, [], $db);
    }

    /** Who may see it: the closure's answer, or the dashboard's role, manager, by default. */
    public function testWhoMaySeeIt(): void
    {
        // Arrange
        $default = Metric::flow('orders.count');
        $open    = Metric::flow('orders.count')->allowed(static fn (?object $user): bool => $user === null);

        // Act & Assert
        $this->assertFalse($default->allows(null), 'nobody signed in is below every role');
        $this->assertTrue($default->allows((object) ['usertype' => 99]));
        $this->assertTrue($open->allows(null));
    }

    /**
     * A derived metric's arithmetic: precedence, parentheses, unary minus, numbers — and no value
     * when an operand has none or a division is by nothing.
     */
    public function testDerivedArithmetic(): void
    {
        // Arrange
        $ratio = Metric::derived('orders.conversion', '100 * orders.count / (visits.unique - visits.bots)', 'Conversion', '%');
        $neg   = Metric::derived('orders.net', '-orders.refunds + orders.count * 2');

        // Act & Assert
        $this->assertSame(['orders.count', 'visits.unique', 'visits.bots'], $ratio->operands());
        $this->assertSame(25.0, $ratio->evaluate(['orders.count' => 5, 'visits.unique' => 22, 'visits.bots' => 2]));
        $this->assertNull($ratio->evaluate(['orders.count' => 5, 'visits.unique' => 2, 'visits.bots' => 2]), 'by nothing');
        $this->assertNull($ratio->evaluate(['orders.count' => 5, 'visits.unique' => 22]), 'an operand without a value');
        $this->assertSame(17.0, $neg->evaluate(['orders.refunds' => 3, 'orders.count' => 10]));
        $this->assertNull($neg->evaluate(['orders.count' => 10]));
        $this->assertSame('%', $ratio->getUnit());
        $this->assertSame(Metric::DERIVED, $ratio->kind);
    }

    /** @return array<string, array{string}> */
    public static function badExpressions(): array
    {
        return [
            'a character it does not read' => ['orders.count % 2'],
            'unclosed'                     => ['(orders.count + 1'],
            'closed too often'             => ['orders.count + 1)'],
            'two operators'                => ['orders.count * / 2'],
            'ends on an operator'          => ['orders.count +'],
        ];
    }

    /** An expression it cannot read is refused when declared, not when read. */
    #[DataProvider('badExpressions')]
    public function testAnExpressionItCannotReadIsRefused(string $expression): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        Metric::derived('orders.bad', $expression);
    }

    /** Names, aggregates and dimension names it does not take are refused; a dimension's values and words. */
    public function testDeclarationsItRefusesAndDimensions(): void
    {
        // Arrange
        $refused = 0;
        foreach ([
            static fn () => Metric::flow('Orders Count'),
            static fn () => Metric::flow('orders.count')->aggregate('avg'),
            static fn () => Dimension::of('Region'),
        ] as $declare) {
            try {
                $declare();
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }
        $region = Dimension::of('region', 'Region')->values(['north' => 'North']);
        $lazy   = Dimension::of('plan')->values(static fn (): array => ['basic' => 'Basic']);

        // Assert
        $this->assertSame(3, $refused);
        $this->assertSame('North', $region->labelOf('north'));
        $this->assertSame('west', $region->labelOf('west'), 'a value not listed is shown as it is');
        $this->assertSame(['basic' => 'Basic'], $lazy->all());
        $this->assertSame('plan', $lazy->label, 'no words: the name');
        $metric = Metric::flow('orders.count')->unit('€')->describe('Orders placed');
        $this->assertSame('€', $metric->getUnit());
        $this->assertSame('Orders placed', $metric->description());
    }
}
