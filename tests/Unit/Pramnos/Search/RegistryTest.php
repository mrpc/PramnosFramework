<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Pramnos\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiList\ApiListSource;
use Pramnos\Search\Registry;

/**
 * The registry behind one search box covering several entities.
 *
 * Per-entity search was never the gap: every model implements {@see ApiListSource} and
 * `ApiListQuery` runs the term. What no entity can implement for itself is the
 * aggregate, so that is all this class does — and all these tests check.
 *
 * The cases that matter are the ones about a registry being a *shared* surface: one
 * broken source must not take the box down, a re-registered label must not be searched
 * twice, and a source is not constructed until something actually searches it.
 */
#[CoversClass(Registry::class)]
class RegistryTest extends TestCase
{
    protected function setUp(): void
    {
        Registry::reset();
    }

    protected function tearDown(): void
    {
        Registry::reset();
    }

    /**
     * A term reaches every registered source and comes back grouped.
     *
     * The whole contract in one test: label, per-source results, and a total that is the
     * sum across groups.
     */
    public function testATermIsRunAcrossEverySourceAndGroupedByLabel(): void
    {
        // Arrange
        Registry::register('Users', new FakeSource([
            ['id' => 1, 'username' => 'annak', 'email' => 'anna@example.com'],
        ], 12), ['display' => ['username', 'email'], 'url' => '/users/edit/:id']);

        Registry::register('Orders', new FakeSource([
            ['id' => 4, 'reference' => 'ANN-4'],
        ]), ['display' => ['reference']]);

        // Act
        $result = Registry::query('ann');

        // Assert
        $this->assertSame('ann', $result['query']);
        $this->assertCount(2, $result['groups']);
        $this->assertSame('Users', $result['groups'][0]['label']);
        $this->assertSame('Orders', $result['groups'][1]['label']);
        // 12 + 1: the group totals come from the engine, not from the rows returned.
        $this->assertSame(13, $result['total']);
    }

    /**
     * The title is the first display column and the subtitle is the rest.
     */
    public function testTheFirstDisplayColumnIsTheTitleAndTheRestTheSubtitle(): void
    {
        // Arrange
        Registry::register('Users', new FakeSource([
            ['id' => 1, 'username' => 'annak', 'email' => 'anna@example.com', 'city' => 'Athens'],
        ]), ['display' => ['username', 'email', 'city']]);

        // Act
        $row = Registry::query('ann')['groups'][0]['results'][0];

        // Assert
        $this->assertSame('annak', $row['title']);
        $this->assertSame('anna@example.com · Athens', $row['subtitle']);
    }

    /**
     * `:id` in the URL pattern is replaced, and encoded.
     *
     * A primary key is usually an integer, but not always — a slug or a UUID goes in the
     * same place, and one containing a slash or a space would otherwise build a link to
     * a different path than the row it came from.
     */
    public function testTheUrlPatternIsFilledAndEncoded(): void
    {
        // Arrange
        Registry::register('Pages', new FakeSource([
            ['id' => 'a b/c', 'title' => 'About'],
        ]), ['display' => ['title'], 'url' => '/pages/:id/edit']);

        // Act
        $row = Registry::query('ab')['groups'][0]['results'][0];

        // Assert
        $this->assertSame('/pages/a%20b%2Fc/edit', $row['url']);
    }

    /**
     * With no URL pattern a result is not a link.
     *
     * For an entity that has no edit screen. `null` rather than `'#'`, so the UI can
     * render a non-interactive line instead of a link that goes nowhere.
     */
    public function testAResultWithoutAPatternHasNoUrl(): void
    {
        // Arrange
        Registry::register('Notes', new FakeSource([['id' => 7, 'body' => 'note']]), ['display' => ['body']]);

        // Act & Assert
        $this->assertNull(Registry::query('note')['groups'][0]['results'][0]['url']);
    }

    /**
     * A row whose first display column is empty still has visible text.
     *
     * The reason the title is the first *non-empty* value and not simply the first: a
     * user with no username set would otherwise produce a result line with nothing on
     * it, which reads as a rendering bug rather than as missing data.
     */
    public function testARowWithAnEmptyFirstColumnFallsForwardThenToTheKey(): void
    {
        // Arrange — first column empty, second populated.
        Registry::register('Users', new FakeSource([
            ['id' => 3, 'username' => '', 'email' => 'anna@example.com'],
        ]), ['display' => ['username', 'email']]);

        // Everything empty: the key is the only thing left to show.
        Registry::register('Blank', new FakeSource([
            ['id' => 9, 'username' => '', 'email' => ''],
        ]), ['display' => ['username', 'email']]);

        // Act
        $result = Registry::query('ann');

        // Assert
        $this->assertSame('anna@example.com', $result['groups'][0]['results'][0]['title']);
        $this->assertSame('', $result['groups'][0]['results'][0]['subtitle']);
        $this->assertSame('9', $result['groups'][1]['results'][0]['title']);
    }

    /**
     * One source that throws does not take the whole box down.
     *
     * The case this is really about: a model whose migration has not run yet. Failing
     * the request would make every other entity unsearchable because of one that is not
     * deployed — so the group is dropped and the rest are returned.
     */
    public function testABrokenSourceIsSkippedAndTheRestStillAnswer(): void
    {
        // Arrange
        Registry::register('Broken', new ThrowingSource());
        Registry::register('Users', new FakeSource([['id' => 1, 'username' => 'annak']]), ['display' => ['username']]);

        // Act
        $result = Registry::query('ann');

        // Assert
        $this->assertCount(1, $result['groups']);
        $this->assertSame('Users', $result['groups'][0]['label']);
    }

    /**
     * Registering the same label twice replaces rather than duplicates.
     *
     * `app/search.php` is edited by hand and appended to by `create:crud`, so the same
     * label arriving twice is an ordinary accident. Searching it twice would show every
     * matching row twice and double the query cost with no visible cause.
     */
    public function testRegisteringTheSameLabelTwiceReplacesIt(): void
    {
        // Arrange
        Registry::register('Users', new FakeSource([['id' => 1, 'username' => 'first']]), ['display' => ['username']]);
        Registry::register('Users', new FakeSource([['id' => 2, 'username' => 'second']]), ['display' => ['username']]);

        // Act
        $result = Registry::query('a');

        // Assert
        $this->assertSame(['Users'], Registry::labels());
        $this->assertCount(1, $result['groups']);
        $this->assertSame('second', $result['groups'][0]['results'][0]['title']);
    }

    /**
     * An empty label is refused.
     *
     * It is the group heading *and* the key, so an empty one produces an unnamed group
     * that any other empty registration silently replaces.
     */
    public function testAnEmptyLabelIsRefused(): void
    {
        // Assert
        $this->expectException(\InvalidArgumentException::class);

        // Act
        Registry::register('   ', new FakeSource([]));
    }

    /**
     * An empty term queries nothing at all.
     *
     * An empty search box is the normal state of a search box. Passing '' through would
     * make every source return its first page, so simply focusing the field would run a
     * query per registered entity.
     */
    public function testAnEmptyTermQueriesNothing(): void
    {
        // Arrange
        $source = new FakeSource([['id' => 1, 'username' => 'annak']]);
        Registry::register('Users', $source, ['display' => ['username']]);

        // Act
        $result = Registry::query('   ');

        // Assert
        $this->assertSame([], $result['groups']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $source->calls, 'the source must not be queried at all');
    }

    /**
     * A class name is not constructed until something searches it.
     *
     * The reason resolution is deferred: `app/search.php` is loaded on the request that
     * serves the search box, and a registry of six models that each open a connection at
     * registration time would cost six connections on every request that never searches.
     */
    public function testAClassNameIsNotConstructedUntilQueried(): void
    {
        // Arrange
        CountingSource::$constructions = 0;
        Registry::register('Counted', CountingSource::class, ['display' => ['username']]);

        // Assert — registration alone builds nothing.
        $this->assertSame(0, CountingSource::$constructions);

        // Act
        Registry::query('ann');

        // Assert
        $this->assertSame(1, CountingSource::$constructions);
    }

    /**
     * A callable source is called and its result used.
     *
     * The escape hatch for a model whose constructor needs more than a controller.
     */
    public function testACallableSourceIsUsed(): void
    {
        // Arrange
        Registry::register(
            'Lazy',
            static fn(): ApiListSource => new FakeSource([['id' => 1, 'username' => 'annak']]),
            ['display' => ['username']]
        );

        // Act & Assert
        $this->assertSame('annak', Registry::query('ann')['groups'][0]['results'][0]['title']);
    }

    /**
     * A source that cannot be resolved is skipped, not fatal.
     *
     * Three ways to get this wrong — a class that does not exist, one that does not
     * implement the interface, and a callable returning the wrong thing — and all three
     * are typos in a hand-edited file. None of them may take out the endpoint.
     */
    public function testAnUnresolvableSourceIsSkipped(): void
    {
        // Arrange
        Registry::register('Missing', 'Not\\A\\Class');
        Registry::register('WrongType', \stdClass::class);
        Registry::register('BadCallable', static fn(): string => 'nope');
        Registry::register('Users', new FakeSource([['id' => 1, 'username' => 'annak']]), ['display' => ['username']]);

        // Act
        $result = Registry::query('ann');

        // Assert — only the real one answers.
        $this->assertCount(1, $result['groups']);
        $this->assertSame('Users', $result['groups'][0]['label']);
    }

    /**
     * The per-source cap is passed through, and a source's own limit wins.
     */
    public function testTheLimitIsPerSourceAndOverridable(): void
    {
        // Arrange
        $default  = new FakeSource([['id' => 1, 'username' => 'a']]);
        $explicit = new FakeSource([['id' => 2, 'username' => 'b']]);
        Registry::register('Default', $default, ['display' => ['username']]);
        Registry::register('Explicit', $explicit, ['display' => ['username'], 'limit' => 3]);

        // Act
        Registry::query('a', 7);

        // Assert
        $this->assertSame(7, $default->lastLimit);
        $this->assertSame(3, $explicit->lastLimit);
    }

    /**
     * Display columns are guessed from the source when not configured.
     *
     * Documented as a guess. It exists so a registration with only a label works at all,
     * not so that anybody relies on it.
     */
    public function testDisplayColumnsFallBackToTheSourcesOwnDefaults(): void
    {
        // Arrange — default fields are id, username, email; the key is dropped and the
        // remainder capped at two.
        Registry::register('Users', new FakeSource([
            ['id' => 1, 'username' => 'annak', 'email' => 'anna@example.com'],
        ]));

        // Act
        $row = Registry::query('ann')['groups'][0]['results'][0];

        // Assert
        $this->assertSame('annak', $row['title']);
        $this->assertSame('anna@example.com', $row['subtitle']);
    }

    /**
     * A source the viewer may not see is absent, not empty.
     *
     * The distinction is the whole point: an empty group headed "Invoices" tells somebody
     * who may not see invoices that invoices exist, how they are labelled, and that
     * searching found none — three facts they were not entitled to.
     */
    public function testASourceTheViewerMayNotSeeLeavesNoTrace(): void
    {
        // Arrange
        $hidden = new FakeSource([['id' => 1, 'username' => 'annak']]);
        Registry::register('Hidden', $hidden, [
            'display'    => ['username'],
            'permission' => static fn(): bool => false,
        ]);
        Registry::register('Visible', new FakeSource([['id' => 2, 'username' => 'annab']]), [
            'display'    => ['username'],
            'permission' => static fn(): bool => true,
        ]);

        // Act
        $result = Registry::query('ann');

        // Assert — one group, and it is not the hidden one.
        $this->assertCount(1, $result['groups']);
        $this->assertSame('Visible', $result['groups'][0]['label']);
        // Not merely filtered out of the output: never queried, so it costs nothing.
        $this->assertSame(0, $hidden->calls);
    }

    /**
     * No `permission` means the endpoint's own guard is the only gate.
     *
     * The default has to be "visible", because the registry is reached through an
     * endpoint that has already checked something. Defaulting to hidden would mean every
     * registration needs a permission before it does anything, and the first symptom
     * would be a search box that finds nothing.
     */
    public function testASourceWithoutAPermissionIsVisible(): void
    {
        // Arrange
        Registry::register('Users', new FakeSource([['id' => 1, 'username' => 'annak']]), ['display' => ['username']]);

        // Act & Assert
        $this->assertCount(1, Registry::query('ann')['groups']);
    }

    /**
     * A malformed permission hides the source rather than ignoring the key.
     *
     * A `permission` that cannot be evaluated must not degrade to "no permission". That
     * would leave a source looking restricted in the file and open in fact — the one
     * failure mode where the safe direction is not the convenient one.
     */
    public function testAMalformedPermissionHidesTheSource(): void
    {
        // Arrange — neither an ability name nor a callable.
        Registry::register('Broken', new FakeSource([['id' => 1, 'username' => 'annak']]), [
            'display'    => ['username'],
            'permission' => ['not', 'valid'],
        ]);

        // Act & Assert
        $this->assertSame([], Registry::query('ann')['groups']);
    }

    /**
     * A filter callable receives the current user and its result reaches the query.
     *
     * How a per-viewer scope is written. The assertion is on the filter arriving at the
     * engine, because that is the only thing that makes the scope real.
     */
    public function testAFilterCallableScopesTheQuery(): void
    {
        // Arrange
        $source = new FakeSource([['id' => 1, 'username' => 'annak']]);
        $seen   = 'not called';
        Registry::register('Users', $source, [
            'display' => ['username'],
            'filter'  => static function ($user) use (&$seen): string {
                $seen = $user;

                return 'tenant_id = 7';
            },
        ]);

        // Act
        Registry::query('ann');

        // Assert
        $this->assertSame('tenant_id = 7', $source->lastFilter);
        // Called with the current user — null here, because no session is signed in.
        $this->assertNull($seen);
    }

    /**
     * A static filter string still works.
     */
    public function testAStaticFilterStringIsPassedThrough(): void
    {
        // Arrange
        $source = new FakeSource([['id' => 1, 'username' => 'annak']]);
        Registry::register('Users', $source, ['display' => ['username'], 'filter' => 'deleted = 0']);

        // Act
        Registry::query('ann');

        // Assert
        $this->assertSame('deleted = 0', $source->lastFilter);
    }

    /**
     * A filter callable that returns no scope drops the source.
     *
     * The most dangerous branch in the class. A scope callable that returns null — a
     * missing tenant on the user object, an early return somebody added — would
     * otherwise mean "no filter", and the query returns **every** row of the table to a
     * viewer entitled to a subset. Dropping the group is the only acceptable reading.
     */
    public function testAFilterCallableReturningNoScopeDropsTheSource(): void
    {
        // Arrange
        $source = new FakeSource([['id' => 1, 'username' => 'annak']]);
        Registry::register('Users', $source, [
            'display' => ['username'],
            'filter'  => static fn($user) => null,
        ]);

        // Act
        $result = Registry::query('ann');

        // Assert — no group, and the query never ran unscoped.
        $this->assertSame([], $result['groups']);
        $this->assertSame(0, $source->calls);
    }

    /**
     * `loadDefinitions()` runs the file once and reports whether anything registered.
     */
    public function testDefinitionsAreLoadedOnceFromAFile(): void
    {
        // Arrange
        $file = tempnam(sys_get_temp_dir(), 'search') . '.php';
        file_put_contents($file, '<?php \Pramnos\Search\Registry::register('
            . "'FromFile', new \\Pramnos\\Tests\\Unit\\Pramnos\\Search\\FakeSource([]));\n");

        // Act
        $first  = Registry::loadDefinitions($file);
        $second = Registry::loadDefinitions($file);

        // Assert — loaded, and not loaded twice (a second require would fatal on a
        // redeclare in a file that declared anything, and would double-register here).
        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertSame(['FromFile'], Registry::labels());

        unlink($file);
    }

    /**
     * A missing definitions file is false, not an exception.
     *
     * A project scaffolded before the registry existed has no such file, and that must
     * mean "no search box" rather than "a broken admin page".
     */
    public function testAMissingDefinitionsFileIsNotAnError(): void
    {
        // Act & Assert
        $this->assertFalse(Registry::loadDefinitions('/nonexistent/search.php'));
        $this->assertFalse(Registry::hasSources());
    }
}

/**
 * An {@see ApiListSource} backed by an array, recording how it was queried.
 *
 * A fake rather than a mock: the assertions are about the *arguments* the registry
 * passes down (the limit, and that it passes at all), and a fake makes those readable
 * as properties instead of expectation chains.
 */
class FakeSource implements ApiListSource
{
    public int $calls = 0;
    public ?int $lastLimit = null;
    public ?string $lastFilter = null;

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private array $rows = [], private ?int $total = null)
    {
    }

    public function apiListSchemaFields($join = ''): array
    {
        return ['id' => 'int', 'username' => 'string', 'email' => 'string', 'city' => 'string',
            'reference' => 'string', 'title' => 'string', 'body' => 'string'];
    }

    public function apiListDefaultFields($join = ''): array
    {
        return ['id', 'username', 'email'];
    }

    public function apiListPrimaryKey(): string
    {
        return 'id';
    }

    public function apiListSearchConditions(array $validFields, $globalSearch, array $fieldSearches, $join): string
    {
        return $globalSearch === '' ? '' : "username LIKE '%x%'";
    }

    public function apiListPaginate(
        $itemsPerPage, $page, $filter, $order, $table, $key, $debug,
        $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
    ): array {
        $this->calls++;
        $this->lastLimit  = (int) $itemsPerPage;
        // The filter reaches apiListPaginate() folded into the WHERE clause, so the
        // scope is asserted where it actually lands rather than where it was configured.
        $this->lastFilter = self::scopeOf((string) $filter);

        return [
            'items' => $this->rows,
            'total' => $this->total ?? count($this->rows),
            'page'  => (int) $page,
            'pages' => 1,
        ];
    }

    public function apiListFetchAll(
        $filter, $order, $table, $key, $debug,
        $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
    ) {
        $this->calls++;

        return $this->rows;
    }

    public function apiListProcessRow(array $row, $join): array
    {
        return $row;
    }

    public function apiListLastError()
    {
        return null;
    }

    public function apiListRecordsTotal($baseFilter, $table, $key, $join, $selectFields, $group, $addedfields): int
    {
        return $this->total ?? count($this->rows);
    }

    /**
     * The configured scope, recovered from whatever the engine built around it.
     *
     * `ApiListQuery` combines the base filter with the search conditions, so the string
     * arriving here is not the one registered. Only the registered part is asserted on:
     * how the engine joins clauses is its own contract, tested elsewhere.
     */
    private static function scopeOf(string $filter): string
    {
        foreach (['tenant_id = 7', 'deleted = 0'] as $known) {
            if (str_contains($filter, $known)) {
                return $known;
            }
        }

        return $filter;
    }
}

/** A source whose table does not exist — the undeployed-migration case. */
class ThrowingSource extends FakeSource
{
    public function apiListPaginate(
        $itemsPerPage, $page, $filter, $order, $table, $key, $debug,
        $join, $selectFields, $group, $returnAsModels, $useGetData, $customGetListMethod, $addedfields
    ): array {
        throw new \RuntimeException('relation "orders" does not exist');
    }
}

/** Counts its own construction, to prove resolution is deferred. */
class CountingSource extends FakeSource
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
        parent::__construct([['id' => 1, 'username' => 'annak']]);
    }
}
