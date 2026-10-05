<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Framework\Testing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Framework\Testing\Schema;

/**
 * Every migration that changes a recipe table's columns is in that table's recipe.
 *
 * `Testing\Schema::table()` builds a table in its production shape from a list of migrations,
 * and the list fell behind: `usertokens` lacked `add_oidc_context`, `applications` lacked
 * `add_trusted` and `add_token_lifetimes`. A test built on such a table asserts against a
 * shape no installation has, and on an empty database the first class to build it decided
 * the shape for every class after.
 */
#[CoversClass(Schema::class)]
class SchemaRecipesAreCompleteTest extends TestCase
{
    /**
     * Migrations that touch a recipe table but not its columns, with why.
     *
     * Named here rather than inferred, so a new one is a decision someone writes down.
     */
    private const NOT_COLUMNS = [
        // Sweeps that add keys and indexes across the whole schema; a fixture wants the columns.
        'AddMissingForeignKeysToExistingTables',
        'AddMissingIndexesToExistingTables',
        // A foreign key's ON DELETE, not a column.
        'CascadeUsertokensApplication',
        // Moves data between two columns the recipe already has.
        'SplitBroadcastSecretFromApisecret',
    ];

    /** The recipes, read from the class. @return array<string, list<string>> */
    private function recipes(): array
    {
        return (new \ReflectionClassConstant(Schema::class, 'RECIPES'))->getValue();
    }

    /**
     * Each recipe lists every column-changing migration of its table.
     */
    public function testEveryMigrationThatChangesARecipeTableIsInItsRecipe(): void
    {
        // Arrange
        $recipes = $this->recipes();
        $files   = glob(dirname(__DIR__, 4) . '/database/migrations/framework/*/*.php') ?: [];
        $this->assertNotEmpty($files, 'no migrations were found to check');
        $this->assertNotEmpty($recipes, 'no recipes were found to check');

        // Act
        $missing = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (!preg_match('/^namespace\s+([\w\\\\]+);/m', $source, $ns)
                || !preg_match('/^class\s+(\w+)/m', $source, $class)
            ) {
                continue;
            }
            if (in_array($class[1], self::NOT_COLUMNS, true)) {
                continue;
            }
            $fqcn = $ns[1] . '\\' . $class[1];
            foreach ($recipes as $table => $recipe) {
                $touches = preg_match(
                    "/(->table|alterTable|hasColumn)\\(\\s*'(#PREFIX#)?" . preg_quote($table, '/') . "'/",
                    $source
                );
                if ($touches && !in_array($fqcn, $recipe, true)) {
                    $missing[] = $table . ': ' . $fqcn;
                }
            }
        }

        // Assert
        $this->assertSame([], $missing, 'Testing\Schema builds these tables without these migrations');
    }
}
