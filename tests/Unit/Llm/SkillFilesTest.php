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
        $this->assertSame(['description' => 'Retention: who drifts', 'tools' => ['audience_segments', 'retention'], 'instructions' => "# Method\n\nStart from the cohort."], $skills['crm-loyalty']);
        $this->assertSame(['draft', 'polish'], $skills['copywriting']['tools'], '- item lines');
        $this->assertSame(['description' => '', 'tools' => [], 'instructions' => 'Just a method, no front matter.'], $skills['plain']);
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
}
