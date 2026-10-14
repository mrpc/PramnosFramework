<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\NavItem;
use Pramnos\Application\NavRegistry;
use Pramnos\Application\NavSection;
use Pramnos\Console\Commands\Init;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The scaffolded tailwind header, run: an admin item whose parent this visitor may not see.
 *
 * The header nests children under their parent. A child the visitor is allowed, under a parent
 * they are not, was filed under the missing parent and never printed — the link existed, the
 * screen let them in, and nothing led there. Run rather than read, because the bug was in what
 * the template does with the registry's answer, not in its text.
 */
#[CoversClass(Init::class)]
#[CoversClass(NavItem::class)]
class ScaffoldedHeaderNavTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pramnos-headernav-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0777, true);
        file_put_contents($this->tmpDir . '/composer.json', json_encode(['name' => 'test/app']));
        NavRegistry::reset();
    }

    protected function tearDown(): void
    {
        NavRegistry::reset();
        if (!str_starts_with($this->tmpDir, sys_get_temp_dir() . '/pramnos-headernav-')) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmpDir);
    }

    /** Scaffold a project on `$ui` into the temporary directory. */
    private function scaffold(string $ui): void
    {
        $command = new Init();
        $command->targetBaseDir = $this->tmpDir;
        $command->skipDockerRun = true;
        $app = new Application();
        $app->add($command);
        (new CommandTester($command))->execute([
            '--app-name' => 'Nav App', '--no-install' => true, '--no-download' => true, '--namespace' => 'NavApp',
            '--features' => '', '--ui-system' => $ui, '--docker' => 'n', '--libraries' => '',
            '--db-type' => 'mysql', '--db-host' => 'localhost', '--db-name' => 'x', '--db-user' => 'x',
            '--db-pass' => 'x', '--db-prefix' => '',
        ], ['interactive' => false]);
        NavRegistry::reset();
    }

    /** The scaffolded header, run as the theme includes it. */
    private function header(): string
    {
        ob_start();
        try {
            include $this->tmpDir . '/app/themes/default/header.php';
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /**
     * The unread count beside a navigation label, in every UI system's header: the inbox
     * registers a badge, and no scaffolded header drew it.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('uiSystems')]
    public function testTheUnreadBadgeIsDrawn(string $ui): void
    {
        // Arrange — a signed-in person with three unread
        $this->scaffold($ui);
        NavRegistry::register(new NavItem('user.messages', 'Messages', '/messages', NavSection::User, 10, badge: static fn (int $id): int => $id === 5 ? 3 : 0));
        \Pramnos\Http\RequestIdentity::reset();
        $person = new \Pramnos\User\User();
        $person->userid = 5;
        \Pramnos\Http\RequestIdentity::seal($person, 'test');

        try {
            // Act
            $html = $this->header();
        } finally {
            \Pramnos\Http\RequestIdentity::reset();
        }

        // Assert
        $this->assertStringContainsString('Messages <span class="pf-nav-badge" aria-label="3 unread">3</span>', $html);
    }

    /** @return array<string, array{string}> */
    public static function uiSystems(): array
    {
        return ['bootstrap' => ['bootstrap'], 'tailwind' => ['tailwind'], 'plain-css' => ['plain-css']];
    }

    /** A child whose parent is hidden from this visitor is shown at the top level. */
    public function testAChildOfAHiddenParentIsShown(): void
    {
        // Arrange — scaffold tailwind; a parent above the guest's floor, its child open to them
        $this->scaffold('tailwind');
        NavRegistry::register(new NavItem('admin.reports', 'Reports', '/admin/Reports', NavSection::Admin, 10, minUserType: 90));
        NavRegistry::register(new NavItem('admin.reports.exports', 'Exports', '/admin/Reports/exports', NavSection::Admin, 11, parent: 'admin.reports'));
        NavRegistry::register(new NavItem('admin.audit', 'Audit', '/admin/Audit', NavSection::Admin, 20));
        NavRegistry::register(new NavItem('admin.audit.log', 'Audit log', '/admin/Audit/log', NavSection::Admin, 21, parent: 'admin.audit'));

        // Act — the header as the theme includes it, for a guest
        $html = $this->header();

        // Assert — the orphan is drawn; the parent is not; a child of a visible parent still nests
        $this->assertStringContainsString('/admin/Reports/exports', $html);
        $this->assertStringNotContainsString('>Reports<', $html);
        $this->assertStringContainsString('/admin/Audit/log', $html);
    }
}
