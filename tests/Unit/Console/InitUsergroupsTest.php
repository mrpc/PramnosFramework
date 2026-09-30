<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `init` offers user groups as a choice, off unless asked for, and only with `auth`.
 *
 * Off by default because most applications never need groups, and a project should not
 * carry two tables and an admin screen it did not ask for. Only with `auth`, because a
 * membership row references a user: offering it without would build a feature whose
 * migration cannot create its key.
 */
#[CoversClass(Init::class)]
class InitUsergroupsTest extends TestCase
{
    /**
     * Answer the feature questions, one line per question asked.
     *
     * @return array{0: list<string>, 1: string} The enabled features and what was printed
     */
    private function ask(string $answers): array
    {
        $command = new Init();
        $input   = new ArrayInput([], $command->getDefinition());
        $stream  = fopen('php://memory', 'r+');
        fwrite($stream, $answers);
        rewind($stream);
        $input->setStream($stream);
        $output = new BufferedOutput();

        $enabled = (new \ReflectionMethod(Init::class, 'askFeatures'))
            ->invoke($command, $input, $output, new QuestionHelper());

        return [$enabled, $output->fetch()];
    }

    /**
     * With auth, the question is asked and Enter leaves groups off.
     */
    public function testEnterLeavesGroupsOff(): void
    {
        // Act — Enter for each of the five features (all yes), then Enter for groups.
        [$enabled, $printed] = $this->ask(str_repeat("\n", 6));

        // Assert
        $this->assertStringContainsString('[usergroups]? [y/N]', $printed);
        $this->assertContains('auth', $enabled);
        $this->assertNotContains('usergroups', $enabled);
    }

    /**
     * Answering yes enables it.
     */
    public function testYesEnablesGroups(): void
    {
        // Act
        [$enabled] = $this->ask(str_repeat("\n", 5) . "y\n");

        // Assert
        $this->assertContains('usergroups', $enabled);
    }

    /**
     * Without auth the question is not asked at all.
     */
    public function testWithoutAuthTheQuestionIsNotAsked(): void
    {
        // Act — no to auth, yes to the rest.
        [$enabled, $printed] = $this->ask("n\n\n\n\n\n");

        // Assert
        $this->assertStringNotContainsString('usergroups', $printed);
        $this->assertNotContains('usergroups', $enabled);
    }
}
