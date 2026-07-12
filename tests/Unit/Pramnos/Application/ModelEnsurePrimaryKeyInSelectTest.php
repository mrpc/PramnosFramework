<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Application\Controller;
use Pramnos\Application\Model;
use Pramnos\Application\Settings;

/**
 * Unit tests for Model::_ensurePrimaryKeyInSelect().
 *
 * This private helper guarantees that the primary key is part of the SELECT list
 * of generated list/API queries (needed so hydration can key rows by their id).
 *
 * Why this matters — the regression these tests guard against:
 * generated queries qualify AND quote the fields (e.g. a.`id`, a.`name`). The
 * original implementation compared the raw field token against the primary key
 * with the identifier quotes still attached, so a.`id` was NOT recognised as the
 * already-present primary key. It therefore prepended a bare, un-qualified `id`
 * to the SELECT. When the query JOINed a table that also owns an `id` column,
 * the database raised "column reference 'id' is ambiguous" and the whole query
 * collapsed to zero rows — silently, from the caller's perspective.
 *
 * The two invariants locked here:
 *  1. A quoted / alias-qualified PK already in the field list is detected and the
 *     field list is returned unchanged (no duplicate, no bare column added).
 *  2. When the PK is genuinely absent it is prepended *qualified* to the primary
 *     table alias, so it can never collide with a same-named joined column.
 */
#[CoversClass(Model::class)]
class ModelEnsurePrimaryKeyInSelectTest extends TestCase
{
    /**
     * Load the test settings so that Model's constructor (which calls
     * Database::getInstance()) resolves against a valid configuration instead of
     * leaking a settings-less singleton into sibling DB-backed tests. No database
     * connection is opened — these tests only exercise pure string logic.
     */
    protected function setUp(): void
    {
        if (!defined('CONFIG')) {
            define('CONFIG', 'tests' . DS . 'fixtures' . DS . 'app');
        }
        $settingsFile = ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
        Settings::loadSettings($settingsFile);
        Application::getInstance();
    }

    /**
     * Builds a Model with a mocked controller and returns a closure that invokes
     * the private _ensurePrimaryKeyInSelect() through reflection.
     */
    private function invoker(): callable
    {
        /** @var Controller&\PHPUnit\Framework\MockObject\MockObject $controller */
        $controller = $this->getMockBuilder(Controller::class)
            ->disableOriginalConstructor()
            ->getMock();
        $model = new Model($controller, 'Item');

        $method = new \ReflectionMethod($model, '_ensurePrimaryKeyInSelect');

        return static function (?string $fields, string $pk) use ($model, $method) {
            return $method->invoke($model, $fields, $pk);
        };
    }

    /**
     * A backtick-quoted, alias-qualified PK already present must be recognised —
     * the field list is returned verbatim with no bare column prepended.
     *
     * Proves the fix for the ambiguous-column regression: this exact input
     * (a.`id` alongside a joined b.`id`) previously produced a duplicated bare
     * `id` and an ambiguous SELECT.
     */
    public function testBacktickQuotedQualifiedPrimaryKeyIsDetected(): void
    {
        // Arrange
        $ensure = $this->invoker();

        // Act
        $result = $ensure('a.`id`, a.`name`, b.`id` AS `b_id`', 'id');

        // Assert — unchanged: the PK was already there
        $this->assertSame('a.`id`, a.`name`, b.`id` AS `b_id`', $result);
    }

    /**
     * The PostgreSQL double-quote variant of the same case must also be detected.
     */
    public function testDoubleQuotedQualifiedPrimaryKeyIsDetected(): void
    {
        // Arrange
        $ensure = $this->invoker();

        // Act
        $result = $ensure('a."id", a."name"', 'id');

        // Assert
        $this->assertSame('a."id", a."name"', $result);
    }

    /**
     * When the PK is genuinely absent from the field list it is prepended.
     *
     * This is the unchanged legacy behaviour — the fix only affects *detection*
     * of an already-present quoted PK, not the prepend path.
     */
    public function testAbsentPrimaryKeyIsPrepended(): void
    {
        // Arrange
        $ensure = $this->invoker();

        // Act
        $result = $ensure('a.`name`, a.`status`', 'id');

        // Assert — PK prepended ahead of the existing fields
        $this->assertSame('id, a.`name`, a.`status`', $result);
    }

    /**
     * SELECT * and empty field lists are passed straight through — there is no
     * field list to inspect or extend.
     */
    public function testWildcardAndEmptyPassThroughUnchanged(): void
    {
        // Arrange
        $ensure = $this->invoker();

        // Act & Assert
        $this->assertSame('*', $ensure('*', 'id'));
        $this->assertSame('*', $ensure(null, 'id'));
        $this->assertSame('', $ensure('', 'id'));
    }

    /**
     * An unquoted, un-qualified PK already present is still detected (baseline
     * behaviour must not regress).
     */
    public function testBarePrimaryKeyAlreadyPresentIsDetected(): void
    {
        // Arrange
        $ensure = $this->invoker();

        // Act
        $result = $ensure('id, name, email', 'id');

        // Assert
        $this->assertSame('id, name, email', $result);
    }
}
