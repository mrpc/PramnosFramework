<?php

declare(strict_types=1);

namespace Pramnos\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pramnos\Routing\Route;
use Pramnos\Routing\Router;

/**
 * Every route the framework's API providers mount says what it does in the API's document
 * (`api:docs`): a summary at least, from the docblock of the method that answers it. A route
 * added without one — or a method that lost its docblock — fails here rather than appearing in
 * the published document as a bare method and path.
 */
#[CoversClass(Route::class)]
class ApiRoutesDescribedTest extends TestCase
{
    /**
     * Each provider, mounted on a router of its own: no route without a summary, and the summary
     * is the answering method's first line, not a placeholder.
     */
    public function testEveryApiRouteIsDescribed(): void
    {
        // Arrange
        $app    = \Pramnos\Application\Application::getInstance();
        $router = new Router($app);
        \Pramnos\Notification\FeedApi::routes($router);
        \Pramnos\Assist\HelpApi::routes($router);
        \Pramnos\Changelog\HistoryApi::routes($router);
        \Pramnos\Metrics\MetricsApi::routes($router);
        \Pramnos\Todos\TodosApi::routes($router);
        \Pramnos\Llm\Chat\Api\Routes::register($router);
        \Pramnos\Conversations\ConversationsApi::routes($router);
        \Pramnos\Auth\Controllers\AccountApi::routes($router);

        // Act
        $bare  = [];
        $count = 0;
        foreach ($router->getRoutesWithPermissions() as $method => $byUri) {
            foreach ($byUri as $uri => $entry) {
                $count++;
                if (trim((string) ($entry['route']->getDoc()['summary'] ?? '')) === '') {
                    $bare[] = $method . ' ' . $uri;
                }
            }
        }

        // Assert
        $this->assertGreaterThan(100, $count, 'the providers were mounted at all');
        $this->assertSame([], $bare, 'these routes say nothing in the API\'s document: give the answering method a docblock');
        $this->assertSame('One page of the feed, newest first.', $router->getRoutesWithPermissions()['GET']['/notifications']['route']->getDoc()['summary']);
    }

    /**
     * A docblock read as the document wants it: the first paragraph the summary on one line, the
     * rest the description, the markers and the `@` tags left out.
     */
    public function testADocblockIsReadAsSummaryAndDescription(): void
    {
        // Arrange
        $doc = "/**\n     * Change a goal the person may read:\n     * its metric stays.\n     *\n     * A second paragraph,\n     * on two lines.\n     *\n     * @return array<string, mixed>\n     */";

        // Act
        [$summary, $description] = Route::fromDocblock($doc);

        // Assert
        $this->assertSame('Change a goal the person may read: its metric stays.', $summary);
        $this->assertSame("A second paragraph,\non two lines.", $description);
        $this->assertSame(['', ''], Route::fromDocblock(''));
    }
}
