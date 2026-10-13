<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Console\Commands\ProjectResync;
use Pramnos\Console\ShippedFiles;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * `project:resync --spa-components` replaces a shared component only while it is still what the
 * framework wrote.
 *
 * The components are the project's once they exist: it fixes its DataTable, writes its own Chat
 * test at a path a newer framework also ships. A resync that replaced those undid the work and
 * reported "updated" for each — so a file changed since it was written, or never recorded as
 * written, is kept and named, and only `--overwrite` takes the framework's copy.
 */
#[CoversClass(ProjectResync::class)]
#[CoversClass(ShippedFiles::class)]
class ResyncKeepsProjectFilesTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/pramnos-resync-keep-' . bin2hex(random_bytes(4));
        mkdir($this->base . '/frontend/components', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->base));
        parent::tearDown();
    }

    /** One file through the protected write, as the command does it; the action and the output. */
    private function apply(string $rel, string $content, bool $overwrite = false, bool $dryRun = false, bool $all = true): array
    {
        $output = new BufferedOutput();
        $action = (new \ReflectionMethod(ProjectResync::class, 'applyProjectFile'))
            ->invoke(new ProjectResync(), $this->base, $rel, $content, $dryRun, $all, $overwrite, $output);

        return [$action, $output->fetch()];
    }

    private function put(string $rel, string $content): void
    {
        file_put_contents($this->base . '/' . $rel, $content);
    }

    private function onDisk(string $rel): string
    {
        return (string) file_get_contents($this->base . '/' . $rel);
    }

    /**
     * A file still as the framework wrote it takes the newer one; a file the project changed is
     * kept and said so, and so is one the framework never recorded writing — however the
     * command was run, short of `--overwrite`.
     */
    public function testOnlyWhatTheFrameworkWroteIsReplaced(): void
    {
        // Arrange
        $pristine = 'frontend/components/DataTable.svelte';
        $changed  = 'frontend/components/Field.svelte';
        $unknown  = 'frontend/components/Omnibox.svelte';
        foreach ([$pristine, $changed] as $rel) {
            $this->put($rel, 'v1');
            ShippedFiles::record($this->base, $rel, 'v1');
        }
        $this->put($changed, 'v1 with the project\'s fix');
        $this->put($unknown, 'the project\'s own');

        // Act
        [$updated] = $this->apply($pristine, 'v2');
        [$kept, $keptSays] = $this->apply($changed, 'v2');
        [$notOurs, $notOursSays] = $this->apply($unknown, 'v2');

        // Assert
        $this->assertSame(['updated', 'v2'], [$updated, $this->onDisk($pristine)]);
        $this->assertSame(['kept', 'v1 with the project\'s fix'], [$kept, $this->onDisk($changed)]);
        $this->assertStringContainsString('kept       frontend/components/Field.svelte (locally changed)', $keptSays);
        $this->assertSame(['kept', 'the project\'s own'], [$notOurs, $this->onDisk($unknown)]);
        $this->assertStringContainsString('(not written by the framework)', $notOursSays);
        // The newer copy is what is recorded now, so the next resync can replace it again.
        $this->assertTrue(ShippedFiles::untouched($this->base, $pristine, 'v2'));
    }

    /** `--overwrite` takes the framework's copy, and records it. */
    public function testOverwriteTakesTheFrameworksCopy(): void
    {
        // Arrange
        $rel = 'frontend/components/Field.svelte';
        $this->put($rel, 'mine');

        // Act
        [$action] = $this->apply($rel, 'theirs', overwrite: true);

        // Assert
        $this->assertSame(['updated', 'theirs'], [$action, $this->onDisk($rel)]);
        $this->assertTrue(ShippedFiles::untouched($this->base, $rel, 'theirs'));
    }

    /**
     * A new file is written and recorded; one already identical is recorded too, so a project
     * from before the record exists gains it. A dry run records nothing, and a missing file
     * without `--all` is skipped.
     */
    public function testWhatIsRecorded(): void
    {
        // Arrange
        $same = 'frontend/components/Pagination.svelte';
        $this->put($same, 'same');

        // Act
        [$dry]     = $this->apply('frontend/components/Dry.svelte', 'x', dryRun: true);
        [$skipped] = $this->apply('frontend/components/Skip.svelte', 'x', all: false);
        [$created] = $this->apply('frontend/components/llm/Chat.svelte', 'chat');
        [$equal]   = $this->apply($same, 'same');

        // Assert
        $this->assertSame(['created', 'skipped', 'created', 'unchanged'], [$dry, $skipped, $created, $equal]);
        $this->assertNull(ShippedFiles::recorded($this->base, 'frontend/components/Dry.svelte'));
        $this->assertNull(ShippedFiles::recorded($this->base, 'frontend/components/Skip.svelte'));
        $this->assertTrue(ShippedFiles::untouched($this->base, 'frontend/components/llm/Chat.svelte', 'chat'));
        $this->assertTrue(ShippedFiles::untouched($this->base, $same, 'same'));
        $this->assertFileExists($this->base . '/' . ShippedFiles::PATH);
    }

    /** A record that is not JSON is no record: every file is the project's until rewritten. */
    public function testAnUnreadableRecordIsNoRecord(): void
    {
        // Arrange
        mkdir($this->base . '/app');
        file_put_contents($this->base . '/' . ShippedFiles::PATH, 'not json');

        // Act / Assert
        $this->assertNull(ShippedFiles::recorded($this->base, 'frontend/components/DataTable.svelte'));
    }
}
