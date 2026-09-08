<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;
use Pramnos\Database\QueryException;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;

/** A model over a table with a unique constraint, so a second save collides. */
class SaveFailureProbeModel extends Model
{
    protected $modelname = 'savefailureprobe';

    protected $_primaryKey = 'probeid';

    public $probeid;

    public $email;

    public function __construct(?string $table = null)
    {
        if ($table !== null) {
            $this->_dbtable = $table;
        }
    }

    /** `_save()` is protected; an application exposes what it needs. */
    public function store(): static
    {
        $this->_save();

        return $this;
    }
}

/**
 * The driver's message must not be what a failed save throws.
 *
 * Reported after an authorization server answered it to an API client verbatim as an OAuth
 * `error_description`, and the customer read their own schema out of an HTTP 400. On
 * PostgreSQL a unique-constraint violation names the table, the index and the colliding
 * value; on MySQL it names the index and the value.
 *
 * A unit test can assert what `QueryException` does with a string. Only this can assert
 * what a **real driver** produces on a **real constraint** actually reaches — which is the
 * half the report was about, since the leak was the driver's own text and nobody wrote it.
 */
#[CoversClass(Model::class)]
#[CoversClass(QueryException::class)]
class ModelSaveHidesTheDriverMessageTest extends BaseTestCase
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

        $this->table = 'savefailureprobe_' . bin2hex(random_bytes(4));
        $this->createTable();
    }

    protected function tearDown(): void
    {
        if ($this->table !== '') {
            $this->db->query('DROP TABLE IF EXISTS ' . $this->quoted($this->table));
        }

        parent::tearDown();
    }

    /** Which connection this class runs against; the PostgreSQL subclass returns the other. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    private function quoted(string $name): string
    {
        return $this->db->type === 'postgresql' ? '"' . $name . '"' : '`' . $name . '`';
    }

    /** The index name is asserted on, so it is spelled out here rather than generated. */
    private function indexName(): string
    {
        return 'uq_' . $this->table . '_email';
    }

    private function createTable(): void
    {
        $q = fn(string $n): string => $this->quoted($n);

        if ($this->db->type === 'postgresql') {
            $this->db->query(
                'CREATE TABLE ' . $q($this->table) . ' ('
                . $q('probeid') . ' bigserial PRIMARY KEY, '
                . $q('email') . ' varchar(255) NOT NULL)'
            );
            $this->db->query(
                'CREATE UNIQUE INDEX ' . $q($this->indexName())
                . ' ON ' . $q($this->table) . ' (' . $q('email') . ')'
            );

            return;
        }

        $this->db->query(
            'CREATE TABLE ' . $q($this->table) . ' ('
            . $q('probeid') . ' BIGINT AUTO_INCREMENT PRIMARY KEY, '
            . $q('email') . ' VARCHAR(255) NOT NULL, '
            . 'UNIQUE KEY ' . $q($this->indexName()) . ' (' . $q('email') . '))'
        );
    }

    /** Save the same address twice; the second one collides. */
    private function collide(): QueryException
    {
        $first = new SaveFailureProbeModel($this->table);
        $first->email = 'taken@example.com';
        $first->store();

        $second = new SaveFailureProbeModel($this->table);
        $second->email = 'taken@example.com';

        try {
            $second->store();
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('the duplicate save did not raise a QueryException');
    }

    /**
     * It is a `QueryException`, which is the whole of the ask.
     *
     * `\Exception` is what a hand-written refusal throws *and* what a failed insert threw,
     * so a consumer's `catch` could not separate them — and masking the catch-all instead
     * turned legitimate refusals into "something went wrong". The type is what makes both
     * call sites correct in one line each.
     */
    public function testAFailedSaveThrowsAQueryException(): void
    {
        // Act
        $exception = $this->collide();

        // Assert
        $this->assertInstanceOf(QueryException::class, $exception);
    }

    /**
     * And its message names neither the table nor the index nor the value.
     *
     * The three things the driver volunteers and the three things the customer read back.
     */
    public function testTheMessageCarriesNoSchema(): void
    {
        // Act
        $message = $this->collide()->getMessage();

        // Assert
        $this->assertStringNotContainsString($this->table, $message, 'the table name leaked');
        $this->assertStringNotContainsString($this->indexName(), $message, 'the index name leaked');
        $this->assertStringNotContainsString('taken@example.com', $message, 'the value leaked');
        $this->assertStringNotContainsString('INSERT', $message);
    }

    /**
     * The message says what could not be done, in the application's own vocabulary.
     *
     * A safe message still has to be worth printing: «Could not save …» names the model
     * class, which is what an application calls the thing, not what its schema does.
     */
    public function testTheMessageSaysWhatFailed(): void
    {
        // Act
        $message = $this->collide()->getMessage();

        // Assert
        $this->assertStringContainsString('Could not save', $message);
        $this->assertStringContainsString('SaveFailureProbeModel', $message);
    }

    /**
     * The driver's own diagnosis is still available to whoever logs it.
     *
     * Without this the change would trade an unsafe state for an undiagnosable one: the
     * index name and the colliding value are exactly what an operator needs.
     */
    public function testTheDriverDiagnosisIsAvailableForALog(): void
    {
        // Act
        $exception = $this->collide();

        // Assert — every driver names the constraint it refused
        $this->assertNotSame('', $exception->getDriverMessage());
        $this->assertStringContainsString(
            $this->indexName(),
            $exception->getDriverMessage(),
            "the driver's text no longer identifies which constraint was violated"
        );
        $this->assertStringContainsString($this->indexName(), $exception->getDetail());
    }
}
