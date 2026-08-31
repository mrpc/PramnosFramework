<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Messaging;

use PHPUnit\Framework\Attributes\CoversClass;
use Pramnos\Application\Application;
use Pramnos\Application\Settings;
use Pramnos\Framework\Factory;
use Pramnos\Framework\Testing\BaseTestCase;
use Pramnos\Messaging\Controllers\MailTemplatesController;
use Pramnos\Messaging\MailTemplate;

/**
 * The template screens, executed.
 *
 * 131 of 137 statements had never run. That is worse than it sounds for this screen in
 * particular: `mailtemplates` shipped as a table with no editor, so the templates an
 * application's mail goes through were reachable only from a database client — and a screen that
 * has never been executed is indistinguishable from the screen that was missing.
 *
 * What is asserted is what makes the screen worth having rather than decorative: the grouping
 * that answers «is the reset email translated into Greek», the placeholders read **from** the
 * template rather than from a list somebody maintains, the body stored as written because an
 * email template is markup, and the test send actually rendering.
 *
 * Runs on **every** backend the framework supports, and that is not decoration: the last defect
 * found in this area was a `Model` addressing its table differently on PostgreSQL than on MySQL,
 * shipped by a suite that was green because only one engine ever ran. `settingsFixture()` is the
 * seam — {@see MailTemplatesScreenPostgreSQLTest} overrides it and re-runs all of this against
 * PostgreSQL/TimescaleDB, so a divergence fails here rather than in somebody's admin screen.
 */
#[CoversClass(MailTemplatesController::class)]
class MailTemplatesScreenTest extends BaseTestCase
{
    private $db;

    /** @var list<int> */
    private array $created = [];

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

        $this->runMigrations([
            \Pramnos\Framework\Migrations\Messaging\CreateMailtemplatesTable::class,
        ], $this->db);

        $_GET  = [];
        $_POST = [];
        \Pramnos\Http\Request::resetInstance();
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $id) {
            try {
                $this->db->queryBuilder()->table('#PREFIX#mailtemplates')
                    ->where('templateid', $id)->delete();
            } catch (\Throwable $exception) {
                // Nothing to undo if the table went away with the test.
            }
        }
        $this->created = [];

        $_GET  = [];
        $_POST = [];
        \Pramnos\Http\Request::resetInstance();

        parent::tearDown();
    }

    /**
     * Which connection this class runs against.
     *
     * The default is the suite's MySQL fixture; the PostgreSQL subclass returns the other one.
     * A seam rather than two copies of the file: the assertions are about the screen, and the
     * only thing that differs is what is underneath it.
     */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    /**
     * The list groups the language variants of one notification together.
     *
     * The question somebody opens this screen with is "is the reset email translated into
     * Greek", and a flat list of eighty rows cannot answer it. Grouped by `(category, type)`,
     * so one notification is one row of the screen whatever its languages.
     */
    public function testTheListGroupsTheLanguageVariants(): void
    {
        // Arrange
        $this->seed('password_reset', 'en', MailTemplate::TYPE_EMAIL);
        $this->seed('password_reset', 'el', MailTemplate::TYPE_EMAIL);
        $this->seed('password_reset', 'en', MailTemplate::TYPE_SMS);
        $controller = $this->controller();

        // Act
        $controller->display();

        // Assert
        $groups = $controller->view->groups;
        $email  = 'password_reset|' . MailTemplate::TYPE_EMAIL;
        $sms    = 'password_reset|' . MailTemplate::TYPE_SMS;

        $this->assertArrayHasKey($email, $groups);
        $this->assertArrayHasKey($sms, $groups, 'a channel is not a language');
        $this->assertCount(2, $groups[$email], 'the two languages are not one group');
        $this->assertCount(1, $groups[$sms]);
        $this->assertSame(MailTemplatesController::TYPES, $controller->view->types);
    }

    /**
     * One template, read-only, with the placeholders it actually contains.
     */
    public function testOneTemplateListsItsOwnPlaceholders(): void
    {
        // Arrange
        $id = $this->seed('welcome', 'en', MailTemplate::TYPE_EMAIL, [
            'defaulttext'    => 'Hello {name}, your link is {link}.',
            'defaultsubject' => 'Welcome {name}',
        ]);
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->view($id);

        // Assert
        $this->assertSame('view', $controller->view->layout);
        $this->assertSame(
            ['name', 'link'],
            $controller->view->placeholders,
            'the placeholders are not the ones in the template'
        );
    }

    /**
     * The placeholder list comes from the template, not from a maintained list.
     *
     * A documented list goes stale the first time an application adds a placeholder, and the
     * failure is silent: the editor omits it, somebody mistypes it, and the mail goes out with a
     * literal brace in it. Duplicates collapse; the subject counts as much as the body.
     */
    public function testPlaceholdersAreReadFromTheTemplateItself(): void
    {
        // Arrange
        $template = new MailTemplate($this->controller());
        $template->defaulttext    = 'Hi {name}. {name} again, plus {order.id} and {code_2}.';
        $template->defaultsubject = 'About {order.id}';

        // Act
        $found = MailTemplatesController::placeholders($template);

        // Assert
        $this->assertSame(['name', 'order.id', 'code_2'], $found);
    }

    /** A template with no placeholders reports none, rather than a false positive. */
    public function testATemplateWithNoPlaceholdersReportsNone(): void
    {
        // Arrange
        $template = new MailTemplate($this->controller());
        $template->defaulttext    = 'Nothing to substitute here. { } and {} and { name }.';
        $template->defaultsubject = '';

        // Assert
        $this->assertSame([], MailTemplatesController::placeholders($template));
    }

    /** The editor opens empty for a new template, and on the row for an existing one. */
    public function testTheEditorOpensEmptyAndOnARow(): void
    {
        // Arrange
        $id = $this->seed('reminder', 'en', MailTemplate::TYPE_EMAIL);

        // Act — new
        $blank = $this->controller();
        $this->route(0);
        $blank->edit();

        // Assert
        $this->assertTrue($blank->view->isNew);
        $this->assertSame('edit', $blank->view->layout);

        // Act — existing
        $existing = $this->controller();
        $this->route($id);
        $existing->edit($id);

        // Assert
        $this->assertFalse($existing->view->isNew);
        $this->assertSame('reminder', $existing->view->template['category'] ?? null);
    }

    /** A template that is not there redirects and says so, on every screen that takes an id. */
    public function testAMissingTemplateIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->route(987654);

        // Act
        $controller->view(987654);
        $controller->edit(987654);

        // Assert
        $this->assertNull($controller->view, 'a screen was rendered for a template that is gone');
        $this->assertCount(2, $controller->errors);
        $this->assertSame(['That template no longer exists.'], array_unique($controller->errors));
    }

    /** And an id that is not a number. */
    public function testAnInvalidIdIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->route(0);

        // Act
        $controller->view(0);

        // Assert
        $this->assertSame(['The id in that link is not valid.'], $controller->errors);
    }

    // ── Writing ───────────────────────────────────────────────────────────────

    /**
     * The body is stored as written, because an email template is markup.
     *
     * A screen that sanitised it would make the feature useless — there would be no way to
     * write an email. The category and title *are* stripped: they are labels, and they are
     * printed in places where markup would be a defect rather than the point.
     */
    public function testTheBodyKeepsItsMarkupAndTheLabelsDoNot(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = [
            'templateid'     => 0,
            'category'       => '<b>invoice</b>',
            'title'          => 'Invoice <i>ready</i>',
            'language'       => 'en',
            'type'           => (string) MailTemplate::TYPE_EMAIL,
            'defaultsubject' => 'Invoice {number}',
            'defaulttext'    => '<h1>Hello {name}</h1><p>See <a href="{link}">this</a>.</p>',
            'emailtemplate'  => 'default',
        ];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->save();

        // Assert
        $row = $this->rowByCategory('invoice');
        $this->assertNotNull($row, 'nothing was saved');
        $this->created[] = (int) $row['templateid'];

        $this->assertSame('invoice', $row['category'], 'the label kept its markup');
        $this->assertSame('Invoice ready', $row['title']);
        $this->assertStringContainsString('<a href="{link}">', (string) $row['defaulttext']);
        $this->assertSame(['Template saved.'], $controller->messages);
    }

    /** A save with no category or no title is refused rather than written as a blank row. */
    public function testASaveWithoutACategoryOrTitleIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = ['templateid' => 0, 'category' => '  ', 'title' => 'Has a title'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->save();

        // Assert
        $this->assertSame(['A template needs a category and a title.'], $controller->errors);
        $this->assertNull($this->rowByCategory(''));
    }

    /** An empty language falls back to `en` rather than being stored blank. */
    public function testAnEmptyLanguageFallsBackToEnglish(): void
    {
        // Arrange
        $controller = $this->controller();
        $_POST      = [
            'templateid' => 0,
            'category'   => 'fallback_lang',
            'title'      => 'No language given',
            'language'   => '   ',
        ];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->save();

        // Assert
        $row = $this->rowByCategory('fallback_lang');
        $this->assertNotNull($row);
        $this->created[] = (int) $row['templateid'];
        $this->assertSame('en', $row['language']);
    }

    /** Saving an existing template updates it rather than adding a second row. */
    public function testSavingAnExistingTemplateUpdatesIt(): void
    {
        // Arrange
        $id         = $this->seed('editme', 'en', MailTemplate::TYPE_EMAIL);
        $controller = $this->controller();
        $_POST      = [
            'templateid' => $id,
            'category'   => 'editme',
            'title'      => 'Renamed',
            'language'   => 'en',
        ];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->save();

        // Assert
        $this->assertSame('Renamed', (string) ($this->rowById($id)['title'] ?? ''));
        $this->assertSame(
            1,
            $this->countByCategory('editme'),
            'the update created a second row'
        );
    }

    /** Delete removes the row; a delete with no id does nothing and does not throw. */
    public function testDeleteRemovesTheRowAndNoIdIsANoOp(): void
    {
        // Arrange
        $id         = $this->seed('deleteme', 'en', MailTemplate::TYPE_EMAIL);
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->delete($id);

        // Assert
        $this->assertNull($this->rowById($id));
        $this->assertSame(['Template deleted.'], $controller->messages);

        // Act — no id at all
        $quiet = $this->controller();
        $this->route(0);
        $quiet->delete(0);

        // Assert
        $this->assertSame([], $quiet->errors);
        $this->assertSame([], $quiet->messages, 'nothing was deleted, so nothing is reported');
    }

    // ── The test send ─────────────────────────────────────────────────────────

    /**
     * A test send renders the placeholders as their own names, in the template's own wrapper.
     *
     * Filling them with invented data would hide a missing one — `Hello ,` reads like a
     * template bug rather than a placeholder nobody passed. `[name]` shows exactly where each
     * one lands. And the wrapper matters: until it was passed, the field on the edit form was
     * written to the database and read by nothing.
     */
    public function testATestSendRendersThePlaceholdersAndUsesTheWrapper(): void
    {
        // Arrange
        $id = $this->seed('probe', 'en', MailTemplate::TYPE_EMAIL, [
            'defaulttext'    => 'Hello {name}, code {code}.',
            'defaultsubject' => 'Your code',
            'emailtemplate'  => 'branded',
        ]);
        $controller = $this->controller();
        $this->route($id);
        $_POST = ['address' => 'operator@example.com'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->test($id);

        // Assert
        $this->assertSame('Hello [name], code [code].', $controller->mailed['body'] ?? null);
        $this->assertSame('branded', $controller->mailed['template'] ?? null);
        $this->assertSame('operator@example.com', $controller->mailed['to'] ?? null);
        $this->assertStringStartsWith('[test] ', (string) ($controller->mailed['subject'] ?? ''));
        $this->assertSame(['Test sent to operator@example.com.'], $controller->messages);
    }

    /** With no subject on the template, the title is used rather than an empty subject line. */
    public function testTheTitleStandsInForAMissingSubject(): void
    {
        // Arrange
        $id = $this->seed('nosubject', 'en', MailTemplate::TYPE_EMAIL, [
            'defaultsubject' => '',
        ]);
        $controller = $this->controller();
        $this->route($id);
        $_POST = ['address' => 'operator@example.com'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->test($id);

        // Assert
        $this->assertSame('[test] nosubject title', $controller->mailed['subject'] ?? null);
    }

    /** A mailer that refuses says so, rather than reporting a test that never went out. */
    public function testARefusedTestSendIsReported(): void
    {
        // Arrange
        $id         = $this->seed('refused', 'en', MailTemplate::TYPE_EMAIL);
        $controller = $this->controller(sends: false);
        $this->route($id);
        $_POST = ['address' => 'operator@example.com'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->test($id);

        // Assert
        $this->assertSame([], $controller->messages);
        $this->assertStringContainsString('mail settings', $controller->errors[0] ?? '');
    }

    /** An address that is not one is refused before the mailer is reached. */
    public function testATestSendToSomethingThatIsNotAnAddressIsRefused(): void
    {
        // Arrange
        $id         = $this->seed('badaddress', 'en', MailTemplate::TYPE_EMAIL);
        $controller = $this->controller();
        $this->route($id);
        $_POST = ['address' => 'not-an-address'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->test($id);

        // Assert
        $this->assertSame(['That is not an email address.'], $controller->errors);
        $this->assertSame([], $controller->mailed, 'the mailer was reached anyway');
    }

    /** And a test send for a template that does not exist. */
    public function testATestSendForAMissingTemplateIsRefused(): void
    {
        // Arrange
        $controller = $this->controller();
        $this->route(987655);
        $_POST = ['address' => 'operator@example.com'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->test(987655);

        // Assert
        $this->assertSame(['That template no longer exists.'], $controller->errors);
        $this->assertSame([], $controller->mailed);
    }

    // ── The gate ──────────────────────────────────────────────────────────────

    /**
     * Below usertype 80, nothing renders and nothing is written.
     *
     * Templates are the words the system says in its own name. A gate that only skipped the
     * render would leave `save`, `delete` and `test` open — and `test` mails to an address the
     * caller chooses.
     */
    public function testEveryActionStopsBelowTheFloor(): void
    {
        // Arrange
        $id         = $this->seed('gated', 'en', MailTemplate::TYPE_EMAIL);
        $controller = $this->controller(refused: true);
        $this->route($id);
        $_POST = ['templateid' => $id, 'category' => 'changed', 'title' => 'changed',
                  'address' => 'operator@example.com'];
        \Pramnos\Http\Request::resetInstance();

        // Act
        $controller->display();
        $controller->view($id);
        $controller->edit($id);
        $controller->save();
        $controller->delete($id);
        $controller->test($id);

        // Assert
        $this->assertNull($controller->view);
        $this->assertSame([], $controller->messages);
        $this->assertSame([], $controller->mailed, 'the gate let a mail out');
        $this->assertSame('gated', (string) ($this->rowById($id)['category'] ?? ''), 'written through the gate');
    }

    /** The floor is 80, and every action is auth-registered. */
    public function testTheFloorAndTheRegistrationAreDeclared(): void
    {
        // Arrange
        $controller = new MailTemplatesController(null);
        $reflection = new \ReflectionClass(MailTemplatesController::class);

        // Assert
        $this->assertGreaterThanOrEqual(
            80,
            $reflection->getProperty('requiredUserType')->getValue($controller)
        );
        $registered = $reflection->getProperty('actions_auth')->getValue($controller);
        foreach (['display', 'view', 'edit', 'save', 'delete', 'test'] as $action) {
            $this->assertContains($action, $registered, $action . ' is not auth-protected');
        }
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    /**
     * The controller with the four things a screen needs from outside it replaced: the gate,
     * the redirect, the view and the mailer. Everything else runs, queries included.
     */
    private function controller(bool $refused = false, bool $sends = true): object
    {
        return new class ($refused, $sends, $this->db) extends MailTemplatesController {
            public ?object $view = null;

            public array $errors = [];

            public array $messages = [];

            public array $redirects = [];

            /** @var array<string, string> what the mailer was handed */
            public array $mailed = [];

            public function __construct(
                private bool $refused,
                private bool $sends,
                \Pramnos\Database\Database $db
            ) {
                $app = Application::getInstance();
                $app->database        = $db;
                $this->application    = $app;
                $this->controllerName = 'MailTemplates';
            }

            protected function requireMinUserType($type): bool
            {
                return $this->refused;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
                $this->redirects[] = (string) $url;
            }

            protected function addError($error)
            {
                $this->errors[] = (string) $error;

                return $this;
            }

            protected function addMessage($message)
            {
                $this->messages[] = (string) $message;

                return $this;
            }

            protected function mailer(): \Pramnos\Email\Email
            {
                $recorder = $this;

                return new class ($recorder, $this->sends) extends \Pramnos\Email\Email {
                    public function __construct(private object $recorder, private bool $sends)
                    {
                    }

                    public function setSubject($subject)
                    {
                        $this->recorder->mailed['subject'] = (string) $subject;

                        return $this;
                    }

                    public function setBody($body)
                    {
                        $this->recorder->mailed['body'] = (string) $body;

                        return $this;
                    }

                    public function setTo($to)
                    {
                        $this->recorder->mailed['to'] = (string) $to;

                        return $this;
                    }

                    public function setTemplate(?string $template)
                    {
                        $this->recorder->mailed['template'] = (string) $template;

                        return $this;
                    }

                    public function send()
                    {
                        return $this->sends;
                    }
                };
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $this->view = new class ($name) {
                    public array $assigned = [];

                    public string $layout = '';

                    public function __construct(public string $name)
                    {
                    }

                    public function __set($key, $value)
                    {
                        $this->assigned[$key] = $value;
                    }

                    public function __get($key)
                    {
                        return $this->assigned[$key] ?? null;
                    }

                    public function display($layout = '')
                    {
                        $this->layout = (string) $layout;

                        return 'rendered';
                    }
                };

                return $this->view;
            }
        };
    }

    /** What the route said. */
    private function route(int $id): void
    {
        $_GET['_option'] = (string) $id;
        \Pramnos\Http\Request::resetInstance();
    }

    /** One template row. Returns its id. */
    private function seed(
        string $category,
        string $language,
        int $type,
        array $extra = []
    ): int {
        $row = array_merge([
            'title'          => $category . ' title',
            'category'       => $category,
            'language'       => $language,
            'type'           => $type,
            'defaultsubject' => $category . ' subject',
            'defaulttext'    => 'Body of ' . $category,
            'emailtemplate'  => '',
            'sendmethod'     => 0,
            'sound'          => '',
        ], $extra);

        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->insert($row);

        $found = $this->db->queryBuilder()->table('#PREFIX#mailtemplates')
            ->where('category', $category)
            ->where('language', $language)
            ->where('type', $type)
            ->orderBy('templateid', 'desc')
            ->first();

        $id = (int) ($found->fields['templateid'] ?? 0);
        $this->assertGreaterThan(0, $id, 'the fixture template was not created');
        $this->created[] = $id;

        return $id;
    }

    /** @return array<string, mixed>|null */
    private function rowById(int $id): ?array
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#mailtemplates')
            ->where('templateid', $id)->first();

        return ($row === null || ($row->numRows ?? 0) === 0) ? null : (array) $row->fields;
    }

    /** @return array<string, mixed>|null */
    private function rowByCategory(string $category): ?array
    {
        $row = $this->db->queryBuilder()->table('#PREFIX#mailtemplates')
            ->where('category', $category)->orderBy('templateid', 'desc')->first();

        return ($row === null || ($row->numRows ?? 0) === 0) ? null : (array) $row->fields;
    }

    private function countByCategory(string $category): int
    {
        $result = $this->db->queryBuilder()->table('#PREFIX#mailtemplates')
            ->where('category', $category)->get();
        $count  = 0;
        while ($result !== null && $result->fetch()) {
            $count++;
        }

        return $count;
    }
}
