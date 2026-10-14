<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Assist;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Assist\Help;
use Pramnos\Llm\Analyst\Tools\HelpTool;

/**
 * The help pages: the application's before the framework's, a screen's page found by its name,
 * front matter read, a question matched in Greek as in English, and pages drawn with their links
 * and pictures — none of which may reach outside a help directory.
 */
#[CoversClass(Help::class)]
#[CoversClass(HelpTool::class)]
#[CoversClass(\Pramnos\Llm\Chat\Markdown::class)]
class HelpTest extends TestCase
{
    private string $own = '';

    private string $framework = '';

    protected function setUp(): void
    {
        $base            = sys_get_temp_dir() . '/pf-help-' . bin2hex(random_bytes(4));
        $this->own       = $base . '/app';
        $this->framework = $base . '/framework';
        $files = [
            $this->framework . '/users.md'       => "# Users\n\nEvery account.",
            $this->framework . '/queue.md'       => "# Queue\n\nBackground jobs.",
            $this->own . '/users.md'             => "# Our people\n\nOnly ours.",
            $this->own . '/users/edit.md'        => "---\ntitle: Editing a person\nsection: People\nquestions:\n  - How do I change somebody's email?\n  - Πώς αλλάζω το email ενός χρήστη;\nupdated: 2026-10-01\n---\n# Editing\n\nSee [the list](help:users) and ![The form](images/form.png).",
            $this->own . '/billing/refunds.md'   => "---\nsection: Billing\n---\n# Επιστροφές χρημάτων\n\nΗ επιστροφή γίνεται από τη σελίδα της παραγγελίας.",
            $this->own . '/images/form.png'      => 'png',
            $this->own . '/images/notes.txt'     => 'text',
            $this->own . '/empty.md'             => '',
        ];
        foreach ($files as $file => $content) {
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $content);
        }
        file_put_contents($base . '/secret.png', 'outside');
        Help::useDirectories([$this->own, $this->framework]);
    }

    protected function tearDown(): void
    {
        Help::useDirectories(null);
        $base = dirname($this->own);
        if (str_starts_with($base, sys_get_temp_dir() . '/pf-help-')) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($base);
        }
    }

    /**
     * The application's page replaces the framework's of the same name; front matter is read
     * and left out of the text; a title comes from the first heading when none is given.
     */
    public function testPagesAndTheirFrontMatter(): void
    {
        // Act
        $users = Help::page('users');
        $edit  = Help::page('/Users/Edit/');
        $queue = Help::page('queue');

        // Assert
        $this->assertSame('Our people', $users['title'], 'the application\'s, not the framework\'s');
        $this->assertSame('Editing a person', $edit['title']);
        $this->assertSame('People', $edit['section']);
        $this->assertSame(["How do I change somebody's email?", 'Πώς αλλάζω το email ενός χρήστη;'], $edit['questions']);
        $this->assertSame('2026-10-01', $edit['updated']);
        $this->assertStringStartsWith('# Editing', $edit['body'], 'the front matter is not text');
        $this->assertSame('Administration', $queue['section'], 'a framework page without one');
        $this->assertTrue($queue['framework']);
        $this->assertSame('empty', Help::page('empty')['title'], 'no heading: its name');
        $this->assertNull(Help::page('nothing'));
        $this->assertNull(Help::page('../../etc/passwd'), 'a name is only ever a name');
    }

    /** A screen's action has its own page, or the screen's; `display` is the screen. */
    public function testAScreensPage(): void
    {
        // Act / Assert
        $this->assertSame('users/edit', Help::forScreen('users', 'edit')['slug']);
        $this->assertSame('users', Help::forScreen('users', 'view')['slug'], 'no page of its own: the screen\'s');
        $this->assertSame('users', Help::forScreen('Users', 'display')['slug']);
        $this->assertSame('Only ours.', trim(substr((string) Help::text('users'), 13)));
        $this->assertNull(Help::forScreen('billing/refunds'), 'a screen is one name');
        $this->assertNull(Help::forScreen(''));
    }

    /**
     * A question finds its page by the page's questions first, then its text — folded, so
     * accents, case and the final sigma do not matter, and word endings do not either.
     */
    public function testSearchingInGreekAndEnglish(): void
    {
        // Act
        $greek    = Help::search('πως αλλαζω το ΕΜΑΙL ενος χρηστη');
        $endings  = Help::search('επιστροφή παραγγελιών');
        $english  = Help::search('change email');
        $nothing  = Help::search('zebra');
        $tooShort = Help::search('to');

        // Assert
        $this->assertSame('users/edit', $greek[0]['slug'], 'the question it lists, without its accents');
        $this->assertSame('billing/refunds', $endings[0]['slug'], 'another form of the same words');
        $this->assertSame('users/edit', $english[0]['slug']);
        $this->assertSame([], $nothing);
        $this->assertSame([], $tooShort, 'words of two letters say nothing');
        $this->assertSame(['επιστ', 'χρημα'], Help::words('Επιστροφές χρημάτων'));
    }

    /** Every page, the application's first, by section and title. */
    public function testEveryPage(): void
    {
        // Act
        $slugs = array_column(Help::all(), 'slug');

        // Assert
        $this->assertSame(['empty', 'users', 'billing/refunds', 'users/edit', 'queue'], $slugs);
    }

    /**
     * A page draws its `help:` links and its pictures through the help page's addresses, and a
     * picture is served only from inside a help directory and only as a picture.
     */
    public function testLinksAndPictures(): void
    {
        // Act
        $page = (string) Help::page('users/edit')['body'];
        $html = Help::html($page, static fn (string $slug): string => '/help?p=' . $slug, static fn (string $path): string => '/img?f=' . $path, 'users/edit');
        $bare = Help::html($page);

        // Assert
        $this->assertStringContainsString('<a href="/help?p=users">the list</a>', $html, 'a page links by name, in the same tab');
        $this->assertStringContainsString('<img src="/img?f=users/images/form.png" alt="The form"', $html, 'beside the page it is in');
        $this->assertStringContainsString('[the list](help:users)', $bare, 'without addresses, left as written');
        $this->assertStringNotContainsString('<img', $bare, 'and a picture is its alt text');
        $this->assertSame(realpath($this->own . '/images/form.png'), Help::image('images/form.png'));
        $this->assertNull(Help::image('../secret.png'), 'not outside the directory');
        $this->assertNull(Help::image('images/notes.txt'), 'not a file that is not a picture');
        $this->assertNull(Help::image('images/none.png'));
    }

    /** One page fits one tool result; the tool finds pages by a question or by name. */
    public function testTheAnalystsHelpTool(): void
    {
        // Arrange
        $tool = new HelpTool();

        // Act
        $asked   = $tool->execute(['question' => 'How do I change somebody\'s email?']);
        $named   = $tool->execute(['page' => 'billing/refunds']);
        $missing = $tool->execute(['page' => 'nothing']);
        $none    = $tool->execute(['question' => 'zebra']);

        // Assert
        $this->assertSame('users/edit', $asked['pages'][0]['page']);
        $this->assertArrayHasKey('text', $asked['pages'][0], 'the best page in full');
        $this->assertStringContainsString('Help/page?p=users%2Fedit', $asked['pages'][0]['link']);
        $this->assertArrayNotHasKey('text', $asked['pages'][1] ?? ['questions' => []], 'the others by name');
        $this->assertStringContainsString('επιστροφή', $named['text']);
        $this->assertSame('There is no help page called nothing.', $missing);
        $this->assertSame('No help page answers that.', $none);
        $this->assertSame('help', $tool->name());
        $this->assertNotSame('', $tool->description());
        $this->assertArrayHasKey('question', $tool->inputSchema()['properties']);
        $this->assertSame("abcde\n\n[…]", Help::excerpt('abcdefgh', 5));
    }

    /** The framework's directory is where its pages ship, and has one for every screen it has. */
    public function testTheFrameworksOwnPages(): void
    {
        // Arrange
        Help::useDirectories(null);

        // Act
        $users = Help::forScreen('users');

        // Assert
        $this->assertStringEndsWith('/resources/help', Help::frameworkDirectory());
        $this->assertNotNull($users, 'the Users screen has a page');
        $this->assertSame('Users', $users['title']);
        $this->assertSame('', Help::screen(), 'no application screen in this test');
    }
}
