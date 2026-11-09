<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Audience;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Api\AdminApi;
use Pramnos\Application\Api\ScreenAnswer;
use Pramnos\Application\Api\ScreenApi;
use Pramnos\Application\Application;
use Pramnos\Application\Controllers\Rules as Screen;
use Pramnos\Audience\Rules\Rules;
use Pramnos\Http\RequestIdentity;
use Pramnos\User\User;

/**
 * An administration screen under the API (`ScreenApi`), on MySQL, prefixed MySQL and PostgreSQL,
 * with the rules' screen: a person the API's credentials sealed — no page session — reads the
 * view's data and writes through the screen's own actions, its messages and errors answered as
 * JSON; below the floor is a 403, nobody a 401; a screen that answers JSON already is passed
 * through, one that only draws a page is a 406; and the page's flash is not left for the next page.
 */
#[CoversClass(ScreenApi::class)]
#[CoversClass(ScreenAnswer::class)]
#[CoversClass(AdminApi::class)]
class ScreenApiTest extends TestCase
{
    use AudienceProbe {
        tearDown as private probeTearDown;
    }
    use AdminScreen;

    /** The identity and the request cleared, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        RequestIdentity::reset();
        $this->clearRequest();
        \Pramnos\Http\Request::setRawInput('');
        $this->probeTearDown();
    }

    /** Account 2 as the API's person, of this type: no session, as a bearer token would be. */
    private function asApi(int $usertype): void
    {
        $user = new User(0);
        $user->userid   = 2;
        $user->usertype = $usertype;
        $user->username = 'admin';
        RequestIdentity::seal($user, 'token');
    }

    /**
     * Reads answer the view's data, writes the screen's messages and where it goes next, a
     * refused write its errors as a 422 — all with no session, through the screen's own `exec()`.
     */
    #[DataProvider('databases')]
    public function testTheRulesScreenAnswersTheApi(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->asApi(98);
        $definition = json_encode(['trigger' => ['type' => 'once'], 'steps' => ['b' => ['type' => 'action', 'action' => 'bell', 'params' => ['subject' => 'Hi'], 'next' => null]]]);

        // Act
        $this->request('POST', null, [], ['name' => 'Hello', 'definition' => (string) $definition, 'who' => '{"conditions":[]}']);
        $saved = ScreenApi::call(Screen::class, 'POST', 'save');
        $flashLeft = $_SESSION['_messages'] ?? null;
        $id = (int) Rules::all()[0]['id'];
        $this->request('POST', null, [], ['ruleid' => (string) $id, 'name' => 'Hello', 'definition' => '{"trigger":{"type":"never"}}']);
        $wrong = ScreenApi::call(Screen::class, 'POST', 'save');
        $this->request('POST', null, [], ['status' => 'shadow']);
        $status = ScreenApi::call(Screen::class, 'POST', 'status', $id);
        $this->request('GET');
        $list = ScreenApi::call(Screen::class, 'GET', 'display');
        $this->request('GET');
        $one = ScreenApi::call(Screen::class, 'GET', 'view', $id);

        // Assert
        $this->assertSame(200, $saved['status']);
        $this->assertSame(['messages' => ['Saved as a draft.'], 'redirect' => adminUrl('Rules/view/') . $id], $saved['data']);
        $this->assertNull($flashLeft, 'answered here, not shown again on the next page');
        $this->assertSame(422, $wrong['status'], 'the screen said no, in its own words');
        $this->assertSame('trigger: one of event, segment_entered, segment_left, schedule, once.', $wrong['error']);
        $this->assertSame([200, ['The rule is shadow.']], [$status['status'], $status['data']['messages']]);
        $this->assertSame('shadow', Rules::get($id)['status'], 'the write took effect');
        $this->assertSame([200, 'Hello'], [$list['status'], $list['data']['rules'][0]['name']]);
        $this->assertSame([200, $id], [$one['status'], (int) $one['data']['rule']['id']]);
        $this->assertStringContainsString('data-step="b"', $one['data']['canvas'], 'the view\'s own variables, as the page has them');
    }

    /**
     * Below the screen's floor is a 403 and nobody a 401 — the floor `exec()` checks, outside the
     * administration area too; nothing is written.
     */
    #[DataProvider('databases')]
    public function testTheFloorHoldsUnderTheApi(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->request('POST', null, [], ['name' => 'Nope', 'definition' => '{"trigger":{"type":"once"},"steps":{}}']);

        // Act
        $nobody = ScreenApi::call(Screen::class, 'POST', 'save');
        $this->asApi(1);
        $below = ScreenApi::call(Screen::class, 'POST', 'save');

        // Assert
        $this->assertSame([401, 403], [$nobody['status'], $below['status']]);
        $this->assertSame([], Rules::all(), 'nothing written');
    }

    /**
     * A screen's action that answers JSON already is passed through; one that only draws a page
     * outside its view is a 406. The routes: the list at the path, a read by GET, a write by POST,
     * every one tagged and described.
     */
    #[DataProvider('databases')]
    public function testJsonPassesThroughAndThePageAloneIsRefused(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        $this->asApi(98);
        $drawn = new class (Application::getInstance()) extends \Pramnos\Application\Controller {
            /** Declares its one action. */
            public function __construct(?Application $application = null)
            {
                $this->addAuthAction(['display']);
                parent::__construct($application);
            }

            /** A page drawn without a view. */
            public function display(): string
            {
                return '<p>page</p>';
            }
        };
        $router = new \Pramnos\Routing\Router(Application::getInstance());

        // Act
        $this->request('GET');
        $catalog = ScreenApi::call(\Pramnos\Application\Controllers\Audience::class, 'GET', 'catalog');
        $page    = ScreenApi::call($drawn::class, 'GET', 'display');
        AdminApi::routes($router, '/admin', ['rules']);
        $routes = $router->getRoutesWithPermissions();

        // Assert
        $this->assertSame(200, $catalog['status']);
        $this->assertIsArray($catalog['data'], 'the screen\'s JSON, decoded');
        $this->assertSame(406, $page['status']);
        $this->assertArrayHasKey('/admin/rules', $routes['GET']);
        $this->assertArrayHasKey('/admin/rules/view/{id?}', $routes['GET']);
        $this->assertArrayHasKey('/admin/rules/save/{id?}', $routes['POST']);
        $this->assertArrayNotHasKey('/admin/rules/save/{id?}', $routes['GET'], 'a write is never a GET');
        $doc = $routes['POST']['/admin/rules/save/{id?}']['route']->getDoc();
        $this->assertSame(['Create a rule as a draft, or save a new version.', ['admin']], [$doc['summary'], $doc['tags']]);
        $this->assertSame('users', AdminApi::name(\Pramnos\Application\Controllers\UsersController::class));
    }
}
