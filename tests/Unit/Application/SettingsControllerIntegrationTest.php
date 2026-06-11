<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Application\Controllers;

use PHPUnit\Framework\TestCase;
use Pramnos\Application\Controllers\SettingsController;
use Pramnos\Database\Database;
use Pramnos\Database\QueryBuilder;
use Pramnos\Application\Settings;

class TestableSettingsController extends SettingsController
{
    protected function requireMinUserType(int $minType): bool
    {
        return false; // bypass for tests
    }

    protected function terminate(): void
    {
        // Do nothing in tests to avoid exit;
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        echo "REDIRECTED_TO:" . $url;
    }
    
    public function renderLayout(string $activeTab, string $content): void
    {
        echo $content;
    }

    public function &getView($name = '', $type = '', $args = [])
    {
        $view = new #[\AllowDynamicProperties] class($name) {
            public $settings = [];
            public $key = '';
            public $value = '';
            public $isNew = false;
            
            public function __construct($name) { 
                $this->name = $name;
            }
            public function display(string $layout = 'default', bool $return = false, bool $outputBuffer = true): mixed
            {
                $out = "";
                if ($layout === 'default') {
                    $out = "Settings System Display";
                } elseif ($layout === 'list') {
                    $out = "Settings List Display";
                } elseif ($layout === 'edit') {
                    if ($this->isNew) {
                        $out = "Edit New Setting";
                    } else {
                        $out = "Edit Setting: " . $this->key . " = " . $this->value;
                    }
                }
                
                if ($return) {
                    return $out;
                }
                echo $out;
                return true;
            }
            public function assign(string $key, mixed $val): void
            {
                $this->$key = $val;
            }
        };
        return $view;
    }
}

class SettingsControllerIntegrationTest extends TestCase
{
    private TestableSettingsController $controller;
    private $dbMock;
    private $queryBuilderMock;
    private $originalDb;

    protected function setUp(): void
    {
        \Pramnos\Http\Session::getInstance();

        // Save original database reference
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $this->originalDb = clone $dbRef;

        // Mock QueryBuilder
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->queryBuilderMock->method('table')->willReturnSelf();
        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')->willReturnSelf();

        // Mock Database
        $this->dbMock = $this->createMock(Database::class);
        $this->dbMock->method('queryBuilder')->willReturn($this->queryBuilderMock);
        $this->dbMock->method('prepareQuery')->willReturn('MOCKED_QUERY');
        
        $mockDbResult = new \stdClass();
        $mockDbResult->numRows = 0;
        $mockDbResult->fields = [];
        $this->dbMock->method('query')->willReturn($mockDbResult);

        // Inject Database via reference
        $dbRef = $this->dbMock;
        
        // Inject Database into Settings
        Settings::setDatabase($this->dbMock, false);

        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->controller = new TestableSettingsController(null);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];

        // Reset DB singleton to null so subsequent tests get a fresh real connection
        // (a cloned DB object does not reliably preserve the mysqli connection resource).
        $dbRef = &\Pramnos\Database\Database::getInstance();
        $dbRef = null;
        Settings::clearSettings();
    }

    public function testDisplay()
    {
        ob_start();
        $this->controller->display();
        $echoed = ob_get_clean();

        $this->assertIsString($echoed);
        $this->assertStringContainsString('Settings System Display', $echoed);
    }

    public function testSaveSystem()
    {
        $_POST['sitename'] = 'My Test Site';
        $_POST['debug'] = 'yes';
        
        ob_start();
        $this->controller->saveSystem();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('My Test Site', Settings::getSetting('sitename'));
        $this->assertEquals('yes', Settings::getSetting('debug'));
    }

    public function testList()
    {
        $this->queryBuilderMock->method('getAll')->willReturn([
            ['setting' => 'test_key', 'value' => 'test_val']
        ]);

        ob_start();
        $this->controller->list();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Settings List Display', $echoed);
    }

    public function testEditNew()
    {
        $_GET['_option'] = ''; // new setting

        ob_start();
        $this->controller->edit();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Edit New Setting', $echoed);
    }

    public function testEditExisting()
    {
        $_GET['_option'] = 'existing_key';
        Settings::setSetting('existing_key', 'existing_val', false);

        ob_start();
        $this->controller->edit();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('Edit Setting: existing_key = existing_val', $echoed);
    }

    public function testEditReadonly()
    {
        $_GET['_option'] = 'hostname'; // protected

        ob_start();
        $this->controller->edit();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('This setting is read-only and cannot be modified.', $_SESSION['settings_error']);
    }

    public function testSave()
    {
        $_POST['key'] = 'new_key';
        $_POST['value'] = 'new_val';

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('new_val', Settings::getSetting('new_key'));
    }

    public function testSaveEmptyKey()
    {
        $_POST['key'] = '';

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('Setting key must not be empty.', $_SESSION['settings_error']);
    }

    public function testSaveReadonly()
    {
        $_POST['key'] = 'hostname'; // protected

        ob_start();
        $this->controller->save();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('This setting is read-only and cannot be modified.', $_SESSION['settings_error']);
    }

    public function testDelete()
    {
        $_GET['_option'] = 'some_key';

        ob_start();
        $this->controller->delete();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
    }

    // ── Normalisation helpers (reflection — protected methods) ────────────────

    /** Invoke a protected normalise helper on the controller. */
    private function callProtected(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionMethod(SettingsController::class, $method);
        return $ref->invokeArgs($this->controller, $args);
    }

    /**
     * normalizeYesNo() must map any casing/whitespace of "yes" to 'yes' and
     * everything else to 'no' — settings booleans are stored as yes/no strings.
     */
    public function testNormalizeYesNo(): void
    {
        // Act + Assert — each input maps to the expected canonical value
        $this->assertSame('yes', $this->callProtected('normalizeYesNo', 'yes'));
        $this->assertSame('yes', $this->callProtected('normalizeYesNo', ' YES '));
        $this->assertSame('no',  $this->callProtected('normalizeYesNo', 'no'));
        $this->assertSame('no',  $this->callProtected('normalizeYesNo', 'true'));
        $this->assertSame('no',  $this->callProtected('normalizeYesNo', ''));
    }

    /**
     * normalizeIntRange() must clamp into [min,max] and substitute the default
     * for blank input — protects SMTP port / lockout window from garbage.
     */
    public function testNormalizeIntRange(): void
    {
        // Act + Assert
        $this->assertSame(25,    $this->callProtected('normalizeIntRange', '', 1, 65535, 25),
            'Blank input must yield the default');
        $this->assertSame(1,     $this->callProtected('normalizeIntRange', '-5', 1, 65535, 25),
            'Below-min input must clamp to min');
        $this->assertSame(65535, $this->callProtected('normalizeIntRange', '99999999', 1, 65535, 25),
            'Above-max input must clamp to max');
        $this->assertSame(587,   $this->callProtected('normalizeIntRange', '587', 1, 65535, 25),
            'In-range input must pass through');
    }

    /**
     * normalizeLoginLockoutSteps() with an empty payload must fall back to the
     * defaults and record an error message in the by-reference errors array.
     */
    public function testNormalizeLockoutStepsEmptyFallsBackToDefaults(): void
    {
        // Arrange — invokeArgs() cannot pass by reference; use a closure bound
        // to the controller so the &$errors parameter works naturally.
        $errors = [];
        $call = \Closure::bind(
            function (string $value, ?array &$err) {
                return $this->normalizeLoginLockoutSteps($value, $err);
            },
            $this->controller,
            SettingsController::class
        );

        // Act
        $json = $call('   ', $errors);

        // Assert — defaults returned, error recorded
        $this->assertJson($json);
        $this->assertNotEmpty(json_decode($json, true));
        $this->assertNotEmpty($errors, 'An error message must be recorded for empty input');
    }

    /**
     * Invalid JSON (or JSON without positive thresholds/durations) must also
     * fall back to the defaults.
     */
    public function testNormalizeLockoutStepsInvalidJsonFallsBack(): void
    {
        // Act
        $jsonGarbage   = $this->callProtected('normalizeLoginLockoutSteps', 'not-json{{{');
        $jsonNegatives = $this->callProtected('normalizeLoginLockoutSteps', '{"-1": -5, "0": 0}');

        // Assert — both return the (non-empty) default step map
        $this->assertJson($jsonGarbage);
        $this->assertNotEmpty(json_decode($jsonGarbage, true));
        $this->assertSame($jsonGarbage, $jsonNegatives,
            'Garbage JSON and all-invalid entries must both produce the same defaults');
    }

    /**
     * Durations that do not strictly increase with the threshold must be
     * rejected (defaults returned) — a higher attempt count must never lock
     * for a shorter time.
     */
    public function testNormalizeLockoutStepsNonIncreasingRejected(): void
    {
        // Arrange — 5 attempts → 600s but 10 attempts → 60s (decreasing)
        $input = json_encode([5 => 600, 10 => 60]);

        // Act
        $json = $this->callProtected('normalizeLoginLockoutSteps', $input);

        // Assert — the invalid map was replaced by the defaults
        $decoded = json_decode($json, true);
        $this->assertNotEquals([5 => 600, 10 => 60], $decoded,
            'Non-increasing durations must not be accepted');
    }

    /**
     * A valid, increasing step map must pass through normalised (sorted by
     * threshold, ints cast).
     */
    public function testNormalizeLockoutStepsValidMapPassesThrough(): void
    {
        // Arrange — out-of-order keys, string values
        $input = json_encode([10 => '900', 3 => '60', 5 => '300']);

        // Act
        $json = $this->callProtected('normalizeLoginLockoutSteps', $input);

        // Assert — sorted ascending by threshold with int values
        $this->assertSame([3 => 60, 5 => 300, 10 => 900], json_decode($json, true));
    }

    // ── saveSystem() branches ─────────────────────────────────────────────────

    /**
     * saveSystem() with an allowed settings_active_tab must redirect back to
     * the same tab anchor so the user stays on the tab they edited.
     */
    public function testSaveSystemRedirectsToActiveTab(): void
    {
        // Arrange
        $_POST = [
            'sitename'            => 'Tab Site',
            'settings_active_tab' => 'settings-tab-email',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Act
        ob_start();
        $this->controller->saveSystem();
        $echoed = ob_get_clean();

        // Assert — redirect carries the tab anchor
        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertStringContainsString('#settings-tab-email', $echoed);
    }

    /**
     * saveSystem() with invalid lockout steps must store the safe defaults and
     * leave a warning message in the session for the next page render.
     */
    public function testSaveSystemRecordsLockoutWarning(): void
    {
        // Arrange — lockout steps that fail validation
        $_POST = [
            'sitename'          => 'X',
            'loginlockoutsteps' => 'garbage-not-json',
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Act
        ob_start();
        $this->controller->saveSystem();
        ob_end_clean();

        // Assert — warning recorded alongside the success flash
        $this->assertArrayHasKey('settings_warning', $_SESSION);
        $this->assertStringContainsString('safe defaults', $_SESSION['settings_warning']);
        $this->assertSame('Settings saved.', $_SESSION['settings_success'] ?? '');
        unset($_SESSION['settings_warning'], $_SESSION['settings_success']);
    }

    /**
     * saveSystem() without loginlockoutsteps in the POST (the __KEEP__ path)
     * must preserve the existing setting value untouched.
     */
    public function testSaveSystemKeepsExistingLockoutStepsWhenAbsent(): void
    {
        // Arrange — existing steps in Settings, no key in POST
        Settings::setSetting('loginlockoutsteps', '{"3":60}', false);
        $_POST = ['sitename' => 'Keep Site'];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Act
        ob_start();
        $this->controller->saveSystem();
        ob_end_clean();

        // Assert — the stored value survived the save
        $this->assertSame('{"3":60}', Settings::getSetting('loginlockoutsteps'));
        unset($_SESSION['settings_success']);
    }

    public function testDeleteReadonly()
    {
        $_GET['_option'] = 'hostname'; // protected

        ob_start();
        $this->controller->delete();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        // It shouldn't attempt to delete.
    }
}
