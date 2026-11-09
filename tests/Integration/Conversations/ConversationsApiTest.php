<?php

declare(strict_types=1);

namespace Pramnos\Tests\Integration\Conversations;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Pramnos\Application\Application;
use Pramnos\Conversations\Conversations;
use Pramnos\Conversations\ConversationsApi;
use Pramnos\Framework\Migrations\Conversations\AddDetailsToConversations;
use Pramnos\Framework\Migrations\Conversations\AddImageToConversations;
use Pramnos\Framework\Migrations\Conversations\CreateConversationCanvasesTable;

/**
 * The conversations under the application's API (`ConversationsApi`), against MySQL with and
 * without a prefix and PostgreSQL: the screen's actions with the API's person — a read by GET, a
 * write by POST, in the API's shape — the wrong method refused, an action there is not a 404, the
 * page itself not offered, nobody a 401; the routes mounted under the path chosen.
 */
#[CoversClass(ConversationsApi::class)]
class ConversationsApiTest extends TestCase
{
    use ConversationsProbe {
        tearDown as private probeTearDown;
    }

    /** The request cleared, then the probe's own tear-down. */
    protected function tearDown(): void
    {
        $_GET = $_POST = [];
        \Pramnos\Http\Request::setRawInput('');
        $this->probeTearDown();
    }

    /** The API, asked by `$name` (nobody for ''). */
    private function api(string $name): ConversationsApi
    {
        $api = new class extends ConversationsApi {
            /** Who asks, set by the test. */
            public ?object $asking = null;

            /** The test's account, not the API's credentials. */
            protected function user(): ?object
            {
                return $this->asking;
            }
        };
        $api->asking = $name === '' ? null : $this->user($name);

        return $api;
    }

    /**
     * Reads and writes through the API answer as the screen does, wrapped in the API's shape.
     */
    #[DataProvider('databases')]
    public function testTheScreensActionsUnderTheApi(string $type, string $host, int $port, string $user): void
    {
        // Arrange
        $this->boot($type, $host, $port, $user);
        foreach (['2026_11_09_000013_add_image_to_conversations', '2026_11_09_000014_add_details_to_conversations', '2026_11_09_000015_create_conversation_canvases_table'] as $file) {
            require_once ROOT . '/database/migrations/framework/conversations/' . $file . '.php';
        }
        \Pramnos\Framework\Testing\Schema::ensure([AddImageToConversations::class, AddDetailsToConversations::class, CreateConversationCanvasesTable::class], $this->db);
        Application::getInstance()->applicationInfo['conversations'] = ['usertype' => 30];
        Application::getInstance()->applicationInfo['features'] = ['conversations'];
        [$maria, $nikos] = [$this->ids['maria'], $this->ids['nikos']];
        $launch = Conversations::create('channel', 'launch', [$nikos], $maria);
        \Pramnos\Broadcasting\Testing\FakeDriver::swap();

        try {
            // Act
            \Pramnos\Http\Request::setRawInput((string) json_encode(['topic' => 'Q4']));
            $described = $this->api('maria')->call('POST', 'describe', $launch);
            \Pramnos\Http\Request::setRawInput('');
            $tabs      = $this->api('nikos')->call('GET', 'tabs', $launch);
            $list      = $this->api('nikos')->call('GET', 'conversations', 0);
            \Pramnos\Http\Request::setRawInput((string) json_encode(['body' => 'Γεια']));
            $posted    = $this->api('nikos')->call('POST', 'post', $launch);
            \Pramnos\Http\Request::setRawInput('');
            $refused   = $this->api('nikos')->call('POST', 'describe', $launch);
            $byGet     = $this->api('maria')->call('GET', 'describe', $launch);
            $byPost    = $this->api('maria')->call('POST', 'tabs', $launch);
            $unknown   = $this->api('maria')->call('GET', 'nothing', 0);
            $page      = $this->api('maria')->call('GET', 'display', 0);
            $nobody    = $this->api('')->call('GET', 'conversations', 0);
            $router    = new \Pramnos\Routing\Router(Application::getInstance());
            ConversationsApi::routes($router, '/chat');
            $mounted   = array_keys($router->getRoutesWithPermissions()['POST'] ?? []);

            // Assert
            $this->assertSame([200, 'Q4'], [$described['status'], $described['data']['conversation']['topic']]);
            $this->assertSame(200, $tabs['status']);
            $this->assertSame(['messages', 'files', 'pins'], array_column($tabs['data']['tabs'], 'id'));
            $this->assertContains($launch, array_column($list['data']['conversations'], 'id'));
            $this->assertSame([200, 'Γεια'], [$posted['status'], $posted['data']['message']['body']]);
            $this->assertSame(404, $refused['status'], 'a member who may not: as the screen says');
            $this->assertNotSame('', $refused['error']);
            $this->assertSame([405, 405, 404, 404, 401], [$byGet['status'], $byPost['status'], $unknown['status'], $page['status'], $nobody['status']]);
            $this->assertNotEmpty(array_filter($mounted, static fn ($u): bool => str_contains((string) $u, '/chat/')), 'mounted under the path chosen');
            $actions = ConversationsApi::actions();
            $this->assertSame('POST', $actions['describe'][0]);
            $this->assertSame('GET', $actions['tabs'][0]);
            $this->assertSame('A channel\'s topic and description: `topic`, `description`, each one sent.', $actions['describe'][1], 'its docblock, for the API\'s document');
            $this->assertArrayNotHasKey('display', $actions);
            $this->assertEmpty(array_filter($actions, static fn (array $a): bool => $a[1] === ''), 'every action says what it does');
            $this->assertContains('/chat/describe/{id?}', $mounted);
            $doc = $router->getRoutesWithPermissions()['GET']['/chat/tabs/{id?}']['route']->getDoc();
            $this->assertSame(['conversations'], $doc['tags']);
        } finally {
            \Pramnos\Broadcasting\Testing\FakeDriver::restore();
        }
    }
}
