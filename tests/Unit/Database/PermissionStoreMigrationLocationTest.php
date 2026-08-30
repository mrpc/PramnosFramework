<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

/**
 * Pins where the permission store's migrations live.
 *
 * Framework migration directories are named after features, and a directory is
 * only scanned when its feature is enabled. While the RBAC tables sat under
 * `authserver/`, an application with users but no OAuth server got **no**
 * permission tables at all — authorisation arrived only if you happened to want
 * an OAuth server, which is an unrelated need.
 *
 * They now live under `auth/`. That is a property of the directory layout, not
 * of any one class, so nothing else can enforce it: a new RBAC table added to
 * the wrong directory would be silently unavailable to every project that does
 * not run an authserver, and would only surface as "permissions do not work
 * here" much later.
 */
class PermissionStoreMigrationLocationTest extends TestCase
{
    /** @var string Absolute path to the framework migrations tree */
    private string $base;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__, 3) . '/database/migrations/framework';
    }

    /**
     * The files that make up the permission store, **by slug**.
     *
     * Not by filename: the timestamp prefix is metadata — it decides ordering and whether a
     * `migration_cutoff` skips the file — and it changes when a migration is found to have been
     * misdated. Twenty-five of them were, and this test broke on a rename that changed nothing
     * it was testing.
     *
     * @return list<array{0: string}>
     */
    public static function storeMigrations(): array
    {
        return [
            ['create_authserver_schema'],
            ['create_authserver_roles_table'],
            ['create_authserver_permissions_table'],
            ['create_authserver_user_roles_table'],
            ['add_audience_and_conditions_to_permissions'],
        ];
    }

    /**
     * The file for a slug, whatever timestamp it currently carries.
     *
     * @param string $slug
     */
    private function pathOf(string $slug): string
    {
        $found = glob($this->base . '/auth/*_' . $slug . '.php');

        $this->assertNotEmpty($found, $slug . ' is not under auth/');

        return $found[0];
    }

    /**
     * Each one lives under `auth/`, so it runs wherever there are users.
     *
     * @param string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storeMigrations')]
    public function testTheStoreMigrationsLiveUnderAuth(string $file): void
    {
        // Act + Assert
        $this->assertNotEmpty(
            glob($this->base . '/auth/*_' . $file . '.php'),
            'the permission store must be created by the auth feature'
        );
    }

    /**
     * And none of them is left behind under `authserver/`.
     *
     * A copy in both places would run twice and, worse, disagree the moment one
     * of the two is edited.
     *
     * @param string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storeMigrations')]
    public function testNoneIsLeftUnderAuthserver(string $file): void
    {
        // Act + Assert
        $this->assertFileDoesNotExist($this->base . '/authserver/' . $file);
    }

    /**
     * Each declares the feature that matches its directory.
     *
     * The directory decides whether a migration runs; `$feature` is what gets
     * recorded and what `migrate --feature=` filters on. The two disagreeing
     * would make the migration status report lie about why something ran.
     *
     * @param string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storeMigrations')]
    public function testEachDeclaresTheAuthFeature(string $file): void
    {
        // Arrange
        $source = (string) file_get_contents($this->pathOf($file));

        // Act + Assert
        $this->assertMatchesRegularExpression(
            '/\$feature\s*=\s*\'auth\'/',
            $source,
            $file . ' must declare the auth feature to match its directory'
        );
    }

    /**
     * The set is self-contained: every dependency it declares is in the same
     * directory.
     *
     * This is the invariant that actually matters. A project with `auth` on and
     * `authserver` off only has this directory scanned, so a dependency pointing
     * outside it would make the store depend on a feature nobody enabled — which
     * is the situation this move existed to end.
     *
     * @param string $file
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('storeMigrations')]
    public function testDependenciesResolveWithinTheAuthDirectory(string $file): void
    {
        // Arrange
        $source = (string) file_get_contents($this->pathOf($file));

        if (!preg_match('/\$dependencies\s*=\s*\[([^\]]*)\]/', $source, $match)) {
            // No declared dependencies is trivially self-contained.
            $this->addToAssertionCount(1);

            return;
        }

        preg_match_all("/'([^']+)'/", $match[1], $slugs);
        $names = array_map(
            static fn(string $path): string => basename($path, '.php'),
            glob($this->base . '/auth/*.php') ?: []
        );

        // Act + Assert
        foreach ($slugs[1] as $slug) {
            $found = false;
            foreach ($names as $name) {
                if (str_contains($name, $slug)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue(
                $found,
                $file . ' depends on "' . $slug . '", which is not in the auth directory'
            );
        }
    }
}
