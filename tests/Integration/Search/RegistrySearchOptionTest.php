<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Search;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Database\Database;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Framework\Testing\Connection;
use Pramnos\Search\Registry;

/**
 * A source's own `search` rule and `title` pattern, against a real table.
 *
 * The rule replaces the model's search conditions, so what has to be proved is that the rows
 * that come back are the ones the rule selects — on each driver, where `LIKE` is spelled
 * differently and the conditions are escaped by the driver's own quoting.
 *
 * MySQL here; the PostgreSQL sibling runs the same cases.
 */
#[CoversClass(Registry::class)]
#[\PHPUnit\Framework\Attributes\Group('serial')]
class RegistrySearchOptionTest extends BaseTestCase
{
    private const TABLE = 'search_option_probe';

    private Database $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        Application::getInstance();

        $this->db = Connection::fresh(false);
        if (!$this->db->connected) {
            $this->markTestSkipped('The database is not reachable.');
        }

        $this->drop();
        $this->db->query(
            'CREATE TABLE ' . $this->quoted() . ' ('
            . $this->quote('id') . ' INT NOT NULL PRIMARY KEY, '
            . $this->quote('firstname') . ' VARCHAR(64) NULL, '
            . $this->quote('lastname') . ' VARCHAR(64) NULL, '
            . $this->quote('mobile') . ' VARCHAR(20) NULL)'
        );
        foreach ([[1, 'Anna', 'Papadopoulou', '6912345678'], [2, 'Nikos', 'Annas', '6987654321'], [3, 'Maria', "O''Neil", '6900000000']] as [$id, $first, $last, $mobile]) {
            $this->db->query(
                'INSERT INTO ' . $this->quoted() . ' VALUES (' . $id . ", '" . $first . "', '" . $last . "', '" . $mobile . "')"
            );
        }
        Model::$columnCache = [];

        Registry::reset();
        \Pramnos\Http\RequestIdentity::reset();
        \Pramnos\Http\RequestIdentity::seal((object) ['userid' => 2, 'usertype' => 99], 'test');
    }

    protected function tearDown(): void
    {
        Registry::reset();
        \Pramnos\Http\RequestIdentity::reset();
        if (isset($this->db) && $this->db->connected) {
            $this->drop();
        }
        Model::$columnCache = [];

        Settings::loadSettings(ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php');
        $reference = &Database::getInstance();
        $reference = null;

        parent::tearDown();
    }

    /** The settings file naming the driver under test. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /**
     * The application's rule decides the rows: a long number searches `mobile`, anything else
     * the names — and the title is built from the pattern, its columns selected without
     * `fields`.
     */
    public function testTheSearchRuleSelectsTheRowsAndThePatternTitlesThem(): void
    {
        // Arrange
        $this->register();

        // Act
        $byName   = Registry::query('anna');
        $byMobile = Registry::query('69876');

        // Assert — "anna" matches a first name and a last name, case-insensitively on both drivers
        $this->assertSame([1, 2], $this->ids($byName));
        $this->assertSame('#1: Papadopoulou Anna', $byName['groups'][0]['results'][0]['title']);
        $this->assertSame('6912345678', $byName['groups'][0]['results'][0]['subtitle']);
        $this->assertSame([2], $this->ids($byMobile), 'a number of more than four digits is a mobile search');
    }

    /** `false` from the rule: no group for this term, while other terms still answer. */
    public function testARuleThatAnswersFalseLeavesNoGroup(): void
    {
        // Arrange
        $this->register();

        // Act
        $result = Registry::query('a@b.gr');

        // Assert
        $this->assertSame([], $result['groups']);
        $this->assertSame([3], $this->ids(Registry::query('maria')), 'another term still answers');
    }

    /**
     * A quote in the term reaches the database as data, not as SQL.
     *
     * Asserted by the row it finds: a term that broke the statement would drop the source, and
     * "no group" would look the same as "no match".
     */
    public function testATermWithAQuoteIsEscaped(): void
    {
        // Arrange
        $this->register();

        // Act
        $result = Registry::query("o'neil");

        // Assert
        $this->assertSame([3], $this->ids($result));
    }

    /** Registers the probe with the rule and the title pattern. */
    private function register(): void
    {
        $db = $this->db;
        Registry::register('People', static function () use ($db): SearchOptionProbe {
            $controller = (new \ReflectionClass(Controller::class))->newInstanceWithoutConstructor();
            $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
            $app->database = $db;
            $controller->application = $app;

            return new SearchOptionProbe($controller);
        }, [
            'permission' => static fn ($user): bool => true,
            'title'      => '#:id: :lastname :firstname',
            'subtitle'   => ':mobile',
            'order'      => 'id',
            'search'     => static function (string $term): array|false {
                if (str_contains($term, '@')) {
                    return false;
                }
                if (preg_match('/^\d{5,}$/', $term)) {
                    return [['field' => 'mobile', 'op' => 'LIKE', 'value' => '%' . $term . '%']];
                }

                return [['or' => [
                    ['field' => 'firstname', 'op' => 'LIKE', 'value' => '%' . $term . '%'],
                    ['field' => 'lastname', 'op' => 'LIKE', 'value' => '%' . $term . '%'],
                ]]];
            },
        ]);
    }

    /**
     * The ids of the one group, sorted.
     *
     * @param array<string, mixed> $result
     * @return list<int>
     */
    private function ids(array $result): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $result['groups'][0]['results'] ?? []);
        sort($ids);

        return $ids;
    }

    private function quote(string $identifier): string
    {
        return $this->db->type === 'postgresql' ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }

    private function quoted(): string
    {
        return $this->quote($this->db->prefix . self::TABLE);
    }

    private function drop(): void
    {
        $this->db->query('DROP TABLE IF EXISTS ' . $this->quoted());
    }
}

/** The probe table as a model — what `Registry` searches. */
class SearchOptionProbe extends Model
{
    protected $_dbtable    = '#PREFIX#search_option_probe';
    protected $_primaryKey = 'id';
}
