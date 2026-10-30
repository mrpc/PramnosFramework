<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Console\Commands\LlmEval;
use Pramnos\Llm\Eval\Report;
use Pramnos\Llm\Eval\Suite;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The eval suite: questions with the tool calls a good answer makes, asked of a (faked) model,
 * scored, and compared with a baseline by the CI gates.
 *
 * What holds: no real tool runs — each answers its canned result; a call nobody expected costs
 * accuracy as a missing one does; arguments match loosely (case, space, "5" for 5) but by value;
 * and the gates fail on more than five points of accuracy or fidelity lost, or a fifth more
 * rounds, and not on less.
 */
#[CoversClass(Suite::class)]
#[CoversClass(Report::class)]
#[CoversClass(LlmEval::class)]
class EvalSuiteTest extends LlmTestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setting('llm_openai_api_key', 'sk-test');
        $this->setting('llm_provider', 'openai');
        $this->dir = sys_get_temp_dir() . '/pf-eval-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** The application's tools, whose run must never be reached by an eval. */
    private static function tools(): array
    {
        $never = static function (): string {
            throw new \LogicException('an eval ran a real tool');
        };

        return [
            'count_orders' => ['description' => 'Count orders', 'parameters' => ['type' => 'object', 'properties' => ['period' => ['type' => 'string'], 'limit' => ['type' => 'integer']]], 'run' => $never],
            'list_users'   => ['description' => 'List users', 'parameters' => ['type' => 'object', 'properties' => []], 'run' => $never],
        ];
    }

    /** A case asking for the orders of this month. */
    private static function ordersCase(array $extra = []): array
    {
        return $extra + [
            'name'                 => 'orders',
            'user_query'           => 'How many orders this month?',
            'available_tools'      => ['count_orders', 'list_users'],
            'tool_results'         => ['count_orders' => ['count' => 42]],
            'expected_tool_calls'  => ['count_orders'],
            'expected_arguments'   => ['count_orders' => ['period' => 'this_month', 'limit' => 5]],
            'expected_final_state' => ['answer_contains' => ['42']],
        ];
    }

    /**
     * The right tool with the right arguments, loosely written, and the answer holding the
     * number: every score full. The tool answered its canned result; the real one never ran.
     */
    public function testAGoodAnswerScoresFull(): void
    {
        // Arrange
        $this->answer([
            [200, self::calls([['count_orders', ['period' => ' This_Month ', 'limit' => '5', 'status' => ['paid', 'sent']]]])],
            [200, self::says('There were 42 orders.')],
        ]);
        $case = self::ordersCase(['expected_arguments' => ['count_orders' => ['period' => 'this_month', 'limit' => 5, 'status' => ['paid', 'sent']]]]);

        // Act
        $report = (new Suite([$case]))->run('You answer about orders.', self::tools());

        // Assert
        $case = $report->cases[0];
        $this->assertSame(['count_orders'], $case['called']);
        $this->assertSame(1.0, $case['tool_accuracy']);
        $this->assertSame(1.0, $case['argument_fidelity'], 'case, space, "5" for 5, and a list whole, all match');
        $this->assertSame(1.0, $case['final_state']);
        $this->assertSame(2, $case['rounds']);
        // The model was given the canned result.
        $this->assertSame('{"count":42}', self::spoken($this->sent[1])[2]['content']);
        $this->assertSame('You answer about orders.', $this->sent[0]['body']['messages'][0]['content']);
    }

    /**
     * An extra tool nobody expected halves accuracy as a missing one would; a wrong argument
     * costs its share; an answer without the number fails the final state.
     */
    public function testExtraCallsWrongArgumentsAndAMissingAnswerCost(): void
    {
        // Arrange
        $this->answer([
            [200, self::calls([['count_orders', ['period' => 'last_month', 'limit' => 5]], ['list_users', []]])],
            [200, self::says('I could not tell.')],
        ]);

        // Act
        $report = (new Suite([self::ordersCase()]))->run('', self::tools());

        // Assert
        $case = $report->cases[0];
        $this->assertSame(0.5, $case['tool_accuracy']);
        $this->assertSame(0.5, $case['argument_fidelity']);
        $this->assertSame(0.0, $case['final_state']);
    }

    /**
     * Tools defined in the case itself, a case's own system prompt, no expectations at all
     * (full marks for asking nothing), and a provider failure scored as a failed answer.
     */
    public function testDefinedToolsOwnPromptAndAFailure(): void
    {
        // Arrange
        $suite = new Suite([
            ['user_query' => 'Hi', 'system' => 'Own prompt.', 'available_tools' => ['ping' => ['description' => 'p', 'parameters' => []]]],
            ['user_query' => 'Again'],
        ]);
        $this->answer([[200, self::says('Hello.')], [500, 'down']]);

        // Act
        $report = $suite->run('Suite prompt.', [], static fn () => new \Pramnos\Llm\Client());

        // Assert
        $this->assertSame('Own prompt.', $this->sent[0]['body']['messages'][0]['content']);
        $this->assertSame('ping', $this->sent[0]['body']['tools'][0]['function']['name']);
        $this->assertSame([1.0, 1.0, 1.0], [$report->cases[0]['tool_accuracy'], $report->cases[0]['argument_fidelity'], $report->cases[0]['final_state']]);
        $this->assertSame('case 2', $report->cases[1]['name']);
        $this->assertFalse($report->cases[1]['ok']);
        $this->assertSame(0.0, $report->cases[1]['final_state'], 'no answer is not the expected state');
    }

    /** A case without a question, and one naming tools the suite was not given, are refused. */
    public function testBadCasesAreRefused(): void
    {
        // Arrange
        $suite = new Suite([['user_query' => 'x', 'available_tools' => ['nope']]]);

        // Act / Assert
        try {
            new Suite([['name' => 'empty']]);
            $this->fail('a case without a question');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('(empty)', $e->getMessage());
        }
        $this->expectExceptionMessage('names tools not given to the suite: nope');
        $suite->run('', self::tools());
    }

    /**
     * Fixtures from a directory: one case a file, or a list of them, named after the file; a
     * file that is not JSON is refused.
     */
    public function testFixturesFromADirectory(): void
    {
        // Arrange
        file_put_contents($this->dir . '/a.json', json_encode(['user_query' => 'one']));
        file_put_contents($this->dir . '/b.json', json_encode([['user_query' => 'two'], ['user_query' => 'three', 'name' => 'mine']]));

        // Act
        $suite = Suite::fromDirectory($this->dir);
        $this->answer([[200, self::says('ok')]]);
        $names = array_column($suite->run('')->cases, 'name');

        // Assert
        $this->assertSame(3, $suite->count());
        $this->assertSame(['a', 'b#1', 'mine'], $names);
        file_put_contents($this->dir . '/c.json', '{not json');
        $this->expectException(\InvalidArgumentException::class);
        Suite::fromDirectory($this->dir);
    }

    /**
     * The gates: five points of accuracy or fidelity may go, a sixth may not; a fifth more
     * rounds may come, more may not; getting better never fails. A report survives being saved.
     */
    public function testTheGates(): void
    {
        // Arrange
        $case     = static fn (float $acc, float $arg, int $rounds): array => ['tool_accuracy' => $acc, 'argument_fidelity' => $arg, 'final_state' => 1.0, 'rounds' => $rounds];
        $baseline = Report::fromArray((new Report([$case(1.0, 1.0, 5)]))->toArray());

        // Act / Assert
        $this->assertSame([], (new Report([$case(0.95, 0.95, 6)]))->regressions($baseline), 'exactly at the limits');
        $this->assertSame([], (new Report([$case(1.0, 1.0, 2)]))->regressions($baseline), 'better is never a regression');
        $failed = (new Report([$case(0.94, 0.9, 7)]))->regressions($baseline);
        $this->assertCount(3, $failed);
        $this->assertStringStartsWith('tool_accuracy fell from 100.0% to 94.0%', $failed[0]);
        $this->assertStringStartsWith('argument_fidelity fell', $failed[1]);
        $this->assertStringStartsWith('rounds rose from 5.00 to 7.00', $failed[2]);
        $this->assertSame(0.0, (new Report([]))->mean('rounds'));
        $this->assertSame([], (new Report([$case(1.0, 1.0, 3)]))->regressions(new Report([])), 'no baseline rounds, nothing to rise from');
    }

    /**
     * The command: writes a baseline — beside the cases, and not read as one — passes against it,
     * fails (exit 1) on a regression, and fails when there are no cases or no baseline.
     */
    public function testTheCommand(): void
    {
        // Arrange
        file_put_contents($this->dir . '/orders.json', json_encode(self::ordersCase()));
        file_put_contents($this->dir . '/setup.php', '<?php return ["system" => "S.", "tools" => ' . var_export(array_map(static fn (array $t): array => array_diff_key($t, ['run' => 1]), self::tools()), true) . '];');
        $baseline = $this->dir . '/baseline.json';
        $tester   = new CommandTester(new LlmEval());
        $good     = [[200, self::calls([['count_orders', ['period' => 'this_month', 'limit' => 5]]])], [200, self::says('42 orders.')]];
        $run      = fn (array $more = []): int => $tester->execute(['fixtures' => $this->dir, '--setup' => $this->dir . '/setup.php', '--baseline' => $baseline] + $more);

        // Act / Assert — no baseline yet
        $this->answer($good);
        $this->assertSame(1, $run());
        $this->assertStringContainsString('No baseline at', $tester->getDisplay());

        // Write it, then compare with it
        $this->answer($good);
        $this->assertSame(0, $run(['--write-baseline' => true]));
        $this->assertSame(1.0, json_decode((string) file_get_contents($baseline), true)['summary']['tool_accuracy']);
        $this->answer($good);
        $this->assertSame(0, $run());
        $this->assertStringContainsString('orders  tools 100%  arguments 100%  answer ok  rounds 2', $tester->getDisplay());

        // A worse model fails the gate
        $this->answer([[200, self::says('No idea.')]]);
        $this->assertSame(1, $run());
        $this->assertStringContainsString('Regression: tool_accuracy fell', $tester->getDisplay());

        // Without a baseline option, only the scores; without cases, a failure
        $this->answer([[500, 'down']]);
        $this->assertSame(0, $tester->execute(['fixtures' => $this->dir, '--setup' => $this->dir . '/setup.php']));
        $this->assertStringContainsString('answer no  rounds 0  (', $tester->getDisplay());
        $this->assertSame(1, $tester->execute(['fixtures' => $this->dir . '/none']));
    }
}
