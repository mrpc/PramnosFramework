<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Llm\Analyst\Tools\ChangesTool;

/**
 * What changed in the code, for "it worked yesterday and not today".
 *
 * What holds: the application's commits in the window are listed newest first with their date,
 * message and files, and a word narrows them; commits outside the window are left out; a root
 * without git says so; the framework's changelog days in the window are listed by their titles;
 * and the tool tells the model to answer in plain words.
 */
#[CoversClass(ChangesTool::class)]
class ChangesToolTest extends LlmTestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir() . '/pf-changes-' . bin2hex(random_bytes(4));
        mkdir($this->repo);
        $git = fn (string $args, string $date = ''): string => (string) shell_exec(
            ($date !== '' ? 'GIT_AUTHOR_DATE=' . escapeshellarg($date) . ' GIT_COMMITTER_DATE=' . escapeshellarg($date) . ' ' : '')
            . 'git -C ' . escapeshellarg($this->repo) . ' -c user.name=Dev -c user.email=dev@example.test ' . $args . ' 2>&1'
        );
        $git('init -q');
        file_put_contents($this->repo . '/old.php', 'a');
        $git('add old.php');
        $git("commit -q -m 'Old change'", '2020-01-01T10:00:00+00:00');
        file_put_contents($this->repo . '/Invoices.php', 'b');
        $git('add Invoices.php');
        $git("commit -q -m 'Invoices need a VAT number' -m 'Saving one without it is refused now.'", date('c', time() - 3600));
        file_put_contents($this->repo . '/Login.php', 'c');
        $git('add Login.php');
        $git("commit -q -m 'Login remembers the device'", date('c', time() - 1800));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->repo));
        parent::tearDown();
    }

    public function testRecentChangesNewestFirstAndNarrowedByAWord(): void
    {
        // Arrange
        $tool = new ChangesTool($this->repo);

        // Act
        $all      = $tool->execute(['days' => 7]);
        $invoices = $tool->execute(['days' => 7, 'about' => 'vat']);
        $nothing  = (new ChangesTool(sys_get_temp_dir()))->execute(['days' => 7]);

        // Assert
        $this->assertTrue($all['application']['available']);
        $this->assertSame(['Login remembers the device', "Invoices need a VAT number\nSaving one without it is refused now."],
            array_column($all['application']['changes'], 'said'), 'newest first; the old one is outside the window');
        $this->assertSame(['Invoices.php'], $all['application']['changes'][1]['touched']);
        $this->assertCount(1, $invoices['application']['changes'], 'a word narrows them, whatever its case');
        $this->assertFalse($nothing['application']['available']);
        $this->assertStringContainsString('not deployed with git', $nothing['application']['says']);
        // The framework's own changelog: today's post lists its entries
        $this->assertSame(date('Y-m-d'), $all['framework'][0]['date'] ?? null);
        $this->assertNotSame([], $all['framework'][0]['changes']);
    }

    public function testItAsksForPlainWords(): void
    {
        // Arrange
        $tool = new ChangesTool($this->repo);

        // Act / Assert
        $this->assertSame('recent_changes', $tool->name());
        $this->assertStringContainsString('plain, non-technical words', $tool->description());
        $this->assertStringContainsString('never commit hashes, file names', $tool->description());
        $this->assertArrayHasKey('about', $tool->inputSchema()['properties']);
    }
}
