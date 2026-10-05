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
use Pramnos\Messaging\SystemMailTemplates;

/**
 * A category an **application** registers reaches the editor, the same as the framework's.
 *
 * The registry was written for the framework's own four, and `placeholdersFor()` said an
 * application's category is "one the framework does not declare — which it documents
 * itself". There was nowhere to do that documenting. So an application sending its own mail
 * through `MailChannel` got exactly what the framework's categories got *before* the
 * registry existed: a category name, an empty body and nothing else.
 *
 * That is the sentence the registry was written to make false, left true for everybody
 * except the framework — and it read as finished from the outside, because the doc-block
 * invited an application to document its own.
 *
 * **Asserted on what the screen is handed**, not on the registry. A registry that answers
 * correctly while the controller never asks it is the shape this failed in: an application
 * had a `placeholders()` override and a passing unit test, because the test called the
 * static method directly and the framework called `self::placeholders()` — the one caller
 * that never reaches a subclass.
 */
#[CoversClass(SystemMailTemplates::class)]
#[CoversClass(MailTemplatesController::class)]
class RegisteredMailCategoryReachesTheScreenTest extends BaseTestCase
{
    protected \Pramnos\Database\Database $db;

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

        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->truncate();
        SystemMailTemplates::reset();
    }

    protected function tearDown(): void
    {
        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->truncate();

        // Registrations are process-wide and a test run is one process: one left behind
        // would answer for every test after it.
        SystemMailTemplates::reset();
    }

    /** Which connection this class runs against. */
    protected function settingsFixture(): string
    {
        return ROOT . DS . 'tests' . DS . 'fixtures' . DS . 'app' . DS . 'settings.php';
    }

    /** Seed a blank row, the way an application's own seeding migration would. */
    private function seedBlank(string $category): int
    {
        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->insert([
            'title'          => 'Order shipped',
            'category'       => $category,
            'language'       => 'en',
            'type'           => MailTemplate::TYPE_EMAIL,
            'defaultsubject' => '',
            'defaulttext'    => '',
            'emailtemplate'  => '',
            'sendmethod'     => 0,
            'sound'          => '',
        ]);

        $found = $this->db->queryBuilder()->table('#PREFIX#mailtemplates')
            ->where('category', $category)->first();

        return (int) ($found->fields['templateid'] ?? 0);
    }

    /** A controller that keeps the view instead of rendering it. */
    private function controller(): object
    {
        return new class ($this->db) extends MailTemplatesController {
            public ?object $view = null;

            public function __construct(\Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
                $this->controllerName = 'MailTemplates';
            }

            protected function requireMinUserType($type): bool
            {
                return false;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $this->view = new class {
                    public string $layout = '';
                    public array $placeholders = [];
                    public string $categoryNote = '';
                    public mixed $template = null;
                    public mixed $types = null;
                    public mixed $isNew = null;

                    public function display($layout = '')
                    {
                        $this->layout = (string) $layout;

                        return '';
                    }
                };

                return $this->view;
            }
        };
    }

    private function route(int $id): void
    {
        $_GET['_option'] = (string) $id;
        \Pramnos\Http\Request::resetInstance();
    }

    /**
     * The registered placeholders and note are what the edit screen is handed.
     *
     * The whole finding. Before registration existed, both were empty for every category
     * but the framework's four.
     */
    public function testARegisteredCategoryIsDescribedOnTheEditScreen(): void
    {
        // Arrange — what an application's ServiceProvider::boot() would do
        SystemMailTemplates::register([
            'shop.order_shipped' => [
                'title'        => 'Order shipped',
                'description'  => 'Sent when an order leaves the warehouse.',
                'placeholders' => ['ordernumber', 'trackingurl', 'sitename'],
            ],
        ]);

        $id         = $this->seedBlank('shop.order_shipped');
        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->edit($id);

        // Assert — a blank row, so every placeholder shown comes from the registration
        $this->assertSame(
            ['ordernumber', 'trackingurl', 'sitename'],
            $controller->view->placeholders,
            'a registered category was shown no placeholders'
        );
        $this->assertSame(
            'Sent when an order leaves the warehouse.',
            $controller->view->categoryNote
        );
    }

    /**
     * An unregistered category is what it always was: whatever the text contains.
     *
     * The control, and the behaviour every installation has today. Registration adds a
     * source; it does not replace the one that was there.
     */
    public function testAnUnregisteredCategoryStillReadsItsOwnText(): void
    {
        // Arrange
        $this->db->queryBuilder()->table('#PREFIX#mailtemplates')->insert([
            'title'          => 'Something else',
            'category'       => 'shop.something_else',
            'language'       => 'en',
            'type'           => MailTemplate::TYPE_EMAIL,
            'defaultsubject' => 'Hello {name}',
            'defaulttext'    => 'Your link is {link}.',
            'emailtemplate'  => '',
            'sendmethod'     => 0,
            'sound'          => '',
        ]);

        $found = $this->db->queryBuilder()->table('#PREFIX#mailtemplates')
            ->where('category', 'shop.something_else')->first();
        $id    = (int) ($found->fields['templateid'] ?? 0);

        $controller = $this->controller();
        $this->route($id);

        // Act
        $controller->edit($id);

        // Assert — body first, then subject, which is the order the scan reads them in
        $this->assertSame(['link', 'name'], $controller->view->placeholders);
        $this->assertSame('', $controller->view->categoryNote);
    }

    /**
     * An application cannot redefine one of the framework's own categories.
     *
     * The two lists — what the editor advertises and what the notification supplies — have
     * to agree, and the notification behind `auth.twofactor_code` is the framework's. An
     * entry that disagreed would put `{like_this}` in somebody's email with the screen's
     * blessing.
     */
    public function testTheFrameworksOwnCategoriesWinACollision(): void
    {
        // Arrange
        SystemMailTemplates::register([
            'auth.twofactor_code' => [
                'title'        => 'Ours now',
                'description'  => 'Hijacked.',
                'placeholders' => ['anything_at_all'],
            ],
        ]);

        // Act + Assert
        $this->assertSame(
            ['code', 'minutes', 'sitename'],
            SystemMailTemplates::placeholdersFor('auth.twofactor_code')
        );
        $this->assertStringContainsString(
            'second factor',
            SystemMailTemplates::describe('auth.twofactor_code')
        );
    }

    /**
     * A subclass's `placeholders()` override is reached from the screen.
     *
     * `Init.php` generates a subclass of this controller for every application and its
     * doc-block invites overriding, so a `public static` method here is an extension point
     * whether or not it was meant as one. It was called as `self::placeholders()`, which
     * never reaches a subclass — so an override was reachable only by calling it directly,
     * which is the one caller the framework never uses. An application had exactly that,
     * with a passing unit test and a screen showing none of it.
     */
    public function testASubclassOverrideOfPlaceholdersIsUsed(): void
    {
        // Arrange
        $id = $this->seedBlank('shop.order_shipped');

        $controller = new class ($this->db) extends MailTemplatesController {
            public ?object $view = null;

            public function __construct(\Pramnos\Database\Database $db)
            {
                $app = Application::getInstance();
                $app->database     = $db;
                $this->application = $app;
                $this->controllerName = 'MailTemplates';
            }

            protected function requireMinUserType($type): bool
            {
                return false;
            }

            public function redirect($url = null, $quit = true, $code = '302')
            {
            }

            public static function placeholders(MailTemplate $template): array
            {
                return ['from_the_subclass'];
            }

            public function &getView($name = '', $type = '', $args = [])
            {
                $this->view = new class {
                    public array $placeholders = [];
                    public string $categoryNote = '';
                    public mixed $template = null;
                    public mixed $types = null;
                    public mixed $isNew = null;

                    public function display($layout = '')
                    {
                        return '';
                    }
                };

                return $this->view;
            }
        };

        $this->route($id);

        // Act
        $controller->edit($id);

        // Assert
        $this->assertSame(
            ['from_the_subclass'],
            $controller->view->placeholders,
            'the framework called self::placeholders(), so the subclass was never asked'
        );
    }
}
