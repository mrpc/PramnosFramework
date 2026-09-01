<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Settings;
use Pramnos\Console\Commands\MakeCommandBase;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/**
 * Which columns a generated screen is allowed to show — and the one that matters is `password`.
 *
 * A generated CRUD renders every column it is handed **twice**: as a cell in the list, and as a
 * text input on the edit form. So for `users` an unfiltered generator would print the password
 * hash in an administration list — and offer it for editing, where typing over it writes a plain
 * string into the hash column and locks the account out. For `applications` it would print the API
 * secret.
 *
 * `editableColumns()` is what stands between the generator and that, and it had never run. It is a
 * name heuristic and cannot be complete — an application whose secret column is called something
 * else must exclude it in the generated file, which it owns — but the framework's own tables are
 * the ones it has to get right, and those are exactly what this asserts against.
 *
 * Beside it, the two "no database" paths. Both are ordinary at generation time — `create:crud` is
 * frequently run before the migration — and both must answer "no columns" rather than failing the
 * run, because a generator that dies on a missing table produces nothing at all instead of a
 * screen somebody can finish by hand.
 *
 * Both backends: {@see GeneratedScreenColumnsPostgreSQLTest} re-runs it. `getColumns()` reads
 * `information_schema` on one engine and `SHOW COLUMNS` on the other, so "which columns does this
 * table have" is two different queries returning the same answer.
 */
#[CoversClass(MakeCommandBase::class)]
class GeneratedScreenColumnsTest extends BaseTestCase
{
    private $db;

    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        Settings::loadSettings($this->settingsFixture());
        \Pramnos\Application\Application::getInstance();

        $reference = &\Pramnos\Database\Database::getInstance();
        $reference = null;
        $this->db  = Factory::getDatabase();
        if (!$this->db->connected) {
            $this->db->connect();
        }
        if (!$this->db->connected) {
            $this->markTestSkipped('The database for this backend is not reachable.');
        }

        \Pramnos\User\User::setupDb();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── What a generated screen may show ──────────────────────────────────────

    /**
     * The password hash is not among the columns a generated screen gets.
     *
     * The assertion this class exists for. `users` is the table somebody scaffolds first, and the
     * generator writes each column into a list cell **and** an edit input. Printing the hash is a
     * disclosure; offering it for editing is a lockout, because a typed string is not a hash and
     * the account can no longer sign in.
     */
    public function testThePasswordHashIsNeverOfferedForEditing(): void
    {
        // Arrange
        $probe = $this->probe();

        // Act
        $columns = $probe->reachEditableColumns('users');

        // Assert
        $this->assertNotSame([], $columns, 'no columns at all — the introspection did not run');
        $this->assertNotContains('password', $columns, 'the password hash reached a generated screen');
        $this->assertContains('username', $columns, 'an ordinary column was excluded too');
    }

    /**
     * Nor is anything else on the secrets list, for the tables that have them.
     *
     * Asserted against the whole list rather than one name, because the protection is the list:
     * a column added to the framework's schema whose name is already on it is covered without
     * anybody remembering, and one that is not has to be excluded by hand in the generated file.
     */
    public function testNothingOnTheSecretsListIsOffered(): void
    {
        // Arrange
        $probe  = $this->probe();
        $secret = [
            'password', 'passwd', 'pass', 'salt', 'secret',
            'apikey', 'api_key', 'apisecret', 'api_secret',
            'token', 'accesstoken', 'access_token',
            'refreshtoken', 'refresh_token', 'remember_token',
            'private_key', 'privatekey', 'code_challenge',
            'twofactor_secret', 'twofactorsecret', 'backup_codes',
        ];

        // Act & Assert
        foreach (['users', 'usertokens'] as $table) {
            $columns = $probe->reachEditableColumns($table);

            foreach ($secret as $name) {
                $this->assertNotContains(
                    $name,
                    $columns,
                    $table . '.' . $name . ' would be printed and offered for editing'
                );
            }
        }
    }

    /**
     * The timestamps the model maintains are not offered either.
     *
     * Not a secret — just meaningless to type over. A form that offers `created` invites somebody
     * to change when a record was created, and the model overwrites it on the next save anyway,
     * so the field is a lie either way.
     */
    public function testTheModelsOwnTimestampsAreNotOffered(): void
    {
        // Act
        $columns = $this->probe()->reachEditableColumns('users');

        // Assert
        foreach (['created', 'updated', 'createdate', 'updatedate'] as $maintained) {
            $this->assertNotContains($maintained, $columns);
        }
    }

    // ── When there is nothing to introspect ───────────────────────────────────

    /**
     * A table that does not exist yet yields no columns, and no failure.
     *
     * `create:crud` before the migration is the ordinary order — somebody scaffolds the screen,
     * then writes the table. Dying here would produce no files at all; answering "no columns"
     * produces a screen that needs its fields filled in, which is a much better place to be.
     */
    public function testATableThatDoesNotExistYieldsNoColumns(): void
    {
        // Act & Assert
        $this->assertSame([], $this->probe()->reachEditableColumns('no_such_table_at_all'));
    }

    /**
     * And the search-column guess does the same.
     *
     * Its caller says so when this answers nothing, which is the point: a wrong guess is
     * recoverable because the registration is a line in a file the developer owns, while a wrong
     * guess made *silently* is not.
     */
    public function testTheSearchColumnGuessAlsoSurvivesAMissingTable(): void
    {
        // Act & Assert
        $this->assertSame([], $this->probe()->reachSearchColumns('NoSuchEntity'));
    }

    /**
     * Given a real table, it guesses at most two textual columns.
     *
     * Two, because a search result line has room for two and the guess is meant to be corrected:
     * the first textual columns that are not the key are the ones a person recognises a record by.
     */
    public function testTheSearchGuessTakesAtMostTwoTextualColumns(): void
    {
        // Arrange — point it at a table that exists, as `--table` would.
        $probe = $this->probe('users');

        // Act
        $columns = $probe->reachSearchColumns('User');

        // Assert
        $this->assertLessThanOrEqual(2, count($columns), 'more than a search line can show');

        foreach ($columns as $column) {
            $this->assertIsString($column);
            $this->assertNotSame('', $column, 'an unnamed column was offered as a search field');
        }
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The command with its two protected helpers reachable.
     *
     * No Symfony wiring: neither helper touches the input, the output or the application — they
     * ask the database what a table looks like and filter the answer. Constructing the real
     * command would drag in a console application this test has no use for.
     */
    private function probe(?string $table = null): object
    {
        return new class ('probe', $table) extends MakeCommandBase {
            public function __construct(string $name, ?string $table)
            {
                parent::__construct($name);
                $this->dbtable = $table;
            }

            public function reachEditableColumns(string $table): array
            {
                return $this->editableColumns($table);
            }

            public function reachSearchColumns(string $name): array
            {
                return $this->searchDisplayColumns($name);
            }
        };
    }
}
