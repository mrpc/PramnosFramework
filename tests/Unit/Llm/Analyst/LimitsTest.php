<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm\Analyst;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Llm\Analyst\Limits;
use Pramnos\Llm\Llm;
use PHPUnit\Framework\TestCase;

/**
 * One of the analyst's own ceilings (FW-496): a plain number, a function of the person asking,
 * or the default when the application set neither.
 */
#[CoversClass(Limits::class)]
class LimitsTest extends TestCase
{
    /** @var array<string, mixed>|null The `llm` section before the test */
    private ?array $llmConfig = null;

    protected function setUp(): void
    {
        $this->llmConfig = Application::getInstance()->applicationInfo['llm'] ?? null;
    }

    protected function tearDown(): void
    {
        $app = Application::getInstance();
        if ($this->llmConfig === null) {
            unset($app->applicationInfo['llm']);
        } else {
            $app->applicationInfo['llm'] = $this->llmConfig;
        }
        Llm::reset();
    }

    /** @param array<string, mixed> $analyst */
    private function configure(array $analyst): void
    {
        Application::getInstance()->applicationInfo['llm'] = ['analyst' => $analyst];
        Llm::reset();
    }

    /** Nothing configured: the default, whatever the key. */
    public function testTheDefaultWhenNothingIsConfigured(): void
    {
        // Arrange
        $this->configure([]);

        // Assert
        $this->assertSame(200, Limits::of('sql_rows', 200));
        $this->assertSame(0.5, Limits::of('subagent_cost', 0.5));
    }

    /** A plain number, read back as given. */
    public function testAPlainNumber(): void
    {
        // Arrange
        $this->configure(['sql_rows' => 500, 'export_rows' => 20000]);

        // Assert
        $this->assertSame(500, Limits::of('sql_rows', 200));
        $this->assertSame(20000, Limits::of('export_rows', 100000));
    }

    /** A function of the account asking, called with it, its own answer taken. */
    public function testACallableIsCalledWithTheAccount(): void
    {
        // Arrange
        $seen = null;
        $this->configure(['rounds' => function ($user) use (&$seen): int {
            $seen = $user;

            return 4;
        }]);

        // Act
        $value = Limits::of('rounds', 16);

        // Assert
        $this->assertSame(4, $value);
        $this->assertNotNull($seen, 'the callable was handed an account');
    }

    /** A callable that answers something that is not a number: the default, not a crash. */
    public function testACallableThatAnswersSomethingOtherThanANumberFallsBackToTheDefault(): void
    {
        // Arrange
        $this->configure(['sql_rows' => static fn ($user): string => 'a lot']);

        // Assert
        $this->assertSame(200, Limits::of('sql_rows', 200));
    }

    /**
     * A callable for a specific account (`new User($userId)`) that cannot resolve — no database
     * here — falls back to the default rather than taking the tool call down with it: a
     * per-role ceiling is a convenience, not something the primary read should depend on.
     */
    public function testAnUnresolvableAccountFallsBackToTheDefaultRatherThanThrowing(): void
    {
        // Arrange
        $this->configure(['sql_rows' => static fn ($user): int => 1]);

        // Act & Assert — no exception
        $this->assertSame(200, Limits::of('sql_rows', 200, 999999));
    }

    /** Two different keys, two different ceilings, read independently. */
    public function testEachKeyIsItsOwn(): void
    {
        // Arrange
        $this->configure(['sql_rows' => 50, 'export_rows' => 5000]);

        // Assert
        $this->assertSame(50, Limits::of('sql_rows', 200));
        $this->assertSame(5000, Limits::of('export_rows', 100000));
        $this->assertSame(8, Limits::of('subagent_rounds', 8), 'a key never set: the default');
    }
}
