<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Audience\Rules\Actions;
use Pramnos\Audience\Rules\Context;
use Pramnos\Framework\Migrations\Todos\CreateTodosTable;
use Pramnos\Llm\Analyst\Tools\TodosChangeTool;
use Pramnos\Llm\Analyst\Tools\TodosTool;
use Pramnos\Todos\Todos;
use Pramnos\Todos\TodosApi;

/**
 * The to-dos for the assistant, over JSON, and from a rule (FW-363, step 4), against MySQL with and
 * without a prefix and PostgreSQL: the `todos` tool and its writes under the application's name,
 * the JSON API in the API's shape, and the rules' `todo` action, once per run.
 */
#[CoversClass(TodosTool::class)]
#[CoversClass(TodosChangeTool::class)]
#[CoversClass(TodosApi::class)]
#[CoversClass(Actions::class)]
class TodosToolsTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The application's info before the test. */
    private array $info = [];

    /** The probe with the to-do table, and the settings the test changes. */
    private function ready(string $type, string $host, int $port, string $user): void
    {
        $this->boot($type, $host, $port, $user);
        require_once ROOT . '/database/migrations/framework/todos/2026_11_09_000011_create_todos_table.php';
        \Pramnos\Framework\Testing\Schema::ensure([CreateTodosTable::class], $this->db);
        $this->db->queryBuilder()->table(Todos::TABLE)->delete();
        $this->info = Application::getInstance()->applicationInfo;
        Application::getInstance()->applicationInfo['metrics'] = ['timezone' => 'UTC'];
        Application::getInstance()->applicationInfo['todos'] = [];
        Todos::forgetSources();
    }

    /** The settings back, the request cleared, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        if ($this->info !== []) {
            Application::getInstance()->applicationInfo = $this->info;
        }
        Todos::forgetSources();
        Actions::reset();
        $_GET = $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \Pramnos\Http\Request::setRawInput('');
        Application::getInstance()->currentUser = null;
        $this->probeTearDown();
    }

    /**
     * The tools: named by `todos.tool`, its writes `<name>_change`; add for someone by name, for
     * nobody (0), a day cleared with '', a reminder on the person's clock; list mine, all, a person,
     * the team's counts, about a page, closed ones too; refused for somebody who may not, a plan
     * without to-dos, a name nobody has, a to-do not there, a reminder that is not a time.
     */
    #[DataProvider('databases')]
    public function testTheAssistantsTools(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria] = [$this->ids['eleni'], $this->ids['maria']];
        Application::getInstance()->applicationInfo['todos'] = ['tool' => 'glideday-todo', 'people' => static fn (): array => [$eleni => 'Ελένη Σ.', $maria => 'Μαρία Κ.']];
        $list   = new TodosTool($eleni);
        $change = new TodosChangeTool($eleni);
        $names  = [$list->name(), $change->name()];

        // Act
        $forMaria = $change->execute(['title' => 'Call the supplier', 'assigned_to' => 'Μαρία', 'due' => '2026-11-20', 'remind_at' => '2026-11-19 09:30']);
        $mine     = $change->execute(['title' => 'Mine, about https://site.test/admin/Users/edit/12']);
        $nobodys  = $change->execute(['title' => 'Nobody\'s', 'assigned_to' => '0']);
        $cleared  = $change->execute(['id' => $forMaria['added']['id'], 'due' => '', 'status' => 'in_progress']);
        $closed   = $change->execute(['id' => $mine['added']['id'], 'status' => 'done']);
        $errors   = [$change->execute(['title' => 'x', 'assigned_to' => 'Κανείς']), $change->execute(['id' => 999999, 'status' => 'done']),
            $change->execute(['title' => 'x', 'remind_at' => 'soon']), $change->execute(['title' => ''])];
        $me       = $list->execute([]);
        $all      = $list->execute(['who' => 'all']);
        $marias   = $list->execute(['who' => 'Μαρία']);
        $team     = $list->execute(['who' => 'team']);
        $about    = $list->execute(['who' => 'all', 'about' => '12', 'closed' => true]);
        $byName   = $list->execute(['who' => 'Κανείς']);
        Application::getInstance()->applicationInfo['todos']['allowed'] = static fn (int $t): bool => false;
        $noPlan   = [$list->execute([]), $change->execute(['title' => 'x'])];
        Application::getInstance()->applicationInfo['todos'] = ['can' => static fn (): bool => false];
        $nobody   = [$list->execute([]), $change->execute(['title' => 'x']), (new TodosTool())->execute([])];

        // Assert
        $this->assertSame(['glideday-todo', 'glideday-todo_change'], $names, 'the application\'s name');
        $this->assertSame([$maria, 'Μαρία Κ.', '2026-11-20', '2026-11-19 09:30'],
            [$forMaria['added']['assigned_to'], $forMaria['added']['assigned_name'], $forMaria['added']['due'], $forMaria['added']['remind_at']]);
        $this->assertSame(0, $nobodys['added']['assigned_to'], 'nobody in particular');
        $this->assertSame([null, 'in_progress'], [$cleared['changed']['due'], $cleared['changed']['status']], 'a day cleared with \'\'');
        $this->assertSame('done', $closed['changed']['status']);
        foreach ($errors as $error) {
            $this->assertStringStartsWith('Error: ', (string) $error);
        }
        $this->assertSame([], array_column($me['items'], 'title'), 'mine: closed, so not listed');
        $this->assertEqualsCanonicalizing(['Call the supplier', 'Nobody\'s'], array_column($all['items'], 'title'));
        $this->assertSame(['Call the supplier'], array_column($marias['items'], 'title'));
        $this->assertSame([1, 1], [array_column($team['team'], 'open', 'user')[$maria], array_column($team['team'], 'open', 'user')[0]]);
        $this->assertSame(['Mine, about'], array_column($about['items'], 'title'), 'about the user\'s page, closed ones too');
        $this->assertStringStartsWith('Error: nobody named', (string) $byName);
        $this->assertSame(['Error: this plan has no to-dos.', 'Error: this plan has no to-dos.'], $noPlan);
        foreach ($nobody as $refused) {
            $this->assertStringStartsWith('Error: the to-do list is not', (string) $refused);
        }
        Application::getInstance()->applicationInfo['todos'] = ['tool' => 'glideday-todo'];
        $this->assertContains('glideday-todo_change', (new \Pramnos\Llm\Analyst\AnalystAssistant())->writes(), 'its writes wait for the person');
    }

    /**
     * The JSON API: listed with the person's name and the plan's capability; added (201), changed,
     * deleted; a to-do not there is 404, nonsense 422, nobody 401, a reader who may only read 403
     * on a write; the routes mount under the path chosen.
     */
    #[DataProvider('databases')]
    public function testTheJsonApi(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        $eleni = $this->ids['eleni'];
        $api = new class extends TodosApi {
            /** Who asks, set by the test. */
            public ?object $asking = null;

            /** The test's account, not the API's credentials. */
            protected function user(): ?object
            {
                return $this->asking;
            }
        };
        $api->asking = $this->user('eleni');

        // Act
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST   = ['title' => 'Water the plants', 'due' => '2026-11-20'];
        $added   = $api->add();
        $_POST   = ['title' => ''];
        $refused = $api->add();
        $_POST   = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET    = ['who' => 'me'];
        $listed  = $api->list();
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        \Pramnos\Http\Request::$putData = [];
        \Pramnos\Http\Request::setRawInput((string) json_encode(['status' => 'done', 'due' => '']));
        $changed = $api->change((int) $added['data']['id']);
        \Pramnos\Http\Request::$putData = [];
        $missing = $api->change(999999);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST   = ['start' => '2026-11-25'];
        $moved   = $api->moveDay((int) $added['data']['id']);
        $_POST   = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET    = ['start' => '2026-11-01', 'end' => '2026-11-30'];
        $calendar = $api->calendar();
        $_GET    = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $movedMissing = $api->moveDay(999999);
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $deleted = $api->remove((int) $added['data']['id']);
        $again   = $api->remove((int) $added['data']['id']);
        Application::getInstance()->applicationInfo['todos'] = ['can' => static fn (object $u, string $what): bool => $what === 'read'];
        $readOnly = $api->remove(1);
        $api->asking = null;
        $signedOut = $api->list();
        $router = new \Pramnos\Routing\Router(Application::getInstance());
        TodosApi::routes($router, '/api/tasks');

        // Assert
        $this->assertSame([201, 'Water the plants', '2026-11-20'], [$added['status'], $added['data']['title'], $added['data']['due']]);
        $this->assertSame(422, $refused['status']);
        $this->assertSame([200, $eleni, true, true], [$listed['status'], $listed['data']['me'], $listed['data']['allowed'], $listed['data']['writes']]);
        $this->assertSame('Ελένη Σ.', $listed['data']['name']);
        $this->assertSame(['Water the plants'], array_column($listed['data']['items'], 'title'));
        $this->assertSame([200, 'done', null], [$changed['status'], $changed['data']['status'], $changed['data']['due']]);
        $this->assertSame(404, $missing['status']);
        $this->assertContains((int) $added['data']['id'], array_column($calendar['data']['events'], 'id'), 'moved there, on its day in the calendar');
        $this->assertSame([200, '2026-11-25'], [$moved['status'], $moved['data']['due']]);
        $this->assertSame(404, $movedMissing['status']);
        $this->assertSame([200, 404], [$deleted['status'], $again['status']]);
        $this->assertSame(403, $readOnly['status'], 'may only read: no delete');
        $this->assertSame(401, $signedOut['status']);
        $routes = $router->getRoutesWithPermissions();
        $this->assertNotEmpty(array_filter(array_keys($routes['PUT'] ?? []), static fn ($k): bool => str_contains((string) $k, '/api/tasks')), 'mounted under the path chosen');
    }

    /**
     * The rules' `todo` action: a to-do about the person, the title filled for them, linked to their
     * page, for someone, due in so many days; once per run and person; a test send to whoever asked.
     */
    #[DataProvider('databases')]
    public function testARuleAddsAToDo(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->ready($type, $host, $port, $user);
        [$eleni, $maria, $nikos] = [$this->ids['eleni'], $this->ids['maria'], $this->ids['nikos']];
        Actions::reset();
        $action  = Actions::get('todo');
        $context = new Context(4, 'Ακυρώσεις', 2, 'call', 900, $nikos);
        $test    = new Context(4, 'Ακυρώσεις', 2, 'call', 901, $nikos, recipient: $eleni);

        // Act
        $done   = ($action['run'])($nikos, ['title' => 'Πάρε τηλέφωνο τον {firstname}', 'assigned_to' => (string) $maria, 'days' => '2'], $context);
        $again  = ($action['run'])($nikos, ['title' => 'Πάρε τηλέφωνο τον {firstname}', 'assigned_to' => (string) $maria, 'days' => '2'], $context);
        $tested = ($action['run'])($nikos, ['title' => 'Δοκιμή', 'assigned_to' => (string) $maria, 'days' => ''], $test);
        $empty  = ($action['run'])($maria, ['title' => '', 'assigned_to' => '0'], $context);
        $item   = Todos::get((int) $done['detail']['todo']);

        // Assert
        $this->assertSame(['done', 'skipped', 'done', 'error'], [$done['status'], $again['status'], $tested['status'], $empty['status']]);
        $this->assertSame(['Πάρε τηλέφωνο τον Νίκος', $maria, 'rule'], [$item['title'], $item['assigned_to'], $item['kind']]);
        $this->assertStringContainsString((string) $nikos, (string) $item['link'], 'linked to their page');
        $this->assertNotNull($item['due_at']);
        $this->assertSame($eleni, Todos::get((int) $tested['detail']['todo'])['assigned_to'], 'a test send: to whoever asked');
    }
}
