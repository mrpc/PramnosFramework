<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Analyst\Analyst;
use Pramnos\Llm\Analyst\AnalystAssistant;
use Pramnos\Llm\Analyst\Tools\Window;
use Pramnos\Llm\Chat\Conversations;
use Pramnos\Llm\Chat\Scope;
use Pramnos\Llm\Chat\Turn;

/**
 * The analyst's two rules an application has to be able to set: when to look people up, and
 * which zone a day is in — both were written into the prompt, so an installation that counts
 * in Athens time, or whose administrators find people by name, could only contradict them.
 */
#[CoversClass(AnalystAssistant::class)]
#[CoversClass(Analyst::class)]
#[CoversClass(Window::class)]
class AnalystPromptTest extends LlmTestCase
{
    private string $zone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->zone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
        parent::tearDown();
    }

    /** The prompt one assistant writes. */
    private function prompt(AnalystAssistant $assistant): string
    {
        return $assistant->systemPrompt(new Turn(new Scope(1), null, false, [], [], [], new Conversations($assistant)));
    }

    /**
     * The people rule is the framework's until an application gives its own — then only its own,
     * so the model is not handed two rules that disagree.
     */
    public function testAnApplicationSetsThePeopleRule(): void
    {
        // Arrange
        $own = new class extends AnalystAssistant {
            protected function peoplePolicy(): string
            {
                return 'Finding accounts by name is ordinary work here.';
            }
        };

        // Act
        $default = $this->prompt(new AnalystAssistant());
        $theirs  = $this->prompt($own);

        // Assert
        $this->assertStringContainsString('Use figures, not people', $default);
        $this->assertStringContainsString('Finding accounts by name is ordinary work here.', $theirs);
        $this->assertStringNotContainsString('Use figures, not people', $theirs, 'replaced, not added to');
        $this->assertStringContainsString('never try to recover them', $theirs, 'masking is not part of the rule');
        $this->assertStringContainsString('info string chart', $default, 'the analyst is told how to chart');
    }

    /**
     * The zone is the analyst's setting, else the application's, and an unknown name is UTC
     * rather than an exception in the middle of an answer.
     */
    public function testTheZone(): void
    {
        // Arrange
        date_default_timezone_set('Europe/Athens');

        // Act
        $application = Analyst::timezone();
        $this->configure(['analyst' => ['timezone' => 'America/New_York']]);
        $configured = Analyst::timezone();
        $this->configure(['analyst' => ['timezone' => 'Mars/Olympus']]);
        $unknown = Analyst::timezone();
        $this->configure([]);

        // Assert
        $this->assertSame(['Europe/Athens', 'America/New_York', 'UTC'], [$application, $configured, $unknown]);
        $this->assertStringContainsString('Dates and days are in Europe/Athens', $this->prompt(new AnalystAssistant()));
    }

    /**
     * A day is the administrator's day: in Athens, 13 October starts at 21:00 UTC on the 12th,
     * and a moment late in the UTC evening is already the next day's.
     */
    public function testADayIsTheZonesDay(): void
    {
        // Arrange
        $athens = new \DateTimeZone('Europe/Athens');
        $now    = gmmktime(12, 0, 0, 10, 13, 2026);

        // Act
        $day = Window::from(['from' => '2026-10-13', 'to' => '2026-10-13'], $now, $athens);

        // Assert
        $this->assertSame(gmmktime(21, 0, 0, 10, 12, 2026), $day->from, 'midnight in Athens');
        $this->assertSame(gmmktime(20, 59, 59, 10, 13, 2026), $day->to, 'the last second of the Athens day');
        $this->assertSame('2026-10-13', $day->label(gmmktime(22, 0, 0, 10, 12, 2026)), 'already the 13th in Athens');
        $this->assertSame(['from' => '2026-10-13', 'to' => '2026-10-13', 'group' => 'day', 'timezone' => 'Europe/Athens'], $day->describe());
    }
}
