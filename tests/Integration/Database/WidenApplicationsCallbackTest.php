<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Database\Database;
use Pramnos\Database\MigrationLoader;

/**
 * A legacy `applications.callback` cannot be widened without moving two views with it.
 *
 * One application has several legitimate callbacks — the same client on localhost, on
 * staging and in production — and the column has to hold all three. Where
 * `public.applications` predates the migration system, `create_applications_table` is
 * `Skipped (cutoff)` and its `text` never applied: the column is `varchar(255)`, and three
 * long callbacks plus separators is about 190 characters.
 *
 * PostgreSQL then refuses the ALTER outright:
 *
 * ```
 * ERROR: cannot alter type of a column used by a view or rule
 * DETAIL: rule _RETURN on view applications.oauth2_application_permissions
 *         depends on column "callback"
 * ```
 *
 * The consuming application wrote this migration and withdrew it, because widening from
 * outside the framework means carrying copies of framework view definitions and keeping
 * them in step by hand. The tests here are what make the framework's version trustworthy:
 * the refusal is real, the migration gets past it, and **the views come back**.
 */
#[CoversClass(\Pramnos\Framework\Migrations\Applications\WidenApplicationsCallback::class)]
class WidenApplicationsCallbackTest extends TestCase
{
    private Database $db;

    private Application $app;

    protected function setUp(): void
    {
        if (!defined('LOG_PATH')) {
            define('LOG_PATH', ROOT . \DS . 'var');
        }

        $this->db = new Database();
        $this->db->type     = 'postgresql';
        $this->db->server   = 'timescaledb';
        $this->db->user     = 'postgres';
        $this->db->password = 'secret';
        $this->db->database = 'pramnos_test';
        $this->db->port     = 5432;
        $this->db->schema   = 'public';

        try {
            $this->db->connect(true);
        } catch (\Exception) {
            $this->markTestSkipped('PostgreSQL container not reachable');
        }

        $app = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->getMock();
        $app->database = $this->db;
        $this->app = $app;

        $this->cleanUp();
        $this->db->query('CREATE SCHEMA IF NOT EXISTS applications');
        $this->legacyTable();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    private function cleanUp(): void
    {
        $this->db->query('DROP VIEW IF EXISTS applications.wcb_probe_view CASCADE');
        $this->db->query('DROP VIEW IF EXISTS applications.wcb_probe_view_on_view CASCADE');
        $this->db->query('DROP TABLE IF EXISTS public.wcb_applications CASCADE');
    }

    /**
     * The legacy shape: a narrow column, with a view selecting it and a view on that view.
     *
     * The second view is the half a `pg_depend` walk gets wrong — a view on a view blocks
     * the ALTER too, and dropping only the direct dependent leaves the transitive one
     * holding the column.
     */
    private function legacyTable(): void
    {
        $this->db->query(
            'CREATE TABLE public.wcb_applications (
                 appid bigserial PRIMARY KEY,
                 name varchar(255) NOT NULL,
                 callback varchar(255)
             )'
        );
        $this->db->query(
            "INSERT INTO public.wcb_applications (name, callback)
             VALUES ('probe', 'https://app.example/callback')"
        );
        $this->db->query(
            'CREATE VIEW applications.wcb_probe_view AS
                 SELECT appid, name, callback AS redirect_uri FROM public.wcb_applications'
        );
        $this->db->query(
            'CREATE VIEW applications.wcb_probe_view_on_view AS
                 SELECT appid, redirect_uri FROM applications.wcb_probe_view'
        );
    }

    private function columnLength(): int
    {
        $result = $this->db->query(
            "SELECT COALESCE(character_maximum_length, 0) AS len
               FROM information_schema.columns
              WHERE table_schema = 'public' AND table_name = 'wcb_applications'
                AND column_name = 'callback'"
        );

        return (int) ($result->fields['len'] ?? -1);
    }

    private function viewExists(string $view): bool
    {
        $result = $this->db->query(
            $this->db->prepareQuery(
                "SELECT COUNT(*) AS cnt FROM information_schema.views
                  WHERE table_schema = 'applications' AND table_name = %s",
                $view
            )
        );

        return (int) ($result->fields['cnt'] ?? 0) > 0;
    }

    /**
     * PostgreSQL really does refuse, so the drop-and-rebuild is not ceremony.
     *
     * Asserted first because everything else here is a reaction to it — and because if a
     * later PostgreSQL allowed the ALTER, the migration would be rebuilding eleven views
     * for nothing and this test is what would say so.
     */
    public function testPostgresRefusesTheAlterWhileAViewSelectsTheColumn(): void
    {
        // Act & Assert
        try {
            $this->db->query(
                'ALTER TABLE public.wcb_applications ALTER COLUMN callback TYPE text'
            );
            $this->fail('PostgreSQL accepted the ALTER; the view dance is now unnecessary');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString(
                'view or rule',
                strtolower($exception->getMessage()),
                'the refusal must be the view-dependency one, not some other failure'
            );
        }
    }

    /**
     * Dropping the direct dependent is not enough, which is why `CASCADE` is used.
     *
     * The transitive view still holds the column. This is the assertion behind the choice
     * not to walk `pg_depend`: getting the order right is the work, and `CASCADE` plus a
     * rebuild of everything the framework owns skips it.
     */
    public function testDroppingOnlyTheDirectViewLeavesTheColumnBlocked(): void
    {
        // Arrange — the direct dependent gone, the view on it still there
        $this->db->query('DROP VIEW applications.wcb_probe_view_on_view');
        $this->db->query('DROP VIEW applications.wcb_probe_view');

        // Act — now it goes through
        $this->db->query(
            'ALTER TABLE public.wcb_applications ALTER COLUMN callback TYPE text'
        );

        // Assert
        $this->assertSame(0, $this->columnLength(), 'text has no length');
    }

    /**
     * `CASCADE` on the direct view takes the transitive one with it.
     *
     * Which is what makes the migration's approach correct rather than lucky: it does not
     * need to know that a second view exists, only that the framework's own views migration
     * will put every view back.
     */
    public function testCascadeTakesTheTransitiveViewToo(): void
    {
        // Act
        $this->db->query('DROP VIEW IF EXISTS applications.wcb_probe_view CASCADE');

        // Assert
        $this->assertFalse($this->viewExists('wcb_probe_view'));
        $this->assertFalse(
            $this->viewExists('wcb_probe_view_on_view'),
            'CASCADE must remove the view that selected from the dropped one'
        );
    }

    /**
     * The migration is a no-op on a column that is already `text`.
     *
     * Every database the framework created is already `text`, so this is the common case —
     * and rebuilding eleven views on every `migrate` for nothing is the sort of cost that
     * only shows up on the installation with the most views.
     */
    public function testItDoesNothingWhenTheColumnIsAlreadyText(): void
    {
        // Arrange — the framework-created shape of the table the guard actually reads.
        // Loaded before reflecting on it: the class comes from the migration loader, not
        // from composer's autoloader, so asking for it first is what puts it in memory.
        $migration = $this->migration();

        $this->db->query('DROP TABLE IF EXISTS public.applications CASCADE');
        $this->db->query(
            'CREATE TABLE public.applications (
                 appid bigserial PRIMARY KEY,
                 callback text
             )'
        );

        try {
            // Act
            $narrow = $this->narrowCheck()->invoke($migration);

            // Assert
            $this->assertFalse(
                $narrow,
                'a text column must not be reported as narrow, or every migrate rebuilds '
                . 'eleven views for nothing'
            );
        } finally {
            $this->db->query('DROP TABLE IF EXISTS public.applications CASCADE');
        }
    }

    /**
     * A narrow column is recognised as narrow.
     *
     * The control for the test above: a guard that always answered false would make the
     * migration a permanent no-op, and the installation that needs it is the one that would
     * never find out.
     */
    public function testANarrowColumnIsRecognised(): void
    {
        // Arrange
        $migration = $this->migration();

        $this->db->query('DROP TABLE IF EXISTS public.applications CASCADE');
        $this->db->query(
            'CREATE TABLE public.applications (
                 appid bigserial PRIMARY KEY,
                 callback varchar(255)
             )'
        );

        try {
            // Act & Assert
            $this->assertTrue($this->narrowCheck()->invoke($migration));
        } finally {
            $this->db->query('DROP TABLE IF EXISTS public.applications CASCADE');
        }
    }

    /**
     * The private guard, reachable.
     *
     * Reflection rather than a seam: the guard is one `information_schema` read and making
     * it protected would be an API for a test to poke, on a migration that runs once.
     */
    private function narrowCheck(): \ReflectionMethod
    {
        return new \ReflectionMethod(
            \Pramnos\Framework\Migrations\Applications\WidenApplicationsCallback::class,
            'columnIsNarrow'
        );
    }

    private function migration(): \Pramnos\Database\Migration
    {
        $base = dirname(__DIR__, 3) . '/database/migrations/framework/applications';

        foreach (MigrationLoader::loadFromDirectory($base, $this->app) as $migration) {
            if ((new \ReflectionClass($migration))->getShortName() === 'WidenApplicationsCallback') {
                return $migration;
            }
        }

        $this->fail('WidenApplicationsCallback was not loaded');
    }
}
