<?php

declare(strict_types=1);

namespace Tests\Unit\Pramnos\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\ApiCrudController;
use Pramnos\Application\ApplicationClosedException;

/**
 * Two seams and an accessor that everything else in their neighbourhood is tested through.
 *
 * `legacyAclTable()` and `db()` exist so a test can point the legacy-permissions probe at a table
 * that certainly does not exist — the only state the bug they were extracted for was visible in,
 * and the state every new installation is in. Which is why they themselves had no covered line:
 * every test in that area overrides them.
 *
 * `ApplicationClosedException::getBody()` is what a test reads instead of the response a real
 * request would have sent. `close()` throws instead of exiting under PHPUnit, and this accessor is
 * the only way to see what the visitor would have got — so an exception carrying a body nobody can
 * read makes every one of those tests assert the status alone.
 */
#[CoversClass(ApiCrudController::class)]
#[CoversClass(ApplicationClosedException::class)]
class ApiCrudSeamsTest extends TestCase
{
    private function controller(): object
    {
        return new class extends ApiCrudController {
            public function __construct() {}

            public function exposeLegacyAclTable(): string
            {
                return $this->legacyAclTable();
            }

            public function exposeDb(): \Pramnos\Database\Database
            {
                return $this->db();
            }
        };
    }

    /**
     * The legacy table name honours `DB_PERMISSIONSTABLE`, and falls back to the prefixed default.
     *
     * An installation that renamed the table set that constant, and a probe reading the default
     * would report "no legacy permissions" for a table that is full of them — which is the wrong
     * answer in the direction that loses a migration.
     */
    public function testTheLegacyTableNameHonoursTheConstant(): void
    {
        // Act
        $table = $this->controller()->exposeLegacyAclTable();

        // Assert
        $expected = defined('DB_PERMISSIONSTABLE') ? DB_PERMISSIONSTABLE : '#PREFIX#permissions';
        $this->assertSame($expected, $table);
        $this->assertNotSame('', $table, 'a blank table name would make the probe query nothing');
    }

    /**
     * `db()` hands back the process's connection, not a new one.
     *
     * Identity, because the probe's behaviour is a dialect fact — PostgreSQL logs a failed select
     * where MySQL does not — so a seam that built its own connection could be talking to a
     * different backend than the test set up.
     */
    public function testTheConnectionIsTheProcessesOwn(): void
    {
        // Act + Assert
        $this->assertSame(
            \Pramnos\Database\Database::getInstance(),
            $this->controller()->exposeDb()
        );
    }

    /**
     * A closed application carries the body it would have sent.
     *
     * Under PHPUnit `close()` throws rather than exiting, and this is the only way to see what the
     * visitor would have received. Without it every test of a closing path asserts the status and
     * nothing about the page.
     */
    public function testAClosedApplicationCarriesItsBody(): void
    {
        // Arrange
        $exception = new ApplicationClosedException('the message', 404, '<h1>404</h1>');

        // Act + Assert
        $this->assertSame('<h1>404</h1>', $exception->getBody());
    }

    /**
     * With no body, it is an empty string rather than `null`.
     *
     * Callers concatenate and search it — `assertStringContainsString()` on `null` is a
     * TypeError, which would be a test failing on its own assertion rather than on the code.
     */
    public function testWithNoBodyItIsAnEmptyString(): void
    {
        // Arrange
        $exception = new ApplicationClosedException('the message');

        // Act + Assert
        $this->assertSame('', $exception->getBody());
    }
}
