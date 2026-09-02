<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Model;

/**
 * The SQL a datatable search turns into.
 *
 * `_buildSearchConditions()` is what every server-side listing filters with, and seven of its
 * statements had never run — each one a search behaviour somebody would notice:
 *
 * - **a Greek word loses its final `ς` or `σ`.** Deliberate stemming: `Γιάννης` has to find
 *   `Γιάννη` as well, because Greek inflects the ending and a visitor types the nominative.
 * - **a Greek term is matched through `unaccent()` on PostgreSQL**, so `Γιάννης` finds `ΓΙΑΝΝΗΣ`
 *   and `Γιαννης`. Without it, accents make an exact-spelling requirement out of a search box.
 * - **a numeric column is matched with `=`, not `LIKE`.** `LIKE '%9%'` on an integer column
 *   returns 9, 19, 29, 90 and 1999 — which reads as a broken filter rather than a broad one.
 * - **a field that is not in the list is skipped**, not interpolated.
 *
 * Reached by reflection, and that is the right split rather than a shortcut: the wiring —
 * `_getPaginated()` calling this and running the query — has tests already, and what had none is
 * the algorithm. What comes out is a SQL fragment, so a test that goes through a real query asserts
 * the database's opinion of the fragment rather than the fragment.
 *
 * The database is a stand-in for the same reason. Both dialects are asserted in one run, because
 * what differs between them here is the string this method writes, not anything the server does
 * with it.
 */
#[CoversClass(Model::class)]
class ModelSearchConditionsTest extends TestCase
{
    private mixed $savedDatabase = null;

    protected function setUp(): void
    {
        parent::setUp();
        $reference           = &\Pramnos\Database\Database::getInstance();
        $this->savedDatabase = $reference;
    }

    protected function tearDown(): void
    {
        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = $this->savedDatabase;
        Model::$columnCache = [];
        parent::tearDown();
    }

    /** Puts a database of a given type behind the singleton. */
    private function useDatabaseOfType(string $type): void
    {
        $stub = new class extends \Pramnos\Database\Database {
            public $type      = 'mysql';
            public $prefix    = '';
            public $connected = true;

            public function __construct() {}

            /** Escaping without a connection: what this test needs is the quoting, not the driver. */
            public function prepareInput($string)
            {
                return str_replace("'", "''", (string) $string);
            }
        };
        $stub->type = $type;

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = $stub;
    }

    /** A model with a table, and the search builder made callable. */
    private function model(): object
    {
        return new class extends Model {
            public string $table      = 'people';
            public string $primaryKey = 'personid';

            public function __construct()
            {
                // No parent constructor: it reaches for settings and a connection, and nothing
                // below needs either.
            }

            public function getFullTableName($table = '', $addSchema = true)
            {
                return 'people';
            }

            /**
             * The `AND`-joined WHERE fragment, which is what the method returns — a string, not
             * the array of conditions it assembles internally.
             *
             * @param  array<int, string>   $fields
             * @param  array<string, mixed> $fieldSearches
             */
            public function buildSearch(
                array $fields,
                string $globalSearch,
                array $fieldSearches,
                string $join = ''
            ): string {
                $method = new \ReflectionMethod(Model::class, '_buildSearchConditions');

                return $method->invoke($this, $fields, $globalSearch, $fieldSearches, $join);
            }
        };
    }

    /**
     * A Greek word is searched without its final sigma.
     *
     * `Γιάννης` becomes `%Γιάννη%`, which finds the nominative and every inflection that keeps the
     * stem. Dropping the ending is the cheapest stemming there is for Greek and it is why a search
     * box on a Greek site feels like it works.
     */
    public function testAGreekWordIsSearchedWithoutItsFinalSigma(): void
    {
        // Arrange
        $this->useDatabaseOfType('mysql');

        // Act
        $conditions = $this->model()->buildSearch(['name'], '', ['name' => 'Γιάννης']);

        // Assert
        $this->assertStringContainsString('Γιάννη%', $conditions);
        $this->assertStringNotContainsString('Γιάννης', $conditions, 'the final sigma was kept');
    }

    /**
     * A lower-case final sigma is trimmed too.
     *
     * Greek writes the final sigma as `ς` and the medial one as `σ`, and a visitor typing from a
     * keyboard layout that does not switch them produces either. Both endings have to go, or the
     * stemming works for some spellings of the same word.
     */
    public function testAMedialSigmaEndingIsAlsoTrimmed(): void
    {
        // Arrange
        $this->useDatabaseOfType('mysql');

        // Act
        $conditions = $this->model()->buildSearch(['name'], '', ['name' => 'Μαρισ']);

        // Assert
        $this->assertStringContainsString('Μαρι%', $conditions);
    }

    /**
     * Only the ending goes — a sigma inside the word stays.
     *
     * The check is on the last character, and this is what says so: trimming every sigma would
     * turn `Κώστας` into `Κώτα` and find nothing.
     */
    public function testASigmaInsideTheWordIsLeftAlone(): void
    {
        // Arrange
        $this->useDatabaseOfType('mysql');

        // Act
        $conditions = $this->model()->buildSearch(['name'], '', ['name' => 'Κώστας']);

        // Assert
        $this->assertStringContainsString('Κώστα%', $conditions);
    }

    /**
     * On PostgreSQL a Greek term is compared through `unaccent()`, case-insensitively.
     *
     * `ILIKE` handles the case and `unaccent()` handles the accents, so `Γιάννης` finds `ΓΙΑΝΝΗΣ`
     * and `Γιαννης`. A Greek name is written all three ways in real data — a form filled in caps,
     * an import that lost its accents — and a search that required the exact spelling would be a
     * search box that mostly returns nothing.
     */
    public function testOnPostgresqlAGreekTermIsUnaccented(): void
    {
        // Arrange
        $this->useDatabaseOfType('postgresql');

        // Act
        $conditions = $this->model()->buildSearch(['name'], '', ['name' => 'Γιάννης']);

        // Assert
        $this->assertStringContainsString('unaccent(', $conditions);
        $this->assertStringContainsString('ILIKE', $conditions);
        $this->assertStringContainsString('"name"', $conditions, 'PostgreSQL quotes with "');
    }

    /**
     * A term with no Greek in it does not pay for `unaccent()`.
     *
     * Still `ILIKE`, so the search stays case-insensitive, but without wrapping both sides in a
     * function — which on a large table is the difference between using an index and not.
     */
    public function testALatinTermOnPostgresqlSkipsUnaccent(): void
    {
        // Arrange
        $this->useDatabaseOfType('postgresql');

        // Act
        $conditions = $this->model()->buildSearch(['name'], '', ['name' => 'Smith']);

        // Assert
        $this->assertStringNotContainsString('unaccent(', $conditions);
        $this->assertStringContainsString('ILIKE', $conditions);
    }

    /**
     * A numeric column is matched exactly, on both dialects.
     *
     * `LIKE '%9%'` against an integer column matches 9, 19, 29, 90 and 1999. A person filtering a
     * listing by `9` means `9`, and the broad answer reads as a bug rather than a feature.
     *
     * The column type comes from `Model::$columnCache`, which is public and static — so this is
     * the one behaviour here that depends on the model knowing its schema.
     */
    public function testANumericColumnIsMatchedExactly(): void
    {
        // Arrange
        $this->useDatabaseOfType('mysql');
        Model::$columnCache['people'] = [
            ['Field' => 'views', 'Type' => 'int(11)'],
            ['Field' => 'name',  'Type' => 'varchar(255)'],
        ];

        // Act
        $numeric = $this->model()->buildSearch(['views'], '', ['views' => '9']);
        $text    = $this->model()->buildSearch(['name'], '', ['name' => '9']);

        // Assert
        $this->assertStringContainsString('= 9', $numeric, 'a numeric column should compare exactly');
        $this->assertStringNotContainsString('LIKE', $numeric);
        $this->assertStringContainsString('LIKE', $text, 'a text column still matches loosely');
    }

    /**
     * A field can be searched by its bare name even when the list qualifies it.
     *
     * A datatable sends `title`; the field list holds `posts.title` because the query joins. The
     * mapping is what stops the search from silently doing nothing on every joined listing.
     */
    public function testAQualifiedFieldIsFoundByItsBareName(): void
    {
        // Arrange
        $this->useDatabaseOfType('mysql');

        // Act
        $conditions = $this->model()->buildSearch(
            ['posts.title'],
            '',
            ['title' => 'hello'],
            'INNER JOIN #PREFIX#posts p ON p.id = a.postid'
        );

        // Assert
        $this->assertNotSame('', $conditions, 'the bare name did not reach its qualified field');
        $this->assertStringContainsString('posts.title', $conditions);
    }

    /**
     * A field nobody declared is skipped, not interpolated.
     *
     * The search terms arrive from the query string. A name that is not in the field list must
     * produce no condition at all — writing it into the SQL would put a request parameter into a
     * statement, which is the whole of the problem this list exists to prevent.
     */
    public function testAnUndeclaredFieldProducesNoCondition(): void
    {
        // Arrange
        $this->useDatabaseOfType('mysql');

        // Act
        $conditions = $this->model()->buildSearch(
            ['name'],
            '',
            ['name' => 'ok', 'passwordhash' => "' OR 1=1 --"]
        );

        // Assert
        $this->assertStringNotContainsString(
            ' AND ',
            $conditions,
            'a second condition was produced, so the undeclared field was not skipped'
        );
        $this->assertStringNotContainsString('passwordhash', $conditions);
        $this->assertStringNotContainsString('OR 1=1', $conditions);
    }

    /**
     * A term that already has wildcards is used as it is.
     *
     * Somebody typing `Γιαν%` has said where the wildcard goes, and wrapping it again in `%…%`
     * would make the leading anchor meaningless.
     */
    public function testATermWithItsOwnWildcardsIsNotWrappedAgain(): void
    {
        // Arrange
        $this->useDatabaseOfType('mysql');

        // Act
        $conditions = $this->model()->buildSearch(['name'], '', ['name' => 'Γιαν%']);

        // Assert
        $this->assertStringNotContainsString("'%Γιαν%%'", $conditions);
        $this->assertStringContainsString('Γιαν%', $conditions);
    }

    /**
     * An empty search term is not a condition.
     *
     * A datatable posts one entry per column whether or not anything was typed, so without this
     * every listing would carry a `LIKE '%%'` for each of its columns.
     */
    public function testAnEmptyTermIsSkipped(): void
    {
        // Arrange
        $this->useDatabaseOfType('mysql');

        // Act
        $conditions = $this->model()->buildSearch(['name', 'city'], '', ['name' => '  ', 'city' => '']);

        // Assert
        $this->assertSame('', $conditions);
    }
}
