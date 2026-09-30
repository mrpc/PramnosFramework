<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Html;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The groups screens exist in every scaffold theme, with the hooks the controller reads.
 *
 * `Groups` posts to fixed actions and reads fixed field names. A theme that renders a
 * form without the token field is refused by the write guard, and one that names the
 * member field differently adds nobody — both silently, from the operator's side. So each
 * theme's files are read and the hooks looked for.
 */
class ScaffoldGroupsViewsTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function themes(): array
    {
        return ['tailwind' => ['tailwind'], 'bootstrap' => ['bootstrap'], 'plain-css' => ['plain-css']];
    }

    private static function view(string $theme, string $file): string
    {
        $path = dirname(__DIR__, 3) . '/scaffolding/themes/' . $theme . '/views/' . $file;
        self::assertFileExists($path, $theme . ' has no ' . $file);

        return (string) file_get_contents($path);
    }

    /**
     * The list links each group to its page and offers a new one.
     */
    #[DataProvider('themes')]
    public function testTheListLinksToEachGroup(string $theme): void
    {
        // Act
        $list = self::view($theme, 'groups/groups.html.php');

        // Assert
        $this->assertStringContainsString("adminUrl('Groups/view/')", $list);
        $this->assertStringContainsString("adminUrl('Groups/edit')", $list);
        $this->assertStringContainsString("activeNav = 'groups'", $list);
    }

    /**
     * The form posts `name` and `description` to save, with a token and, when editing,
     * the id.
     */
    #[DataProvider('themes')]
    public function testTheFormCarriesWhatSaveReads(string $theme): void
    {
        // Act
        $form = self::view($theme, 'groups/edit.html.php');

        // Assert
        $this->assertStringContainsString("adminUrl('Groups/save')", $form);
        $this->assertStringContainsString('name="name"', $form);
        $this->assertStringContainsString('name="description"', $form);
        $this->assertStringContainsString('name="groupid"', $form);
        $this->assertStringContainsString('getTokenField()', $form);
    }

    /**
     * The group page posts every write with a token: add by `user`, remove by `userid`,
     * delete the group.
     */
    #[DataProvider('themes')]
    public function testTheGroupPagePostsEveryWriteWithAToken(string $theme): void
    {
        // Act
        $page = self::view($theme, 'groups/view.html.php');

        // Assert
        $this->assertStringContainsString("adminUrl('Groups/addmember/')", $page);
        $this->assertStringContainsString('name="user"', $page);
        $this->assertStringContainsString("adminUrl('Groups/removemember/')", $page);
        $this->assertStringContainsString('name="userid"', $page);
        $this->assertStringContainsString("adminUrl('Groups/delete/')", $page);
        // One token field per form: add member, remove member, delete, give role, withdraw role.
        $this->assertSame(5, substr_count($page, 'getTokenField()'));
        $this->assertStringContainsString("adminUrl('Groups/addrole/')", $page);
        $this->assertStringContainsString("adminUrl('Groups/removerole/')", $page);
        $this->assertStringContainsString('name="roleid"', $page);
        $this->assertStringContainsString('$this->rolesEnabled', $page, 'the roles section shows without the roles store');
    }

    /**
     * The breadcrumb knows the three screens.
     */
    #[DataProvider('themes')]
    public function testTheBreadcrumbKnowsTheScreens(string $theme): void
    {
        // Act
        $partial = self::view($theme, 'partials/admin_breadcrumb.html.php');

        // Assert
        foreach (["'groups'", "'groups_view'", "'groups_edit'"] as $key) {
            $this->assertStringContainsString($key . ' ', $partial, $theme . ' breadcrumb lacks ' . $key);
        }
    }
}
