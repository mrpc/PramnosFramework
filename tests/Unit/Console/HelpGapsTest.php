<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Assist\Help;
use Pramnos\Console\Commands\HelpGaps;
use Pramnos\Llm\Analyst\Tools\HelpTool;
use Pramnos\Logs\Logger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `help:gaps`: what the analyst asked the help and got weakly or nothing for, read from the
 * `help` log the help tool writes.
 *
 * Why it matters: the golden questions an installation writes by hand are few, and they are the
 * ones somebody thought of. The questions actually asked, and the ones the help did not answer,
 * are where its pages and its section questions are missing — and the source of more golden
 * questions for `help:eval`.
 */
#[CoversClass(HelpGaps::class)]
#[CoversClass(HelpTool::class)]
final class HelpGapsTest extends TestCase
{
    private string $base = '';

    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/pf-help-gaps-' . bin2hex(random_bytes(4));
        mkdir($this->base, 0777, true);
        file_put_contents($this->base . '/billing.md', "# Billing\n\nHow money comes in.\n\n## Gateways\n<!-- questions: Which card processors are there? -->\n\nA gateway takes card payments: Stripe and PayPal.\n\n## Refunds\n\nA refund is made from the order page.\n");
        Help::useDirectories([$this->base]);
        $this->info = \Pramnos\Application\Application::getInstance()->applicationInfo;
        $this->clearLog();
    }

    protected function tearDown(): void
    {
        \Pramnos\Application\Application::getInstance()->applicationInfo = $this->info;
        Help::useDirectories(null);
        $this->clearLog();
        foreach (glob($this->base . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->base);
    }

    /** The help log, gone. */
    private function clearLog(): void
    {
        foreach (glob(Logger::logDirectory() . '/help.log*') ?: [] as $file) {
            unlink($file);
        }
    }

    /** The command's output for these options. @param array<string, mixed> $options */
    private function gaps(array $options = []): string
    {
        $tester = new CommandTester(new HelpGaps());
        $tester->execute($options);

        return $tester->getDisplay();
    }

    /**
     * What the tool was asked becomes the three lists: a question nothing answered, one answered
     * below the weak score, and a section read by name — each counted, most asked first — while a
     * search answered well is in none of them, and an entry older than `--days` is left out.
     */
    public function testTheListsComeFromWhatWasAsked(): void
    {
        // Arrange
        $tool = new HelpTool();
        $tool->execute(['question' => 'Which card processors are there?']);
        $tool->execute(['question' => 'zebra crossing']);
        $tool->execute(['question' => 'zebra crossing']);
        $tool->execute(['question' => 'order page']);
        $tool->execute(['page' => 'billing', 'section' => 'Refunds']);
        file_put_contents(Logger::logDirectory() . '/help.log.1', json_encode(['timestamp' => date('c', time() - 90 * 86400), 'message' => 'help search', 'context' => ['question' => 'an old one', 'found' => 0]]) . "\n" . "not json\n", FILE_APPEND);

        // Act
        $text = $this->gaps(['--weak' => '100']);

        // Assert
        $this->assertStringContainsString("Nothing answered (1):\n    2× zebra crossing", $text);
        $this->assertMatchesRegularExpression('/Answered weakly, below 100 \(1\):\n\s+1× order page → billing#refunds \(\d+\.\d\d\)/', $text);
        $this->assertStringContainsString("Read by name, after a search did not bring them (1):\n    1× billing#refunds", $text);
        $this->assertStringNotContainsString('Which card processors', $text, 'answered well: in none of the lists');
        $this->assertStringNotContainsString('an old one', $text, 'older than --days');
    }

    /** `--golden` prints every question asked as a `_golden.php` line, with the answer it got. */
    public function testGoldenLines(): void
    {
        // Arrange
        (new HelpTool())->execute(['question' => 'order page']);
        (new HelpTool())->execute(['question' => "it's nothing"]);

        // Act
        $text = $this->gaps(['--golden' => true]);

        // Assert
        $this->assertStringContainsString("return [\n    'order page' => 'billing#refunds',\n    'it\\'s nothing' => '',\n];", $text);
    }

    /** With `help.log` off nothing is written, and the command says there is nothing to read. */
    public function testTheLogCanBeTurnedOff(): void
    {
        // Arrange
        \Pramnos\Application\Application::getInstance()->applicationInfo['help'] = ['log' => false];

        // Act
        (new HelpTool())->execute(['question' => 'zebra']);
        $text = $this->gaps();

        // Assert
        $this->assertSame([], glob(Logger::logDirectory() . '/help.log*') ?: []);
        $this->assertStringContainsString('Nothing was asked of the help in that time', $text);
    }
}
