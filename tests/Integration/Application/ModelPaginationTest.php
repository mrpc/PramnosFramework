<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/** A model over a table this test creates. */
class PaginationProbeModel extends Model
{
    protected $modelname = 'pagingprobe';

    protected $_primaryKey = 'probeid';

    public $probeid;

    public $title;

    public $rank;

    public function __construct(?string $table = null)
    {
        if ($table !== null) {
            $this->_dbtable = $table;
        }
    }

    public function page(int $items, int $page, array $options = []): mixed
    {
        return $this->_getPaginated(
            $items,
            $page,
            $options['filter'] ?? null,
            $options['order'] ?? 'ORDER BY a.rank ASC',
            $options['table'] ?? null,
            null,
            false,
            '',
            $options['fields'] ?? null,
            '',
            $options['asModels'] ?? false
        );
    }
}

/**
 * The pagination every model inherits — the base `Model` is what an application's whole data layer
 * extends, so an off-by-one here is an off-by-one in every listing at once.
 *
 * The reason it is worth its own file rather than being covered incidentally: the failures are all
 * plausible-looking. A page that repeats one row and skips another is a listing somebody scrolls
 * past; a total that counts the page rather than the set puts «10 results» under a table of ten on a
 * set of four hundred; a negative page from a hand-edited query string is either a database error on
 * a public listing or, worse, an offset that silently wraps.
 *
 * The one that is invisible until much later: **the primary key is forced into the select.** A
 * caller asking for two columns gets three, deliberately — the returned objects are models, and a
 * model without its key cannot be reloaded or saved. Omit it and the listing renders perfectly while
 * every edit link on the page points at nothing.
 *
 * Both backends: {@see ModelPaginationPostgreSQLTest}. `LIMIT`/`OFFSET` and the aliased
 * `count(a.key)` are spelled the same but answered by different drivers, and the count is a second
 * query that has to agree with the first.
 */
#[CoversClass(Model::class)]
class ModelPaginationTest extends BaseTestCase
{
    private $db;

    private string $table = '';

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        $this->table = 'pagingprobe_' . bin2hex(random_bytes(4));
        $this->createTable();
        $this->seed(25);
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    private function createTable(): void
    {
        $q = $this->db->type === 'postgresql'
            ? static fn (string $n): string => '"' . $n . '"'
            : static fn (string $n): string => '`' . $n . '`';

        $this->db->query(
            'CREATE TABLE ' . $q($this->table) . ' ('
            . $q('probeid') . ($this->db->type === 'postgresql' ? ' SERIAL PRIMARY KEY, ' : ' INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ')
            . $q('title') . ' VARCHAR(255) NOT NULL, '
            . $q('rank') . ' INT NOT NULL)'
            . ($this->db->type === 'postgresql' ? '' : ' ENGINE=InnoDB')
        );
    }

    private function seed(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->db->queryBuilder()->table($this->table)->insert([
                'title' => sprintf('Row %02d', $i),
                'rank'  => $i,
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->table !== '') {
            $q = $this->db->type === 'postgresql' ? '"' : '`';
            try {
                $this->db->query('DROP TABLE IF EXISTS ' . $q . $this->table . $q);
            } catch (\Throwable) {
                // Nothing to drop.
            }
        }

        parent::tearDown();
    }

    private function model(): PaginationProbeModel
    {
        return new PaginationProbeModel($this->table);
    }

    // ── The page and the total ────────────────────────────────────────────────

    /**
     * The first page is the first `items` rows, and the total is the whole set.
     *
     * Two numbers from two queries that have to agree about the same thing. A total taken from the
     * page would put «10 results» under a table of ten on a set of twenty-five, which is the sort of
     * number a person acts on.
     */
    public function testTheFirstPageIsTheFirstRowsAndTheTotalIsTheSet(): void
    {
        // Act
        $answer = $this->model()->page(10, 1);

        // Assert
        $this->assertSame(25, (int) $answer['total'], 'the total counted the page, not the set');
        $this->assertSame(3, (int) $answer['pages'], '25 rows in pages of 10 is three pages');
        $this->assertCount(10, $answer['items']);
        $this->assertSame('Row 01', $answer['items'][0]['title']);
        $this->assertSame('Row 10', $answer['items'][9]['title']);
    }

    /**
     * The second page continues where the first stopped — no repeat, no gap.
     *
     * `$page` arrives 1-based and becomes an offset, so this is where an off-by-one lives. Both
     * failure directions are visible here: an offset one too small repeats `Row 10`, one too large
     * skips `Row 11`, and the page still looks like a page of results either way.
     */
    public function testTheSecondPageContinuesWithoutRepeatingOrSkipping(): void
    {
        // Act
        $first  = $this->model()->page(10, 1);
        $second = $this->model()->page(10, 2);

        // Assert
        $this->assertSame('Row 11', $second['items'][0]['title'], 'the second page repeats or skips');
        $this->assertCount(10, $second['items']);

        $firstTitles  = array_column($first['items'], 'title');
        $secondTitles = array_column($second['items'], 'title');
        $this->assertSame([], array_intersect($firstTitles, $secondTitles), 'the pages overlap');
    }

    /**
     * The last page is the remainder, not a full page padded out.
     *
     * Twenty-five rows in tens leaves five, and a listing that asked for ten and got five has to
     * report five — a caller that trusted the requested count would render five rows and five empty
     * ones.
     */
    public function testTheLastPageIsTheRemainder(): void
    {
        // Act
        $answer = $this->model()->page(10, 3);

        // Assert
        $this->assertCount(5, $answer['items']);
        $this->assertSame('Row 21', $answer['items'][0]['title']);
        $this->assertSame('Row 25', $answer['items'][4]['title']);
    }

    /**
     * A page beyond the end is empty, and still reports the real total.
     *
     * Reachable by a bookmark, or by deleting rows while somebody has page four open. Empty is the
     * right answer; the total still being correct is what lets the screen offer a way back.
     */
    public function testAPageBeyondTheEndIsEmptyButStillCounts(): void
    {
        // Act
        $answer = $this->model()->page(10, 9);

        // Assert
        $this->assertSame([], $answer['items']);
        $this->assertSame(25, (int) $answer['total']);
        $this->assertSame(3, (int) $answer['pages']);
    }

    // ── Numbers a query string can carry ──────────────────────────────────────

    /**
     * A negative page never reaches the database as a negative offset.
     *
     * `?page=-3` is one hand edit away on any listing, and a negative `OFFSET` is a syntax error on
     * both backends — so a public listing becomes a 500. That is what the guard is for, and it is
     * what this asserts.
     *
     * It does *not* normalise to page one, and the arithmetic is worth writing down because it
     * surprised me: the decrement happens **before** the `abs()`, so `page=-1` becomes offset
     * `items × 2` — the third page. Odd, harmless, and load-bearing for anyone who has come to rely
     * on the arithmetic, so it is characterised here rather than changed. What matters is that the
     * offset is non-negative and the total is still the truth.
     */
    public function testANegativePageDoesNotReachTheDatabase(): void
    {
        // Act
        $answer = $this->model()->page(10, -1);

        // Assert — an answer at all means no negative offset was sent
        $this->assertSame(25, (int) $answer['total']);
        $this->assertSame(3, (int) $answer['pages']);
        $this->assertLessThanOrEqual(10, count($answer['items']));

        // `-1` decrements to -2, `abs()` makes 2, so the offset is two pages in.
        $this->assertSame(
            $this->model()->page(10, 3)['items'],
            $answer['items'],
            'the documented arithmetic changed; a negative page now lands elsewhere'
        );
    }

    /**
     * Asking for zero items reports one page rather than dividing by zero.
     *
     * `ceil($total / 0)` is a division by zero, and the guard above it is what turns a hand-edited
     * `?limit=0` into an empty listing instead of a fatal.
     */
    public function testZeroItemsIsOnePageAndNotADivisionByZero(): void
    {
        // Act
        $answer = $this->model()->page(0, 1);

        // Assert
        $this->assertSame(1, (int) $answer['pages'], 'the page count divided by zero');
        $this->assertSame(25, (int) $answer['total']);
    }

    /** An empty table is one page of nothing, not zero pages. */
    public function testAnEmptyTableIsOnePageOfNothing(): void
    {
        // Arrange
        $q = $this->db->type === 'postgresql' ? '"' : '`';
        $this->db->query('DELETE FROM ' . $q . $this->table . $q);

        // Act
        $answer = $this->model()->page(10, 1);

        // Assert
        $this->assertSame(0, (int) $answer['total']);
        $this->assertSame(1, (int) $answer['pages'], 'zero pages leaves a pager with nothing to draw');
        $this->assertSame([], $answer['items']);
    }

    // ── Filtering and shape ───────────────────────────────────────────────────

    /**
     * A filter narrows the rows and the total together.
     *
     * The two queries are built from one builder precisely so they cannot disagree — a total that
     * ignored the filter would page a filtered listing against an unfiltered count, so the last
     * pages would be empty and the pager would still offer them.
     */
    public function testAFilterNarrowsTheRowsAndTheTotalTogether(): void
    {
        // Act
        $answer = $this->model()->page(10, 1, ['filter' => 'WHERE a.rank <= 7']);

        // Assert
        $this->assertSame(7, (int) $answer['total'], 'the count ignored the filter');
        $this->assertSame(1, (int) $answer['pages']);
        $this->assertCount(7, $answer['items']);
    }

    /**
     * The primary key is in the result even when the caller did not ask for it.
     *
     * A caller asking for one column gets two, on purpose: the rows become models, and a model
     * without its key cannot be reloaded or saved. Omit it and the listing renders perfectly while
     * every edit link on the page points at nothing — which is found by a person, not by a test,
     * unless the test is this one.
     */
    public function testThePrimaryKeyIsAlwaysSelected(): void
    {
        // Act — deliberately asking only for the title
        $answer = $this->model()->page(3, 1, ['fields' => 'a.title']);

        // Assert
        $this->assertNotSame([], $answer['items']);
        $this->assertArrayHasKey(
            'probeid',
            $answer['items'][0],
            'the rows came back without their key, so nothing can be edited from this listing'
        );
        $this->assertArrayHasKey('title', $answer['items'][0]);
    }

    /**
     * Asked for models, it returns models keyed by their id; asked for arrays, arrays.
     *
     * Both shapes are used — a listing wants arrays and a batch operation wants objects it can save
     * — and the keying by id is what lets a caller address one row of the page without searching it.
     */
    public function testItReturnsEitherModelsOrArrays(): void
    {
        // Act
        $arrays = $this->model()->page(3, 1);
        $models = $this->model()->page(3, 1, ['asModels' => true]);

        // Assert
        $this->assertIsArray($arrays['items'][0], 'arrays were asked for and objects returned');

        $items = $models['items'];
        $this->assertNotSame([], $items);
        $first = reset($items);
        $this->assertInstanceOf(PaginationProbeModel::class, $first);
        $this->assertSame(
            (int) $first->probeid,
            (int) array_key_first($items),
            'the models are not keyed by their primary key'
        );
        // `_isnew` is not public, so it is read where the class keeps it — reaching for
        // `$first->_isnew` from outside creates a dynamic property and answers null, which is what
        // my first version of this assertion was really testing.
        $this->assertFalse(
            (new \ReflectionProperty(Model::class, '_isnew'))->getValue($first),
            'a loaded row is marked as new, so saving it would insert a duplicate'
        );
    }

    /**
     * The order asked for is the order returned.
     *
     * Pagination without a deterministic order is pagination that can show the same row twice
     * across two pages — the database is free to return rows in any order, and does.
     */
    public function testTheOrderAskedForIsTheOrderReturned(): void
    {
        // Act
        $answer = $this->model()->page(5, 1, ['order' => 'ORDER BY a.rank DESC']);

        // Assert
        $this->assertSame('Row 25', $answer['items'][0]['title']);
        $this->assertSame('Row 21', $answer['items'][4]['title']);
    }
}
