<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Dashboard;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Assist\Help;
use Pramnos\Dashboard\Widgets\HelpNews;

/**
 * The dashboard's "What's new", from the administration's help pages.
 *
 * What holds: only the pages of the news sections — News and Changelog unless app.php names
 * others — newest first by their `updated` date, at most the limit, each linking to its page, with
 * a link to all of the help; nothing new is said; nobody signed in is not offered it.
 */
#[CoversClass(HelpNews::class)]
class HelpNewsTest extends TestCase
{
    private string $dir = '';

    /** @var array<string, mixed> */
    private array $info = [];

    protected function setUp(): void
    {
        $this->info = Application::getInstance()->applicationInfo;
        $this->dir  = sys_get_temp_dir() . '/helpnews-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $pages = [
            'old-news'   => "---\ntitle: Old news\nsection: News\nupdated: 2026-01-01\n---\nOld.",
            'new-news'   => "---\ntitle: New news\nsection: news\nupdated: 2026-10-01\n---\nNew.",
            'release'    => "---\ntitle: Release 1.3\nsection: Changelog\nupdated: 2026-09-01\n---\nIt.",
            'how-to'     => "---\ntitle: How to refund\nsection: Billing\nupdated: 2026-10-15\n---\nRefund.",
            'press'      => "---\ntitle: Press <release>\nsection: Press\n---\nP.",
        ];
        foreach ($pages as $slug => $text) {
            file_put_contents($this->dir . '/' . $slug . '.md', $text);
        }
        Help::useDirectories([$this->dir]);
    }

    protected function tearDown(): void
    {
        Application::getInstance()->applicationInfo = $this->info;
        Help::useDirectories(null);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** The news sections' pages, newest first, a section matched whatever its case. */
    public function testTheNewsPagesNewestFirst(): void
    {
        // Act
        $titles = array_column((new HelpNews())->pages(), 'title');

        // Assert
        $this->assertSame(['New news', 'Release 1.3', 'Old news'], $titles, 'not Billing or Press');
    }

    /**
     * Sections and the limit from app.php; each page links to its own, a title is escaped, and
     * the help as a whole is a link away; with nothing in the sections, that is said.
     */
    public function testConfiguredSectionsLimitAndDrawing(): void
    {
        // Arrange
        Application::getInstance()->applicationInfo['dashboard'] = ['help_news' => ['sections' => ['Press', 'News'], 'limit' => 2]];
        $card = new HelpNews();

        // Act
        $titles = array_column($card->pages(), 'title');
        $html   = $card->render();
        Application::getInstance()->applicationInfo['dashboard'] = ['help_news' => ['sections' => ['Nothing']]];
        $empty  = $card->render();

        // Assert
        $this->assertSame(['New news', 'Old news'], $titles, 'two, newest first; Press has no date and comes after');
        $this->assertStringContainsString('href="' . htmlspecialchars(Help::url('new-news'), ENT_QUOTES) . '"', $html);
        $this->assertStringContainsString('2026-10-01', $html);
        $this->assertStringContainsString('href="' . htmlspecialchars(Help::url(), ENT_QUOTES) . '"', $html);
        $this->assertStringContainsString('Nothing new yet.', $empty);
        $this->assertSame(['help.news', false, Help::url()], [$card->id(), $card->byDefault(), $card->link()]);
        $this->assertNotSame('', $card->title() . $card->description());
        $this->assertFalse($card->allowed(null), 'nobody signed in');
    }
}
