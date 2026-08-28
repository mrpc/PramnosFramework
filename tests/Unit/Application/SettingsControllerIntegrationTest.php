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
    /** The last view this controller built, so a test can read what it was given. */
    public $lastView = null;

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
        $this->lastView = $view;

        return $view;
    }
}

/**
 * The same controller with the guard intact.
 *
 * `TestableSettingsController` bypasses `requireMinUserType()` so the action
 * bodies can be exercised — it has always had that override, which is telling:
 * somebody expected the floor to be there, and it was not.
 */
class GuardedSettingsController extends SettingsController
{
    public array $redirectedTo = [];

    protected function terminate(): void
    {
    }

    public function redirect($url = null, $quit = true, $code = '302')
    {
        $this->redirectedTo[] = $url;
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

        // Mock QueryBuilder. Settings reads and writes through the builder now
        // — it used to hand-build SQL, which is how MySQL backticks reached
        // PostgreSQL — so the chain it calls has to be stubbed here too, or the
        // first unstubbed method returns null and the next call fatals on it.
        $this->queryBuilderMock = $this->createMock(QueryBuilder::class);
        $this->queryBuilderMock->method('table')->willReturnSelf();
        $this->queryBuilderMock->method('select')->willReturnSelf();
        $this->queryBuilderMock->method('orderBy')->willReturnSelf();
        $this->queryBuilderMock->method('where')->willReturnSelf();
        $this->queryBuilderMock->method('limit')->willReturnSelf();
        $this->queryBuilderMock->method('exists')->willReturn(false);
        $this->queryBuilderMock->method('insert')->willReturn(true);
        $this->queryBuilderMock->method('update')->willReturn(true);
        $this->queryBuilderMock->method('delete')->willReturn(true);

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

        ob_start();
        $this->controller->saveSystem();
        $echoed = ob_get_clean();

        $this->assertStringContainsString('REDIRECTED_TO:', $echoed);
        $this->assertEquals('My Test Site', Settings::getSetting('sitename'));
    }

    /**
     * The language field is a list of the catalogues that exist.
     *
     * It was ten characters of free text, and a typo in it is not a validation error: the
     * catalogue is simply not found, `Language` falls back to English, and the setting reads
     * `gr` while every page is in English with nothing anywhere saying why. Greek is `el`.
     *
     * The screen's own reading is asserted rather than the markup, because that is the part
     * that can go wrong quietly — a view offered a picker with nothing in it for a long time
     * because the method behind it looked in one directory and `load()` looked in three.
     */
    public function testTheLanguagePickerIsOfferedTheCataloguesThatExist()
    {
        // Act
        ob_start();
        $this->controller->display();
        ob_get_clean();

        // Assert
        $this->assertIsArray($this->controller->lastView->languages ?? null,
            'the screen has to be given a list, even an empty one');
    }

    /**
     * This screen does not write `debug`, even when something posts it.
     *
     * The field is gone, because what it decided is gone: the debug toolbar, the view path in
     * every page's source and the DevPanel all follow the deployment now. What is asserted
     * here is the half a removed field does not give you — that a hand-crafted POST, or a
     * stale form cached in somebody's browser, cannot put the setting back and re-open a
     * developer tool on a live server.
     */
    public function testTheDebugSettingIsNotWritableFromThisScreen()
    {
        // Arrange
        Settings::setSetting('debug', 'no', false);
        $_POST['sitename'] = 'My Test Site';
        $_POST['debug'] = 'yes';
        $_POST['debug_present'] = '1';

        // Act
        ob_start();
        $this->controller->saveSystem();
        ob_get_clean();

        // Assert
        $this->assertEquals('no', Settings::getSetting('debug'),
            'a POST must not be able to turn a developer tool on');
    }

    /**
     * The DevPanel's own settings are not this screen's business either.
     *
     * The tab is gone — mount point, usertype floor and the "Debug Mode" switch that opened
     * the panel. All three describe a developer tool, which is part of how a server was built:
     * they belong in `app/app.php`, versioned with the code, beside the line that enables the
     * feature. On the screen they were three rows an administrator could edit on a live server
     * — one of them opening a database browser — and two of them never saved at all, because
     * PHP turns the `.` in a field name into `_` and the controller asked for the dotted key.
     *
     * Asserted from the POST side, which is what a stale cached form or a hand-made request
     * looks like: a value arriving under either name must not land in the settings table.
     */
    public function testTheDevPanelSettingsAreNotWritableFromThisScreen()
    {
        // Arrange
        \Pramnos\Application\FeatureRegistry::loadFromConfig(['devpanel']);
        Settings::setSetting('devpanel.mount', 'inside', false);
        Settings::setSetting('devpanel.min_usertype', '95', false);

        // Act — both spellings: the dotted one the form used to carry, and the one PHP posts
        $_POST['sitename'] = 'My Test Site';
        $_POST['devpanel.mount'] = 'elsewhere';
        $_POST['devpanel_mount'] = 'elsewhere';
        $_POST['devpanel_min_usertype'] = '10';

        ob_start();
        $this->controller->saveSystem();
        ob_get_clean();

        // Assert
        $this->assertEquals('inside', Settings::getSetting('devpanel.mount'));
        $this->assertEquals('95', Settings::getSetting('devpanel.min_usertype'),
            'a POST must not be able to lower the floor on a developer tool');
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

    /**
     * An ordinary account cannot read the settings screen.
     *
     * There was no usertype floor on this controller at all: `addAuthAction()`
     * requires only *being signed in*, so any authenticated account — somebody
     * who registered a minute ago — could open the form. It renders the SMTP
     * host, user and **password** into fields.
     *
     * The administration area's floor did not cover it. `AdminArea` strips the
     * prefix before routing, so `/admin/Settings` and `/Settings` reach the same
     * controller: the area's `min_usertype` applies to requests that arrive
     * through the prefix, and nothing makes a request use it. Every peer
     * controller carries its own floor; this one was the exception.
     */
    public function testAnOrdinaryAccountCannotReadTheSettingsScreen(): void
    {
        // Arrange
        $controller = new GuardedSettingsController(null);
        $this->signInWithUsertype(10);

        // Act
        ob_start();
        $result = $controller->display();
        $echoed = ob_get_clean();

        // Assert
        $this->assertNull($result);
        $this->assertNotSame([], $controller->redirectedTo, 'the request must be sent away');
        $this->assertStringNotContainsString('smtp', strtolower($echoed),
            'and nothing of the form may be rendered on the way out');
    }

    /**
     * And it cannot write them.
     *
     * The read is a credential disclosure; this is the hijack. `saveSystem()`
     * rewrites `site_url`, `forcessl`, `admin_mail`, every SMTP field and the
     * login lockout rules. Verified against the setting *not moving*, because a
     * guard that redirects after writing is not a guard.
     */
    public function testAnOrdinaryAccountCannotWriteTheSettings(): void
    {
        // Arrange
        $controller = new GuardedSettingsController(null);
        $this->signInWithUsertype(10);
        Settings::setSetting('sitename', 'Before');
        $_POST['sitename'] = 'After';

        // Act
        ob_start();
        $controller->saveSystem();
        ob_get_clean();

        // Assert
        $this->assertSame('Before', Settings::getSetting('sitename'),
            'a request that is refused must not have written anything first');
    }

    /**
     * Every settings action is behind the floor, not just the two above.
     *
     * `list`, `edit`, `save` and `delete` reach the same settings through a
     * different screen — the raw editor. A floor on the form and not on the
     * editor behind it is no floor.
     */
    public function testEverySettingsActionIsBehindTheFloor(): void
    {
        // Arrange
        $this->signInWithUsertype(10);

        // Act & Assert
        foreach (['display', 'saveSystem', 'list', 'edit', 'save', 'delete'] as $action) {
            $controller = new GuardedSettingsController(null);
            ob_start();
            $controller->$action();
            ob_get_clean();

            $this->assertNotSame([], $controller->redirectedTo,
                $action . '() must refuse an account below the floor');
        }
    }

    /**
     * An administrator is not refused.
     *
     * The other half of the three tests above, and not padding: they would all
     * pass just as well if the controller refused *everybody* — or if the refusal
     * were coming from the sign-in check rather than from the usertype floor.
     * This is what pins the floor as the thing doing the work.
     */
    public function testAnAdministratorIsNotRefused(): void
    {
        // Arrange
        $controller = new GuardedSettingsController(null);
        $this->signInWithUsertype(90);

        // Act
        ob_start();
        $controller->display();
        ob_get_clean();

        // Assert
        $this->assertSame([], $controller->redirectedTo,
            'an administrator must reach the settings screen');
    }

    /**
     * Put a signed-in account of a given usertype on the request.
     */
    private function signInWithUsertype(int $usertype): void
    {
        $_SESSION['logged'] = true;
        $_SESSION['uid'] = 2;
        $user = new \Pramnos\User\User();
        $user->userid = 2;
        $user->usertype = $usertype;

        $app = \Pramnos\Application\Application::getInstance();
        if ($app) {
            $app->currentUser = $user;
        }
    }
}
