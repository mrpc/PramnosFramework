<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Mcp\Tools\ConsoleCommandsTool;

/**
 * `console-commands` — what this CLI can do.
 *
 * There are seventy-odd commands and twenty of them generate code. The reason this is a tool
 * is that an assistant working in the codebase for a whole day does not find out: it writes a
 * controller by hand rather than running `create:controller`, because nothing told it the
 * command exists. `--help` on seventy commands is not a discovery mechanism.
 *
 * Read from the live console definition, so these tests assert the shape of the answer rather
 * than a snapshot of which commands happen to be registered today.
 */
#[CoversClass(ConsoleCommandsTool::class)]
class ConsoleCommandsToolTest extends TestCase
{
    /** @return array<string, mixed> */
    private function ask(array $input = []): array
    {
        /** @var array<string, mixed> $answer */
        $answer = (new ConsoleCommandsTool())->execute($input);

        return $answer;
    }

    /**
     * The commands come back grouped by prefix, with a usage line each.
     *
     * Grouped because that is how they relate — every `create:` is the same kind of act — and
     * a flat list of seventy names reads as seventy unrelated things.
     */
    public function testCommandsAreGroupedWithTheirUsage(): void
    {
        // Act
        $answer = $this->ask();

        // Assert
        $this->assertGreaterThan(40, $answer['count'], 'this CLI has a lot of commands');
        $this->assertArrayHasKey('create', $answer['groups']);
        $this->assertArrayHasKey('migrate', $answer['groups']);

        $create = $answer['groups']['create'];
        $names  = array_column($create, 'name');

        $this->assertContains('create:controller', $names);
        $this->assertContains('create:crud', $names);

        $controller = $create[array_search('create:controller', $names, true)];
        $this->assertSame('create:controller [name]', $controller['usage'],
            'the usage line says whether the name is required');
        $this->assertTrue($controller['generates']);
    }

    /**
     * Commands that write files are flagged.
     *
     * The difference between "shows me something" and "writes eleven files into the project"
     * should not have to be inferred from a one-line description — it decides whether a
     * command can be run to see what it does.
     */
    public function testTheGeneratorsAreFlaggedAndCanBeAskedForAlone(): void
    {
        // Act
        $all  = $this->ask();
        $only = $this->ask(['generators' => true]);

        // Assert
        $this->assertLessThan($all['count'], $only['count']);
        $this->assertGreaterThan(15, $only['count'], 'there are twenty create: commands alone');

        foreach ($only['groups'] as $group) {
            foreach ($group as $command) {
                $this->assertTrue(
                    $command['generates'] ?? false,
                    $command['name'] . ' is listed as a generator and is not flagged as one'
                );
            }
        }

        // And a reporting command is not among them
        $reporting = [];

        foreach ($all['groups'] as $group) {
            foreach ($group as $command) {
                $reporting[$command['name']] = $command['generates'] ?? false;
            }
        }

        $this->assertFalse($reporting['migrate:status'] ?? true,
            'migrate:status reports; it writes nothing');
    }

    /**
     * `filter` narrows by name or description.
     */
    public function testFilterNarrowsTheList(): void
    {
        // Act
        $answer = $this->ask(['filter' => 'migration']);

        // Assert
        $this->assertGreaterThan(0, $answer['count']);

        foreach ($answer['groups'] as $group) {
            foreach ($group as $command) {
                $this->assertTrue(
                    str_contains(strtolower($command['name']), 'migration')
                    || str_contains(strtolower($command['description'] ?? ''), 'migration'),
                    $command['name'] . ' matched neither the name nor the description'
                );
            }
        }
    }

    /**
     * One command by name returns its arguments, its options and its class.
     *
     * The class is there so `find-symbol` is the obvious next question when the description
     * is not enough — the two tools are meant to be used together.
     */
    public function testOneCommandComesBackInFull(): void
    {
        // Act
        $answer = $this->ask(['name' => 'create:crud']);

        // Assert
        $this->assertSame('create:crud', $answer['name']);
        $this->assertNotEmpty($answer['description']);
        $this->assertTrue($answer['generates']);
        $this->assertSame('name', $answer['arguments'][0]['name']);
        $this->assertStringContainsString('Make', $answer['class']);

        $options = array_column($answer['options'], 'name');
        $this->assertContains('--table', $options);

        // Shortcuts are reported, because `-t` is what somebody will type
        $table = $answer['options'][array_search('--table', $options, true)];
        $this->assertSame('-t', $table['shortcut']);
        $this->assertSame('value required', $table['value']);
    }

    /**
     * An unknown name lists the real ones rather than just refusing.
     *
     * The usual cause is a near-miss — `create:migrations`, `make:model` — and the list is the
     * answer to both that and "which are there".
     */
    public function testAnUnknownNameListsTheRealOnes(): void
    {
        // Act
        $answer = $this->ask(['name' => 'create:migrations']);

        // Assert
        $this->assertStringContainsString('No command named', $answer['error']);
        $this->assertContains('create:migration', $answer['names']);
    }

    /**
     * Noise is left out of the answer.
     *
     * Symfony hands back `false` for every flag's default and `null` for most values.
     * Printing them turns a readable list into a column of `"default": false`.
     */
    public function testDefaultsThatMeanNothingAreNotPrinted(): void
    {
        // Act
        $answer = $this->ask(['name' => 'migrate']);

        // Assert
        foreach ($answer['options'] as $option) {
            $this->assertNotSame(false, $option['default'] ?? null);
            $this->assertNotSame('', $option['default'] ?? null);
        }
    }

    /**
     * The description tells a caller to look here before writing a class by hand.
     *
     * That sentence is the entire point of the tool: the failure it fixes is not being read.
     */
    public function testTheDescriptionSaysWhenToUseIt(): void
    {
        // Arrange
        $tool = new ConsoleCommandsTool();

        // Assert
        $this->assertSame('console-commands', $tool->name());
        $this->assertStringContainsString('by hand', $tool->description());
        $this->assertStringContainsString('create:', $tool->description());
    }
}
