<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Llm;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Llm\Chat\Skills;
use Pramnos\Support\FrontMatter;

/**
 * Skills an application writes as Markdown files, and the front matter they are read by.
 *
 * What has to hold: each `<slug>.md` in the folder is a skill named by its `name`, or by the file
 * when it has none; `description`, `tools` (a list on one line or as `- item` lines) and the body
 * are read; a name a skill cannot have is left out; no folder, or one not set, is no skills.
 */
#[CoversClass(Skills::class)]
#[CoversClass(FrontMatter::class)]
final class SkillFilesTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pf-skills-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /**
     * The folder's files, read: the named one, the one named by its file, the one with a list of
     * `- item` lines; the one with a name no skill can have is left out.
     */
    public function testTheFolderIsReadAsSkills(): void
    {
        // Arrange
        file_put_contents($this->dir . '/crm.md', "---\nname: crm-loyalty\ndescription: \"Retention: who drifts\"\ntools: [audience_segments, retention]\nsources: x\n---\n# Method\n\nStart from the cohort.\n");
        file_put_contents($this->dir . '/copywriting.md', "---\ndescription: Words\ntools:\n  - draft\n  - 'polish'\n---\nWrite short.");
        file_put_contents($this->dir . '/bad.md', "---\nname: has spaces!\n---\nNo.");
        file_put_contents($this->dir . '/plain.md', "Just a method, no front matter.");
        file_put_contents($this->dir . '/notes.txt', 'not a skill');

        // Act
        $skills = Skills::load($this->dir);

        // Assert
        $this->assertSame(['copywriting', 'crm-loyalty', 'plain'], array_keys($skills));
        $this->assertSame(['description' => 'Retention: who drifts', 'tools' => ['audience_segments', 'retention'], 'instructions' => "# Method\n\nStart from the cohort.", 'folder' => null, 'references' => [], 'evals' => null, 'enabled' => true, 'audience' => 'all', 'age' => ''], $skills['crm-loyalty'], 'for all, unless it says whom');
        $this->assertSame(['draft', 'polish'], $skills['copywriting']['tools'], '- item lines');
        $this->assertSame(['description' => '', 'tools' => [], 'instructions' => 'Just a method, no front matter.', 'folder' => null, 'references' => [], 'evals' => null, 'enabled' => true, 'audience' => 'all', 'age' => ''], $skills['plain']);
    }

    /** No folder named, or one that is not there, is no skills — not an error. */
    public function testNoFolderIsNoSkills(): void
    {
        // Act & Assert
        $this->assertSame([], Skills::load($this->dir . '/missing'));
        $this->assertSame([], Skills::load(null), 'no app.php setting in a bare test application');
        $this->assertNull(Skills::folder());
    }

    /** A value of names: one, a list, or blanks dropped. */
    public function testNames(): void
    {
        // Act & Assert
        $this->assertSame(['a'], FrontMatter::names(' a '));
        $this->assertSame(['a', 'b'], FrontMatter::names(['a', '', ' b']));
        $this->assertSame(['meta' => [], 'body' => 'x'], FrontMatter::parse("\r\nx\r\n"));
    }

    /**
     * A skill as a folder: `<slug>/SKILL.md` beside the file form, its references listed by name
     * and title, its evals folder found; `use_skill` hands over the body and the list, not the
     * references themselves.
     */
    public function testASkillFolderWithReferencesAndEvals(): void
    {
        // Arrange
        mkdir($this->dir . '/crm/references', 0777, true);
        mkdir($this->dir . '/crm/evals');
        file_put_contents($this->dir . '/crm/SKILL.md', "---\ndescription: Loyalty\n---\nStart from the cohort.");
        file_put_contents($this->dir . '/crm/references/emails.md', "---\nkind: x\n---\n# Win-back emails\nDear customer…");
        file_put_contents($this->dir . '/crm/references/calendar.txt', "A month of touches\nweek 1…");
        file_put_contents($this->dir . '/crm/references/image.png', 'binary');
        file_put_contents($this->dir . '/plain.md', 'A file skill.');

        // Act
        $skills = Skills::load($this->dir);
        $said   = Skills::instructions($skills['crm']);

        // Assert
        $this->assertSame(['crm', 'plain'], array_keys($skills));
        $this->assertSame(['calendar.txt' => 'A month of touches', 'emails.md' => 'Win-back emails'], $skills['crm']['references']);
        $this->assertSame($this->dir . '/crm/evals', $skills['crm']['evals']);
        $this->assertNull($skills['plain']['evals']);
        $this->assertStringContainsString("Start from the cohort.\n\nReferences — read one with read_skill_reference", $said);
        $this->assertStringContainsString('- emails.md: Win-back emails', $said);
        $this->assertStringNotContainsString('Dear customer', $said, 'the references themselves stay out');
        $this->assertSame('A file skill.', Skills::instructions($skills['plain']));
        $this->cleanTree();
    }

    /**
     * The reader: offered only while a skill with references is on, it reads a listed file; a name
     * not listed, a path out of the folder, a link out of it, and the part past the limit are not
     * handed over.
     */
    public function testTheReferenceReaderReadsOnlyListedFilesInsideTheFolder(): void
    {
        // Arrange — a file, a link pointing out of the folder, and an oversized one
        mkdir($this->dir . '/crm/references', 0777, true);
        file_put_contents($this->dir . '/crm/SKILL.md', 'Method.');
        file_put_contents($this->dir . '/crm/references/emails.md', '# Emails');
        file_put_contents($this->dir . '/secret.md', 'outside');
        symlink($this->dir . '/secret.md', $this->dir . '/crm/references/leak.md');
        file_put_contents($this->dir . '/crm/references/big.md', str_repeat('x', Skills::REFERENCE_MAX + 10));
        $skills   = Skills::load($this->dir);
        $registry = Skills::declare(new \Pramnos\Llm\ToolRegistry(), $skills);

        // Act
        $offeredOff = array_keys($registry->tools([], []));
        $offeredOn  = array_keys($registry->tools([], ['crm']));
        $read       = static fn (string $file): string => Skills::read($skills, 'crm', $file);

        // Assert
        $this->assertNotContains(Skills::READER, $offeredOff, 'not offered until a skill with references is on');
        $this->assertContains(Skills::READER, $offeredOn);
        $this->assertSame('# Emails', $read('emails.md'));
        $this->assertStringStartsWith('Error:', $read('../secret.md'), 'not a listed name');
        $this->assertStringStartsWith('Error:', $read('leak.md'), 'a link out of the folder');
        $this->assertStringEndsWith('[Cut at ' . Skills::REFERENCE_MAX . ' bytes.]', $read('big.md'));
        $this->assertStringStartsWith('Error:', Skills::read($skills, 'nope', 'emails.md'));
        $this->cleanTree();
    }

    /** Remove the folders a test made under its directory. */
    private function cleanTree(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir((string) $item) : unlink((string) $item);
        }
    }
}
